<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\GameService;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * §8.2 -- a mend teaches the bench that could have made it.
 *
 * §7.1 says every verb that finishes work pays, and a repair is real bench
 * work: the same anvil, the same job, and a bill in the same materials. What it
 * is not is the afternoon that forged the thing, which is what the share is
 * for.
 */
final class RepairXpTest extends TestCase
{
    use RefreshDatabase;

    private GameService $game;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        config(['game.packs' => false]);

        $this->game = app(GameService::class);
        $this->character = $this->game->createCharacter(
            Player::create(['wallet' => '0xmend', 'session_id' => 'mend']),
        );
    }

    private function give(array $stock): void
    {
        $add = new ReflectionMethod($this->game, 'addMaterial');
        foreach ($stock as $key => $qty) {
            $add->invoke($this->game, $this->character->fresh(), $key, $qty);
        }
        $this->character = $this->character->fresh();
    }

    /** A crafted piece, worn down to `$left` of its ceiling. */
    private function worn(string $key, int $left): CharacterItem
    {
        $item = CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => $key,
            'durability' => $left,
        ]);

        // Whatever a mend of this piece costs, ten times over.
        $this->give(array_map(
            static fn () => 200,
            \App\Game\Formulas::repairCost(
                \App\Game\Catalog::item($key),
                $item->maxDurability() - $left,
                $item->maxDurability(),
            ),
        ));

        return $item;
    }

    /** §8.2 -- mending pays the craft job that would have made the piece. */
    public function test_a_mend_pays_the_bench_that_could_have_made_it(): void
    {
        $item = $this->worn('hewn_axe', 1);

        $before = (int) $this->character->fresh()->jobLevels()
            ->where('job_key', 'smith')->value('xp');

        $out = $this->game->repairItem($this->character->fresh(), $item->id);

        // A Hewn Axe is a weapon-bench piece (§8.4), so the Smith learns.
        $this->assertSame('smith', $out['job']);
        $this->assertGreaterThan(0, $out['jobXp']);
        $this->assertGreaterThan(0, $out['characterXp']);

        // And it is written down, not merely reported.
        $after = (int) $this->character->fresh()->jobLevels()
            ->where('job_key', 'smith')->value('xp');

        $this->assertGreaterThan($before, $after, 'the smith learned nothing from mending an axe');
    }

    /**
     * "Depends on what you repaired", half one: how badly it needed it.
     *
     * The share of the bar put back is half the figure, so a piece brought
     * home from the edge teaches more than a scratched one.
     */
    public function test_a_worse_mend_teaches_more(): void
    {
        $barely = $this->worn('hewn_axe', \App\Game\Catalog::item('hewn_axe')['maxDurability'] - 2);
        $small = $this->game->repairItem($this->character->fresh(), $barely->id);

        $ruined = $this->worn('hewn_axe', 1);
        $big = $this->game->repairItem($this->character->fresh(), $ruined->id);

        $this->assertGreaterThan(
            $small['jobXp'],
            $big['jobXp'],
            'a near-dead piece taught no more than a scratched one',
        );
    }

    /**
     * And half two: the rung. The same share of a better piece teaches more,
     * on the same rarity rank a craft is paid on.
     */
    public function test_a_better_piece_teaches_more(): void
    {
        $common = $this->worn('hewn_axe', 1);
        $low = $this->game->repairItem($this->character->fresh(), $common->id);

        $better = $this->worn('iron_pickaxe', 1);
        $high = $this->game->repairItem($this->character->fresh(), $better->id);

        $this->assertGreaterThan($low['jobXp'], $high['jobXp'], 'the rung bought nothing');
    }

    /**
     * §8.2 -- a mend is not a make, and must stay clearly under one.
     *
     * Otherwise repairing is a cheaper road to a job level than crafting,
     * which would make the bench the slow way to level the bench.
     */
    public function test_a_full_mend_teaches_less_than_making_the_thing(): void
    {
        $item = $this->worn('hewn_axe', 1);
        $out = $this->game->repairItem($this->character->fresh(), $item->id);

        $rank = Balance::rarityRank(\App\Game\Catalog::item('hewn_axe')['rarity']) + 1;
        $craft = Balance::JOB_XP_PER_RARITY_RANK * $rank;

        $this->assertLessThan($craft, $out['jobXp'], 'mending taught as much as forging');
        $this->assertLessThanOrEqual(Balance::REPAIR_XP_SHARE, $out['jobXp'] / $craft);
    }

    /**
     * §8.4 -- a trader is not a bench.
     *
     * Basic gear has no recipe, so there is no craft job standing behind it and
     * nothing to learn from handing somebody coin.
     */
    public function test_paying_a_trader_teaches_nothing(): void
    {
        $shop = CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => 'stone_axe',
            'durability' => 1,
        ]);

        $this->assertArrayNotHasKey('inputs', \App\Game\Catalog::item('stone_axe'));

        // The trader is an NPC who stands somewhere (§6), so walk onto one
        // rather than hoping the spawn was beside it.
        $character = $this->character->fresh();
        $found = false;
        for ($col = -60; $col <= 60 && ! $found; $col++) {
            for ($row = -60; $row <= 60; $row++) {
                if (\App\Game\WorldGen::settlementAt($col, $row) !== null) {
                    $character->update(['col' => $col, 'row' => $row]);
                    $found = true;
                    break;
                }
            }
        }
        $this->assertTrue($found, 'no settlement anywhere near the middle of the map');

        $character = $character->fresh();
        $character->gold = 9999;
        $character->save();

        $out = $this->game->repairItem($character->fresh(), $shop->id);

        $this->assertSame(0, $out['jobXp']);
        $this->assertSame(0, $out['characterXp']);
        $this->assertNull($out['job']);
    }
}
