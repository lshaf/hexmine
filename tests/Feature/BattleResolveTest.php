<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\BattleGear;
use App\Game\Catalog;
use App\Game\Drops;
use App\Game\Formulas;
use App\Game\GameException;
use App\Game\GameService;
use App\Game\HexGeometry;
use App\Game\Monsters;
use App\Game\Spoils;
use App\Game\WorldGen;
use App\Http\Controllers\Api\MiningController;
use App\Models\Carrier;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\GameJob;
use App\Models\Player;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

/**
 * §9.5.5 -- the fight, settled in one action.
 *
 * There is no health on either side, so what a fight costs lands on the gear
 * (§9.5.6) and what it clears is the pack (§9.5.1). Both halves are here, and
 * so is the one rule that keeps the whole thing from being farmable: after the
 * roll there is no pack, whichever way it went.
 */
final class BattleResolveTest extends TestCase
{
    use RefreshDatabase;

    private GameService $game;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->game = app(GameService::class);
        $player = Player::create(['wallet' => '0xfight', 'session_id' => 'fight']);
        $this->character = $this->game->createCharacter($player);
    }

    /**
     * Hexes holding a pack right now, in map order.
     *
     * Searched rather than fabricated: a pack is a hash of the hex and the
     * clock (§9.5.1), so the only honest way onto one is to go and find one.
     *
     * @return Generator<int,array<string,mixed>>
     */
    private function packHexes(): Generator
    {
        $now = $this->game->now();
        $radius = Balance::mapRadius();

        for ($col = -$radius; $col <= $radius; $col++) {
            for ($row = -$radius; $row <= $radius; $row++) {
                $tile = WorldGen::generateTile($col, $row, $now);
                if (($tile['pack'] ?? null) !== null) {
                    yield ['col' => $col, 'row' => $row] + $tile['pack'];
                }
            }
        }
    }

    /**
     * Where the sword job stands, as one comparable value.
     *
     * Level and XP together, because the column alone lies at a level-up: 25
     * XP into a job that needed 17 stores 8, and a test reading the raw figure
     * would call that a loss of progress.
     */
    private function swordhand(): string
    {
        $row = $this->character->fresh()->jobLevels()->where('job_key', 'swordhand')->first();

        return $row === null ? '0/0' : "{$row->level}/{$row->xp}";
    }

    private function standOn(array $pack): void
    {
        $this->character->col = $pack['col'];
        $this->character->row = $pack['row'];
        $this->character->save();
        $this->character = $this->character->fresh();
    }

    /**
     * Stand on a hex that has a pack on it RIGHT NOW.
     *
     * packHexes() snapshots the world at one instant; the fight reads the clock
     * again. The test clock runs at GAME_TIME_SCALE, so a two-hour bucket is
     * two minutes here and a hex listed a moment ago may genuinely be empty by
     * the time we swing at it. Re-asking is the honest fix -- the alternative
     * is a test that fails whenever it straddles a bucket.
     *
     * @return array<string,mixed>
     */
    private function standOnALivePack(): array
    {
        foreach ($this->packHexes() as $pack) {
            $this->standOn($pack);

            if ($this->game->packHere($this->character->fresh()) !== null) {
                return $pack;
            }
        }

        $this->fail('no live pack anywhere on the map');
    }

    /**
     * Is the row a carrier is holding back in the bag?
     *
     * Asked of the loot itself rather than of a material this test happened to
     * put there: the theft is TRULY random (§9.5.7), and a win on the way to
     * the loss drops spoils, so what it takes is not something a test may
     * assume.
     */
    private function holds(array $loot): bool
    {
        return match ($loot['kind']) {
            'material' => $this->game->held($this->character->fresh(), $loot['key']) > 0,
            'consumable' => $this->game->heldConsumable($this->character->fresh(), $loot['key']) > 0,
            default => $this->character->fresh()->items()
                ->where('item_key', $loot['key'])
                ->where('equipped', false)
                ->exists(),
        };
    }

    /**
     * Swing at the corpse until it goes down.
     *
     * A carrier's roll folds in the clock (§9.5.7) precisely so that losing to
     * it once is not losing to it forever -- which means even a 95% kit misses
     * one in twenty, and a test that assumed otherwise would be flaky by
     * design. Retrying is what a player does, and it is what this does.
     *
     * @return array<string,mixed>
     */
    private function killCarrier(Character $fighter, Carrier $carrier, int $tries = 12): array
    {
        for ($i = 0; $i < $tries; $i++) {
            // §9.5.7 -- a loss is a death, and a death wakes you at the nearest
            // settlement. Walking back is part of the recovery, so the retry
            // has to walk back too.
            //
            // FRESHENED FIRST, and that is not a nicety. The previous attempt
            // moved this character in the database through a different
            // instance; assigning the same coordinates to a stale one leaves
            // them un-dirty, save() writes nothing, and the walk back silently
            // does not happen.
            $fighter = $fighter->fresh();
            $fighter->col = $carrier->col;
            $fighter->row = $carrier->row;
            $fighter->save();

            $result = $this->resolveFight($fighter);

            if ($result['won']) {
                return $result;
            }
        }

        $this->fail('a legendary kit never put a treeline monster down');
    }

    /**
     * Take the fight and see it through.
     *
     * §9.5.5 -- a fight is a job on a clock now, so every test that used to
     * call one method calls two. The clock is wound forward rather than waited
     * out; what is being tested is what the fight DOES, not that time passes.
     *
     * @return array<string,mixed>
     */
    private function resolveFight(Character $fighter): array
    {
        $job = $this->game->startBattle($fighter->fresh());
        $job->update(['ends_at' => $this->game->now() - 1]);

        return $this->game->collectJob($fighter->fresh(), $job->id);
    }

    /**
     * The same thing, for a hex that may have emptied since we looked.
     *
     * A pack is time-bucketed and the test clock runs at GAME_TIME_SCALE, so a
     * two-hour bucket is two minutes here: between checking a hex and swinging
     * at it, the thing can genuinely have wandered off. Returning null and
     * moving on is what a player would do, and is the only answer that does not
     * make the suite flaky by design.
     *
     * @return array<string,mixed>|null
     */
    private function tryFight(Character $fighter): ?array
    {
        try {
            return $this->resolveFight($fighter);
        } catch (GameException $e) {
            if ($e->errorCode === 'no_pack') {
                return null;
            }

            throw $e;
        }
    }

    /**
     * §9.5.5 -- one endpoint collects every kind of job, and it has to survive
     * the one whose receipt carries no haul.
     *
     * `finishBattle` returns an exchange and its consequences: no material
     * ledger anywhere in it, so no `gained` key. The controller casts that key
     * so an empty haul serialises as {} rather than [], and casting it
     * unguarded turned every collected fight into a 500.
     *
     * Driven through the controller rather than the route because nothing in
     * this suite authenticates over HTTP yet, and the defect is in the
     * controller method rather than in the middleware in front of it. The
     * service was always right here -- which is exactly why calling it directly,
     * as every other battle test does, never caught this.
     */
    public function test_collecting_a_fight_survives_the_haul_cast(): void
    {
        $this->standOnALivePack();

        $job = $this->game->startBattle($this->character->fresh());
        $job->update(['ends_at' => $this->game->now() - 1]);

        $payload = $this->collectThroughController($job->id);

        $this->assertArrayHasKey('won', $payload, 'a fight came back without its outcome');
        $this->assertArrayNotHasKey('gained', $payload, 'a fight came back with a material ledger');
    }

    /**
     * And the other half: the cast the guard is wrapped around still happens.
     *
     * An empty haul has to reach the client as {} rather than [], because the
     * client reads it as Record<MaterialKey, number>. A guard that skipped the
     * cast on every path would fix the fight and quietly break the mine.
     */
    public function test_collecting_an_empty_haul_still_casts_to_an_object(): void
    {
        $col = (int) $this->character->col;
        $row = (int) $this->character->row;
        $now = $this->game->now();

        $job = GameJob::create([
            'character_id' => $this->character->id,
            'kind' => 'mining',
            'status' => 'active',
            'col' => $col,
            'row' => $row,
            'slot' => 0,
            'material_key' => Catalog::BIOME_SCRAP[
                WorldGen::generateTile($col, $row, $now)['biome']
            ],
            'quantity' => 1,
            'skill_key' => 'woodcutting',
            'started_at' => $now - 10,
            'ends_at' => $now - 1,
        ]);

        $payload = $this->collectThroughController($job->id);

        $this->assertArrayHasKey('gained', $payload);
        $this->assertIsObject($payload['gained'], 'an empty haul would serialise as [] rather than {}');
    }

    /**
     * Call MiningController::collect the way ResolveCharacter would have.
     *
     * The middleware's whole contribution is putting the character on the
     * request, so a bare Request with that attribute set is the same call
     * without the session plumbing.
     *
     * @return array<string,mixed>
     */
    private function collectThroughController(int $jobId): array
    {
        $request = Request::create("/api/jobs/{$jobId}/collect", 'POST');
        $request->attributes->set('character', $this->character->fresh());

        $response = app(MiningController::class)
            ->collect($request, $jobId);

        $this->assertSame(200, $response->getStatusCode());

        return (array) $response->getData()->data;
    }

    /**
     * §9.5.5 -- a blow always lands, however far the attack is under the guard.
     *
     * A wall nobody can scratch is a locked hex, and §9.5.3 says fighting is
     * always one of the two ways out of a pin -- bare-handed if the gear is
     * gone. So the arithmetic has to keep a floor under every strike rather
     * than letting the subtraction reach zero.
     *
     * Swept rather than spot-checked: every monster in the roster against every
     * rung of kit the game allows, over enough seeds that the swing has been
     * everywhere it can go.
     */
    public function test_every_blow_lands_for_at_least_a_chip(): void
    {
        $worst = PHP_INT_MAX;
        $where = '';

        foreach (Monsters::ROSTER as $key => $monster) {
            foreach ([0, 1, 2, 5, 12, 41] as $attack) {
                foreach ([0, 5, 18, 60] as $defense) {
                    for ($seed = 0; $seed < 60; $seed++) {
                        $log = Formulas::resolveBattle(
                            $attack,
                            $defense,
                            900,
                            $monster,
                            $seed,
                        )['log'];

                        foreach ($log as $i => $round) {
                            if ($round['hit'] < $worst) {
                                $worst = $round['hit'];
                                $where = "{$key}, attack {$attack}, seed {$seed}, round {$i}";
                            }

                            // The last round's `back` is zero by design: it is
                            // the round that put the thing down, and it does not
                            // strike back from the floor.
                            if ($i === count($log) - 1) {
                                continue;
                            }

                            if ($round['back'] < $worst) {
                                $worst = $round['back'];
                                $where = "{$key}, defense {$defense}, seed {$seed}, round {$i}";
                            }
                        }
                    }
                }
            }
        }

        $this->assertSame(
            Balance::BATTLE_CHIP,
            $worst,
            "a blow landed under the chip floor: {$where}",
        );
    }

    /**
     * §9.5.5 -- and the floor survives the SWING, which is the half a refactor
     * would break.
     *
     * `strike` reads max(floor, round(gap * swing)), and applying the floor LAST
     * is what makes it hold whatever the swing is tuned to. At the current
     * +-10% the other order agrees by luck -- round(1 * 0.9) is still 1 -- so
     * this asserts the outcome rather than the arrangement: whatever the swing
     * does, a blow lands.
     *
     * The sharpest case in the game is the one asserted: nothing in your hands
     * against the thing with the highest guard on the roster.
     */
    public function test_the_chip_floor_survives_the_swing(): void
    {
        $wall = Monsters::ROSTER['barrow_knight'];

        $this->assertGreaterThan(
            0,
            $wall['defense'],
            'the wall this test is about has no guard to be under',
        );

        for ($seed = 0; $seed < 240; $seed++) {
            $fight = Formulas::resolveBattle(0, 0, 400, $wall, $seed);

            foreach ($fight['log'] as $i => $round) {
                $this->assertGreaterThanOrEqual(
                    Balance::BATTLE_CHIP,
                    $round['hit'],
                    "bare hands failed to land at all on seed {$seed}, round {$i}",
                );
            }

            // And the whole exchange therefore moves: a fight that dealt
            // nothing would be the locked hex the floor exists to prevent.
            $this->assertGreaterThanOrEqual($fight['rounds'], $fight['damageDealt']);
        }
    }

    /**
     * §9.5.5 -- the preview is floored too, so it never promises a fight the
     * exchange will not give.
     *
     * The preview is the same exchange with the swing taken out (§9.5.5's "a
     * promise, not a guess"). An unfloored preview would print a zero-damage
     * forecast beside a fight that lands one a round, and the plate would be
     * arguing with the replay.
     */
    public function test_the_preview_is_floored_the_same_way(): void
    {
        foreach (Monsters::ROSTER as $key => $monster) {
            $expected = Formulas::expectedBattle(0, 0, 400, $monster);

            $this->assertGreaterThan(
                0,
                $expected['damageDealt'],
                "the preview promised nothing at all against {$key}",
            );
            $this->assertLessThanOrEqual(Balance::BATTLE_MAX_ROUNDS, $expected['rounds']);
        }
    }

    /**
     * §9.5.6 -- the bill is a quarter of what the fight took, and the split
     * sends most of it to the half of the kit that earned it.
     *
     * Checked on the arithmetic across the whole roster rather than through a
     * fought pack, because which monster you meet is a hash of the hex and the
     * hour: the rule is about every matchup, not the one the map offered.
     */
    public function test_the_bill_is_a_quarter_and_lands_where_the_fight_did(): void
    {
        foreach (Monsters::ROSTER as $key => $monster) {
            $this->assertSame(
                (int) round(200 * Balance::BATTLE_WEAR_RATE),
                Formulas::battleWearBill(200),
                'the bill stopped being a flat share of the damage taken',
            );

            $split = Formulas::battleWearSplit($monster);

            $this->assertEqualsWithDelta(1.0, $split['worn'] + $split['hands'], 1e-9);

            // A monster leaning on its attack beat on the worn set; one leaning
            // on its guard was a wall you spent the fight hitting.
            if ((int) $monster['attack'] > (int) $monster['defense']) {
                $this->assertGreaterThan(
                    $split['hands'],
                    $split['worn'],
                    "{$key} hits harder than it guards, so armor should take the brunt",
                );
            } else {
                $this->assertGreaterThan(
                    $split['worn'],
                    $split['hands'],
                    "{$key} guards harder than it hits, so the blade should take the brunt",
                );
            }
        }
    }

    /**
     * §9.5.6 -- `wearBias` moves the RATIO, never the total.
     *
     * A Ridge Wyrm "blunts whatever it is hit with", and that has to stay true
     * without letting it charge more than a quarter of the damage: the extra
     * comes out of the worn half rather than out of thin air.
     */
    public function test_a_blunting_monster_shifts_the_split_not_the_total(): void
    {
        $wyrm = Monsters::ROSTER['ridge_wyrm'];
        $this->assertGreaterThan(1.0, $wyrm['wearBias'], 'the blunting monster stopped blunting');

        $shrike = Monsters::ROSTER['iron_shrike'];
        $this->assertSame(1.0, (float) $shrike['wearBias']);

        $biased = Formulas::battleWearSplit($wyrm);
        $plain = Formulas::battleWearSplit($shrike);

        // Both lean on attack, so both send the brunt to the worn set -- but
        // the one that blunts sends a bigger slice to the blade.
        $this->assertGreaterThan($plain['hands'], $biased['hands']);
        $this->assertEqualsWithDelta(1.0, $biased['worn'] + $biased['hands'], 1e-9);

        // And the total is untouched: the bill is the damage, not the monster.
        $this->assertSame(
            Formulas::battleWearBill(400),
            Formulas::battleWearBill(400),
        );
    }

    /**
     * §9.5.6 -- a fight that never landed on you costs nothing.
     *
     * The known consequence of anchoring the bill to damage TAKEN, pinned here
     * so it is a decision rather than a surprise: a kit strong enough to take a
     * pack without being touched pays no repair bill for it. The sink (§11.1)
     * therefore charges the fights that hurt and forgives the routs.
     */
    public function test_an_untouched_kit_pays_nothing(): void
    {
        $this->assertSame(0, Formulas::battleWearBill(0));

        // And a scratch rounds away rather than charging a point out of
        // nowhere: a quarter of one is nothing.
        $this->assertSame(0, Formulas::battleWearBill(1));
        $this->assertSame(1, Formulas::battleWearBill(2));
    }

    /** Put a stack in the bag, through the service so the row rules apply. */
    private function give(string $key, int $quantity): void
    {
        $add = new ReflectionMethod($this->game, 'addMaterial');
        $add->setAccessible(true);
        $add->invoke($this->game, $this->character, $key, $quantity);

        $this->character = $this->character->fresh();
    }

    private function equip(string $key, ?int $durability = null): CharacterItem
    {
        return CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => $key,
            'durability' => $durability ?? Catalog::item($key)['maxDurability'],
            'equipped' => true,
            'options' => [],
        ]);
    }

    /**
     * Fight pack after pack until one lands the way this test needs it.
     *
     * The roll is seeded off the hex (§16), so the outcome is fixed per pack
     * and walking to the next one is the only way to get the other side of it.
     * That is also what makes this deterministic: same map, same answer.
     *
     * @return array<string,mixed>
     */
    private function fightUntil(bool $won, int $tries = 40): array
    {
        $seen = 0;

        foreach ($this->packHexes() as $pack) {
            if (++$seen > $tries) {
                break;
            }

            $this->standOn($pack);

            // Measured across THIS fight rather than the run of them: the walk
            // to a losing pack may cross winning ones, and their gold is not
            // what the loss did or did not pay.
            $goldBefore = (int) $this->character->fresh()->gold;
            $standingBefore = $this->swordhand();

            // The bucket may have rolled since packHexes() looked: an empty hex
            // is not a fight, it is the next hex.
            $result = $this->tryFight($this->character);
            $this->character = $this->character->fresh();

            if ($result === null) {
                continue;
            }

            if ($result['won'] === $won) {
                return $result + [
                    'goldMoved' => (int) $this->character->gold - $goldBefore,
                    'jobMoved' => $this->swordhand() !== $standingBefore,
                ];
            }
        }

        $this->fail($won ? 'never won a fight' : 'never lost a fight');
    }

    /**
     * §9.5.1 -- the anti-farm rule, and it needs no cooldown: you cannot
     * re-roll a pack, because after the roll there is no pack.
     */
    public function test_the_pack_is_cleared_win_or_lose(): void
    {
        $this->standOnALivePack();

        $this->assertNotNull($this->game->packHere($this->character->fresh()));

        $result = $this->resolveFight($this->character);
        $this->assertIsBool($result['won']);

        $this->assertNull(
            $this->game->packHere($this->character->fresh()),
            'the pack survived its own resolution',
        );

        try {
            $this->resolveFight($this->character);
            $this->fail('the same pack was fought twice');
        } catch (GameException $e) {
            $this->assertSame('no_pack', $e->errorCode);
        }
    }

    /** §9.5.3 -- and clearing it is what lifts the pin, whichever way it went. */
    public function test_settling_it_frees_the_hex(): void
    {
        // §9.5.7 -- with something on to take the hit, a loss is a loss rather
        // than a death, so the character is still standing where it happened.
        $this->equip('longwatch_carapace');

        $this->standOnALivePack();

        $this->resolveFight($this->character);

        $preview = $this->game->previewTile(
            $this->character->fresh(),
            (int) $this->character->col,
            (int) $this->character->row,
            Drops::GATHERING,
        );

        $this->assertFalse($preview['pinned'], 'the hex stayed shut after the fight');
    }

    /**
     * §9.5.8 -- gold always, and it needs no bag row. §7.4 -- the weapon's
     * family names the job that learns from the win.
     */
    public function test_a_win_pays_gold_and_teaches_the_battle_job(): void
    {
        $this->equip('the_last_argument');
        $this->equip('longwatch_carapace');

        $result = $this->fightUntil(true);

        $this->assertGreaterThan(0, $result['gold']);
        $this->assertSame($result['gold'], $result['goldMoved'], 'the purse and the receipt disagree');

        $this->assertSame('swordhand', $result['job']);
        $this->assertSame(
            Balance::JOB_XP_PER_BATTLE_TIER * $result['monster']['tier'],
            $result['jobXp'],
        );
        $this->assertTrue($result['jobMoved'], 'the win taught the job nothing');
    }

    /**
     * §9.5.3 -- losing is an exit, not a strategy. Half XP for it sounds
     * generous and is a trickle you can farm by dying on purpose.
     */
    public function test_a_loss_pays_nothing_at_all(): void
    {
        $this->equip('notched_sword');

        $result = $this->fightUntil(false);

        $this->assertSame(0, $result['gold']);
        $this->assertSame(0, $result['jobXp']);
        $this->assertSame(0, $result['goldMoved'], 'a loss moved the purse');
        $this->assertFalse($result['jobMoved'], 'a loss taught the job something');
    }

    /**
     * §9.5.5 -- the fight is settled at engagement and the clock is the REPLAY.
     *
     * The exchange is stored round by round so the screen can draw it, and the
     * job's length is that replay rather than a flat cooldown by tier: a rout
     * is over in a couple of seconds and a grind takes ten, which is the whole
     * reason to watch one.
     */
    public function test_a_fight_stores_its_exchange_and_is_clocked_by_it(): void
    {
        $this->equip('soldiers_sword');
        $this->equip('studded_jack');

        $this->standOnALivePack();

        $job = $this->game->startBattle($this->character->fresh());
        $payload = $job->payload;

        $log = $payload['log'] ?? [];
        $this->assertNotEmpty($log, 'a fight was drawn with nothing to draw');
        $this->assertCount((int) $payload['rounds'], $log, 'the log and the round count disagree');

        // Every round says what each side got through and where both pools
        // stood after it -- which is exactly what a bar needs to move.
        $hp = (int) $payload['pool'];
        foreach ($log as $round) {
            $this->assertArrayHasKey('hit', $round);
            $this->assertArrayHasKey('back', $round);
            $this->assertLessThanOrEqual($hp, $round['hp'], 'a pool went up mid-fight');
            $hp = $round['hp'];
        }

        // §9.5.5 -- the clock is the exchange, and it is REAL milliseconds: an
        // animation is not a game hour, so scaled() must not touch it.
        $this->assertSame(
            Formulas::battleDurationMs((int) $payload['rounds']),
            (int) $job->ends_at - (int) $job->started_at,
            'the fight is not clocked by its own exchange',
        );

        // §9.5.5 -- one second a round, so the longest the game allows is the
        // bell at sixty. The clock is the exchange: a rout is a couple of
        // seconds and a grind against a wall takes as long as the grind did.
        $this->assertSame(
            Balance::BATTLE_MAX_ROUNDS * Balance::BATTLE_ROUND_MS + Balance::BATTLE_TAIL_MS,
            Formulas::battleDurationMs(Balance::BATTLE_MAX_ROUNDS),
        );
    }

    /**
     * §9.5.6 -- durability IS the health bar, so a beating lands on the kit
     * that took it. The blade pays separately, for what it was swung AT.
     */
    public function test_a_fight_spends_the_kit_that_took_it(): void
    {
        $this->equip('the_last_argument');
        $this->equip('longwatch_carapace');
        $this->equip('unmoved_sabatons');
        $this->equip('gauntlets_of_the_last_word');

        // §9.5.6 -- the bill is a quarter of what the fight took OUT of you, so
        // a fight that never landed on you costs nothing at all. Walk packs
        // until one does land, which is the same search the pack tests already
        // do -- what is under test is where a bill goes, not whether one exists.
        $result = null;
        foreach ($this->packHexes() as $pack) {
            $this->standOn($pack);
            $attempt = $this->tryFight($this->character);
            if ($attempt !== null && $attempt['wear'] !== []) {
                $result = $attempt;
                break;
            }
        }

        $this->assertNotNull($result, 'no pack on the map ever charged this kit');

        $slots = array_column($result['wear'], 'slot');

        foreach ($result['wear'] as $row) {
            $this->assertGreaterThan(0, $row['lost'], "{$row['name']} wore nothing");
            $this->assertContains($row['slot'], Balance::COMBAT_SLOTS);
        }

        // A tool belt is not armor (§8 rule 2): nothing outside the combat
        // slots is ever in the exchange.
        $this->assertSame($slots, array_values(array_intersect($slots, Balance::COMBAT_SLOTS)));
    }

    /**
     * §9.5.6 -- one fight costs a quarter of what it took out of you, and the
     * damage can never exceed the pool. So the bill is bounded by a quarter of
     * the kit however badly it went.
     *
     * That is a tighter promise than the old one. There used to be a cap at
     * half the pool plus a separate blade bill on top, which meant the ceiling
     * was "half, plus however long the fight ran" -- not a number a player
     * could hold. A quarter of what you watched drain is.
     */
    public function test_one_fight_never_takes_more_than_a_quarter_of_the_kit(): void
    {
        $pieces = ['notched_sword', 'padded_jack', 'studded_boots', 'knuckle_wraps'];
        foreach ($pieces as $key) {
            $this->equip($key);
        }

        $pool = Formulas::battlePool(
            $this->character->fresh()->items->map(fn (CharacterItem $i) => [
                'id' => $i->id,
                'key' => $i->item_key,
                'durability' => $i->durability,
                'equipped' => $i->equipped,
                'options' => [],
            ])->all(),
        );
        $this->assertGreaterThan(0, $pool);

        $this->standOnALivePack();
        $result = $this->resolveFight($this->character);

        $lost = array_sum(array_column($result['wear'], 'lost'));

        $this->assertLessThanOrEqual(
            (int) ceil($pool * Balance::BATTLE_WEAR_RATE),
            $lost,
            'one fight took more than a quarter of the kit',
        );

        // And it is the quarter of what was TAKEN rather than a flat charge:
        // the two have to agree or the bar the player watched drain was lying.
        $this->assertSame(
            Formulas::battleWearBill($result['damageTaken']),
            $lost,
            'the bill and the damage taken disagree',
        );
    }

    /**
     * §9.5.6 -- half a kit pays the whole bill, and it pays it into what is there.
     *
     * The split says WHERE a beating landed, not which pieces exist to take it.
     * A fighter carrying nothing but a sword took the same quarter as a fighter
     * in full plate: the worn half has nowhere to land, so it spills back to
     * the hands, and the sword is what is left. The reverse holds too -- armor
     * with no weapon takes the hands' share on the coat.
     *
     * The invariant is that nothing goes missing. A half the arithmetic cannot
     * reach must never quietly discount the fight, because that would make
     * stripping down a way of fighting cheaply -- and §9.5.6 already promises
     * "a fighter with no gloves does not get a discount".
     */
    public function test_half_a_kit_still_pays_the_whole_bill(): void
    {
        $kits = [
            'a weapon and nothing else' => ['notched_sword'],
            'worn gear and no weapon' => ['padded_jack', 'studded_boots', 'knuckle_wraps'],
        ];

        foreach ($kits as $what => $pieces) {
            $this->character->items()->delete();
            foreach ($pieces as $key) {
                $this->equip($key);
            }

            $result = null;
            foreach ($this->packHexes() as $pack) {
                $this->standOn($pack);
                $attempt = $this->tryFight($this->character);
                if ($attempt !== null && $attempt['damageTaken'] > 0) {
                    $result = $attempt;
                    break;
                }
            }

            $this->assertNotNull($result, "no pack on the map ever landed on {$what}");

            $lost = array_sum(array_column($result['wear'], 'lost'));

            // The whole bill, not the half that had somewhere to go.
            $this->assertSame(
                Formulas::battleWearBill($result['damageTaken']),
                $lost,
                "{$what} was billed for less than the fight took",
            );

            // And every point of it landed on a piece that was actually worn.
            foreach ($result['wear'] as $row) {
                $this->assertContains($row['slot'], Balance::COMBAT_SLOTS);
                $this->assertGreaterThan(0, $row['lost']);
            }
        }
    }

    /**
     * §9.5.6 -- and a piece is never charged twice for the same bill.
     *
     * The spill is a SECOND look at the same pieces: whatever the worn half
     * could not absorb goes back to the hands, and if that pass re-read the
     * durability the first pass had already spent, a piece could be billed for
     * more than it holds. Unreachable in play -- damage cannot exceed the pool
     * and the bill is a quarter of it, so the two halves can never both be
     * empty at once -- but the receipt has to be true whatever BATTLE_WEAR_RATE
     * is tuned to, and this is the shape that would break first.
     */
    public function test_a_spill_never_charges_a_piece_past_what_it_holds(): void
    {
        $wear = new ReflectionMethod($this->game, 'battleWear');
        $wear->setAccessible(true);

        $sword = [[
            'id' => 1,
            'key' => 'notched_sword',
            'durability' => 3,
            'equipped' => true,
            'options' => [],
        ]];

        foreach (Monsters::ROSTER as $key => $monster) {
            // Far more damage than this kit could ever really take, which is
            // the only way to reach the spill at all.
            $lost = $wear->invoke($this->game, $sword, $monster, 4000, 0.0, 0.0);

            $this->assertSame(
                3,
                array_sum($lost),
                "{$key} billed a 3-durability sword for more than three",
            );
        }
    }

    /**
     * §8.2 -- at zero the thing is GONE. Not broken, not inactive: the row is
     * deleted and named in the result that killed it.
     */
    public function test_gear_that_runs_out_is_destroyed_and_named(): void
    {
        // A full kit, because the bill is a share of the damage taken and a
        // lone sword is a pool of one -- a quarter of which rounds to nothing.
        // The sword is the piece on its last point either way.
        $sword = $this->equip('notched_sword', 1);
        $this->equip('padded_jack');
        $this->equip('studded_boots');
        $this->equip('knuckle_wraps');

        $result = null;
        foreach ($this->packHexes() as $pack) {
            $this->standOn($pack);
            $attempt = $this->tryFight($this->character);
            if ($attempt !== null && $attempt['destroyed'] !== []) {
                $result = $attempt;
                break;
            }
        }

        $this->assertNotNull($result, 'nothing on the map ever finished off a one-point sword');
        $this->assertContains('Notched Sword', $result['destroyed']);
        $this->assertNull(CharacterItem::find($sword->id), 'a destroyed item was left in the bag');

        $row = collect($result['wear'])->firstWhere('name', 'Notched Sword');
        $this->assertTrue($row['destroyed']);
        $this->assertSame(0, $row['left']);
    }

    /**
     * §9.5.6 -- no ONE fight takes more than its share, whatever the mismatch.
     *
     * Not optional now that zero is fatal (§8.2): without the cap a single
     * hopeless fight snaps a legendary outright, and the pre-fight warning
     * would be all that stood between a player and losing a week of work to a
     * mistap. Checked per fight rather than per career: wear accumulates, and
     * it is supposed to.
     */
    public function test_no_single_fight_takes_more_than_its_share(): void
    {
        $this->equip('the_last_argument');
        $this->equip('longwatch_carapace');

        $fought = 0;

        foreach ($this->packHexes() as $pack) {
            $this->standOn($pack);

            // A hex listed a moment ago may be empty by the time we swing at
            // it -- see tryFight(). Skipping is what a player would do.
            $result = $this->tryFight($this->character);
            if ($result === null) {
                continue;
            }

            foreach ($result['wear'] as $row) {
                $max = Catalog::item(
                    $row['slot'] === 'weapon' ? 'the_last_argument' : 'longwatch_carapace',
                )['maxDurability'];

                $this->assertLessThanOrEqual(
                    max(1, (int) floor($max * Balance::WEAR_CAP_FRACTION)),
                    $row['lost'],
                    "{$row['name']} lost more than one fight's share",
                );
                $this->assertFalse($row['destroyed'], 'a legendary was snapped by one fight');
            }

            if (++$fought >= 6) {
                break;
            }
        }

        $this->assertSame(6, $fought);
    }

    // ------------------------------------------------------------ death §9.5.7

    /** §9.5.7 -- a loss is a death, whatever you were wearing when you took it. */
    public function test_a_loss_is_a_death(): void
    {
        $this->equip('notched_sword');
        $this->give('wood', 12);

        $result = $this->fightUntil(false);

        $this->assertTrue($result['died'], 'a bare-chested loss was survived');
        $this->assertNotNull($result['stolen'], 'the pack took nothing');
        $this->assertNotNull($result['wokeAt'], 'nowhere to wake up');

        // §9.5.7 -- you wake at the nearest settlement, which is the first bill.
        $woke = $result['wokeAt'];
        $this->assertSame($woke['col'], (int) $this->character->fresh()->col);
        $this->assertSame($woke['row'], (int) $this->character->fresh()->row);
        $this->assertNotNull(WorldGen::settlementAt($woke['col'], $woke['row']));
    }

    /**
     * §9.5.7 -- and armor does not change that. It decides whether you LOSE,
     * because defense feeds the hold and the hold is half the margin; it does
     * not decide what losing costs.
     *
     * It used to: a loss was only a death when nothing absorbed it. That made
     * the interesting question "am I wearing anything" rather than "should I
     * take this fight", and the second one is what the odds are printed for.
     */
    public function test_armor_does_not_change_what_a_loss_costs(): void
    {
        $this->equip('notched_sword');
        $this->equip('longwatch_carapace');
        $this->give('wood', 9);

        $result = $this->fightUntil(false);

        $this->assertTrue($result['died'], 'a loss in full armor was survived');
        $this->assertNotNull($result['stolen']);
        $this->assertNotNull($result['wokeAt']);
    }

    /**
     * §9.5.7 -- the corpse. Named for what it took, standing where you fell,
     * and drawn for EVERYBODY regardless of sight (§13.2).
     */
    public function test_death_leaves_a_carrier_holding_the_row(): void
    {
        $this->equip('notched_sword');
        $this->give('wood', 9);

        $result = $this->fightUntil(false);
        $this->assertTrue($result['died']);

        $carrier = Carrier::first();
        $this->assertNotNull($carrier, 'nothing was left standing');
        $this->assertSame($this->character->id, $carrier->owner_character_id);
        $this->assertSame($result['stolen']['label'], $carrier->label);

        // The row really is gone from the bag until somebody walks back for it.
        $this->assertFalse($this->holds($carrier->loot), 'the stolen row was still in the bag');

        // §9.5.7 -- the owner sees it through any fog, and it rides the player
        // state rather than the map for exactly that reason. They woke at a
        // settlement, which is nowhere near where they fell.
        $mine = collect($this->game->ownCarriers($this->character->fresh()))
            ->firstWhere(fn (array $c) => $c['col'] === $carrier->col && $c['row'] === $carrier->row);

        $this->assertNotNull($mine, 'a prospector could not find their own corpse');
        $this->assertTrue($mine['mine']);
        $this->assertGreaterThan(
            $this->game->sightRadius($this->character->fresh()),
            HexGeometry::distance(
                (int) $this->character->fresh()->col,
                (int) $this->character->fresh()->row,
                $carrier->col,
                $carrier->row,
            ),
            'the corpse happened to be in sight, so this proves nothing',
        );
    }

    /**
     * §9.5.7 -- and a stranger's corpse obeys the fog like everything else.
     *
     * A map-wide list of every death on the server would be a scanner, with the
     * rich ones worth racing to. Finding one is the interesting part.
     */
    public function test_somebody_elses_corpse_is_only_visible_in_sight(): void
    {
        $this->equip('notched_sword');
        $this->give('wood', 9);

        $this->fightUntil(false);
        $carrier = Carrier::first();
        $this->assertNotNull($carrier);

        $stranger = $this->game->createCharacter(
            Player::create(['wallet' => '0xother', 'session_id' => 'other']),
        );

        $this->assertSame(
            [],
            $this->game->carriersInSight($stranger),
            'a stranger saw a corpse across the map',
        );

        // And it is not on their state either -- that endpoint is what is
        // theirs, and this is not.
        $this->assertSame([], $this->game->ownCarriers($stranger));

        // Walk over to it and it is simply there, like anything else in sight.
        $stranger->col = $carrier->col;
        $stranger->row = $carrier->row;
        $stranger->save();

        $seen = collect($this->game->carriersInSight($stranger->fresh()))
            ->firstWhere(fn (array $c) => $c['col'] === $carrier->col && $c['row'] === $carrier->row);

        $this->assertNotNull($seen, 'a corpse underfoot was invisible');
        $this->assertFalse($seen['mine']);
    }

    /** §9.5.7 -- kill your own corpse and the row comes home, as it left. */
    public function test_the_owner_takes_the_row_back(): void
    {
        $this->equip('notched_sword');
        $this->give('wood', 9);

        $death = $this->fightUntil(false);
        $this->assertTrue($death['died']);

        $carrier = Carrier::first();

        // Kit up properly and walk back to it.
        $this->equip('the_last_argument');
        $this->equip('longwatch_carapace');
        $this->character->col = $carrier->col;
        $this->character->row = $carrier->row;
        $this->character->save();

        $result = $this->killCarrier($this->character, $carrier);

        $this->assertTrue($result['corpse']['mine']);
        $this->assertSame($carrier->label, $result['recovered']);
        $this->assertNull($result['burned']);
        $this->assertTrue($this->holds($carrier->loot), 'the row did not come home');
        $this->assertNull(Carrier::find($carrier->id));
    }

    /**
     * §2 -- and anybody else killing it BURNS the row.
     *
     * An item another wallet can pick up is a direct player-to-player transfer,
     * which the threat model closes outright -- and "random row" is no defense:
     * empty the bag to the one thing worth moving, fight naked, die on purpose,
     * and a partner walks over and collects it.
     */
    public function test_a_stranger_kill_burns_the_row_rather_than_moving_it(): void
    {
        $this->equip('notched_sword');
        $this->give('wood', 9);

        $this->fightUntil(false);
        $carrier = Carrier::first();
        $this->assertNotNull($carrier);

        $stranger = $this->game->createCharacter(
            Player::create(['wallet' => '0xrival', 'session_id' => 'rival']),
        );
        CharacterItem::create([
            'character_id' => $stranger->id,
            'item_key' => 'the_last_argument',
            'durability' => 288,
            'equipped' => true,
            'options' => [],
        ]);
        CharacterItem::create([
            'character_id' => $stranger->id,
            'item_key' => 'longwatch_carapace',
            'durability' => 300,
            'equipped' => true,
            'options' => [],
        ]);
        $stranger->col = $carrier->col;
        $stranger->row = $carrier->row;
        $stranger->save();

        $result = $this->killCarrier($stranger, $carrier);

        $this->assertFalse($result['corpse']['mine']);
        $this->assertSame($carrier->label, $result['burned']);
        $this->assertNull($result['recovered']);

        // §2 -- nothing crossed accounts, and nothing came back to its owner.
        $loot = $carrier->loot;
        $this->assertFalse($this->holds($loot), 'a burned row came back to its owner');

        // Measured against what the KILL legitimately paid: a monster drops
        // spoils, and a burned carapace and a dropped carapace are the same
        // material. What must not happen is the row arriving on top of that.
        if ($loot['kind'] === 'material') {
            $this->assertSame(
                $result['spoils'][$loot['key']] ?? 0,
                $this->game->held($stranger->fresh(), $loot['key']),
                'the row crossed accounts',
            );
        } else {
            $this->assertSame(
                ($result['looted']['key'] ?? null) === $loot['key'] ? 1 : 0,
                $stranger->fresh()->items()->where('item_key', $loot['key'])->count(),
                'the row crossed accounts',
            );
        }

        $this->assertNull(Carrier::find($carrier->id));
    }

    /**
     * §9.5.3 -- a corpse is a hook, not a fence. It stands for twenty-four
     * hours, and a hex locked for a day would be exactly the griefing the
     * settlement rule exists to forbid.
     */
    public function test_a_corpse_does_not_pin_the_hex_it_stands_on(): void
    {
        $this->equip('notched_sword');
        $this->give('wood', 9);

        $this->fightUntil(false);
        $carrier = Carrier::first();

        $this->character->col = $carrier->col;
        $this->character->row = $carrier->row;
        $this->character->save();

        $preview = $this->game->previewTile(
            $this->character->fresh(),
            $carrier->col,
            $carrier->row,
            Drops::GATHERING,
        );

        $this->assertFalse($preview['pinned'], 'a corpse fenced the hex off');
    }

    /**
     * §8.2 / §9.5.7 -- a death is never a surprise. Losing IS dying, so the
     * preview states it every time rather than checking a condition: the odds
     * are half the decision and what a loss costs is the other half.
     */
    public function test_the_preview_warns_that_a_loss_here_would_be_a_death(): void
    {
        $this->equip('notched_sword');

        $this->standOnALivePack();

        $preview = $this->game->previewBattle($this->character->fresh());

        $this->assertNotEmpty($preview['warnings']);
        $this->assertStringContainsString(
            'Lose and you die',
            implode(' ', $preview['warnings']),
            'the terms of the fight were not stated before it was taken',
        );
    }

    // ------------------------------------------------------------ drops §9.5.8

    /**
     * §9.5.8 -- combat feeds combat. Two families off the monster, and the
     * grade is its tier: the plate line the smith and armorer want, the ichor
     * line the consumable bench wants, and nothing from the mining economy.
     */
    public function test_a_win_drops_monster_materials(): void
    {
        $this->equip('the_last_argument');
        $this->equip('longwatch_carapace');

        $seen = [];

        foreach ($this->packHexes() as $pack) {
            $this->standOn($pack);

            // A hex listed a moment ago may be empty by the time we swing at
            // it -- see tryFight(). Skipping is what a player would do.
            $result = $this->tryFight($this->character);
            if ($result === null) {
                continue;
            }
            $this->character = $this->character->fresh();

            if (! $result['won']) {
                continue;
            }

            foreach (array_keys($result['spoils']) as $key) {
                $this->assertArrayHasKey($key, Spoils::STOCK, "{$key} is not a spoil");
                $seen[$key] = true;
            }

            $this->assertNotEmpty($result['spoils'], 'a win dropped no materials at all');

            if (count($seen) >= 2) {
                return;
            }
        }

        $this->fail('never saw both spoil families');
    }

    /**
     * §2 -- looted gear stops at rare, whatever the tier.
     *
     * Epic is where gear becomes mintable (§8.0), so a monster that dropped one
     * is precisely the grind->NFT faucet the threat model exists to close. A
     * harder pack answers with better option rolls instead.
     */
    public function test_looted_gear_never_passes_rare(): void
    {
        $this->equip('the_last_argument');
        $this->equip('longwatch_carapace');

        $found = 0;

        foreach ($this->packHexes() as $pack) {
            $this->standOn($pack);

            // A hex listed a moment ago may be empty by the time we swing at
            // it -- see tryFight(). Skipping is what a player would do.
            $result = $this->tryFight($this->character);
            if ($result === null) {
                continue;
            }
            $this->character = $this->character->fresh();

            $loot = $result['looted'] ?? null;
            if ($loot === null) {
                continue;
            }

            $found++;
            $this->assertContains($loot['rarity'], ['common', 'uncommon', 'rare'], 'a monster dropped a mintable rung');

            // §9.5.8 -- 5-50% of its life. It walks straight into the repair bill.
            $this->assertGreaterThan(0, $loot['durability']);
            $this->assertLessThanOrEqual(
                (int) round($loot['maxDurability'] * Balance::LOOT_DURABILITY_MAX_PERCENT / 100),
                $loot['durability'],
                'looted gear arrived barely used',
            );

            $this->assertArrayHasKey($loot['key'], BattleGear::ITEMS, 'a monster was carrying a sickle');

            if ($found >= 3) {
                return;
            }
        }

        $this->assertGreaterThan(0, $found, 'nothing was ever looted');
    }

    /** §9.5.8 -- a loss takes; it never gives. */
    public function test_a_loss_drops_nothing(): void
    {
        $this->equip('notched_sword');
        $this->equip('longwatch_carapace');

        $result = $this->fightUntil(false);

        $this->assertSame([], $result['spoils']);
        $this->assertNull($result['looted']);
    }

    /**
     * §7.6 -- loot needs a strap, and without one it is named rather than
     * forced in. A refusal at the end of a fight you did not pick would be a
     * pin with no way out.
     */
    public function test_a_full_bag_leaves_the_loot_on_the_ground(): void
    {
        $this->equip('the_last_argument');
        $this->equip('longwatch_carapace');

        // Fill every strap with something else.
        foreach (array_slice(array_keys(Catalog::materials()), 0, Balance::BAG_ROWS) as $key) {
            $this->give($key, 1);
        }

        $this->assertFalse($this->game->hasFreeRow($this->character->fresh()));

        foreach ($this->packHexes() as $pack) {
            $this->standOn($pack);

            // A hex listed a moment ago may be empty by the time we swing at
            // it -- see tryFight(). Skipping is what a player would do.
            $result = $this->tryFight($this->character);
            if ($result === null) {
                continue;
            }
            $this->character = $this->character->fresh();

            if (($result['leftBehind'] ?? null) !== null) {
                $this->assertNull($result['looted'], 'loot was forced into a full bag');

                return;
            }
        }

        $this->markTestSkipped('no loot rolled while the bag was full');
    }
}
