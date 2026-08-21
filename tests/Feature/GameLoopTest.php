<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\GameService;
use App\Models\Character;
use App\Models\GameJob;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The core loop, driven through the service rather than HTTP so the rules are
 * tested rather than the routing.
 *
 * These assert the invariants the design doc calls mandatory -- the trip-time
 * clamp, the two-slot limit, per-wallet caps on rare materials, and the fact
 * that gold can never buy anything tradeable.
 */
final class GameLoopTest extends TestCase
{
    use RefreshDatabase;

    private GameService $game;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->game = app(GameService::class);
        $player = Player::create(['wallet' => '0xtest', 'session_id' => 'test']);
        $this->character = $this->game->createCharacter($player);
    }

    /** Wind a journey's clock back so it has already landed, then settle it. */
    private function arrive(Character $character): void
    {
        $span = (int) $character->travel_ends_at - (int) $character->travel_started_at;
        $character->travel_started_at = (int) $character->travel_started_at - $span;
        $character->travel_ends_at = (int) $character->travel_ends_at - $span;
        $character->save();

        $this->game->settle($character);
    }

    /** Pretend the walker has been going for this many whole hexes. */
    private function walkFor(Character $character, int $hexes): void
    {
        $perHex = Balance::scaled(Balance::TRAVEL_MS_PER_HEX);
        $character->travel_started_at = (int) $character->travel_started_at - ($hexes * $perHex + 5);
        $character->save();
    }

    /** §5 -- ten minutes of ground per hex, and the map is crossed on foot. */
    public function test_travel_takes_ten_minutes_a_hex_and_lands_at_the_destination(): void
    {
        $from = ['col' => $this->character->col, 'row' => $this->character->row];
        $to = ['col' => $from['col'] + 6, 'row' => $from['row']];

        $distance = \App\Game\HexGeometry::distance($from['col'], $from['row'], $to['col'], $to['row']);
        $travel = $this->game->travelTo($this->character, $to['col'], $to['row']);

        $this->assertSame($distance, $travel['hexes']);
        $this->assertSame($distance + 1, count($travel['path']), 'the road includes both ends');
        $this->assertSame(
            $distance * Balance::scaled(Balance::TRAVEL_MS_PER_HEX),
            $travel['endsAt'] - $travel['startedAt'],
        );

        // Still standing where the journey began: you are not there yet.
        $this->assertSame($from['col'], $this->character->col);
        $this->assertSame($from['row'], $this->character->row);
        $this->assertNull($this->game->currentSettlement($this->character));

        $this->arrive($this->character);

        $this->assertSame($to['col'], $this->character->col);
        $this->assertSame($to['row'], $this->character->row);
        $this->assertNull($this->game->travelState($this->character));
    }

    /**
     * Stopping halfway keeps only whole hexes. Half a hex is not a place, so
     * the part-crossed leg is forfeit rather than rounded up.
     */
    public function test_stopping_short_keeps_only_the_whole_hexes_walked(): void
    {
        $target = ['col' => $this->character->col + 6, 'row' => $this->character->row];
        $travel = $this->game->travelTo($this->character, $target['col'], $target['row']);
        $path = $travel['path'];

        // Three hexes of walking, plus most of a fourth that buys nothing.
        $perHex = Balance::scaled(Balance::TRAVEL_MS_PER_HEX);
        $this->character->travel_started_at = (int) $this->character->travel_started_at - (3 * $perHex + (int) ($perHex * 0.9));
        $this->character->save();

        $stop = $this->game->cancelTravel($this->character);

        $this->assertSame(3, $stop['hexes']);
        $this->assertSame($path[3][0], $this->character->col);
        $this->assertSame($path[3][1], $this->character->row);
        $this->assertNull($this->game->travelState($this->character));
    }

    /** A journey abandoned before the first hex lands leaves you where you began. */
    public function test_stopping_inside_the_first_hex_moves_nobody(): void
    {
        $from = ['col' => $this->character->col, 'row' => $this->character->row];
        $this->game->travelTo($this->character, $from['col'] + 4, $from['row']);

        $stop = $this->game->cancelTravel($this->character);

        $this->assertSame(0, $stop['hexes']);
        $this->assertSame($from['col'], $this->character->col);
        $this->assertSame($from['row'], $this->character->row);
    }

    /** You cannot work a hex from the road, §5. */
    public function test_the_road_blocks_mining_and_trading(): void
    {
        $col = $this->character->col;
        $row = $this->character->row;

        $this->game->travelTo($this->character, $col + 3, $row);

        $preview = $this->game->previewTile($this->character, $col, $row);
        $this->assertFalse($preview['canMine']);
        $this->assertStringContainsString('on the road', $preview['reason']);

        try {
            $this->game->travelTo($this->character, $col + 1, $row);
            $this->fail('set a second course while already walking');
        } catch (\App\Game\GameException $e) {
            $this->assertSame('travelling', $e->errorCode);
        }
    }

    /** §12 -- the spawn must make the tutorial completable. */
    public function test_spawn_is_forest_with_a_reachable_woodcutting_village(): void
    {
        $tile = $this->game->buildTile($this->character->col, $this->character->row, $this->game->now());

        $this->assertSame('forest', $tile['biome']);
        $this->assertSame('wood', $tile['material']);
        $this->assertSame('outer', $tile['ring']);

        $range = Balance::travelRange(1);
        $found = null;
        for ($dc = -$range; $dc <= $range && ! $found; $dc++) {
            for ($dr = -$range; $dr <= $range && ! $found; $dr++) {
                $s = \App\Game\WorldGen::settlementAt($this->character->col + $dc, $this->character->row + $dr);
                if ($s && in_array('woodcutting', $s['lines'], true)) {
                    $found = $s;
                }
            }
        }

        $this->assertNotNull($found, 'no woodcutting village within level-1 travel range');
    }

    public function test_mining_spends_ap_and_yields_on_collect(): void
    {
        $col = $this->character->col;
        $row = $this->character->row;
        $apBefore = $this->character->ap;

        $job = $this->game->startMining($this->character, $col, $row);
        $this->assertSame($apBefore - Balance::MINING_AP_COST, $this->character->ap);

        // A trip never moves anyone: you were standing here to start it.
        $this->assertSame($col, $this->character->col);
        $this->assertSame($row, $this->character->row);

        // Cannot collect early.
        try {
            $this->game->collectJob($this->character, $job->id);
            $this->fail('collected a job that was still running');
        } catch (\App\Game\GameException $e) {
            $this->assertSame('not_ready', $e->errorCode);
        }

        // Wind the clock back rather than sleeping.
        $job->update(['ends_at' => $this->game->now() - 1]);

        // §4.0 -- nothing equipped, so this is bare hands and brings back scrap.
        $result = $this->game->collectJob($this->character->fresh(), $job->id);
        $this->assertGreaterThan(0, $result['gained']['branch']);
        $this->assertArrayNotHasKey('wood', $result['gained']);
        $this->assertSame(0, GameJob::count());
    }

    /**
     * §4.0 -- the tool is the difference between a haul and a pile of junk.
     * This is what the tutorial's first three steps exist to teach, and what
     * makes the first 12 gold worth spending.
     */
    public function test_bare_hands_bring_back_scrap_and_the_tool_brings_back_the_material(): void
    {
        $col = $this->character->col;
        $row = $this->character->row;

        $bare = $this->game->previewTile($this->character, $col, $row);
        $this->assertTrue($bare['scrap']);
        $this->assertSame('branch', $bare['material']);
        $this->assertSame('woodcutting', $bare['skill'], 'a scrap haul left its own line');
        $this->assertStringContainsString('No axe', $bare['note']);
        $this->assertTrue($bare['canMine'], 'bare hands were refused the hex outright');

        // Scrap is worth strictly less than what the hex really holds.
        $this->assertLessThan(
            \App\Game\Catalog::material('wood')['npcPrice'],
            \App\Game\Catalog::material('branch')['npcPrice'],
        );

        \App\Models\CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => 'stone_axe',
            'durability' => \App\Game\Catalog::item('stone_axe')['maxDurability'],
            'equipped' => true,
        ]);

        $armed = $this->game->previewTile($this->character->fresh(), $col, $row);
        $this->assertFalse($armed['scrap']);
        $this->assertSame('wood', $armed['material']);
        $this->assertNull($armed['note']);
    }

    /** §8.2 -- a snapped axe is not an axe, so it does not stop the scrap. */
    public function test_a_broken_tool_counts_as_no_tool(): void
    {
        \App\Models\CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => 'stone_axe',
            'durability' => 0,
            'equipped' => true,
        ]);

        $preview = $this->game->previewTile(
            $this->character->fresh(),
            $this->character->col,
            $this->character->row,
        );

        $this->assertTrue($preview['scrap']);
        $this->assertSame('branch', $preview['material']);
    }

    /** §4.0 -- scrap reaches nothing. It is not an input to any recipe. */
    public function test_scrap_feeds_no_recipe_and_undercuts_every_raw_material(): void
    {
        $rawPrices = [];
        foreach (\App\Game\Catalog::BIOME_MATERIAL as $key) {
            $rawPrices[] = \App\Game\Catalog::material($key)['npcPrice'];
        }

        foreach (\App\Game\Catalog::BIOME_SCRAP as $biome => $key) {
            $def = \App\Game\Catalog::material($key);
            $this->assertNotNull($def, "{$biome} has no scrap");
            $this->assertSame(0, $def['tier']);
            $this->assertGreaterThan(0, $def['npcPrice'], 'the trader refuses scrap');
            $this->assertLessThan(min($rawPrices), $def['npcPrice'], "{$key} is worth as much as a raw material");

            foreach (\App\Game\Catalog::recipes() as $recipe) {
                $this->assertNotContains($key, [$recipe['input'], $recipe['secondInput'] ?? null]);
            }
            foreach (\App\Game\Catalog::items() as $item) {
                $this->assertArrayNotHasKey($key, $item['inputs'] ?? []);
            }
        }
    }

    /** You work the hex under your feet, never one across the valley. */
    public function test_mining_requires_standing_on_the_tile(): void
    {
        $now = $this->game->now();

        // A workable neighbour: close enough to walk to, not where we stand.
        $target = null;
        foreach ([[1, 0], [0, 1], [-1, 0], [0, -1], [1, 1], [-1, -1]] as [$dc, $dr]) {
            $col = $this->character->col + $dc;
            $row = $this->character->row + $dr;
            if ($this->game->buildTile($col, $row, $now)['material'] !== null) {
                $target = [$col, $row];
                break;
            }
        }
        $this->assertNotNull($target, 'spawn has no workable neighbour to test against');
        [$col, $row] = $target;

        $preview = $this->game->previewTile($this->character, $col, $row);
        $this->assertFalse($preview['canMine']);
        $this->assertStringContainsString('standing elsewhere', $preview['reason']);

        // Still a scouting report: the haul and the trip are known from afar,
        // which is what makes the travel decision worth anything.
        $this->assertGreaterThan(0, $preview['seconds']);
        $this->assertGreaterThan(0, $preview['yield']);

        try {
            $this->game->startMining($this->character, $col, $row);
            $this->fail('mined a hex the character was not standing on');
        } catch (\App\Game\GameException $e) {
            $this->assertSame('blocked', $e->errorCode);
        }

        // Walk there, and once the journey lands the same hex becomes workable.
        $this->game->travelTo($this->character, $col, $row);
        $this->arrive($this->character);
        $this->assertTrue($this->game->previewTile($this->character->fresh(), $col, $row)['canMine']);
    }

    /** §5.1 -- exactly two mining slots per hex, then the tile is closed. */
    public function test_a_tile_only_takes_two_miners(): void
    {
        $col = $this->character->col;
        $row = $this->character->row;

        // Two other players fill both slots.
        foreach (['0xa', '0xb'] as $wallet) {
            $other = $this->game->createCharacter(Player::create(['wallet' => $wallet]));
            $other->update(['col' => $col, 'row' => $row]);
            $this->game->startMining($other, $col, $row);
        }

        $preview = $this->game->previewTile($this->character, $col, $row);
        $this->assertFalse($preview['canMine']);
        $this->assertStringContainsString('Both slots', $preview['reason']);
    }

    /** §7.3 -- the floor clamp is mandatory and must bind. */
    public function test_trip_time_never_drops_below_the_floor(): void
    {
        // Maxed skill and best-in-slot gear: 30 minutes of reduction against a
        // 60 minute tile lands exactly on the floor, and never under it.
        $trip = \App\Game\Formulas::tripTime(3600, Balance::SKILL_MAX_LEVEL, Balance::STAT_CEILING);
        $this->assertSame(Balance::MINING_FLOOR_SECONDS, $trip['total']);

        // Even absurd inputs cannot breach it.
        $absurd = \App\Game\Formulas::tripTime(1800, 9999, 99.0);
        $this->assertSame(Balance::MINING_FLOOR_SECONDS, $absurd['total']);
        $this->assertTrue($absurd['clamped']);
    }

    /** §8.1 -- stacking diminishes and the per-tier cap binds. */
    public function test_stacking_the_same_item_gives_less_each_time_and_is_capped(): void
    {
        $one = \App\Game\Formulas::aggregateStat(
            [['key' => 'iron_pickaxe', 'durability' => 10, 'equipped' => true]],
            'yield',
            'mining',
        );
        $three = \App\Game\Formulas::aggregateStat(
            array_fill(0, 3, ['key' => 'iron_pickaxe', 'durability' => 10, 'equipped' => true]),
            'yield',
            'mining',
        );

        $this->assertLessThan($one * 3, $three, 'three identical items scaled linearly');
        $this->assertLessThanOrEqual(Balance::STAT_CAP['rare'], $three);
    }

    /** §8 -- a tool pays out on its own line and on no other. */
    public function test_a_gathering_tool_only_counts_on_its_own_line(): void
    {
        $kit = [['key' => 'iron_pickaxe', 'durability' => 10, 'equipped' => true]];

        $this->assertGreaterThan(
            0,
            \App\Game\Formulas::aggregateStat($kit, 'yield', 'mining'),
            'a pickaxe did nothing on a seam',
        );
        $this->assertSame(
            0.0,
            \App\Game\Formulas::aggregateStat($kit, 'yield', 'woodcutting'),
            'a pickaxe felled a tree',
        );
        $this->assertSame(
            0.0,
            \App\Game\Formulas::aggregateStat($kit, 'yield'),
            'a tool counted with no line being worked',
        );

        // Gear worn on the body is not line-locked and counts everywhere.
        $worn = [['key' => 'leather_armor', 'durability' => 10, 'equipped' => true]];
        $this->assertGreaterThan(
            0,
            \App\Game\Formulas::aggregateStat($worn, 'tripReduction', 'harvesting'),
            'armor stopped working outside a line',
        );
        $this->assertGreaterThan(
            0,
            \App\Game\Formulas::aggregateStat($worn, 'tripReduction'),
            'armor stopped working with no line in mind',
        );
    }

    /**
     * §8 -- every gathering line gets the same ladder. Specialisation is meant
     * to come from the §7.2 skill point cap, never from one line having better
     * tools on offer than another.
     */
    public function test_every_gathering_line_has_the_same_tool_ladder(): void
    {
        $ceilings = [];

        foreach (\App\Game\Catalog::TOOL_SLOT_SKILL as $slot => $line) {
            $tools = array_filter(
                \App\Game\Catalog::items(),
                fn (array $def) => ($def['slot'] ?? null) === $slot,
            );

            // Two commons (bought and made), two uncommons (bought and made),
            // one epic. Rare has no craftable yet -- see docs/rarity-plan.md
            // step 5, which fills the capital bench.
            $rarities = array_count_values(array_column($tools, 'rarity'));
            $this->assertSame(2, $rarities['common'] ?? 0, "{$line} is missing a common tool");
            $this->assertSame(2, $rarities['uncommon'] ?? 0, "{$line} is missing an uncommon tool");
            $this->assertSame(1, $rarities['epic'] ?? 0, "{$line} is missing its epic tool");

            $ceilings[$line] = max(array_column($tools, 'value'));
            $this->assertLessThanOrEqual(
                Balance::STAT_CEILING,
                $ceilings[$line],
                "{$line} tops out above the hard ceiling",
            );
        }

        $this->assertCount(1, array_unique($ceilings), 'one line reaches higher than the rest');
    }

    /**
     * §8.1 -- the rarity ladder. Rarity now climbs toward a single global
     * ceiling instead of every tier sharing one, so the thing worth guarding is
     * that nothing ever gets past that ceiling.
     */
    public function test_no_item_can_out_climb_its_rarity_or_the_global_ceiling(): void
    {
        $this->assertSame(
            Balance::STAT_CEILING,
            max(Balance::STAT_CAP),
            'a rarity was allowed past the global ceiling',
        );

        // The ladder must rise. A flat or inverted rung makes rarity meaningless.
        $previous = 0.0;
        foreach (Balance::RARITIES as $rarity) {
            $cap = Balance::STAT_CAP[$rarity];
            $this->assertGreaterThan($previous, $cap, "{$rarity} does not beat the rung below it");
            $previous = $cap;
        }

        foreach (\App\Game\Catalog::items() as $key => $def) {
            $this->assertContains($def['rarity'], Balance::RARITIES, "{$key} has no rarity");
            $this->assertLessThanOrEqual(
                Balance::STAT_CAP[$def['rarity']],
                $def['value'],
                "{$key} claims more than {$def['rarity']} allows",
            );
        }
    }

    /**
     * §2 / §3.3 -- rarity is not tradeability. `unique` is the strongest thing
     * in the game and must stay soulbound: a dungeon drop that was an NFT would
     * be exactly the grind→external-value faucet the threat model exists to close.
     */
    public function test_tradeable_items_are_never_dropped_rarities(): void
    {
        foreach (\App\Game\Catalog::items() as $key => $def) {
            $this->assertArrayHasKey('tradeable', $def, "{$key} does not say whether it is an NFT");

            if ($def['tradeable']) {
                $this->assertNotSame('unique', $def['rarity'], "{$key} is a tradeable unique");
                $this->assertArrayHasKey('inputs', $def, "{$key} is tradeable but is not crafted");
                $this->assertArrayNotHasKey('goldPrice', $def, "{$key} bridges gold to NFT value");

                // §3.3 -- an NFT is crafted from tier 3 + tier 4, never tier 1-2 alone.
                $topTier = max(array_map(
                    fn (string $m) => \App\Game\Catalog::materialTier($m),
                    array_keys($def['inputs']),
                ));
                $this->assertGreaterThanOrEqual(3, $topTier, "{$key} is tradeable off common materials");
            }
        }
    }

    /**
     * §8.0.1 -- rolled lines are variety, never a second power ladder. This is
     * the guardrail: an option must be unable to push a stat past the ceiling,
     * or pay-to-win walks back in through the side door.
     */
    public function test_rolled_options_cannot_breach_the_ceiling(): void
    {
        // Three epics, each stuffed with the fattest legal rolls on one stat.
        $fat = array_fill(0, 3, [
            'key' => 'mythril_pickaxe',
            'durability' => 10,
            'equipped' => true,
            'options' => array_fill(0, 3, ['stat' => 'yield', 'value' => Balance::OPTION_MAX]),
        ]);

        $total = \App\Game\Formulas::aggregateStat($fat, 'yield', 'mining');

        $this->assertLessThanOrEqual(Balance::STAT_CAP['epic'], $total, 'options beat the rarity cap');
        $this->assertLessThanOrEqual(Balance::STAT_CEILING, $total, 'options beat the global ceiling');
    }

    /** §8.0.1 -- a rolled line can reach a stat the item was never built for. */
    public function test_an_option_can_add_a_stat_the_item_does_not_have(): void
    {
        $kit = [[
            'key' => 'iron_pickaxe',
            'durability' => 10,
            'equipped' => true,
            'options' => [['stat' => 'tripReduction', 'value' => 0.02]],
        ]];

        // The pickaxe is a yield tool, yet it now shaves trip time on its line.
        $this->assertSame(0.02, \App\Game\Formulas::aggregateStat($kit, 'tripReduction', 'mining'));
        // ...and nowhere else, because options inherit the line-lock.
        $this->assertSame(0.0, \App\Game\Formulas::aggregateStat($kit, 'tripReduction', 'woodcutting'));
    }

    /** §8.0.1 -- how many lines each rung rolls, and commons roll none. */
    public function test_option_counts_follow_the_rarity_ladder(): void
    {
        foreach (['common' => 'stone_axe', 'rare' => null, 'epic' => 'mythril_pickaxe'] as $rarity => $key) {
            if ($key === null) {
                continue;
            }

            $def = \App\Game\Catalog::item($key);
            $rolled = \App\Game\Formulas::rollOptions($def, 12345);

            $this->assertCount(
                Balance::OPTION_ROLLS[$rarity],
                $rolled,
                "{$key} rolled the wrong number of lines",
            );

            foreach ($rolled as $option) {
                $this->assertGreaterThanOrEqual(Balance::OPTION_MIN, $option['value']);
                $this->assertLessThanOrEqual(Balance::OPTION_MAX, $option['value']);
                $this->assertContains($option['stat'], \App\Game\Catalog::optionStatsFor($def['slot']));
            }

            // One line per stat: two "+2% yield" rows on one item reads as a bug.
            $stats = array_column($rolled, 'stat');
            $this->assertSame($stats, array_unique($stats), "{$key} rolled a stat twice");
        }

        // The capital bazaar is the one way a common ever carries a line.
        $bazaar = \App\Game\Formulas::rollOptions(\App\Game\Catalog::item('stone_axe'), 999, 1);
        $this->assertCount(1, $bazaar);
    }

    /**
     * §8.5 -- a potion is spent, starts a timed effect, and the effect expiring
     * is the sink (§11.1). Nothing here may be permanent.
     */
    public function test_drinking_starts_a_buff_that_expires_on_its_own(): void
    {
        $this->character->consumables()->create(['item_key' => 'forest_draught', 'quantity' => 2]);

        $before = $this->game->bonuses($this->character->fresh(), 'woodcutting')['yield'];
        $buff = $this->game->useConsumable($this->character->fresh(), 'forest_draught');

        $this->assertSame('yield', $buff['stat']);
        $this->assertGreaterThan($this->game->now(), $buff['expiresAt'], 'the buff was born expired');
        $this->assertSame(1, $this->game->heldConsumable($this->character->fresh(), 'forest_draught'));

        $during = $this->game->bonuses($this->character->fresh(), 'woodcutting')['yield'];
        $this->assertGreaterThan($before, $during, 'drinking did nothing');

        // Wind the clock past the deadline: nothing ticks, so the buff simply
        // stops counting the moment it is read after expiry.
        $this->character->buffs()->update(['expires_at' => $this->game->now() - 1]);
        $this->character->unsetRelation('buffs');

        $after = $this->game->bonuses($this->character->fresh(), 'woodcutting')['yield'];
        $this->assertSame($before, $after, 'an expired buff was still paying out');
        $this->assertSame([], $this->game->liveBuffs($this->character->fresh()));
    }

    /** §8.5 -- a second of the same kind refreshes the clock, never stacks. */
    public function test_a_second_potion_refreshes_rather_than_stacks(): void
    {
        $this->character->consumables()->create(['item_key' => 'forest_draught', 'quantity' => 3]);

        $this->game->useConsumable($this->character->fresh(), 'forest_draught');
        $once = $this->game->bonuses($this->character->fresh(), 'woodcutting')['yield'];

        $this->game->useConsumable($this->character->fresh(), 'forest_draught');
        $twice = $this->game->bonuses($this->character->fresh(), 'woodcutting')['yield'];

        $this->assertSame($once, $twice, 'two potions stacked into a bigger bonus');
        $this->assertCount(1, $this->game->liveBuffs($this->character->fresh()));
    }

    /** §8.1 rule 1 -- a buff is inside the ceiling like everything else. */
    public function test_a_buff_cannot_push_a_stat_past_the_ceiling(): void
    {
        // Best legal gear, then drink on top of it.
        \App\Models\CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => 'mythril_pickaxe',
            'durability' => 100,
            'equipped' => true,
            'options' => [['stat' => 'yield', 'value' => Balance::OPTION_MAX]],
        ]);
        $this->character->consumables()->create(['item_key' => 'prospectors_flask', 'quantity' => 1]);
        $this->game->useConsumable($this->character->fresh(), 'prospectors_flask');

        $this->assertLessThanOrEqual(
            Balance::STAT_CEILING,
            $this->game->bonuses($this->character->fresh(), 'mining')['yield'],
        );
    }

    /**
     * §8.4 -- every craftable falls into exactly one bench, and consumables are
     * the ones with no slot at all.
     */
    public function test_every_craftable_belongs_to_one_category(): void
    {
        $seen = [];

        foreach (\App\Game\Catalog::items() as $key => $def) {
            $category = \App\Game\Catalog::category($def);
            $this->assertContains($category, \App\Game\Catalog::CATEGORIES, "{$key} has no bench");
            $seen[$category] = true;

            if ($category === 'consumable') {
                $this->assertTrue(! empty($def['consumable']), "{$key} has no slot but is not a consumable");
                $this->assertArrayNotHasKey('maxDurability', $def, "{$key} is drunk but wears out");
            } else {
                $this->assertArrayHasKey('maxDurability', $def, "{$key} is worn but never wears out");
            }
        }

        $this->assertSame(
            \App\Game\Catalog::CATEGORIES,
            array_keys($seen),
            'a bench has nothing on it',
        );
    }

    /**
     * §8.0 / §9 / §10 -- legendary and unique are defined but unreachable.
     *
     * Guild halls and dungeons are not built. The gates have to exist anyway:
     * without them a capital would quietly become the top of the ladder, and
     * §2's hardest rule -- no grind→NFT faucet -- has no teeth if a drop rarity
     * can leak out of a workbench.
     */
    public function test_legendary_and_unique_are_reachable_from_nowhere(): void
    {
        foreach (\App\Game\Catalog::items() as $key => $def) {
            $this->assertNotSame('legendary', $def['rarity'], "{$key} is legendary but guilds do not exist");
            $this->assertNotSame('unique', $def['rarity'], "{$key} is unique but dungeons do not exist");
        }

        // The gates themselves are defined, and point somewhere no player is.
        $this->assertSame('guild', Balance::stationForRarity('legendary'));
        $this->assertNull(Balance::stationForRarity('unique'), 'a bench can reach unique');

        foreach (['village', 'city', 'capital'] as $tier) {
            $this->assertFalse(
                Balance::stationReaches($tier, 'legendary'),
                "a {$tier} can forge legendary work",
            );
            $this->assertFalse(
                Balance::stationReaches($tier, 'unique'),
                "a {$tier} can forge unique work",
            );
        }
    }

    /** §8 -- only the tool that did the work wears out. */
    public function test_a_trip_wears_the_line_tool_and_leaves_the_others_alone(): void
    {
        // Kit out two lines at once, which is the whole point of separate slots.
        foreach (['stone_axe', 'chipped_pick'] as $key) {
            \App\Models\CharacterItem::create([
                'character_id' => $this->character->id,
                'item_key' => $key,
                'durability' => \App\Game\Catalog::item($key)['maxDurability'],
                'equipped' => true,
            ]);
        }

        // Spawn is forest (§12 step 1), so this trip is the axe's line.
        $col = $this->character->col;
        $row = $this->character->row;
        $tile = $this->game->buildTile($col, $row, $this->game->now());
        $worn = \App\Game\Catalog::slotForSkill(\App\Game\Catalog::skillForMaterial($tile['material']));
        $this->assertSame('axe', $worn, 'spawn is no longer a forest hex');

        $job = $this->game->startMining($this->character->fresh(), $col, $row);
        $job->update(['ends_at' => $this->game->now() - 1]);
        $this->game->collectJob($this->character->fresh(), $job->id);

        foreach ($this->character->fresh()->items as $item) {
            $def = \App\Game\Catalog::item($item->item_key);

            if ($def['slot'] === $worn) {
                $this->assertLessThan(
                    $def['maxDurability'],
                    $item->durability,
                    "the {$def['slot']} did the work and took no wear",
                );
            } else {
                $this->assertSame(
                    $def['maxDurability'],
                    $item->durability,
                    "the {$def['slot']} blunted itself doing nothing",
                );
            }
        }
    }

    /**
     * §11.1 -- throwing things away.
     *
     * Unlike selling, this needs no trader: out in the field the only thing
     * worth having is the room. Nothing comes back for it, or it would just be a
     * worse shop that works everywhere.
     */
    public function test_materials_can_be_thrown_away_anywhere_and_return_nothing(): void
    {
        $add = new \ReflectionMethod($this->game, 'addMaterial');
        $add->setAccessible(true);
        $add->invoke($this->game, $this->character, 'branch', 20);

        $this->assertNull($this->game->currentSettlement($this->character), 'this test wants open country');
        $goldBefore = (int) $this->character->gold;

        $dropped = $this->game->discardMaterial($this->character, 'branch', 5);
        $this->assertSame(5, $dropped);
        $this->assertSame(15, $this->game->held($this->character->fresh(), 'branch'));
        $this->assertSame($goldBefore, (int) $this->character->fresh()->gold, 'discarding paid out');

        // Asking for more than you carry drops what you carry, rather than failing.
        $this->assertSame(15, $this->game->discardMaterial($this->character->fresh(), 'branch', 999));
        $this->assertSame(0, $this->game->held($this->character->fresh(), 'branch'));

        // And once the stack is gone there is nothing left to throw.
        try {
            $this->game->discardMaterial($this->character->fresh(), 'branch', 1);
            $this->fail('threw away material that was not held');
        } catch (\App\Game\GameException $e) {
            $this->assertSame('insufficient', $e->errorCode);
        }
    }

    /** Anything the trader refuses can still be dropped -- that is the point. */
    public function test_unsellable_materials_can_still_be_thrown_away(): void
    {
        $add = new \ReflectionMethod($this->game, 'addMaterial');
        $add->setAccessible(true);
        $add->invoke($this->game, $this->character, 'relic', 3);

        $this->standAtWoodcuttingVillage();

        try {
            $this->game->sellMaterial($this->character->fresh(), 'relic', 1);
            $this->fail('the trader bought a raid material');
        } catch (\App\Game\GameException $e) {
            $this->assertSame('not_sellable', $e->errorCode);
        }

        $this->assertSame(3, $this->game->discardMaterial($this->character->fresh(), 'relic', 3));
        $this->assertSame(0, $this->game->held($this->character->fresh(), 'relic'));
    }

    /** You cannot trade in the middle of a forest. §6 puts the trader at a settlement. */
    public function test_trading_requires_standing_at_a_settlement(): void
    {
        $this->assertNull($this->game->currentSettlement($this->character));

        try {
            $this->game->sellMaterial($this->character, 'wood', 1);
            $this->fail('sold wood in the middle of a forest');
        } catch (\App\Game\GameException $e) {
            $this->assertSame('not_at_settlement', $e->errorCode);
        }

        try {
            $this->game->buyItem($this->character, 'stone_axe');
            $this->fail('bought gear with no shop in sight');
        } catch (\App\Game\GameException $e) {
            $this->assertSame('not_at_settlement', $e->errorCode);
        }
    }

    public function test_trading_works_once_standing_at_a_settlement(): void
    {
        $this->standAtWoodcuttingVillage();

        $reflection = new \ReflectionMethod($this->game, 'addMaterial');
        $reflection->setAccessible(true);
        $reflection->invoke($this->game, $this->character, 'wood', 10);

        $gold = $this->game->sellMaterial($this->character, 'wood', 5);
        $this->assertSame(10, $gold);

        $item = $this->game->buyItem($this->character->fresh(), 'stone_axe');
        $this->assertSame('stone_axe', $item->item_key);
    }

    /** §3.2 -- a village does not stock everything. Better gear needs a city. */
    public function test_villages_do_not_stock_city_gear(): void
    {
        $this->standAtWoodcuttingVillage();
        $this->character->update(['gold' => 500]);

        $this->assertContains('stone_axe', $this->game->shopStock($this->character));
        $this->assertNotContains('iron_hatchet', $this->game->shopStock($this->character));

        $this->expectException(\App\Game\GameException::class);
        $this->game->buyItem($this->character, 'iron_hatchet');
    }

    /**
     * §8.0 -- every workbench reaches exactly as far as its tier allows, and no
     * recipe is stranded somewhere nothing can make it.
     */
    public function test_every_recipe_sits_at_a_bench_that_can_actually_make_it(): void
    {
        foreach (\App\Game\Catalog::items() as $key => $def) {
            if (! isset($def['inputs'])) {
                continue;
            }

            $station = $def['station'] ?? 'village';
            $this->assertTrue(
                Balance::stationReaches($station, $def['rarity']),
                "{$key} is {$def['rarity']} but sits at a {$station} bench",
            );

            // ...and it is at the *smallest* bench that can reach it, so nothing
            // is quietly harder to get than the ladder says.
            $this->assertSame(
                Balance::stationForRarity($def['rarity']),
                $station,
                "{$key} could be made somewhere smaller than a {$station}",
            );
        }
    }

    /** §8.0 -- a village bench refuses work above its rung, whatever you carry. */
    public function test_a_village_bench_refuses_anything_above_common(): void
    {
        $village = $this->standAtWoodcuttingVillage();
        $this->assertSame('village', $village['tier']);

        $add = new \ReflectionMethod($this->game, 'addMaterial');
        $add->setAccessible(true);
        foreach (['ingots' => 40, 'planks' => 40, 'cloth' => 40, 'leather' => 40] as $key => $qty) {
            $add->invoke($this->game, $this->character, $key, $qty);
        }

        // Common is fine here.
        $this->game->craftItem($this->character->fresh(), 'hewn_axe');

        // Uncommon is not, even with every material in the bag.
        try {
            $this->game->craftItem($this->character->fresh(), 'iron_pickaxe');
            $this->fail('a village bench forged an uncommon pickaxe');
        } catch (\App\Game\GameException $e) {
            $this->assertSame('station', $e->errorCode);
            $this->assertStringContainsString('city', $e->getMessage());
        }
    }

    /** §3.2 -- gold stops at uncommon, at every settlement tier. */
    public function test_no_shop_anywhere_stocks_above_uncommon(): void
    {
        foreach (\App\Game\Catalog::items() as $key => $def) {
            if (! isset($def['goldPrice'])) {
                continue;
            }

            $this->assertLessThanOrEqual(
                Balance::rarityRank(Balance::SHOP_RARITY_CAP),
                Balance::rarityRank($def['rarity']),
                "{$key} is sold for gold at {$def['rarity']}",
            );
            $this->assertFalse($def['tradeable'], "{$key} bridges gold to NFT value");
        }
    }

    /**
     * §8.0 -- better gear costs more, and the ladder must not invert. A common
     * priced above an uncommon makes the whole rarity signal a lie at the till.
     */
    public function test_shop_prices_climb_with_rarity(): void
    {
        $byRarity = [];
        foreach (\App\Game\Catalog::items() as $key => $def) {
            if (isset($def['goldPrice'])) {
                $byRarity[$def['rarity']][$key] = $def['goldPrice'];
            }
        }

        $ceilingBelow = 0;
        foreach (Balance::RARITIES as $rarity) {
            if (! isset($byRarity[$rarity])) {
                continue;
            }

            $cheapest = min($byRarity[$rarity]);
            $this->assertGreaterThan(
                $ceilingBelow,
                $cheapest,
                "the cheapest {$rarity} undercuts the priciest rung below it",
            );
            $ceilingBelow = max($byRarity[$rarity]);
        }
    }

    /** §3.3 -- gold must never bridge to NFT-tier value. */
    public function test_the_trader_refuses_rare_and_raid_materials(): void
    {
        $this->standAtWoodcuttingVillage();

        $this->expectException(\App\Game\GameException::class);
        $this->expectExceptionMessage('The trader will not touch that.');

        $this->game->sellMaterial($this->character, 'mythril_ore', 1);
    }

    /** §2 -- tier 3 materials are capped per wallet. */
    public function test_rare_materials_are_capped_per_wallet(): void
    {
        $service = new \ReflectionMethod($this->game, 'addMaterial');
        $service->setAccessible(true);

        $granted = $service->invoke($this->game, $this->character, 'ironwood', Balance::RARE_WALLET_CAP + 50);
        $this->assertSame(Balance::RARE_WALLET_CAP, $granted);

        $overflow = $service->invoke($this->game, $this->character, 'ironwood', 10);
        $this->assertSame(0, $overflow, 'granted past the wallet cap');
    }

    /**
     * §5 -- the map response carries no terrain.
     *
     * The client generates 25 million tiles from the world seed, so this must
     * cost only the two facts it cannot derive. Shipping generated tiles was
     * ~200KB per pan; this guards against that creeping back in.
     */
    public function test_the_map_endpoint_sends_mutations_only(): void
    {
        $empty = $this->game->mapMutations($this->character);

        $this->assertSame(['depleted', 'occupied'], array_keys($empty));
        $this->assertSame([], $empty['depleted']);
        $this->assertSame([], $empty['occupied']);

        // A live trip is the one thing that has to show up.
        $this->game->startMining($this->character, $this->character->col, $this->character->row);

        $busy = $this->game->mapMutations($this->character->fresh());
        $this->assertSame(
            [[$this->character->col, $this->character->row, 1]],
            $busy['occupied'],
        );

        // Small enough that the whole window is a rounding error on the wire.
        $this->assertLessThan(200, strlen(json_encode($busy)));
    }

    /**
     * Sight is the character's travel range, and it is a server rule rather than
     * a rendering choice: a client that pans across the map must not be able to
     * learn where everyone else is mining.
     */
    public function test_the_map_endpoint_sees_only_as_far_as_you_can_reach(): void
    {
        $range = $this->game->travelRange($this->character);

        // Somebody working a hex well beyond sight.
        $far = $this->game->createCharacter(Player::create(['wallet' => '0xfar']));
        $far->update(['col' => $this->character->col + $range + 6, 'row' => $this->character->row]);
        $this->game->startMining($far, (int) $far->col, (int) $far->row);

        $this->assertSame([], $this->game->mapMutations($this->character)['occupied']);

        // Walk toward them and the same hex becomes knowable -- once you get there.
        $this->game->travelTo($this->character, $this->character->col + $range, $this->character->row);
        $this->arrive($this->character);
        $seen = $this->game->mapMutations($this->character->fresh())['occupied'];
        $this->assertSame([[(int) $far->col, (int) $far->row, 1]], $seen);
    }

    /** The generation parameters the client needs, and nothing player-specific. */
    public function test_world_config_carries_generation_parameters(): void
    {
        $config = $this->game->worldConfig();

        foreach (['seed', 'cols', 'rows', 'biomeCell', 'rings', 'namePrefixes', 'dungeonSites'] as $key) {
            $this->assertArrayHasKey($key, $config, "world config is missing {$key}");
        }

        $this->assertCount(5, $config['dungeonSites']);
        $this->assertLessThan(4000, strlen(json_encode($config)));
    }

    /** Walk the character to the village the spawn rule guarantees is in range. */
    /** @return array{col:int,row:int} */
    private function openNeighbour(int $col, int $row): array
    {
        foreach ([[1, 0], [0, 1], [-1, 0], [0, -1], [1, 1], [-1, -1]] as [$dc, $dr]) {
            if (\App\Game\WorldGen::settlementAt($col + $dc, $row + $dr) === null) {
                return ['col' => $col + $dc, 'row' => $row + $dr];
            }
        }

        $this->fail('a settlement completely surrounded by settlements');
    }

    private function standAtWoodcuttingVillage(): array
    {
        $range = Balance::travelRange(1);

        for ($dc = -$range; $dc <= $range; $dc++) {
            for ($dr = -$range; $dr <= $range; $dr++) {
                $s = \App\Game\WorldGen::settlementAt($this->character->col + $dc, $this->character->row + $dr);
                if ($s && in_array('woodcutting', $s['lines'], true)) {
                    $this->character->update(['col' => $s['col'], 'row' => $s['row']]);
                    $this->character->refresh();

                    return $s;
                }
            }
        }

        $this->fail('spawn guarantee broken: no woodcutting village in level-1 range');
    }

    /** One hex at a time: mine, wait, claim. No queue of hexes. */
    public function test_only_one_mine_at_a_time(): void
    {
        $col = $this->character->col;
        $row = $this->character->row;

        $job = $this->game->startMining($this->character, $col, $row);

        // The tile still has a free slot, but the character does not.
        $preview = $this->game->previewTile($this->character->fresh(), $col, $row);
        $this->assertFalse($preview['canMine']);
        $this->assertStringContainsString('Finish that one first', $preview['reason']);

        // Finishing is not claiming: the trip still occupies you until collected.
        $job->update(['ends_at' => $this->game->now() - 1]);
        $preview = $this->game->previewTile($this->character->fresh(), $col, $row);
        $this->assertFalse($preview['canMine']);
        $this->assertStringContainsString('Claim it', $preview['reason']);

        // Collecting clears the trip. Whether the hex survived it is a separate
        // roll (§5.1), so assert the gate rather than the tile, then clear any
        // depletion so the gate is what the last check is actually measuring.
        $this->game->collectJob($this->character->fresh(), $job->id);
        $this->assertNull($this->game->miningTrip($this->character->fresh()));

        \App\Models\TileState::query()->delete();
        $this->assertTrue($this->game->previewTile($this->character->fresh(), $col, $row)['canMine']);
    }

    /** A trip pins you to the hex you are working. */
    public function test_a_trip_stops_you_travelling(): void
    {
        $col = $this->character->col;
        $row = $this->character->row;
        $job = $this->game->startMining($this->character, $col, $row);

        try {
            $this->game->travelTo($this->character, $col + 1, $row);
            $this->fail('travelled away from a running trip');
        } catch (\App\Game\GameException $e) {
            $this->assertSame('working', $e->errorCode);
        }
        $this->assertSame($col, $this->character->fresh()->col);

        // Dropping the trip forfeits the haul (§11.1) and frees you to move.
        $this->game->abandonJob($this->character, $job->id);
        $this->game->travelTo($this->character, $col + 1, $row);
        $this->arrive($this->character);
        $this->assertSame($col + 1, $this->character->fresh()->col);
    }

    /** §6.2 -- helping is standing there, and you can only stand in one place. */
    public function test_only_one_processing_job_at_a_time(): void
    {
        $settlement = $this->standAtWoodcuttingVillage();

        $addMaterial = new \ReflectionMethod($this->game, 'addMaterial');
        $addMaterial->setAccessible(true);
        $addMaterial->invoke($this->game, $this->character, 'wood', 40);

        $this->game->startProcessing($this->character, $settlement['id'], 'planks', 1);

        try {
            $this->game->startProcessing($this->character->fresh(), $settlement['id'], 'planks', 1);
            $this->fail('queued a second line while already helping with one');
        } catch (\App\Game\GameException $e) {
            $this->assertSame('busy', $e->errorCode);
        }
    }

    /** §6.2 -- the helper bonus only covers the time you actually stood there. */
    public function test_walking_away_gives_back_the_presence_bonus(): void
    {
        $settlement = $this->standAtWoodcuttingVillage();
        $away = $this->openNeighbour($settlement['col'], $settlement['row']);

        // Arrive properly: presence is picked up by landing, not by existing.
        $this->character->update(['col' => $away['col'], 'row' => $away['row']]);
        $this->game->travelTo($this->character, $settlement['col'], $settlement['row']);
        $this->arrive($this->character);

        $addMaterial = new \ReflectionMethod($this->game, 'addMaterial');
        $addMaterial->setAccessible(true);
        $addMaterial->invoke($this->game, $this->character, 'wood', 40);

        $job = $this->game->startProcessing($this->character->fresh(), $settlement['id'], 'planks', 1);
        $this->assertTrue((bool) $job->presence, 'queued on site but not helping');
        $helped = $job->ends_at - $this->game->now();

        // Setting out is enough to stop helping: the bonus covers time stood
        // there, and you are not stood there once you start walking.
        $walker = $this->character->fresh();
        $this->game->travelTo($walker, $away['col'], $away['row']);

        $job->refresh();
        $this->assertFalse((bool) $job->presence);
        $alone = $job->ends_at - $this->game->now();
        $this->assertGreaterThan($helped, $alone, 'kept the bonus after walking away');

        // Walking back picks it up again, on what is left.
        $this->arrive($walker);
        $returning = $this->character->fresh();
        $this->game->travelTo($returning, $settlement['col'], $settlement['row']);
        $this->arrive($returning);
        $job->refresh();
        $this->assertTrue((bool) $job->presence);
        $this->assertLessThan($alone, $job->ends_at - $this->game->now());
    }

    /** §6.1 -- the public queue is five slots shared by everyone. */
    public function test_processing_queue_blocks_when_full(): void
    {
        $settlement = $this->standAtWoodcuttingVillage();

        // Five foreign jobs occupy the whole public line. They have to belong to
        // other players: one of the character's own would trip the
        // one-job-at-a-time rule first, and the queue would never be tested.
        for ($i = 0; $i < Balance::PUBLIC_SLOTS; $i++) {
            $other = $this->game->createCharacter(Player::create(['wallet' => "0xq{$i}"]));
            GameJob::create([
                'character_id' => $other->id,
                'kind' => 'processing',
                'status' => 'active',
                'settlement_id' => $settlement['id'],
                'recipe_key' => 'planks',
                'material_key' => 'wood',
                'output_key' => 'planks',
                'quantity' => 1,
                'skill_key' => 'woodcutting',
                'started_at' => $this->game->now(),
                'ends_at' => $this->game->now() + 60000,
            ]);
        }

        $this->expectException(\App\Game\GameException::class);
        $this->game->startProcessing($this->character, $settlement['id'], 'planks', 1);
    }
}
