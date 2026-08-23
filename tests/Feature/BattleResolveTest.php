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

            $result = $this->game->fight($this->character->fresh());
            $this->character = $this->character->fresh();

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
        foreach ($this->packHexes() as $pack) {
            $this->standOn($pack);
            break;
        }

        $this->assertNotNull($this->game->packHere($this->character->fresh()));

        $result = $this->game->fight($this->character->fresh());
        $this->assertIsBool($result['won']);

        $this->assertNull(
            $this->game->packHere($this->character->fresh()),
            'the pack survived its own resolution',
        );

        try {
            $this->game->fight($this->character->fresh());
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

        foreach ($this->packHexes() as $pack) {
            $this->standOn($pack);
            break;
        }

        $this->game->fight($this->character->fresh());

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
     * §9.5.6 -- two wear rolls: the weapon on the gap to their guard, and one
     * worn piece on the excess of their attack over its own. An empty slot
     * absorbs nothing, so a bare-handed fight costs nothing to wear out.
     */
    public function test_a_fight_wears_the_weapon_and_exactly_one_worn_piece(): void
    {
        $this->equip('the_last_argument');
        $this->equip('longwatch_carapace');
        $this->equip('unmoved_sabatons');
        $this->equip('gauntlets_of_the_last_word');

        foreach ($this->packHexes() as $pack) {
            $this->standOn($pack);
            break;
        }

        $result = $this->game->fight($this->character->fresh());

        $this->assertCount(2, $result['wear'], 'a fight wore something other than two things');

        $slots = array_column($result['wear'], 'slot');
        $this->assertContains('weapon', $slots);

        $worn = array_values(array_diff($slots, ['weapon']));
        $this->assertCount(1, $worn);
        $this->assertContains($worn[0], ['armor', 'boots', 'gloves']);

        foreach ($result['wear'] as $row) {
            $this->assertGreaterThan(0, $row['lost'], "{$row['name']} wore nothing");
        }

        // Three of the four are untouched, and the fourth is down by what the
        // result says it is down by.
        $intact = $this->character->fresh()->items
            ->filter(fn (CharacterItem $i) => $i->durability === \App\Game\Catalog::item($i->item_key)['maxDurability'])
            ->count();

        $this->assertSame(2, $intact, 'the wear did not land on exactly two pieces');
    }

    /**
     * §8.2 -- at zero the thing is GONE. Not broken, not inactive: the row is
     * deleted and named in the result that killed it.
     */
    public function test_gear_that_runs_out_is_destroyed_and_named(): void
    {
        $sword = $this->equip('notched_sword', 1);

        foreach ($this->packHexes() as $pack) {
            $this->standOn($pack);
            break;
        }

        $result = $this->game->fight($this->character->fresh());

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
            $result = $this->game->fight($this->character->fresh());

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

    /**
     * §9.5.7 -- the narrow rule. A loss becomes a death when NOTHING absorbed
     * it: no armor at all, or the piece that would have taken the hit went with
     * it. Fighting bare-chested is not merely worse, it is how you die.
     */
    public function test_a_loss_with_nothing_to_absorb_it_is_a_death(): void
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

    /** §9.5.7 -- and something on you to take the hit is what prevents it. */
    public function test_armor_is_what_stands_between_a_loss_and_a_death(): void
    {
        $this->equip('notched_sword');
        $this->equip('longwatch_carapace');

        $result = $this->fightUntil(false);

        $this->assertFalse($result['died']);
        $this->assertNull($result['stolen']);
        $this->assertNull($result['wokeAt']);
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
        $this->assertSame(0, $this->game->held($this->character->fresh(), 'wood'));

        // §9.5.7 -- and it is on the map for anybody, not only its owner.
        $stranger = $this->game->createCharacter(
            Player::create(['wallet' => '0xother', 'session_id' => 'other']),
        );

        $seen = collect($this->game->liveCarriers($stranger))
            ->firstWhere(fn (array $c) => $c['col'] === $carrier->col && $c['row'] === $carrier->row);

        $this->assertNotNull($seen, 'a corpse was hidden from another wallet');
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

        $result = $this->game->fight($this->character->fresh());

        $this->assertTrue($result['won'], 'a legendary kit lost to a treeline monster');
        $this->assertTrue($result['corpse']['mine']);
        $this->assertSame($carrier->label, $result['recovered']);
        $this->assertNull($result['burned']);
        $this->assertSame(9, $this->game->held($this->character->fresh(), 'wood'));
        $this->assertNull(Carrier::find($carrier->id));
    }

    /**
     * §2 -- and anybody else killing it BURNS the row.
     *
     * An item another wallet can pick up is a direct player-to-player transfer,
     * which the threat model closes outright -- and "random row" is no defence:
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

        $result = $this->game->fight($stranger->fresh());

        $this->assertTrue($result['won']);
        $this->assertFalse($result['corpse']['mine']);
        $this->assertSame($carrier->label, $result['burned']);
        $this->assertNull($result['recovered']);

        // Nothing crossed accounts, and nothing came back.
        $this->assertSame(0, $this->game->held($stranger->fresh(), 'wood'));
        $this->assertSame(0, $this->game->held($this->character->fresh(), 'wood'));
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
     * §8.2 / §9.5.7 -- a death is never a surprise. The preview says so while
     * there is still a choice to make.
     */
    public function test_the_preview_warns_that_a_loss_here_would_be_a_death(): void
    {
        $this->equip('notched_sword');

        foreach ($this->packHexes() as $pack) {
            $this->standOn($pack);
            break;
        }

        $preview = $this->game->previewBattle($this->character->fresh());

        $this->assertNotEmpty($preview['warnings']);
        $this->assertStringContainsString(
            'a death',
            implode(' ', $preview['warnings']),
            'fighting bare-chested was not flagged as fatal',
        );
    }
}
