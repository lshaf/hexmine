<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\GameException;
use App\Game\GameService;
use App\Game\WorldGen;
use App\Models\Carrier;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     * @return \Generator<int,array<string,mixed>>
     */
    private function packHexes(): \Generator
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
    private function killCarrier(\App\Models\Character $fighter, Carrier $carrier, int $tries = 12): array
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
    private function resolveFight(\App\Models\Character $fighter): array
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
    private function tryFight(\App\Models\Character $fighter): ?array
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

    /** Put a stack in the bag, through the service so the row rules apply. */
    private function give(string $key, int $quantity): void
    {
        $add = new \ReflectionMethod($this->game, 'addMaterial');
        $add->setAccessible(true);
        $add->invoke($this->game, $this->character, $key, $quantity);

        $this->character = $this->character->fresh();
    }

    private function equip(string $key, ?int $durability = null): CharacterItem
    {
        return CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => $key,
            'durability' => $durability ?? \App\Game\Catalog::item($key)['maxDurability'],
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
            \App\Game\Drops::GATHERING,
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
     * §9.5.6 -- durability IS the health bar, so a beating lands on the kit
     * that took it. The blade pays separately, for what it was swung AT.
     */
    public function test_a_fight_spends_the_kit_that_took_it(): void
    {
        $this->equip('the_last_argument');
        $this->equip('longwatch_carapace');
        $this->equip('unmoved_sabatons');
        $this->equip('gauntlets_of_the_last_word');

        $this->standOnALivePack();

        $result = $this->resolveFight($this->character);

        $slots = array_column($result['wear'], 'slot');

        // The weapon always pays: enemy armor blunts it whatever else happens.
        $this->assertContains('weapon', $slots, 'the blade came out of a fight unmarked');

        foreach ($result['wear'] as $row) {
            $this->assertGreaterThan(0, $row['lost'], "{$row['name']} wore nothing");
            $this->assertContains($row['slot'], Balance::COMBAT_SLOTS);
        }

        // A tool belt is not armor (§8 rule 2): nothing outside the combat
        // slots is ever in the exchange.
        $this->assertSame($slots, array_values(array_intersect($slots, Balance::COMBAT_SLOTS)));
    }

    /**
     * §9.5.6 -- the bill is capped at half the pool, so one hopeless swing in
     * the center cannot strip a whole kit in a single go. The fight is still
     * lost; the cap is on the cost.
     */
    public function test_one_fight_never_takes_more_than_half_the_kit(): void
    {
        $pieces = ['notched_sword', 'padded_jack', 'studded_boots', 'knuckle_wraps'];
        foreach ($pieces as $key) {
            $this->equip($key);
        }

        $pool = \App\Game\Formulas::battlePool(
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

        // Half the pool, plus whatever the blade paid for enemy armor -- that
        // stream is its own and is capped per item rather than by the pool.
        $weapon = collect($result['wear'])->firstWhere('slot', 'weapon');
        $blade = $weapon === null ? 0 : $weapon['lost'];

        $this->assertLessThanOrEqual(
            (int) floor($pool * Balance::BATTLE_POOL_WEAR_CAP) + $blade,
            $lost,
            'one fight took more than the cap allows',
        );
    }

    /**
     * §8.2 -- at zero the thing is GONE. Not broken, not inactive: the row is
     * deleted and named in the result that killed it.
     */
    public function test_gear_that_runs_out_is_destroyed_and_named(): void
    {
        $sword = $this->equip('notched_sword', 1);

        $this->standOnALivePack();

        $result = $this->resolveFight($this->character);

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
                $max = \App\Game\Catalog::item(
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
            \App\Game\HexGeometry::distance(
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
            \App\Game\Drops::GATHERING,
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
                $this->assertArrayHasKey($key, \App\Game\Spoils::STOCK, "{$key} is not a spoil");
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

            $this->assertArrayHasKey($loot['key'], \App\Game\BattleGear::ITEMS, 'a monster was carrying a sickle');

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
        foreach (array_slice(array_keys(\App\Game\Catalog::materials()), 0, Balance::BAG_ROWS) as $key) {
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
