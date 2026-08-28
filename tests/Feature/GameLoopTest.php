<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Alchemy;
use App\Game\Balance;
use App\Game\Catalog;
use App\Game\Components;
use App\Game\Critters;
use App\Game\Drops;
use App\Game\Formulas;
use App\Game\GameException;
use App\Game\GameService;
use App\Game\Hash;
use App\Game\HexGeometry;
use App\Game\Jobs;
use App\Game\Monsters;
use App\Game\Quests;
use App\Game\Spoils;
use App\Game\Tiles;
use App\Game\Variants;
use App\Game\WorldGen;
use App\Http\Controllers\Api\MiningController;
use App\Http\Controllers\Api\QuestController;
use App\Http\Middleware\ResolveCharacter;
use App\Models\Character;
use App\Models\CharacterBuff;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\CharacterNode;
use App\Models\GameJob;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The core loop, driven through the service rather than HTTP so the rules are
 * tested rather than the routing.
 *
 * These assert the invariants the design doc calls mandatory -- the mine-time
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

        // §9.5.1 -- quiet roads. This file measures mining, processing and the
        // bag; a pack wandering onto a hex mid-test pins the character and
        // refuses all three, on a schedule nobody can see. Map combat has its
        // own files, and they turn it back on.
        config(['game.packs' => false]);

        $this->game = app(GameService::class);
        $player = Player::create(['wallet' => '0xtest', 'session_id' => 'test']);
        $this->character = $this->game->createCharacter($player);
    }

    /**
     * §8.4 -- a craft is a bench job now, so a test that wants the ITEM has to
     * start it, wind the clock, and take it off the bench it was left on.
     *
     * Collected from where it was started, because that is the rule the bench
     * enforces (§8.4); every caller here is already standing at one.
     */
    private function craftNow(string $itemKey): array
    {
        $job = $this->game->startCraft($this->character->fresh(), $itemKey);
        $job->update(['ends_at' => $this->game->now() - 1]);

        return $this->game->collectJob($this->character->fresh(), $job->id);
    }

    /**
     * Wind a journey's clock back, so time appears to have passed.
     *
     * Both ends move together, and that is not tidiness. The journey's pace is
     * read back from the gap between them (§8.3, so that changing boots mid-walk
     * cannot move the hex a stop lands on), which means rewinding only the start
     * would not simulate a walk -- it would fabricate a slower journey than the
     * one that was actually set out on.
     */
    private function rewind(Character $character, int $ms): void
    {
        $character->travel_started_at = (int) $character->travel_started_at - $ms;
        $character->travel_ends_at = (int) $character->travel_ends_at - $ms;
        $character->save();
    }

    /** Wind a journey's clock back so it has already landed, then settle it. */
    private function arrive(Character $character): void
    {
        $this->rewind(
            $character,
            (int) $character->travel_ends_at - (int) $character->travel_started_at,
        );

        $this->game->settle($character);
    }

    /**
     * The pace of the journey under way, read back from its own clock.
     *
     * Not Balance::TRAVEL_MS_PER_HEX any more: §8.3 makes travelSpeed divide the
     * clock, and boots or a tonic move it, so there is no single figure a test
     * can assume. (The Explorer no longer contributes -- §7.5's ladder pays in
     * capability and never in a stat.)
     */
    private function perHex(Character $character): int
    {
        $hexes = HexGeometry::distance(
            (int) $character->col,
            (int) $character->row,
            (int) $character->travel_to_col,
            (int) $character->travel_to_row,
        );

        return intdiv(
            (int) $character->travel_ends_at - (int) $character->travel_started_at,
            max(1, $hexes),
        );
    }

    /** Pretend the walker has been going for this many whole hexes. */
    private function walkFor(Character $character, int $hexes): void
    {
        $this->rewind($character, $hexes * $this->perHex($character) + 5);
    }

    /** §5 -- ten minutes of ground per hex, and the map is crossed on foot. */
    public function test_travel_takes_ten_minutes_a_hex_and_lands_at_the_destination(): void
    {
        $from = ['col' => $this->character->col, 'row' => $this->character->row];
        $to = ['col' => $from['col'] + 6, 'row' => $from['row']];

        $distance = HexGeometry::distance($from['col'], $from['row'], $to['col'], $to['row']);
        $travel = $this->game->travelTo($this->character, $to['col'], $to['row']);

        $this->assertSame($distance, $travel['hexes']);
        $this->assertSame($distance + 1, count($travel['path']), 'the road includes both ends');

        // §8.3 -- ten minutes a hex divided by whatever travelSpeed the walker
        // carries. Even a fresh character has some: Explorer's first node is
        // granted the moment the job exists (§7.5).
        $pace = Balance::scaled(Balance::travelMsPerHex(
            $this->game->bonuses($this->character)['travelSpeed'],
        ));

        $this->assertSame($distance * $pace, $travel['endsAt'] - $travel['startedAt']);
        $this->assertSame($pace, $travel['perHexMs']);

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
        $perHex = $this->perHex($this->character);
        $this->rewind($this->character, 3 * $perHex + (int) ($perHex * 0.9));

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
        } catch (GameException $e) {
            $this->assertSame('traveling', $e->errorCode);
        }
    }

    /** §12 -- the spawn must make the opening quest arc completable. */
    public function test_spawn_is_forest_with_a_reachable_woodcutting_village(): void
    {
        $tile = $this->game->buildTile($this->character->col, $this->character->row, $this->game->now());

        $this->assertSame('forest', $tile['biome']);
        $this->assertSame('wood', $tile['material']);
        $this->assertSame('outer', $tile['ring']);

        $range = Balance::SPAWN_VILLAGE_RADIUS;
        $found = null;
        for ($dc = -$range; $dc <= $range && ! $found; $dc++) {
            for ($dr = -$range; $dr <= $range && ! $found; $dr++) {
                $s = WorldGen::settlementAt($this->character->col + $dc, $this->character->row + $dr);
                if ($s && in_array('woodcutting', $s['lines'], true)) {
                    $found = $s;
                }
            }
        }

        $this->assertNotNull($found, 'no woodcutting village within spawn radius');
    }

    public function test_mining_yields_on_collect(): void
    {
        $col = $this->character->col;
        $row = $this->character->row;
        $this->equipToolForHere();

        $job = $this->game->startMining($this->character, $col, $row);

        // A mine never moves anyone: you were standing here to start it.
        $this->assertSame($col, $this->character->col);
        $this->assertSame($row, $this->character->row);

        // Cannot collect early.
        try {
            $this->game->collectJob($this->character, $job->id);
            $this->fail('collected a job that was still running');
        } catch (GameException $e) {
            $this->assertSame('not_ready', $e->errorCode);
        }

        // Wind the clock back rather than sleeping.
        $job->update(['ends_at' => $this->game->now() - 1]);

        // §8.0 -- an axe is on the belt, so the hex gives up what it holds.
        $result = $this->game->collectJob($this->character->fresh(), $job->id);
        $this->assertGreaterThan(0, $result['gained']['wood']);
        $this->assertArrayNotHasKey('branch', $result['gained']);
        $this->assertSame(0, GameJob::count());
    }

    /**
     * §7.4.4 -- a fast clock is a testing tool, never a progression cheat.
     *
     * Timers go through Balance::scaled(); XP must not. If XP ever picked up the
     * time scale, GAME_TIME_SCALE=100 would pay a hundred times the progression
     * per real hour and the six-month pacing target would mean nothing. This
     * pins the rule at the one place it would break: the collect that pays out.
     */
    public function test_xp_does_not_move_with_the_game_clock(): void
    {
        $earned = [];

        foreach ([1, 100] as $scale) {
            config(['game.time_scale' => $scale]);
            $this->assertSame($scale, Balance::timeScale());

            $player = Player::create(['wallet' => "0xclock{$scale}", 'session_id' => "clock{$scale}"]);
            $character = $this->game->createCharacter($player);

            $job = $this->game->startMining($character, $character->col, $character->row, Drops::GATHERING);
            $job->update(['ends_at' => $this->game->now() - 1, 'quantity' => 5]);

            $before = $character->fresh()->xp;
            $this->game->collectJob($character->fresh(), $job->id);
            $earned[$scale] = $character->fresh()->xp - $before;
        }

        config(['game.time_scale' => 1]);

        $this->assertGreaterThan(0, $earned[1], 'a finished mine paid no XP at all');
        $this->assertSame(
            $earned[1],
            $earned[100],
            'XP moved with the game clock -- a fast clock must compress timers, not progression',
        );
    }

    /**
     * §7.4.4 -- the level curve is sized against measured income, so a change to
     * it should have to argue with this test rather than slip through.
     *
     * ~1,080 character XP a day is what an unbroken career averages at speed 1
     * (28 mines a day unequipped, 48 on the old 30-minute floor, plus the
     * processing those hauls feed). The target is level 100 at about six months.
     *
     * The divisor is a measurement rather than a derivation, which is why this
     * test still passes after §7.3 dropped the floor to a guard -- see
     * Balance::xpForLevel(). Re-fitting it is a pacing decision of its own.
     */
    public function test_the_level_curve_lands_on_the_six_month_target(): void
    {
        $this->assertSame(100, Balance::MAX_LEVEL);

        $total = 0;
        for ($level = 1; $level < Balance::MAX_LEVEL; $level++) {
            $total += Balance::xpForLevel($level);
        }

        $days = $total / 1078.0;
        $this->assertGreaterThan(150, $days, "level 100 reachable in only {$days} days");
        $this->assertLessThan(220, $days, "level 100 takes {$days} days, well past six months");

        // The first level should cost a few mines, not a fraction of one: a
        // character that gains four levels on its first haul has no curve at all.
        $firstTrip = 5 * 4 * 0.6;
        $this->assertGreaterThan(
            2 * $firstTrip,
            Balance::xpForLevel(1),
            'level 2 arrives in under two mines',
        );

        // Monotonic, or later levels would be cheaper than earlier ones.
        for ($level = 2; $level < Balance::MAX_LEVEL; $level++) {
            $this->assertGreaterThan(Balance::xpForLevel($level - 1), Balance::xpForLevel($level));
        }
    }

    /**
     * §7.4.1 -- 100 levels buys three complete 30-node trees and 10 spare. The
     * spare is deliberate: it is enough to dabble, never enough for a fourth
     * job, which is what keeps a character a specialist.
     */
    public function test_skill_points_buy_three_trees_and_no_more(): void
    {
        $this->assertSame(1, Balance::skillPointsFor(1), 'level 1 starts with a point');
        $this->assertSame(100, Balance::skillPointsFor(Balance::MAX_LEVEL));

        $treesAffordable = intdiv(Balance::skillPointsFor(Balance::MAX_LEVEL), 30);
        $this->assertSame(3, $treesAffordable);
        $this->assertSame(10, Balance::skillPointsFor(Balance::MAX_LEVEL) - 3 * 30);
    }

    /** §7.4.1 -- job levels gate nodes and are reachable by doing the work. */
    public function test_job_curve_is_reachable_by_crafting(): void
    {
        $this->assertSame(30, Balance::JOB_MAX_LEVEL);

        $total = 0;
        for ($level = 1; $level < Balance::JOB_MAX_LEVEL; $level++) {
            $total += Balance::jobXpForLevel($level);
        }

        // A craft pays 10 x (rarity rank + 1): common 10 through epic 40.
        $crafts = $total / 20;
        $this->assertGreaterThan(500, $crafts, 'a job maxes in a handful of crafts');
        $this->assertLessThan(4000, $crafts, "job 30 needs {$crafts} crafts, which nobody will do");
    }

    // ------------------------------------------------------------- jobs §7.4

    /** Put a job at a level so its tier gates open, without crafting for hours. */
    private function setJobLevel(string $job, int $level): void
    {
        $this->character->jobLevels()->where('job_key', $job)->update(['level' => $level]);
    }

    /** Put materials in the bag without mining for them. */
    /**
     * Find a hex with a live herd on it and stand the character there.
     *
     * Herds are time-bucketed and 6% likely on plains/grassland (§5.5), so this
     * scans rather than assuming. Returns [col, row].
     *
     * @return array{0:int,1:int}
     */
    private function standOnAHerd(): array
    {
        $now = $this->game->now();

        for ($col = -Balance::mapRadius(); $col <= Balance::mapRadius(); $col++) {
            for ($row = -Balance::mapRadius(); $row <= Balance::mapRadius(); $row++) {
                $tile = $this->game->buildTile($col, $row, $now);
                if (($tile['herdUntil'] ?? null) !== null && $tile['herdUntil'] > $now) {
                    $this->character->update(['col' => $col, 'row' => $row]);
                    $this->character->refresh();

                    return [$col, $row];
                }
            }
        }

        $this->fail('no herd anywhere on the map');
    }

    /** Stand on a forest hex that actually has a seam to work. */
    private function standOnMineableGround(): array
    {
        $now = $this->game->now();

        for ($col = -Balance::mapRadius(); $col <= Balance::mapRadius(); $col++) {
            for ($row = -Balance::mapRadius(); $row <= Balance::mapRadius(); $row++) {
                $tile = $this->game->buildTile($col, $row, $now);

                if ($tile['biome'] !== 'forest' || ($tile['material'] ?? null) === null) {
                    continue;
                }

                if ($tile['settlement'] !== null || $tile['water'] !== null) {
                    continue;
                }

                $this->character->update(['col' => $col, 'row' => $row]);
                $this->character->refresh();

                return [$col, $row];
            }
        }

        $this->fail('no workable forest anywhere on the map');
    }

    /**
     * A session whose player row has had its session_id cleared comes back to
     * the same wallet, rather than 500ing on the unique index.
     *
     * `game:demo` nulls other rows' session_id when it rebinds, so this is a
     * state the dev flow produces routinely -- and the wallet is derived from
     * the session, so creating blind always collides.
     */
    public function test_a_cleared_session_rebinds_instead_of_colliding(): void
    {
        config(['game.auto_provision' => true]);

        // Laravel's SessionStore replaces an id that is not 40 alphanumerics
        // with a fresh random one, which would silently defeat this test.
        $sessionId = str_repeat('a1b2', 10);
        $wallet = '0x'.substr(hash('sha256', $sessionId), 0, 40);

        // The row exists, orphaned from its session.
        $player = Player::create(['wallet' => $wallet, 'session_id' => null]);

        $resolve = new ReflectionMethod(ResolveCharacter::class, 'resolvePlayer');
        $middleware = new ResolveCharacter($this->game);

        $request = Request::create('/api/state');
        $session = new Store('test', new ArraySessionHandler(120), $sessionId);
        $request->setLaravelSession($session);

        $resolved = $resolve->invoke($middleware, $request);

        $this->assertNotNull($resolved);
        $this->assertSame($player->id, $resolved->id, 'a new row was created instead of rebinding');
        $this->assertSame($sessionId, $resolved->session_id);
        $this->assertSame(1, Player::where('wallet', $wallet)->count());
    }

    /** §8.5 -- every potion names the action it buffs. Seventy, fourteen a rung. */
    public function test_every_consumable_is_locked_to_one_action(): void
    {
        $byRank = [];

        foreach (Catalog::items() as $key => $def) {
            if (empty($def['consumable'])) {
                continue;
            }

            $this->assertArrayHasKey('scope', $def, "{$key} buffs everything at once");
            $this->assertContains(
                $def['scope'],
                [
                    'woodcutting', 'mining', 'hunting', 'quarrying', 'harvesting',
                    'travel', 'processing',
                    // §9.5 -- the one scope that is not work, and the only place
                    // `power` and `defense` are worth drinking for.
                    'battle',
                ],
                "{$key} names an action nothing does",
            );

            $byRank[$def['rarity']][] = $key;
        }

        foreach (['common', 'uncommon', 'rare', 'epic', 'legendary'] as $rarity) {
            $this->assertGreaterThanOrEqual(
                10,
                count($byRank[$rarity] ?? []),
                "{$rarity} has fewer than ten potions",
            );
        }
    }

    /**
     * A recipe never wants MORE different materials as it climbs.
     *
     * A common draft is a muddle of four cheap things; a legendary philtre is
     * two perfect ones. Every consumable wants at least two, so nothing is a
     * one-ingredient shortcut.
     */
    public function test_better_potions_ask_for_no_more_materials_than_worse_ones(): void
    {
        $worst = [];

        foreach (Catalog::items() as $key => $def) {
            if (empty($def['consumable'])) {
                continue;
            }

            $count = count($def['inputs'] ?? []);
            $this->assertGreaterThanOrEqual(2, $count, "{$key} is a one-material recipe");
            $worst[$def['rarity']] = max($worst[$def['rarity']] ?? 0, $count);
        }

        $ladder = ['common', 'uncommon', 'rare', 'epic', 'legendary'];
        for ($i = 1; $i < count($ladder); $i++) {
            $this->assertLessThanOrEqual(
                $worst[$ladder[$i - 1]],
                $worst[$ladder[$i]],
                "{$ladder[$i]} asks for more materials than {$ladder[$i - 1]}",
            );
        }
    }

    /**
     * §2 -- no potion is tradeable, because nothing capped stands behind one.
     *
     * The bench runs on reagents alone: Tier 1, uncapped, mined by anybody. An
     * NFT rung on that stock would be exactly the grind-to-external-value path
     * §2 exists to close, so the shelf stops at prestige. Should a wallet-capped
     * alchemy input ever land, this is the test to reopen.
     */
    public function test_potions_are_never_tradeable(): void
    {
        $checked = 0;

        foreach (Catalog::items() as $key => $def) {
            if (empty($def['consumable'])) {
                continue;
            }

            $this->assertFalse($def['tradeable'], "{$key} is a tradeable potion off uncapped stock");

            $capped = array_filter(
                array_keys($def['inputs'] ?? []),
                fn (string $m) => Catalog::walletCap($m) !== null,
            );

            $this->assertEmpty($capped, "{$key} wants a capped material -- the NFT rung can come back");
            $checked++;
        }

        $this->assertGreaterThanOrEqual(60, $checked, 'the potion shelf is missing');
    }

    /** §4.0 -- junk sells for a copper, feeds nothing, and reaches no tier. */
    public function test_junk_is_worth_a_copper_and_feeds_no_recipe(): void
    {
        $junk = ['deadfall', 'slag', 'bone_splinter', 'cinder', 'thistle'];

        foreach ($junk as $key) {
            $def = Catalog::material($key);
            $this->assertNotNull($def, "{$key} is not in the catalog");
            $this->assertSame(1, $def['npcPrice'], "{$key} sells for more than a copper");
            $this->assertSame(0, $def['tier'], "{$key} is not tier zero");
        }

        foreach (Catalog::items() as $key => $def) {
            foreach (array_keys($def['inputs'] ?? []) as $input) {
                $this->assertNotContains($input, $junk, "{$key} is crafted from junk");
            }
        }

        foreach (Catalog::recipes() as $key => $recipe) {
            $this->assertNotContains($recipe['input'], $junk, "{$key} processes junk");
            $this->assertNotContains($recipe['output'], $junk, "{$key} produces junk");
        }
    }

    /**
     * §4.0 -- every reagent outsells scrap and junk.
     *
     * The gap between what bare hands bring back and what a real material is
     * worth is the whole argument for buying a first tool, and §4.0 calls it a
     * rule rather than a tuning value. A one-gold reagent would close it.
     */
    public function test_reagents_outsell_the_rubbish(): void
    {
        $reagents = array_keys(Alchemy::REAGENTS);
        $this->assertCount(10, $reagents);

        foreach ($reagents as $key) {
            $def = Catalog::material($key);
            $this->assertSame(1, $def['tier'], "{$key} is not a raw material");
            $this->assertGreaterThan(1, $def['npcPrice'], "{$key} sells for scrap money");
            $this->assertNotNull($def['biome'] ?? null, "{$key} comes from no kind of ground");
        }

        // Two per biome, so a recipe can want two different things off one tile.
        $byBiome = [];
        foreach ($reagents as $key) {
            $byBiome[Catalog::material($key)['biome']][] = $key;
        }
        $this->assertCount(5, $byBiome, 'reagents do not cover the five biomes');
        foreach ($byBiome as $biome => $keys) {
            $this->assertCount(2, $keys, "{$biome} does not have two reagents");
        }
    }

    /**
     * §4 -- the smith and the armorer have their own raw stock, as the
     * alchemist does.
     *
     * Ten craft components on the reagent model: Tier 1, biome-locked, two per
     * biome, and every one worth more than the scrap floor §4.0 makes a rule.
     * Two per biome so one recipe can want two things off a single kind of
     * ground, and one bench each so neither craft is short of a line.
     */
    public function test_craft_components_are_biome_locked_and_outsell_the_rubbish(): void
    {
        $components = array_keys(Components::CRAFT);
        $this->assertCount(10, $components);

        $byBiome = [];
        $byBench = [];

        foreach ($components as $key) {
            $def = Catalog::material($key);
            $this->assertNotNull($def, "{$key} is not in the catalog");
            $this->assertSame(1, $def['tier'], "{$key} is not a raw material");
            $this->assertGreaterThan(1, $def['npcPrice'], "{$key} sells for scrap money");
            $this->assertNotNull($def['biome'] ?? null, "{$key} comes from no kind of ground");
            $this->assertNull($def['walletCap'] ?? null, "{$key} is capped, which Tier 1 never is");

            $byBiome[$def['biome']][] = $key;
            $byBench[$def['bench']][] = $key;
        }

        $this->assertCount(5, $byBiome, 'components do not cover the five biomes');
        foreach ($byBiome as $biome => $keys) {
            $this->assertCount(2, $keys, "{$biome} does not have two components");
        }

        $benches = array_keys($byBench);
        sort($benches);
        $this->assertSame(['armor', 'weapon'], $benches, 'a component names an unknown bench');
        $this->assertCount(5, $byBench['weapon'], 'the weapon bench is short a component');
        $this->assertCount(5, $byBench['armor'], 'the armor bench is short a component');
    }

    // ------------------------------------------------------------- drops §4

    /** A tile of a known grade in a known biome, for exercising a table. */
    private function tileOfGrade(string $biome, int $grade): array
    {
        $want = Variants::BIOME_VARIANTS[$biome][$grade]['key'];
        $radius = Balance::mapRadius();

        for ($col = -$radius; $col <= $radius; $col += 1) {
            for ($row = -$radius; $row <= $radius; $row += 1) {
                $tile = WorldGen::generateTile($col, $row, 0);
                if (($tile['variant'] ?? null) === $want) {
                    return $tile;
                }
            }
        }

        $this->fail("no {$want} hex anywhere on the map");
    }

    /**
     * §4 -- a haul is the size the tile card promised, whatever shape it takes.
     *
     * The whole point of splitting a mine across a table is that the total does
     * not move: a player reads a number off the hex before committing an hour
     * to it, and the drop system is not allowed to make that number a lie.
     */
    public function test_a_split_haul_is_exactly_the_promised_size(): void
    {
        $tile = $this->tileOfGrade('forest', 1);

        foreach ([1, 3, 7, 12, 40] as $units) {
            foreach ([Drops::GATHERING, Drops::MINING, Drops::HUNTING] as $activity) {
                $table = Drops::table($activity, $tile, 1);

                for ($seed = 0; $seed < 12; $seed++) {
                    $rolled = Drops::roll($table, $units, $seed);

                    $this->assertSame($units, array_sum($rolled), "{$activity} lost or invented units");
                    $this->assertLessThanOrEqual(
                        Drops::MAX_KINDS,
                        count($rolled),
                        "{$activity} split past the strap budget",
                    );

                    foreach ($rolled as $key => $qty) {
                        $this->assertGreaterThan(0, $qty, "{$key} came back as an empty stack");
                        $this->assertNotNull(Catalog::material((string) $key), "{$key} is not a material");
                    }
                }
            }
        }
    }

    /**
     * §5.3 -- the tool sets the grade, the ground sets the ceiling.
     *
     * A common axe on a Hardwood Stand takes wood nearly every time and
     * hardwood occasionally. This is the rule that makes a better tool worth
     * buying WITHOUT making a lesser one useless, so both halves are asserted:
     * the better grade must be reachable, and it must be rare.
     */
    public function test_a_lesser_tool_mostly_takes_the_lesser_grade(): void
    {
        $tile = $this->tileOfGrade('forest', 1);
        $table = Drops::table(Drops::MINING, $tile, 0);
        $total = array_sum($table);

        $this->assertArrayHasKey('wood', $table, 'a common axe cannot take common timber');
        $this->assertArrayHasKey('hardwood', $table, 'the better grade is unreachable, not merely rare');

        $this->assertGreaterThan(0.5, $table['wood'] / $total, 'the common grade does not dominate');
        $this->assertLessThan(0.15, $table['hardwood'] / $total, 'the better grade is not a long shot');

        // With the right tool the ladder inverts, which is the reward.
        $better = Drops::table(Drops::MINING, $tile, 1);
        $this->assertGreaterThan(
            0.5,
            $better['hardwood'] / array_sum($better),
            'the matching tool does not reliably take its grade',
        );

        // Each rung short halves the odds again, so a village pick on contested
        // ground is a lottery rather than a shortcut.
        $epic = $this->tileOfGrade('forest', 3);
        $stretch = Drops::table(Drops::MINING, $epic, 0);
        $this->assertLessThan(
            $stretch['hardwood'],
            $stretch['ironwood'],
            'reaching three rungs up is no harder than reaching one',
        );
    }

    /**
     * §5.3 -- a grade is what a hex MOSTLY carries, never all it carries.
     *
     * The ladder ran one way: reach a grade and you took it on every swing.
     * That made a hex a switch rather than a place -- an Ironwood Grove is a
     * grove of ironwood with ordinary trees standing in it, and a Mythril Seam
     * runs through rock that is mostly iron.
     *
     * Both tails are asserted, because the shape is the point: the grade above
     * is a long shot and the grade below is merely uncommon.
     */
    public function test_the_grade_you_reach_is_mostly_what_you_take(): void
    {
        $tile = $this->tileOfGrade('forest', 3);
        $table = Drops::table(Drops::MINING, $tile, 3);
        $total = array_sum($table);

        // The thing you came for still dominates, or the grade means nothing.
        $this->assertGreaterThan(0.5, $table['ironwood'] / $total, 'the grade does not dominate');

        // And every rung under it turns up, thinning the whole way down.
        foreach (['heartoak', 'hardwood', 'wood'] as $lesser) {
            $this->assertArrayHasKey($lesser, $table, "{$lesser} never turns up under ironwood");
        }

        $this->assertGreaterThan($table['hardwood'], $table['heartoak']);
        $this->assertGreaterThan($table['wood'], $table['hardwood']);

        // Falling short is commoner than exceeding: a grade under the one you
        // are cutting is ordinary, a grade over it is luck.
        $stretch = Drops::table(Drops::MINING, $tile, 2);
        $this->assertGreaterThan(
            $stretch['ironwood'],
            $stretch['hardwood'],
            'the grade below is rarer than the grade above',
        );

        // §5.3 -- base ground has nothing under it to fall to.
        $base = Drops::table(Drops::MINING, $this->tileOfGrade('forest', 0), 3);
        $this->assertSame(
            ['wood'],
            array_values(array_intersect(
                array_keys($base),
                ['wood', 'hardwood', 'heartoak', 'ironwood'],
            )),
            'base ground gave up a grade it does not carry',
        );
    }

    /**
     * §4.0 -- gathering is a windfall, not a career.
     *
     * The gap between what bare hands bring back and what a tool brings back is
     * the entire argument for buying the first one, and §4.0 calls that a rule
     * rather than a tuning value. A gatherer may get lucky; a gatherer may not
     * get the good ground at all.
     */
    public function test_gathering_pays_rubbish_and_a_thin_windfall(): void
    {
        foreach ([0, 1, 2, 3] as $grade) {
            $tile = $this->tileOfGrade('forest', $grade);
            $table = Drops::table(Drops::GATHERING, $tile, 3);
            $total = array_sum($table);

            // The windfall is the COMMON grade, on every kind of ground. A
            // gatherer standing in an ironwood grove gets ordinary wood.
            $this->assertArrayHasKey('wood', $table);
            $this->assertLessThan(0.08, $table['wood'] / $total, 'the windfall is not thin');

            foreach (['hardwood', 'heartoak', 'ironwood'] as $better) {
                $this->assertArrayNotHasKey($better, $table, "bare hands reached {$better}");
            }

            // Rubbish is most of it, which is what makes the tool obvious.
            $rubbish = ($table['branch'] ?? 0) + ($table['deadfall'] ?? 0);
            $this->assertGreaterThan(0.7, $rubbish / $total, 'gathering is too generous to be a floor');
        }
    }

    /**
     * §4 -- the bench stocks finally have a faucet, and it is the right one.
     *
     * Herbs and craft components were in the catalog and in recipes with
     * nothing on the map dropping them. Mining a biome now yields its two herbs
     * and its two components; hunting yields its critter. Nothing else does.
     */
    public function test_every_bench_stock_drops_off_its_own_activity(): void
    {
        foreach (Catalog::BIOMES as $biome) {
            $tile = $this->tileOfGrade($biome, 0);

            $mined = Drops::table(Drops::MINING, $tile, 0);
            $hunted = Drops::table(Drops::HUNTING, $tile, 0);
            $gathered = Drops::table(Drops::GATHERING, $tile, 0);

            foreach (Components::CRAFT as $key => $def) {
                if ($def['biome'] === $biome) {
                    $this->assertArrayHasKey($key, $mined, "{$key} has no faucet");
                }
            }

            foreach (Alchemy::REAGENTS as $key => $def) {
                if ($def['biome'] === $biome) {
                    $this->assertArrayHasKey($key, $mined, "{$key} has no faucet");
                    $this->assertArrayHasKey($key, $gathered, "{$key} cannot be gathered");
                }
            }

            // A critter is hunted and never gathered: that is the difference
            // between the two halves of the alchemist's shelf.
            $critter = Critters::BY_BIOME[$biome];
            $this->assertArrayHasKey($critter, $hunted, "{$critter} has no faucet");
            $this->assertArrayNotHasKey($critter, $gathered, "{$critter} can be picked up by hand");
            $this->assertArrayNotHasKey($critter, $mined, "{$critter} is dug out of the ground");
        }
    }

    /** A haul is settled once: claiming the same mine twice cannot re-roll it. */
    public function test_a_haul_is_deterministic(): void
    {
        $tile = $this->tileOfGrade('mountain', 2);
        $table = Drops::table(Drops::MINING, $tile, 2);

        $first = Drops::roll($table, 9, 4242);
        $again = Drops::roll($table, 9, 4242);
        $other = Drops::roll($table, 9, 4243);

        $this->assertSame($first, $again, 'the same mine rolled twice paid differently');
        $this->assertNotSame($first, $other, 'every mine pays exactly the same haul');
    }

    /**
     * §5.1 -- the world is measured from the middle out, and (0,0) is the middle.
     *
     * A radius of 200 is every column from -200 to 200, so both ends are on the
     * map and the count is the odd number 401. The origin being the center is
     * the part everything else leans on: the rings are a distance from it, the
     * dungeon mouths are placed around it, and the atlas draws its circles on it.
     */
    public function test_the_map_is_measured_from_the_origin_out(): void
    {
        $radius = Balance::mapRadius();

        $this->assertSame($radius * 2 + 1, Balance::mapSize());

        // Both ends inclusive, and one step past either is off.
        foreach ([[-$radius, 0], [$radius, 0], [0, -$radius], [0, $radius], [0, 0]] as [$col, $row]) {
            $this->assertTrue(WorldGen::inBounds($col, $row), "{$col},{$row} should be on the map");
        }
        foreach ([[-$radius - 1, 0], [$radius + 1, 0], [0, -$radius - 1], [0, $radius + 1]] as [$col, $row]) {
            $this->assertFalse(WorldGen::inBounds($col, $row), "{$col},{$row} should be off the map");
        }

        // The origin is the dead center, and the corners are the rim.
        $this->assertSame('center', WorldGen::ringOf(0, 0));
        $this->assertSame('outer', WorldGen::ringOf($radius, $radius));
        $this->assertSame('outer', WorldGen::ringOf(-$radius, -$radius));

        // Opposite corners are the same distance out, which is only true if the
        // center really is the origin rather than somewhere along a signed axis.
        $this->assertEqualsWithDelta(
            WorldGen::radiusOf($radius, $radius),
            WorldGen::radiusOf(-$radius, -$radius),
            1e-9,
        );
    }

    /**
     * §5.1 -- the size and the seed of the world are settings, not a code edit.
     *
     * A different seed has to be a different world, and a different radius a
     * different edge, or the .env knobs are decorative. Both caches are dropped
     * around the change: the seed is memoised precisely because it is read once
     * per hashed coordinate, and a stale one would answer for the old world.
     */
    public function test_the_map_size_and_seed_come_from_config(): void
    {
        $before = [];
        foreach ([[0, 0], [10, -14], [-33, 7]] as [$col, $row]) {
            $before["{$col},{$row}"] = WorldGen::biomeOf($col, $row);
        }

        try {
            config(['game.map.seed' => '0xdeadbeef', 'game.map.radius' => 40]);
            WorldGen::forget();

            $this->assertSame(0xDEADBEEF, Balance::mapSeed(), 'the seed did not come from config');
            $this->assertSame(40, Balance::mapRadius());
            $this->assertSame(81, Balance::mapSize(), 'the count is not derived from the radius');

            // The edge moved with it.
            $this->assertTrue(WorldGen::inBounds(40, 0));
            $this->assertFalse(WorldGen::inBounds(41, 0));

            $after = [];
            foreach ([[0, 0], [10, -14], [-33, 7]] as [$col, $row]) {
                $after["{$col},{$row}"] = WorldGen::biomeOf($col, $row);
            }

            $this->assertNotSame($before, $after, 'a new seed generated the same ground');
        } finally {
            config([
                'game.map.seed' => '0x5eed1a3f',
                'game.map.radius' => 200,
            ]);
            WorldGen::forget();
        }

        // And the world came back, which is what makes the seed a seed.
        foreach ($before as $coord => $biome) {
            [$col, $row] = array_map('intval', explode(',', $coord));
            $this->assertSame($biome, WorldGen::biomeOf($col, $row));
        }
    }

    /** A hex seed and the decimal it spells are the same world. */
    public function test_the_seed_accepts_hex_or_decimal(): void
    {
        try {
            config(['game.map.seed' => '0x5eed1a3f']);
            WorldGen::forget();
            $hex = Balance::mapSeed();

            config(['game.map.seed' => (string) 0x5EED1A3F]);
            WorldGen::forget();
            $this->assertSame($hex, Balance::mapSeed(), 'decimal and hex disagree');

            // Anything wider than the hash is masked, not silently divergent.
            config(['game.map.seed' => 0x1_5EED_1A3F]);
            WorldGen::forget();
            $this->assertSame($hex, Balance::mapSeed(), 'the seed was not masked to 32 bits');
        } finally {
            config(['game.map.seed' => '0x5eed1a3f']);
            WorldGen::forget();
        }
    }

    /**
     * §5.1 -- nobody lives off the edge, and the lattice does not stop there.
     *
     * The settlement lattice is infinite: it will happily place a village in a
     * cell beyond the rim. The atlas walks cells and the slow path walks tiles,
     * so if only one of them knew where the map ended the two would disagree
     * about every border cell.
     */
    public function test_no_settlement_stands_off_the_map(): void
    {
        $radius = Balance::mapRadius();

        $found = 0;
        for ($col = $radius + 1; $col <= $radius + 40; $col++) {
            for ($row = -20; $row <= 20; $row++) {
                if (WorldGen::settlementAt($col, $row) !== null) {
                    $found++;
                }
            }
        }

        $this->assertSame(0, $found, 'the lattice placed settlements past the rim');

        // And the guard did not cost anything inside the map: the rim still
        // carries the villages §6 puts there.
        $inside = 0;
        for ($col = $radius - 40; $col <= $radius; $col++) {
            for ($row = -20; $row <= 20; $row++) {
                if (WorldGen::settlementAt($col, $row) !== null) {
                    $inside++;
                }
            }
        }

        $this->assertGreaterThan(0, $inside, 'the rim lost its settlements to the bounds guard');
    }

    /**
     * §5.3 -- a biome is four kinds of ground, and the weights have to hold.
     *
     * The variant walk in WorldGen::variantOf() adds a ring's column until it
     * passes the roll. If a column does not sum to 1 the walk falls off the end
     * and every hex past that point silently becomes the base grade.
     */
    public function test_every_biome_has_four_grades_and_the_weights_close(): void
    {
        foreach (Catalog::BIOMES as $biome) {
            $variants = Variants::BIOME_VARIANTS[$biome];
            $this->assertCount(4, $variants, "{$biome} does not have four variants");
            $this->assertSame(
                Variants::GRADES,
                array_column($variants, 'grade'),
                "{$biome} is out of grade order",
            );

            foreach (['outer', 'mid', 'inner'] as $ring) {
                $total = 0.0;
                foreach ($variants as $variant) {
                    $total += $variant['weights'][$ring];
                }
                $this->assertEqualsWithDelta(1.0, $total, 1e-9, "{$biome} {$ring} weights do not close");
            }
        }
    }

    /**
     * §5.2 -- the outer rim is safe ground and poor ground, and the gradient in
     * is the whole reason to walk.
     *
     * Asserted on where a grade is AT HOME rather than on where it can turn up
     * at all, because the two middle grades leak outward at a few per cent: a
     * grade sealed inside the ring that already outclasses it is a recipe
     * nobody ever cooks. The leak has to stay a lucky find, so it is bounded
     * here as well as floored.
     *
     * Contested is the exception and it is a §2 rule: Tier 3 is capped per
     * wallet and gates every mintable recipe, so a lucky one on the safe rim
     * would be the grind->NFT path the threat model exists to close.
     */
    public function test_grades_are_gated_by_ring(): void
    {
        $home = [
            'common' => ['outer', 'mid', 'inner'],
            'uncommon' => ['mid', 'inner'],
            'rare' => ['inner'],
            'epic' => ['inner'],
        ];

        foreach (Catalog::BIOMES as $biome) {
            foreach (Variants::BIOME_VARIANTS[$biome] as $variant) {
                $rings = [];
                foreach (['outer', 'mid', 'inner'] as $ring) {
                    if ($variant['weights'][$ring] >= 0.1) {
                        $rings[] = $ring;
                    }
                }

                $this->assertSame(
                    $home[$variant['grade']],
                    $rings,
                    "{$variant['key']} is at home in the wrong rings",
                );

                foreach (['outer', 'mid'] as $ring) {
                    $weight = $variant['weights'][$ring];

                    if ($variant['grade'] === 'epic') {
                        $this->assertSame(
                            0.0,
                            (float) $weight,
                            "Tier 3 leaked into the {$ring} ring -- that is a grind->NFT faucet",
                        );

                        continue;
                    }

                    // Everything below Tier 3 is findable everywhere, thinly.
                    $this->assertGreaterThan(
                        0,
                        $weight,
                        "{$variant['key']} is sealed out of the {$ring} ring",
                    );
                    $this->assertLessThanOrEqual(
                        in_array($ring, $home[$variant['grade']], true) ? 1.0 : 0.05,
                        $weight,
                        "{$variant['key']} is a supply in the {$ring} ring, not a lucky find",
                    );
                }
            }
        }

        // §5.3 -- and the Tier 3 rate is still the one Balance names.
        foreach (Catalog::BIOMES as $biome) {
            $epic = Variants::BIOME_VARIANTS[$biome][3];
            $this->assertSame('epic', $epic['grade']);
            $this->assertEqualsWithDelta(
                Balance::RARE_SPAWN_CHANCE,
                $epic['weights']['inner'],
                1e-9,
                'the rare spawn rate drifted off Balance',
            );
        }
    }

    /**
     * §5.3 -- a variant gives up its own material, and the map says which.
     *
     * The Tier 3 rares used to spawn on a tile that looked exactly like the
     * plain biome next to it. Every variant now carries its own tint and its
     * own prop treatment, and no two share a tint.
     */
    public function test_every_variant_has_its_own_material_and_its_own_face(): void
    {
        $materials = [];
        $tints = [];

        foreach (Catalog::BIOMES as $biome) {
            foreach (Variants::BIOME_VARIANTS[$biome] as $variant) {
                $def = Catalog::material($variant['material']);
                $this->assertNotNull($def, "{$variant['key']} gives up a material nothing knows");
                $this->assertContains($def['tier'], [1, 3], "{$variant['key']} gives up a processed material");

                $materials[] = $variant['material'];
                $tints[] = $variant['tint'];

                $this->assertNotSame('', $variant['props'], "{$variant['key']} draws nothing");
                $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $variant['tint']);
            }
        }

        $this->assertCount(20, $materials);
        $this->assertSame($materials, array_unique($materials), 'two variants give up the same material');
        $this->assertSame($tints, array_unique($tints), 'two variants share a tint');
    }

    /**
     * §5.3 -- a grade refines on the same 3:1 the base line does.
     *
     * A better grade is a better material, never a better ratio. Making the
     * good ore also process cheaper would turn one ladder into two, and the
     * second one would be invisible.
     */
    public function test_grade_processing_matches_the_base_ratio(): void
    {
        $this->assertCount(10, Variants::PROCESSING);

        foreach (Variants::PROCESSING as $key => $recipe) {
            $this->assertSame(3, $recipe['inputQty'], "{$key} does not cost three raw");
            $this->assertSame(1, $recipe['outputQty'], "{$key} does not make one");
            $this->assertSame(1, Catalog::materialTier($recipe['input']));
            $this->assertSame(2, Catalog::materialTier($recipe['output']));
            $this->assertContains($recipe['skill'], Catalog::SKILLS);

            // The line that gathers the raw is the line that processes it.
            $this->assertSame(
                Catalog::skillForMaterial($recipe['input']),
                $recipe['skill'],
                "{$key} is processed by the wrong line",
            );
        }
    }

    /**
     * §5.3 -- the ground a hex turns out to be is stable and ring-legal.
     *
     * Terrain is a pure function of (col, row, seed), and the client generates
     * it locally from the same table. A variant that moved between two calls,
     * or one that turned up in a ring its weights forbid, would put the two
     * generators permanently out of step.
     */
    public function test_the_variant_roll_is_stable_and_respects_its_ring(): void
    {
        // §5.2 -- only Tier 3 is ring-gated now. The grades below it leak
        // outward at a few per cent, so "legal" out here is a list of what may
        // NOT turn up rather than of what may.
        $forbidden = ['outer' => ['epic'], 'mid' => ['epic']];
        $seen = [];

        for ($col = 40; $col < 120; $col += 3) {
            for ($row = 40; $row < 120; $row += 3) {
                $tile = WorldGen::generateTile($col, $row, 0);
                if ($tile['material'] === null) {
                    continue;
                }

                $again = WorldGen::generateTile($col, $row, 0);
                $this->assertSame($tile['variant'], $again['variant'], 'the ground moved between two calls');

                $variant = WorldGen::variantOf($col, $row, $tile['biome'], $tile['ring']);
                $this->assertSame($tile['variant'], $variant['key']);
                $this->assertSame($variant['material'], $tile['material']);

                if (isset($forbidden[$tile['ring']])) {
                    $this->assertNotContains(
                        $variant['grade'],
                        $forbidden[$tile['ring']],
                        "a {$variant['grade']} hex turned up in the {$tile['ring']} ring",
                    );
                }

                $seen[$variant['grade']] = true;
            }
        }

        // All four grades have to actually occur, or a weight is effectively zero.
        foreach (Variants::GRADES as $grade) {
            $this->assertArrayHasKey($grade, $seen, "no {$grade} ground anywhere on the map");
        }
    }

    /**
     * §4 -- every component is actually wanted by something.
     *
     * A material nothing asks for is a row in the bag and a line in the shop
     * that pays for neither. The whole reason these ten exist is that the two
     * craft benches stopped borrowing the alchemist's shelf.
     */
    public function test_every_craft_component_feeds_a_recipe(): void
    {
        $wanted = [];
        foreach (Catalog::items() as $def) {
            foreach (array_keys($def['inputs'] ?? []) as $input) {
                $wanted[$input] = true;
            }
        }

        foreach (array_keys(Components::CRAFT) as $key) {
            $this->assertArrayHasKey($key, $wanted, "{$key} feeds no recipe at all");
        }
    }

    /**
     * The crafted recipes are hand-written on both sides, so they can drift.
     *
     * Materials and consumables are generated from one spec and cannot; the
     * crafted items are hand-written on both sides, and a recipe that disagrees
     * between the server and the client shows the player a cost they will not
     * be charged.
     */
    public function test_crafted_recipes_agree_between_php_and_typescript(): void
    {
        // §8.0's top two rungs and §9.5.4's combat gear are generated into their
        // own files, so the client side of the catalog is three rather than one.
        $ts = file_get_contents(base_path('resources/js/game/catalog.ts'))
            .file_get_contents(base_path('resources/js/game/toptier.ts'))
            .file_get_contents(base_path('resources/js/game/battlegear.ts'));
        $checked = 0;

        foreach (Catalog::items() as $key => $def) {
            if (! isset($def['inputs']) || ! empty($def['consumable'])) {
                continue;
            }

            $pattern = "/key: '".preg_quote($key, '/')."',.*?inputs: \{([^}]*)\}/s";
            $this->assertMatchesRegularExpression($pattern, $ts, "{$key} has no recipe in catalog.ts");
            preg_match($pattern, $ts, $m);

            $client = [];
            foreach (explode(',', trim($m[1])) as $pair) {
                [$material, $qty] = array_map('trim', explode(':', $pair));
                $client[$material] = (int) $qty;
            }

            $this->assertSame($def['inputs'], $client, "{$key} costs different things on the two sides");
            $checked++;
        }

        // 37 tools and work-leaning worn gear, plus §9.5.4's 90 combat pieces:
        // six groups, five rungs, three material grades apiece, and every one
        // of them craftable.
        $this->assertSame(127, $checked, 'the crafted list changed size');
    }

    /**
     * §8.5 -- a scoped buff pays out on its own action and on no other.
     *
     * This is the rule that lets sixty potions exist. Without it every one of
     * them would be a flat stat increase and the shelf would be a power ladder.
     */
    public function test_a_scoped_buff_only_counts_on_its_own_action(): void
    {
        // Forest Draft: +3% yield, woodcutting only.
        $this->game->useConsumable($this->giveDrink('forest_draft'), 'forest_draft');

        $wood = $this->game->bonuses($this->character->fresh(), 'woodcutting');
        $iron = $this->game->bonuses($this->character->fresh(), 'mining');

        $this->assertEqualsWithDelta(0.03, $wood['yield'], 0.0001, 'the draft did nothing for its own line');
        $this->assertEqualsWithDelta(0.0, $iron['yield'], 0.0001, 'a woodcutting draft helped a mine');
    }

    /**
     * Same stat, different actions: both are held. Same stat, same action: one
     * charge, and the better draft is the one that counts.
     */
    public function test_two_actions_can_be_charged_at_once_but_one_action_cannot_stack(): void
    {
        $this->game->useConsumable($this->giveDrink('forest_draft'), 'forest_draft');
        $this->game->useConsumable($this->giveDrink('deepseam_draft'), 'deepseam_draft');

        $this->assertSame(2, $this->character->fresh()->buffs()->count(), 'two actions did not both take');
        $this->assertEqualsWithDelta(0.03, $this->game->bonuses($this->character->fresh(), 'woodcutting')['yield'], 0.0001);
        $this->assertEqualsWithDelta(0.03, $this->game->bonuses($this->character->fresh(), 'mining')['yield'], 0.0001);

        // A stronger one of the same kind takes the charge's place rather than
        // adding to it.
        $this->game->useConsumable($this->giveDrink('forest_tonic'), 'forest_tonic');

        $this->assertSame(2, $this->character->fresh()->buffs()->count(), 'the same potion stacked');
        $this->assertEqualsWithDelta(
            Catalog::item('forest_tonic')['value'],
            $this->game->bonuses($this->character->fresh(), 'woodcutting')['yield'],
            0.0001,
            'two drafts on one action added up',
        );
    }

    /**
     * §8.1 rule 1 -- scoping buys more potions, never a higher ceiling.
     *
     * Every buff that lands on one action still feeds that action's single
     * aggregate and is clamped by the same STAT_CEILING as gear and tree nodes.
     */
    public function test_stacking_scoped_potions_on_one_action_still_stops_at_the_ceiling(): void
    {
        // Every rung of the woodcutting-yield ladder at once.
        foreach (['forest_draft', 'forest_tonic', 'forest_flask', 'forest_elixir', 'forest_philtre'] as $key) {
            $character = $this->giveDrink($key);
            CharacterBuff::updateOrCreate(
                ['character_id' => $character->id, 'stat' => 'yield', 'scope' => 'woodcutting'],
                [
                    'item_key' => $key,
                    'value' => Catalog::item($key)['value'],
                ],
            );
        }

        $this->assertLessThanOrEqual(
            Balance::STAT_CEILING,
            $this->game->bonuses($this->character->fresh(), 'woodcutting')['yield'],
            'the potion shelf climbed past the global ceiling',
        );
    }

    /** §8.5 -- travel and processing are actions too, and get their own potions. */
    public function test_the_road_and_the_bench_get_their_own_potions(): void
    {
        $this->game->useConsumable($this->giveDrink('road_tonic'), 'road_tonic');
        $this->game->useConsumable($this->giveDrink('guild_cordial'), 'guild_cordial');

        $road = $this->game->bonuses($this->character->fresh(), 'travel');
        $bench = $this->game->bonuses($this->character->fresh(), 'processing');
        $field = $this->game->bonuses($this->character->fresh(), 'woodcutting');

        $this->assertGreaterThan(0, $road['travelSpeed'], 'the road tonic did nothing on the road');
        $this->assertGreaterThan(0, $bench['processingSpeed'], 'the cordial did nothing at the bench');
        $this->assertEqualsWithDelta(0.0, $field['travelSpeed'], 0.0001, 'a road tonic followed you into the forest');
    }

    /** Put one of a potion on the shelf and hand back the character. */
    private function giveDrink(string $key): Character
    {
        $character = $this->character->fresh();
        $character->consumables()->updateOrCreate(['item_key' => $key], ['quantity' => 1]);

        return $character->fresh();
    }

    /** §8.3 -- the village basic for each line, so a test can just have one. */
    private const STARTER_TOOL = [
        'axe' => 'stone_axe',
        'pickaxe' => 'chipped_pick',
        'bow' => 'crude_bow',
        'hammer' => 'stone_mallet',
        'sickle' => 'bent_sickle',
    ];

    /**
     * Equip the village basic for whichever line the hex underfoot belongs to.
     *
     * Mining wants the line's tool now -- gathering is the bare-handed verb and
     * it is a separate action -- so a test that means to dig has to carry one,
     * and which one depends on where it is standing.
     */
    private function equipToolForHere(): void
    {
        $character = $this->character->fresh();
        $preview = $this->game->previewTile($character, (int) $character->col, (int) $character->row);
        $slot = Catalog::slotForSkill($preview['skill'] ?? 'woodcutting');
        $key = self::STARTER_TOOL[$slot];

        CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => $key,
            'durability' => Catalog::item($key)['maxDurability'],
            'equipped' => true,
            'options' => [],
        ]);

        $this->character = $this->character->fresh();
    }

    /** Equip a working bow, so the hunting line has its §8.0 tool. */
    private function equipBow(): void
    {
        CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => 'crude_bow',
            'durability' => 200,
            'equipped' => true,
            'options' => [],
        ]);
    }

    /**
     * §5.5 / §7.3 -- a herd is a pile of work, and the bow is what gets through
     * it.
     *
     * It was a flat twenty-five minutes for as long as §7.3's clamp would have
     * rounded any difference away. The floor is a guard now, so the hunting
     * line has the same ladder every other line has: the crude bow is the
     * reference mine and everything above it is felt on the clock.
     */
    public function test_a_better_bow_works_a_herd_faster(): void
    {
        [$col, $row] = $this->standOnAHerd();

        CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => 'crude_bow',
            'durability' => 200,
            'equipped' => true,
            'options' => [],
        ]);

        $crude = $this->game->previewHunt($this->character->fresh(), $col, $row);
        $this->assertTrue($crude['canHunt'], $crude['reason'] ?? '');

        // A crude bow on a herd is twenty-five minutes, which is what a hunt
        // has always cost -- the same yardstick a hex's HP was set by.
        $this->assertSame(25 * 60, $crude['seconds']);
        $this->assertSame(Balance::HERD_HP, $crude['hp']);
        $this->assertTrue($crude['able']);

        CharacterItem::where('character_id', $this->character->id)->delete();
        CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => 'recurve_bow',
            'durability' => 200,
            'equipped' => true,
            'options' => [],
        ]);

        $recurve = $this->game->previewHunt($this->character->fresh(), $col, $row);
        $this->assertGreaterThan($crude['toolAttack'], $recurve['toolAttack']);
        $this->assertLessThan($crude['seconds'], $recurve['seconds']);
        $this->assertFalse($recurve['clamped']);
    }

    /**
     * §5.5 -- a herd pays pelt for a bow, and no Tier 4 for anybody.
     *
     * Essence used to be a line in this table. It is raid loot: a herd on a
     * four-hour clock that anyone with a crude bow can shoot would be a faucet
     * for the one tier the dungeons exist to gate, and §9.4's ladder is
     * supposed to end at a boss rather than at a deer.
     */
    public function test_a_herd_pays_pelt_and_never_pays_tier_four(): void
    {
        [$col, $row] = $this->standOnAHerd();
        $this->equipBow();

        $preview = $this->game->previewHunt($this->character->fresh(), $col, $row);
        $this->assertTrue($preview['canHunt'], $preview['reason'] ?? '');
        $this->assertSame('pelt', $preview['material']);
        $this->assertFalse($preview['scrap']);

        // §4 -- the card names what this ground can give up, pelt leading it.
        $this->assertSame('pelt', $preview['drops'][0]);

        $pelt = 0;
        for ($i = 0; $i < 40; $i++) {
            $job = $this->game->startHunt($this->character->fresh(), $col, $row);
            $job->update(['ends_at' => $this->game->now() - 1]);

            $result = $this->game->collectJob($this->character->fresh(), $job->id);
            $pelt += $result['gained']['pelt'] ?? 0;

            // §4 -- the haul splits, but never past the strap budget.
            $this->assertLessThanOrEqual(Drops::MAX_KINDS, count($result['gained']));
        }

        // Pelt is the heaviest line in the table, so over forty hunts it
        // dominates.
        $this->assertGreaterThan(0, $pelt, 'forty hunts produced no pelt at all');
    }

    /**
     * Tier 4 is raid loot, and there is no back door onto the map.
     *
     * Not one of the three activities may pay it, on any ground, at any grade:
     * a bow and a wandering marker must never stand in for a dungeon floor.
     */
    public function test_no_activity_on_the_map_pays_a_raid_material(): void
    {
        $raid = [];
        foreach (Catalog::materials() as $key => $def) {
            if ($def['tier'] === 4) {
                $raid[] = $key;
            }
        }
        $this->assertNotEmpty($raid, 'no Tier 4 materials to guard');

        foreach (Catalog::BIOMES as $biome) {
            foreach ([0, 1, 2, 3] as $grade) {
                $tile = $this->tileOfGrade($biome, $grade);

                foreach ([
                    Drops::MINING,
                    Drops::GATHERING,
                    Drops::HUNTING,
                ] as $activity) {
                    $table = Drops::table($activity, $tile, $grade);

                    foreach ($raid as $key) {
                        $this->assertArrayNotHasKey(
                            $key,
                            $table,
                            "{$activity} on {$biome} grade {$grade} pays {$key}",
                        );
                    }
                }
            }
        }
    }

    /**
     * A hunt is a mine, and the client has to be able to see one.
     *
     * The payload used to branch on 'mining' alone, so a hunting job came back
     * shaped like a processing job -- no hex, no material, and matching neither
     * of the client's two job selectors. The result was a character pinned to a
     * finished hunt with no Claim anywhere on screen and every other action
     * refused for a job that was, as far as the UI knew, not running.
     */
    public function test_a_hunting_job_is_reported_as_a_trip_on_a_hex(): void
    {
        [$col, $row] = $this->standOnAHerd();
        $this->equipBow();

        $job = $this->game->startHunt($this->character->fresh(), $col, $row);
        $payload = $this->game->jobPayload($job->fresh());

        $this->assertSame('hunting', $payload['kind']);
        $this->assertSame($col, $payload['col']);
        $this->assertSame($row, $payload['row']);
        $this->assertSame('pelt', $payload['material']);
        $this->assertArrayNotHasKey('recipeKey', $payload);

        // §5.5 -- a herd is not one of the hex's two seats.
        $this->assertNull($payload['slot']);
    }

    /**
     * §5.5 / §8.0 -- a hunt is the one thing bare hands cannot do at all.
     *
     * Every other line has a bare-handed floor, because §4.0 says a hex is
     * never blocked for want of a tool: you work it and you get scrap. A herd
     * is not a hex. You do not take an animal down by hand, so the bow is the
     * single tool in the game with a refusal behind it -- and that refusal is
     * what keeps the one Tier 4 faucet outside a dungeon shut to the toolless.
     */
    public function test_a_hunt_is_refused_without_a_bow(): void
    {
        [$col, $row] = $this->standOnAHerd();

        $preview = $this->game->previewHunt($this->character->fresh(), $col, $row);
        $this->assertFalse($preview['canHunt']);
        $this->assertStringContainsString('bow', strtolower((string) $preview['reason']));

        try {
            $this->game->startHunt($this->character->fresh(), $col, $row);
            $this->fail('a bare-handed hunt was allowed');
        } catch (GameException $e) {
            $this->assertStringContainsString('bow', strtolower($e->getMessage()));
        }

        // And the hex itself is still workable by hand, §4.0 -- it is the hunt
        // that is refused, never the ground.
        $tile = $this->game->previewGather($this->character->fresh(), $col, $row);
        $this->assertTrue($tile['canMine']);
        $this->assertTrue($tile['scrap']);
        $this->assertSame('gathering', $tile['activity']);
    }

    /** §5.5 -- a herd is not a seam: no slot taken, nothing depleted. */
    public function test_hunting_takes_no_slot_and_depletes_nothing(): void
    {
        [$col, $row] = $this->standOnAHerd();
        $this->equipBow();

        $job = $this->game->startHunt($this->character->fresh(), $col, $row);

        $this->assertNull($job->slot);
        $this->assertSame(0, $this->game->buildTile($col, $row, $this->game->now())['slotsUsed'] ?? 0);

        // Both mining seats are still free to other players while the hunt runs.
        $others = [];
        foreach (['0xa', '0xb'] as $wallet) {
            $other = $this->game->createCharacter(Player::create(['wallet' => $wallet, 'session_id' => $wallet]));
            $other->update(['col' => $col, 'row' => $row]);
            $others[] = $this->game->startMining($other->fresh(), $col, $row, Drops::GATHERING);
        }
        $this->assertCount(2, $others);

        // And collecting the hunt takes nothing off the seam: a herd is not a
        // hex, and it leaves on its own clock (§5.5).
        $job->update(['ends_at' => $this->game->now() - 1]);
        $this->game->collectJob($this->character->fresh(), $job->id);
        $this->assertSame(0, Tiles::state($col, $row)['taken']);
    }

    /** A person is in one place: a hunt blocks a dig, and a dig blocks a hunt. */
    public function test_a_hunt_and_a_dig_exclude_each_other(): void
    {
        [$col, $row] = $this->standOnAHerd();
        $this->equipBow();

        $this->game->startHunt($this->character->fresh(), $col, $row);
        $this->assertFalse($this->game->previewGather($this->character->fresh(), $col, $row)['canMine']);

        try {
            $this->game->startMining($this->character->fresh(), $col, $row, Drops::GATHERING);
            $this->fail('dug a hex while already hunting it');
        } catch (GameException $e) {
            $this->assertSame('blocked', $e->errorCode);
        }
    }

    /** §5.5 -- herds wander. A hex without one cannot be hunted. */
    public function test_a_hex_with_no_herd_cannot_be_hunted(): void
    {
        // Which hexes carry a herd depends on the clock (§5.5 buckets on now),
        // so stand on one that has none rather than assuming spawn is empty.
        $now = $this->game->now();
        $bare = null;
        for ($d = 1; $d < 40 && $bare === null; $d++) {
            $col = (int) $this->character->col + $d;
            $row = (int) $this->character->row;
            if (($this->game->buildTile($col, $row, $now)['herdUntil'] ?? null) === null) {
                $bare = [$col, $row];
            }
        }
        $this->assertNotNull($bare, 'no herd-free hex within forty columns');

        [$col, $row] = $bare;
        $this->character->update(['col' => $col, 'row' => $row]);

        $preview = $this->game->previewHunt($this->character->fresh(), $col, $row);
        $this->assertFalse($preview['canHunt']);
        $this->assertStringContainsString('No herd', $preview['reason']);

        try {
            $this->game->startHunt($this->character->fresh(), $col, $row);
            $this->fail('hunted a hex with no herd on it');
        } catch (GameException $e) {
            $this->assertSame('blocked', $e->errorCode);
        }
    }

    // ------------------------------------------------------- rich ground §5.7

    /** Stand on a hex whose ground is having a good few hours. */
    private function standOnAPocket(): array
    {
        $now = $this->game->now();

        for ($col = -Balance::mapRadius(); $col <= Balance::mapRadius(); $col++) {
            for ($row = -Balance::mapRadius(); $row <= Balance::mapRadius(); $row++) {
                $tile = $this->game->buildTile($col, $row, $now);
                if (($tile['pocketUntil'] ?? null) !== null && $tile['pocketUntil'] > $now) {
                    $this->character->update(['col' => $col, 'row' => $row]);
                    $this->character->refresh();

                    return [$col, $row];
                }
            }
        }

        $this->fail('no rich ground anywhere on the map');
    }

    /**
     * §5.7 -- a pocket pays half again on the haul, and NOTHING on the clock.
     *
     * §7.3 keeps the two apart on purpose: yield is how big the haul is and
     * attack is how fast it comes out. A pocket that also shortened the mine
     * would be a second answer to a question the tool already answers.
     */
    public function test_rich_ground_pays_more_and_takes_no_less_time(): void
    {
        [$col, $row] = $this->standOnAPocket();
        $character = $this->character->fresh();

        $rich = $this->game->previewTile($character, $col, $row);
        $this->assertNotNull($rich['pocketUntil']);

        // The same hex costed as though the pocket had closed. Everything else
        // about the tile is a pure function of (col, row, seed), so this is the
        // one variable moving.
        $plain = Formulas::mineYield(
            $this->game->buildTile($col, $row, $this->game->now())['baseYield'],
            (int) ($character->skills()->where(
                'skill_key',
                Catalog::skillForMaterial($rich['material']),
            )->value('level') ?? 1),
            0.0,
            WorldGen::ringYield(WorldGen::ringOf($col, $row)),
        );

        $this->assertGreaterThan($plain, $rich['yield'], 'rich ground paid no more');
        $this->assertSame(
            $rich['seconds'],
            $this->game->previewTile($character, $col, $row)['seconds'],
        );
    }

    /**
     * §4.0 -- and it counts bare-handed, because scrap is the same haul size at
     * a fraction of the worth. A bonus you need a tool to collect would miss
     * the whole of §12's opening arc, which is worked by hand.
     */
    public function test_rich_ground_counts_for_bare_hands_too(): void
    {
        [$col, $row] = $this->standOnAPocket();

        $gather = $this->game->previewGather($this->character->fresh(), $col, $row);

        $this->assertNotNull($gather['pocketUntil']);
        $this->assertTrue($gather['yield'] > 0);
    }

    /**
     * §5.7 -- a pocket is the GROUND, and a herd is not standing in it.
     *
     * The herd walked here and pays out of its own table (§5.5); the ground
     * being good today has nothing to do with the animal on top of it.
     */
    public function test_rich_ground_does_not_reach_a_herd(): void
    {
        [$col, $row] = $this->standOnAHerd();

        $hunt = $this->game->previewHunt($this->character->fresh(), $col, $row);

        $this->assertArrayNotHasKey('pocketUntil', $hunt, 'a hunt was costed against the ground');
    }

    /**
     * §5.7 -- nothing to work, no pocket. Water, dead ground and a settlement
     * all fall out of that one test rather than needing three of their own.
     */
    public function test_rich_ground_never_lands_where_there_is_nothing_to_work(): void
    {
        $now = $this->game->now();
        $checked = 0;

        for ($col = -Balance::mapRadius(); $col <= Balance::mapRadius(); $col += 3) {
            for ($row = -Balance::mapRadius(); $row <= Balance::mapRadius(); $row += 3) {
                $tile = WorldGen::generateTile($col, $row, $now);
                if ($tile['material'] !== null) {
                    continue;
                }

                $checked++;
                $this->assertNull(
                    $tile['pocketUntil'],
                    "rich ground on a hex with no seam at {$col},{$row}",
                );
            }
        }

        $this->assertGreaterThan(100, $checked, 'swept no unworkable ground at all');
    }

    /** §5.6 -- a herd is live state, so it is bounded by the sight disc. */
    public function test_a_herd_outside_sight_will_not_be_costed(): void
    {
        [$col, $row] = $this->standOnAHerd();

        // Half a map away in both axes, so this is well outside any sight the
        // Explorer tree can reach. Mirroring through the origin is the simplest
        // way to be far from a herd wherever the scan above happened to find it.
        $this->character->update([
            'col' => -$col === $col ? Balance::mapRadius() : -$col,
            'row' => -$row === $row ? Balance::mapRadius() : -$row,
        ]);

        $preview = $this->game->previewHunt($this->character->fresh(), $col, $row);
        $this->assertTrue($preview['unseen']);
        $this->assertNull($preview['herdUntil']);
        $this->assertFalse($preview['canHunt']);
    }

    private function give(array $materials): void
    {
        $add = new ReflectionMethod($this->game, 'addMaterial');
        foreach ($materials as $key => $qty) {
            $add->invoke($this->game, $this->character->fresh(), $key, $qty);
        }
    }

    /** Buy nodes directly, for tests that care about the effect not the gate. */
    private function grantNodes(array $keys): void
    {
        foreach ($keys as $key) {
            CharacterNode::create([
                'character_id' => $this->character->id,
                'node_key' => $key,
            ]);
        }
    }

    public function test_every_job_exists_from_the_start_at_level_one(): void
    {
        // Five processing lines, three benches, three battle roles, and the
        // road (§7.5). The five gathering lines have no row: their level is the
        // CharacterSkill one.
        $this->assertCount(12, $this->character->jobLevels()->get());

        foreach ($this->character->jobLevels()->get() as $job) {
            $this->assertSame(1, $job->level, "{$job->job_key} did not start at level 1");
            $this->assertSame(0, $job->xp);
        }
    }

    /**
     * §7.2 as a job -- the level is the skill level, not a second number.
     *
     * Working a forest raises Woodcutting, and that same figure is what gates
     * the Woodcutting tree. Two numbers would be two things to keep in step.
     */
    public function test_a_gathering_job_reads_the_skill_level_it_already_had(): void
    {
        $this->character->skills()->where('skill_key', 'woodcutting')->update(['level' => 13]);

        $levels = new ReflectionMethod($this->game, 'jobLevels');
        $this->assertSame(13, $levels->invoke($this->game, $this->character->fresh())['woodcutting']);

        // And no second row was invented to hold it.
        $this->assertFalse(
            $this->character->jobLevels()->where('job_key', 'woodcutting')->exists(),
            'a gathering job grew its own level row alongside the skill',
        );
    }

    /**
     * §8 rule 1, applied to trees. A Woodcutting node pays out in a forest and
     * nowhere else, exactly as an axe does.
     *
     * Without this, three gathering trees would stack yield on every mine at
     * once -- which is precisely the shortcut the line-locked tool ladder is
     * built to prevent.
     */
    public function test_a_gathering_node_only_counts_on_its_own_line(): void
    {
        $this->character->level = Balance::MAX_LEVEL;
        $this->character->save();

        // Every yield node the Woodcutting tree carries.
        $keys = [];
        foreach (Jobs::nodesFor('woodcutting') as $key => $node) {
            if ($node['effect']['kind'] === 'stat' && $node['effect']['stat'] === 'yield') {
                $keys[] = $key;
            }
        }
        $this->assertNotEmpty($keys);
        $this->grantNodes($keys);

        $fresh = $this->character->fresh();
        $inForest = $this->game->bonuses($fresh, 'woodcutting')['yield'];
        $inMountain = $this->game->bonuses($fresh, 'mining')['yield'];
        $anywhere = $this->game->bonuses($fresh)['yield'];

        $this->assertGreaterThan(0, $inForest, 'a full woodcutting tree did nothing in a forest');
        $this->assertSame(0.0, $inMountain, 'woodcutting nodes paid out on a mountain seam');
        $this->assertSame(0.0, $anywhere, 'woodcutting nodes paid out with no line in mind');
    }

    /** §7.4.1 -- points are levels, spent is counted from the rows themselves. */
    public function test_points_come_from_levels_and_spending_is_counted_not_stored(): void
    {
        $this->character->level = 12;
        $this->character->save();

        $points = $this->game->skillPoints($this->character->fresh());
        $this->assertSame(12, $points['total']);
        $this->assertSame(0, $points['spent']);
        $this->assertSame(12, $points['available']);

        $this->grantNodes(['smith.whetstone_round', 'smith.cold_shut_eye']);

        $points = $this->game->skillPoints($this->character->fresh());
        $this->assertSame(2, $points['spent']);
        $this->assertSame(10, $points['available']);
    }

    public function test_a_tier_one_node_is_buyable_immediately(): void
    {
        $this->character->level = 3;
        $this->character->save();

        $result = $this->game->buyNode($this->character->fresh(), 'smith.hammer_sense');

        $this->assertSame('smith.hammer_sense', $result['node']);
        $this->assertSame(1, $result['points']['spent']);
        $this->assertTrue(
            $this->character->fresh()->nodes()->where('node_key', 'smith.hammer_sense')->exists(),
        );
    }

    public function test_a_node_cannot_be_bought_twice(): void
    {
        $this->character->level = 5;
        $this->character->save();
        $this->game->buyNode($this->character->fresh(), 'smith.hammer_sense');

        try {
            $this->game->buyNode($this->character->fresh(), 'smith.hammer_sense');
            $this->fail('bought the same node twice');
        } catch (GameException $e) {
            $this->assertSame('owned', $e->errorCode);
        }
    }

    public function test_buying_needs_a_point_to_spend(): void
    {
        // Level 1 grants exactly one point; spend it, then try again.
        $this->game->buyNode($this->character->fresh(), 'smith.hammer_sense');

        try {
            $this->game->buyNode($this->character->fresh(), 'smith.cold_shut_eye');
            $this->fail('bought a node with no points left');
        } catch (GameException $e) {
            $this->assertSame('no_points', $e->errorCode);
        }
    }

    /** §7.4.1 -- a job level is a gate, and it cannot be bought past. */
    public function test_a_deep_node_needs_the_job_level(): void
    {
        $this->character->level = 60;
        $this->character->save();
        $this->grantNodes(['smith.hammer_sense']);

        try {
            $this->game->buyNode($this->character->fresh(), 'smith.anvil_song');
            $this->fail('reached a tier 2 node at job level 1');
        } catch (GameException $e) {
            $this->assertSame('job_level', $e->errorCode);
        }

        $this->setJobLevel('smith', 5);
        $result = $this->game->buyNode($this->character->fresh(), 'smith.anvil_song');
        $this->assertSame('smith.anvil_song', $result['node']);
    }

    /** §7.4.2 -- and the parent has to be owned, whatever the job level says. */
    public function test_a_node_needs_its_parent_first(): void
    {
        $this->character->level = 60;
        $this->character->save();
        $this->setJobLevel('smith', Balance::JOB_MAX_LEVEL);

        try {
            $this->game->buyNode($this->character->fresh(), 'smith.anvil_song');
            $this->fail('bought a tier 2 node with no parent');
        } catch (GameException $e) {
            $this->assertSame('requires', $e->errorCode);
        }
    }

    /** §7.4.2 -- a capstone takes two parents, which is what makes it a tree. */
    public function test_a_capstone_needs_both_of_its_parents(): void
    {
        $this->character->level = 100;
        $this->character->save();
        $this->setJobLevel('smith', Balance::JOB_MAX_LEVEL);

        $capstone = Jobs::node('smith.the_named_blade');
        $this->assertCount(2, $capstone['requires']);

        $this->grantNodes([$capstone['requires'][0]]);

        try {
            $this->game->buyNode($this->character->fresh(), 'smith.the_named_blade');
            $this->fail('bought a capstone with only one parent');
        } catch (GameException $e) {
            $this->assertSame('requires', $e->errorCode);
        }

        $this->grantNodes([$capstone['requires'][1]]);
        $this->game->buyNode($this->character->fresh(), 'smith.the_named_blade');
        $this->assertTrue(
            $this->character->fresh()->nodes()->where('node_key', 'smith.the_named_blade')->exists(),
        );
    }

    /** §7.4 -- the bench that made it is the job that learns from it. */
    public function test_crafting_teaches_the_job_whose_bench_made_it(): void
    {
        $this->standAtWoodcuttingVillage();
        $this->give(['wood' => 12, 'planks' => 8, 'heartknot' => 8]);

        $before = $this->character->jobLevels()->where('job_key', 'smith')->first()->xp;
        $this->craftNow('hewn_axe');
        $after = $this->character->fresh()->jobLevels()->where('job_key', 'smith')->first()->xp;

        $this->assertGreaterThan($before, $after, 'forging an axe taught the Smith nothing');

        // And nobody else's job moved.
        foreach (['armorer', 'alchemist'] as $other) {
            $this->assertSame(
                0,
                $this->character->fresh()->jobLevels()->where('job_key', $other)->first()->xp,
                "{$other} learned from a weapon craft",
            );
        }
    }

    /**
     * §7.4 -- the battle jobs are dormant, and dormancy has to be enforced.
     *
     * They level by raiding and by nothing else. If mining or crafting could
     * move them, combat would become optional -- which is exactly the hole the
     * empty `weapon` slot and these three trees are being careful about.
     */
    public function test_nothing_outside_a_raid_can_level_a_battle_job(): void
    {
        $this->standAtWoodcuttingVillage();
        $this->give(['wood' => 12, 'fiber' => 12, 'planks' => 8, 'cloth' => 8, 'leather' => 8, 'heartknot' => 8, 'beeswax' => 8]);

        $this->craftNow('hewn_axe');
        $this->craftNow('work_gloves');

        // Settlements sit on worked ground, so step off it before digging.
        $open = $this->openNeighbor($this->character->col, $this->character->row);
        $this->character->update($open);
        $this->character->refresh();

        $job = $this->game->startMining($this->character->fresh(), $open['col'], $open['row'], Drops::GATHERING);
        $job->update(['ends_at' => $this->game->now() - 1]);
        $this->game->collectJob($this->character->fresh(), $job->id);

        foreach (['shieldbearer', 'swordhand', 'runecaster'] as $battle) {
            $row = $this->character->fresh()->jobLevels()->where('job_key', $battle)->first();
            $this->assertSame(1, $row->level, "{$battle} levelled without a raid");
            $this->assertSame(0, $row->xp, "{$battle} earned XP without a raid");
        }
    }

    /**
     * §8.1 rule 1, and the reason 90 skill points are allowed to exist at all.
     *
     * Gear, potions and bought nodes are three roads to the same +15%. This
     * buys every stat node in a tree, drinks on top, and asserts the total is
     * still clamped -- because clamping the tree separately and adding it after
     * would let two clamped halves total 30%.
     */
    public function test_a_full_tree_plus_gear_plus_a_potion_still_stops_at_the_ceiling(): void
    {
        $this->character->level = Balance::MAX_LEVEL;
        $this->character->save();

        // Every node in the tree that carries `yield` on this line, bought
        // outright. §7.4 locks a stat node to its own class, so the tree that
        // pays out on a woodcutting mine is Woodcutting's and no other.
        $keys = [];
        foreach (['woodcutting'] as $job) {
            foreach (array_keys(Jobs::nodesFor($job)) as $key) {
                $keys[] = $key;
            }
        }
        $this->grantNodes($keys);

        // Best-in-slot on the line, plus a running potion on the same stat.
        CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => 'ironwood_axe',
            'durability' => 200,
            'equipped' => true,
            'options' => [['stat' => 'yield', 'value' => 0.03]],
        ]);
        $this->character->buffs()->create([
            'item_key' => 'forest_draft',
            'stat' => 'yield',
            'scope' => 'woodcutting',
            'value' => 0.03,
        ]);

        $bonuses = $this->game->bonuses($this->character->fresh(), 'woodcutting');

        foreach ($bonuses as $stat => $value) {
            $this->assertLessThanOrEqual(
                Balance::STAT_CEILING + 1e-9,
                $value,
                sprintf('%s reached %.4f, past the +15%% ceiling', $stat, $value),
            );
        }

        // And the ceiling is actually being pressed against, not missed by luck.
        $this->assertEqualsWithDelta(Balance::STAT_CEILING, $bonuses['yield'], 1e-9);
    }

    /**
     * §7.4.3 -- a discount is a discount, never a hole. Cost reduction must not
     * take any input to zero, or the §11 materials sink stops draining.
     */
    public function test_cost_reduction_never_makes_a_craft_free(): void
    {
        $this->character->level = Balance::MAX_LEVEL;
        $this->character->save();
        $this->standAtWoodcuttingVillage();

        // Every cost node the Armorer tree has. It is the bench that leans on
        // the discount -- a Smith's tree spends most of itself on what comes
        // off the anvil instead, which is what makes the two different trees.
        $keys = [];
        foreach (Jobs::nodesFor('armorer') as $key => $node) {
            if ($node['effect']['kind'] === 'costReduction') {
                $keys[] = $key;
            }
        }
        $this->grantNodes($keys);

        $this->give(['fiber' => 40, 'cloth' => 40, 'beeswax' => 40]);
        $before = $this->game->held($this->character->fresh(), 'fiber');
        $this->craftNow('work_gloves');
        $spent = $before - $this->game->held($this->character->fresh(), 'fiber');

        $this->assertGreaterThan(0, $spent, 'a maxed Armorer crafted out of thin air');
        $this->assertLessThan(6, $spent, 'the discount did nothing at all');
    }

    /**
     * §4.0 -- the tool is the difference between a haul and a pile of junk.
     * This is what the opening arc's first three quests exist to teach, and what
     * makes the first 12 gold worth spending.
     */
    public function test_bare_hands_bring_back_scrap_and_the_tool_brings_back_the_material(): void
    {
        $col = $this->character->col;
        $row = $this->character->row;

        // Mining is the tool's verb, so with an empty belt it is the one that
        // is refused -- and it names the tool that would answer it.
        $mine = $this->game->previewTile($this->character, $col, $row);
        $this->assertFalse($mine['canMine'], 'mined a hex with nothing in hand');
        $this->assertTrue($mine['bare']);
        $this->assertStringContainsString('No axe', $mine['reason']);

        // §4.0 -- and the hex is still not blocked, because the other verb is
        // standing right beside it and needs nothing at all.
        $bare = $this->game->previewGather($this->character, $col, $row);
        $this->assertTrue($bare['scrap']);
        $this->assertSame('branch', $bare['material']);
        $this->assertSame('woodcutting', $bare['skill'], 'a scrap haul left its own line');
        $this->assertTrue($bare['canMine'], 'bare hands were refused the hex outright');

        // Scrap is worth strictly less than what the hex really holds.
        $this->assertLessThan(
            Catalog::material('wood')['npcPrice'],
            Catalog::material('branch')['npcPrice'],
        );

        CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => 'stone_axe',
            'durability' => Catalog::item('stone_axe')['maxDurability'],
            'equipped' => true,
        ]);

        $armed = $this->game->previewTile($this->character->fresh(), $col, $row);
        $this->assertTrue($armed['canMine']);
        $this->assertFalse($armed['scrap']);
        $this->assertSame('wood', $armed['material']);
        $this->assertNull($armed['note']);

        // The axe does not take gathering away: it is the floor, not a fallback.
        $this->assertTrue($this->game->previewGather($this->character->fresh(), $col, $row)['canMine']);
    }

    /** §8.2 -- a snapped axe is not an axe, and it says so in those words. */
    public function test_a_broken_tool_counts_as_no_tool(): void
    {
        CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => 'stone_axe',
            'durability' => 0,
            'equipped' => true,
        ]);

        $col = (int) $this->character->col;
        $row = (int) $this->character->row;

        $mine = $this->game->previewTile($this->character->fresh(), $col, $row);
        $this->assertFalse($mine['canMine']);
        $this->assertTrue($mine['bare']);

        // Broken is not missing: the answer is a repair, and a refusal saying
        // "no axe" to someone wearing one reads as a bug rather than a rule.
        $this->assertStringContainsString('broken', $mine['reason']);

        $bare = $this->game->previewGather($this->character->fresh(), $col, $row);
        $this->assertTrue($bare['canMine']);
        $this->assertTrue($bare['scrap']);
        $this->assertSame('branch', $bare['material']);
    }

    /**
     * §4.0 -- gathering is a verb of its own, and it is never refused.
     *
     * The floor under the §8.0 ladder only works if it is always there: mining
     * asks for the line's tool and says so, and this is the answer standing
     * next to that refusal. An axe on the belt does not take it away either --
     * it is the floor, not a fallback.
     */
    public function test_gathering_needs_no_tool_and_is_never_taken_away(): void
    {
        $col = (int) $this->character->col;
        $row = (int) $this->character->row;

        $job = $this->game->startMining($this->character, $col, $row, Drops::GATHERING);
        $this->assertSame('mining', $job->kind);
        $this->assertTrue(Catalog::isScrap((string) $job->material_key));

        $job->update(['ends_at' => $this->game->now() - 1]);
        $result = $this->game->collectJob($this->character->fresh(), $job->id);

        // Rubbish and what grows here, and the real material only by luck.
        $this->assertNotEmpty($result['gained']);
        $this->assertArrayNotHasKey('hardwood', $result['gained']);

        // And with a tool on the belt it is still on offer.
        //
        // Depletion is the one refusal that may stand here: §4.0 promises a hex
        // is never blocked for want of a tool, and says nothing about ground
        // that has just been worked out. The haul above sometimes empties the
        // tile, so the assertion is about WHY it was refused, not whether.
        $this->equipToolForHere();
        $preview = $this->game->previewGather($this->character->fresh(), $col, $row);
        $this->assertTrue(
            $preview['canMine'] || str_contains((string) $preview['reason'], 'Depleted'),
            'gathering was refused for something other than worked-out ground: '
                .json_encode($preview),
        );
    }

    /**
     * §8.2 -- a bow at zero durability is not a bow.
     *
     * Hunting is the one verb with no bare-handed floor beneath it (§5.5), so
     * this is the only place in the game where broken gear closes an action
     * outright rather than dropping it to the un-geared rate.
     */
    public function test_a_broken_bow_cannot_hunt(): void
    {
        [$col, $row] = $this->standOnAHerd();

        CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => 'crude_bow',
            'durability' => 0,
            'equipped' => true,
            'options' => [],
        ]);

        $preview = $this->game->previewHunt($this->character->fresh(), $col, $row);
        $this->assertFalse($preview['canHunt']);

        try {
            $this->game->startHunt($this->character->fresh(), $col, $row);
            $this->fail('hunted with a snapped bow');
        } catch (GameException $e) {
            $this->assertStringContainsString('bow', strtolower($e->getMessage()));
        }

        // The herd is still standing there: it is the bow that failed, not the
        // marker, so repairing it is enough.
        $this->assertNotNull($preview['herdUntil']);
    }

    /**
     * The dock costs all three verbs in one snapshot.
     *
     * Every cell on it has to be able to say what it would give before it is
     * pressed, and doing that in three requests inside a seven-tile sight disc
     * would be three times the traffic for one hex.
     */
    public function test_the_state_snapshot_costs_every_verb_on_the_hex(): void
    {
        $underfoot = $this->game->playerState($this->character)['underfoot'];

        $this->assertSame('mining', $underfoot['activity']);
        $this->assertSame('gathering', $underfoot['gather']['activity']);
        $this->assertArrayHasKey('canHunt', $underfoot['hunt']);

        // Bare belt: the tool's verb is shut and the other one is open.
        $this->assertFalse($underfoot['canMine']);
        $this->assertTrue($underfoot['bare']);
        $this->assertTrue($underfoot['gather']['canMine']);
    }

    /** §4.0 -- scrap reaches nothing. It is not an input to any recipe. */
    public function test_scrap_feeds_no_recipe_and_undercuts_every_raw_material(): void
    {
        $rawPrices = [];
        foreach (Catalog::BIOME_MATERIAL as $key) {
            $rawPrices[] = Catalog::material($key)['npcPrice'];
        }

        foreach (Catalog::BIOME_SCRAP as $biome => $key) {
            $def = Catalog::material($key);
            $this->assertNotNull($def, "{$biome} has no scrap");
            $this->assertSame(0, $def['tier']);
            $this->assertGreaterThan(0, $def['npcPrice'], 'the trader refuses scrap');
            $this->assertLessThan(min($rawPrices), $def['npcPrice'], "{$key} is worth as much as a raw material");

            foreach (Catalog::recipes() as $recipe) {
                $this->assertNotContains($key, [$recipe['input'], $recipe['secondInput'] ?? null]);
            }
            foreach (Catalog::items() as $item) {
                $this->assertArrayNotHasKey($key, $item['inputs'] ?? []);
            }
        }
    }

    /** You work the hex under your feet, never one across the valley. */
    public function test_mining_requires_standing_on_the_tile(): void
    {
        $this->equipToolForHere();
        $now = $this->game->now();

        // A workable neighbor: close enough to walk to, not where we stand.
        $target = null;
        foreach ([[1, 0], [0, 1], [-1, 0], [0, -1], [1, 1], [-1, -1]] as [$dc, $dr]) {
            $col = $this->character->col + $dc;
            $row = $this->character->row + $dr;
            if ($this->game->buildTile($col, $row, $now)['material'] !== null) {
                $target = [$col, $row];
                break;
            }
        }
        $this->assertNotNull($target, 'spawn has no workable neighbor to test against');
        [$col, $row] = $target;

        $preview = $this->game->previewTile($this->character, $col, $row);
        $this->assertFalse($preview['canMine']);
        $this->assertStringContainsString('standing elsewhere', $preview['reason']);

        // Still a scouting report: the haul and the mine are known from afar,
        // which is what makes the travel decision worth anything.
        $this->assertGreaterThan(0, $preview['seconds']);
        $this->assertGreaterThan(0, $preview['yield']);

        try {
            $this->game->startMining($this->character, $col, $row);
            $this->fail('mined a hex the character was not standing on');
        } catch (GameException $e) {
            $this->assertSame('blocked', $e->errorCode);
        }

        // Walk there, and once the journey lands the same hex becomes workable.
        $this->game->travelTo($this->character, $col, $row);
        $this->arrive($this->character);
        $this->equipToolForHere();
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
            $this->game->startMining($other, $col, $row, Drops::GATHERING);
        }

        $preview = $this->game->previewTile($this->character, $col, $row);
        $this->assertFalse($preview['canMine']);
        $this->assertStringContainsString('Both slots', $preview['reason']);
    }

    /** §7.3 -- the clamp is a guard, and a guard that binds is a bug. */
    public function test_trip_time_never_drops_below_the_guard(): void
    {
        // Best tool in the game, maxed skill, best-in-slot gear on the hardest
        // hex: nowhere near the guard, so the ladder has somewhere left to go.
        $best = Formulas::toolAttack(Catalog::item('mythril_pickaxe'));
        $mine = Formulas::mineTime(
            Balance::TILE_HP_MAX,
            Balance::SKILL_MAX_LEVEL,
            Balance::STAT_CEILING,
            $best,
        );
        $this->assertGreaterThan(Balance::MINING_FLOOR_SECONDS, $mine['total']);
        $this->assertFalse($mine['clamped'], 'the top of the tool ladder is wasted');

        // Even absurd inputs cannot breach it.
        $absurd = Formulas::mineTime(Balance::TILE_HP_MAX, 9999, 99.0, 9999);
        $this->assertSame(Balance::MINING_FLOOR_SECONDS, $absurd['total']);
        $this->assertTrue($absurd['clamped']);
    }

    /**
     * §7.3 -- the line skill is floor(level / 10), and bare hands are that plus
     * BARE_HAND_ATTACK because gathering is the verb with no tool in it (§4.0).
     */
    public function test_bare_hands_step_a_point_every_ten_levels(): void
    {
        $this->assertSame(Balance::BARE_HAND_ATTACK, Formulas::gatherAttack(0));
        $this->assertSame(Balance::BARE_HAND_ATTACK, Formulas::gatherAttack(1));
        $this->assertSame(Balance::BARE_HAND_ATTACK, Formulas::gatherAttack(9));
        $this->assertSame(Balance::BARE_HAND_ATTACK + 1, Formulas::gatherAttack(10));
        $this->assertSame(Balance::BARE_HAND_ATTACK + 2, Formulas::gatherAttack(20));

        // The line skill stops where the line skill stops.
        $capped = intdiv(Balance::SKILL_MAX_LEVEL, Balance::MINING_SKILL_LEVELS_PER_ATTACK);
        $this->assertSame($capped, Formulas::skillAttack(Balance::SKILL_MAX_LEVEL));
        $this->assertSame($capped, Formulas::skillAttack(9999));
        $this->assertSame(
            Balance::BARE_HAND_ATTACK + $capped,
            Formulas::gatherAttack(9999),
        );
    }

    /**
     * §4.0 / §8.0 rule 1 -- no mine in the game mixes hands and a tool.
     *
     * Mining and hunting are refused without their tool rather than downgraded,
     * so the attack a mine runs on is the tool's OR the hands', never a sum.
     * The consequence that has to hold is the direction: bare hands are the
     * FLOOR under the ladder, so the very cheapest tool must still beat them.
     *
     * They did not, briefly. While BARE_HAND_ATTACK was the base every verb
     * shared it could sit above the common rung harmlessly; the moment it
     * became gathering's whole rate, four against a Stone Axe's three meant
     * buying your first axe made the hex SLOWER, which is §12 step 5 inverted.
     */
    /**
     * §7.3 + §7.4.3 -- the gathering tree is whole points of the same attack
     * the tool carries, and it lands on the rate rather than beside it.
     *
     * Flat rather than a percentage, which inverts who it is worth most to: a
     * Stone Axe gains proportionally far more from five points than a Mythril
     * Pickaxe does. That is deliberate and it is the opposite of gear -- §8.1
     * rule 4 keeps the whole ladder twelve points wide precisely so a tree can
     * be a different road to the top rather than a longer one.
     */
    public function test_the_line_tree_is_whole_points_of_the_mine_rate(): void
    {
        $hex = Balance::TILE_HP_MIN;
        $level = 20;

        $plain = Formulas::mineTime($hex, $level, 0.0, Balance::MINING_COMMON_ATTACK);
        $tree = Formulas::mineTime($hex, $level, 0.0, Balance::MINING_COMMON_ATTACK, Balance::SKILL_BITE_CAP);

        $this->assertSame(
            $plain['rate'] + Balance::SKILL_BITE_CAP,
            $tree['rate'],
            'the tree does not reach the rate the mine is actually run at',
        );
        $this->assertLessThan($plain['total'], $tree['total']);

        // The cap is enforced where the rate is built, not only where the
        // nodes are added up. A rate is a bad place to find out a cap was
        // missed somewhere upstream.
        $overrun = Formulas::mineTime($hex, $level, 0.0, Balance::MINING_COMMON_ATTACK, 99);
        $this->assertSame($tree['rate'], $overrun['rate'], 'the mine rate does not enforce SKILL_BITE_CAP');

        // A count, so a maxed coat cannot clamp it away -- which is the whole
        // reason it stopped being tripReduction.
        $capped = Formulas::mineTime($hex, $level, Balance::STAT_CEILING, Balance::MINING_COMMON_ATTACK);
        $both = Formulas::mineTime($hex, $level, Balance::STAT_CEILING, Balance::MINING_COMMON_ATTACK, Balance::SKILL_BITE_CAP);
        $this->assertGreaterThan($capped['rate'], $both['rate']);
    }

    /**
     * §4.0 -- bare hands TIE the common rung, and what a tool buys at the
     * bottom of the ladder is worth rather than speed.
     *
     * The two numbers are both 3 on purpose. Hands are gathering's rate and
     * nothing else's: §8.0 rule 1 refuses a mine outright without the line's
     * tool and points at the gather button instead, so the hex is worked with
     * the tool or with the hands and the two never race on the same verb. What
     * the first tool changes is what comes home -- §4.0, scrap sells for a gold
     * and no recipe anywhere will take it, while the seam it displaced feeds
     * every one of them.
     *
     * So the ladder is felt from the SECOND rung up, and the first rung is felt
     * in the bag. This used to assert hands were strictly slower, which made a
     * Stone Axe's whole argument "the same hex, faster" -- an argument §4.0 had
     * already replaced with a better one.
     */
    public function test_bare_hands_tie_the_common_rung_and_lose_to_every_rung_above(): void
    {
        $this->assertSame(
            Balance::MINING_COMMON_ATTACK,
            Balance::BARE_HAND_ATTACK,
            'hands and the cheapest tool have stopped taking the same bite',
        );

        $hex = Balance::TILE_HP_MIN;

        // At every level of the line, because the skill term is shared: a level
        // that helped the hands and not the tool would break the tie sideways.
        foreach ([1, 10, 25, Balance::SKILL_MAX_LEVEL] as $level) {
            $hands = Formulas::mineTime($hex, $level, 0.0, Balance::BARE_HAND_ATTACK)['total'];

            // The five rungs a village sells, one per line. Same bite as hands.
            foreach (['stone_axe', 'chipped_pick', 'crude_bow'] as $key) {
                $attack = Formulas::toolAttack(Catalog::item($key));

                $this->assertSame(
                    $hands,
                    Formulas::mineTime($hex, $level, 0.0, $attack)['total'],
                    "{$key} no longer ties bare hands at line level {$level}",
                );
            }

            // And everything above it is strictly faster, which is where the
            // ladder starts being a ladder.
            foreach (['hewn_axe', 'iron_hatchet', 'ironwood_axe'] as $key) {
                $attack = Formulas::toolAttack(Catalog::item($key));

                $this->assertLessThan(
                    $hands,
                    Formulas::mineTime($hex, $level, 0.0, $attack)['total'],
                    "{$key} is no faster than bare hands at line level {$level}",
                );
            }
        }
    }

    /**
     * §7.3 -- the HP range is calibrated once, and this is where that is written
     * down.
     *
     * 2,700 is fifteen minutes for the common rung with nothing learned yet,
     * and 5,400 is thirty. Nothing at runtime derives those seconds -- HP is
     * the fact the world rolls -- so if the numbers are ever retuned it should
     * have to argue with this test rather than drift quietly.
     */
    public function test_the_hp_range_is_fifteen_to_thirty_minutes_at_the_common_rung(): void
    {
        $fresh = 1;

        $easy = Formulas::mineTime(
            Balance::TILE_HP_MIN,
            $fresh,
            0.0,
            Balance::MINING_COMMON_ATTACK,
        );
        $hard = Formulas::mineTime(
            Balance::TILE_HP_MAX,
            $fresh,
            0.0,
            Balance::MINING_COMMON_ATTACK,
        );

        $this->assertSame(15 * 60, $easy['total']);
        $this->assertSame(30 * 60, $hard['total']);

        // A herd is read off the same yardstick, at twenty-five.
        $herd = Formulas::mineTime(
            Balance::HERD_HP,
            $fresh,
            0.0,
            Balance::MINING_COMMON_ATTACK,
        );
        $this->assertSame(25 * 60, $herd['total']);
    }

    /**
     * §5.3 -- better ground is more work, and the rung it is named for is how
     * much more.
     *
     * A variant is one rung of the equipment ladder, and that used to be
     * decoration: an Ironwood Grove was the same afternoon's work as the plain
     * forest beside it. Every grade of ground now takes ITS rung exactly as
     * long as base ground takes the common one, which is the sentence this
     * pins -- fifteen minutes to thirty, all the way up the ladder.
     */
    public function test_each_grade_of_ground_costs_its_own_rung_the_base_fifteen_to_thirty(): void
    {
        $fresh = 1;

        // Several rolls rather than one, so the assertion is about the ladder
        // rather than about whichever hex hash zero happens to produce.
        foreach ([0, 1, 7, 12345, 999983, 0xFFFFFFFF] as $hash) {
            $base = Formulas::mineTime(
                WorldGen::tileHp($hash, 'common'),
                $fresh,
                0.0,
                Balance::MINING_COMMON_ATTACK,
            )['total'];

            $this->assertGreaterThanOrEqual(15 * 60, $base);
            $this->assertLessThanOrEqual(30 * 60, $base);

            foreach (Balance::TILE_HP_GRADE_ATTACK as $grade => $attack) {
                $own = Formulas::mineTime(
                    WorldGen::tileHp($hash, $grade),
                    $fresh,
                    0.0,
                    $attack,
                )['total'];

                // A second either way is integer division, not a design gap.
                $this->assertLessThanOrEqual(
                    1,
                    abs($own - $base),
                    "{$grade} ground does not cost its own rung what base ground costs the common one",
                );
            }
        }
    }

    /**
     * §5.3 -- and the other half of the same rule: at a FIXED rung, better
     * ground is strictly more work.
     *
     * The test above would still pass if HP and the rung ladder were both
     * flat. This is the one that fails if the grade stops costing anything.
     */
    public function test_better_ground_is_strictly_more_work_at_one_rung(): void
    {
        foreach ([0, 1, 7, 12345, 999983, 0xFFFFFFFF] as $hash) {
            $previous = 0;
            foreach (array_keys(Balance::TILE_HP_GRADE_ATTACK) as $grade) {
                $hp = WorldGen::tileHp($hash, $grade);
                $this->assertGreaterThan($previous, $hp, "{$grade} ground is no harder than the grade below it");
                $previous = $hp;
            }
        }
    }

    /**
     * §5.3 -- the base grade is the yardstick, so it comes through untouched.
     *
     * If common ground ever picked up a multiplier the HP range would mean two
     * things at once, and the calibration test above would be measuring a
     * number nothing on the map actually carries.
     */
    public function test_the_base_grade_is_the_unscaled_roll(): void
    {
        $this->assertSame(
            Balance::MINING_COMMON_ATTACK,
            Balance::TILE_HP_GRADE_ATTACK['common'],
        );

        foreach ([0, 1, 12345, 0xFFFFFFFF] as $hash) {
            $this->assertSame(
                Hash::randInt($hash, Balance::TILE_HP_MIN, Balance::TILE_HP_MAX),
                WorldGen::tileHp($hash, 'common'),
            );
        }
    }

    /**
     * §5.3 -- the grade ladder only ever climbs, and it covers every grade the
     * variant table can produce.
     *
     * A grade with no entry would fall back to the common rung and cost
     * nothing extra, which is the silent version of the bug this whole rule
     * exists to fix.
     */
    public function test_every_variant_grade_is_on_the_hp_ladder_and_it_only_climbs(): void
    {
        $ladder = Balance::TILE_HP_GRADE_ATTACK;

        $this->assertSame(
            Variants::GRADES,
            array_keys($ladder),
            'the HP ladder and the variant grades have drifted apart',
        );

        $previous = 0;
        foreach ($ladder as $grade => $attack) {
            $this->assertGreaterThan($previous, $attack, "{$grade} is not above the grade below it");
            $previous = $attack;
        }

        foreach (Variants::BIOME_VARIANTS as $biome => $variants) {
            foreach ($variants as $variant) {
                $this->assertArrayHasKey(
                    $variant['grade'],
                    $ladder,
                    "{$biome}'s {$variant['key']} has a grade the HP ladder does not know",
                );
            }
        }
    }

    /**
     * §7.3 -- a character who has learned nothing is worth nothing extra.
     *
     * The skill term was `ceil(level / 10)`, which handed the very first level
     * of a line a free point: a panel describing what your skill was worth to
     * this mine printed "+1" at somebody who had never swung an axe.
     */
    public function test_an_unskilled_line_adds_no_attack(): void
    {
        $this->assertSame(0, Formulas::skillAttack(0));
        $this->assertSame(0, Formulas::skillAttack(1));
        $this->assertSame(0, Formulas::skillAttack(9));
        $this->assertSame(1, Formulas::skillAttack(10));

        $mine = Formulas::mineTime(Balance::TILE_HP_MIN, 1, 0.0, 3);
        $this->assertSame(0, $mine['skillAttack']);
        $this->assertSame(3.0, $mine['rate']);
    }

    /**
     * §8.0 rule 1 -- nothing in your hands and nothing learned is NO mine.
     *
     * Not a very long one. The rate is zero, so the arithmetic has no answer,
     * and what the card owes the player is a refusal rather than a clock nobody
     * can reach.
     */
    public function test_no_attack_at_all_is_a_refusal_rather_than_a_long_trip(): void
    {
        $none = Formulas::mineTime(Balance::TILE_HP_MIN, 1, 0.0, 0);

        $this->assertFalse($none['able']);
        $this->assertSame(0, $none['total']);
        $this->assertSame(0.0, $none['rate']);
        $this->assertFalse($none['clamped']);

        // One point of anything is enough to make it a mine again.
        $some = Formulas::mineTime(Balance::TILE_HP_MIN, 1, 0.0, 1);
        $this->assertTrue($some['able']);
        $this->assertGreaterThan(0, $some['total']);

        // Including a point that came from the line rather than from a tool --
        // which is why the arithmetic alone is not the whole answer. §8.0 rule
        // 1 refuses the VERB without its tool, so previewTile overrides this
        // for a bare-handed dig no matter how well the line is known.
        $learned = Formulas::mineTime(Balance::TILE_HP_MIN, 10, 0.0, 0);
        $this->assertTrue($learned['able']);
    }

    /**
     * §8.0 rule 1 -- a skilled prospector with no axe still cannot mine.
     *
     * The rate would be one a second off the line alone, which is a clock; the
     * button beside it is dead, and a card that prints a time next to a refusal
     * is telling the player two different things.
     */
    public function test_a_skilled_line_with_no_tool_still_cannot_mine(): void
    {
        [$col, $row] = $this->standOnMineableGround();

        $this->character->skills()->updateOrCreate(
            ['skill_key' => 'woodcutting'],
            ['level' => 30, 'xp' => 0],
        );

        $mine = $this->game->previewTile($this->character->fresh(), $col, $row);
        $this->assertTrue($mine['bare'], 'the character found a tool somewhere');
        $this->assertFalse($mine['able'], 'a bare-handed dig was costed as a mine');
        $this->assertFalse($mine['canMine']);

        // The floor under it is still open, and it IS able: hands are a rate.
        $gather = $this->game->previewGather($this->character->fresh(), $col, $row);
        $this->assertTrue($gather['able']);
        $this->assertGreaterThan(0, $gather['seconds']);
    }

    /**
     * §7.3 -- a hex is an amount of work, and the tool ladder is how fast you
     * get through it. Every rung has to be felt on the clock.
     */
    public function test_a_better_tool_works_a_hex_faster(): void
    {
        // The shop uncommon and the crafted uncommon carry the same ladder
        // stat, so they take the same bite -- what separates them is how long
        // they last. Distinct rungs only.
        $ladder = ['stone_axe', 'hewn_axe', 'iron_hatchet', 'ironwood_axe'];
        $hex = Balance::TILE_HP_MAX;

        // Bare hands are NOT the bottom of this ladder: they take the common
        // rung's own bite (§4.0), so seeding the walk with them would ask the
        // Stone Axe to beat a tie. What the first rung buys is what comes home,
        // and the rung above it is where the clock starts moving -- which is
        // exactly what this walk measures.
        $last = INF;

        foreach ($ladder as $key) {
            $def = Catalog::item($key);
            $this->assertNotNull($def, "{$key} is not in the catalog");

            $attack = Formulas::toolAttack($def);
            $this->assertGreaterThan(0, $attack, "{$key} has no bite");

            $mine = Formulas::mineTime($hex, 1, 0.0, $attack);
            $this->assertLessThan($last, $mine['total'], "{$key} was no faster than the rung below");
            $this->assertFalse($mine['clamped'], "{$key} is wasted on this hex");
            $last = $mine['total'];
        }
    }

    /**
     * §8.1 -- stacking diminishes and the per-tier cap binds.
     *
     * Read off worn gear rather than a tool: §8 gives a gathering tool a solid
     * attack and no percentage at all, so there is nothing on one for the
     * falloff to apply to.
     */
    public function test_stacking_the_same_item_gives_less_each_time_and_is_capped(): void
    {
        $one = Formulas::aggregateStat(
            [['key' => 'leather_armor', 'durability' => 10, 'equipped' => true]],
            'tripReduction',
        );
        $three = Formulas::aggregateStat(
            array_fill(0, 3, ['key' => 'leather_armor', 'durability' => 10, 'equipped' => true]),
            'tripReduction',
        );

        $this->assertGreaterThan(0.0, $one);
        $this->assertLessThan($one * 3, $three, 'three identical items scaled linearly');
        $this->assertLessThanOrEqual(Balance::STAT_CEILING, $three);
    }

    /**
     * §8 -- a tool pays out on its own line and on no other.
     *
     * Read off a ROLLED line, because a tool's base is now a solid attack and
     * carries no percentage of its own. The lock is what matters and it is
     * unchanged: whatever percentage a tool ends up with is its line's.
     */
    public function test_a_gathering_tool_only_counts_on_its_own_line(): void
    {
        $kit = [[
            'key' => 'iron_pickaxe',
            'durability' => 10,
            'equipped' => true,
            'options' => [['stat' => 'yield', 'value' => 0.03]],
        ]];

        $this->assertGreaterThan(
            0,
            Formulas::aggregateStat($kit, 'yield', 'mining'),
            'a pickaxe did nothing on a seam',
        );
        $this->assertSame(
            0.0,
            Formulas::aggregateStat($kit, 'yield', 'woodcutting'),
            'a pickaxe felled a tree',
        );
        $this->assertSame(
            0.0,
            Formulas::aggregateStat($kit, 'yield'),
            'a tool counted with no line being worked',
        );

        // Gear worn on the body is not line-locked and counts everywhere.
        $worn = [['key' => 'leather_armor', 'durability' => 10, 'equipped' => true]];
        $this->assertGreaterThan(
            0,
            Formulas::aggregateStat($worn, 'tripReduction', 'harvesting'),
            'armor stopped working outside a line',
        );
        $this->assertGreaterThan(
            0,
            Formulas::aggregateStat($worn, 'tripReduction'),
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

        foreach (Catalog::TOOL_SLOT_SKILL as $slot => $line) {
            $tools = array_filter(
                Catalog::items(),
                fn (array $def) => ($def['slot'] ?? null) === $slot,
            );

            // Two commons (bought and made), two uncommons (bought and made),
            // one epic. Rare has no craftable yet -- see docs/rarity-plan.md
            // step 5, which fills the capital bench.
            $rarities = array_count_values(array_column($tools, 'rarity'));
            $this->assertSame(2, $rarities['common'] ?? 0, "{$line} is missing a common tool");
            $this->assertSame(2, $rarities['uncommon'] ?? 0, "{$line} is missing an uncommon tool");
            $this->assertSame(1, $rarities['epic'] ?? 0, "{$line} is missing its epic tool");

            // §8 -- the ladder is measured in ATTACK now: a tool's base is
            // solid (§7.3) and it has no percentage to compare.
            $ceilings[$line] = max(array_column($tools, 'attack'));
            $this->assertGreaterThan(0, $ceilings[$line], "{$line} has a tool with no bite");

            foreach ($tools as $key => $def) {
                $this->assertArrayNotHasKey(
                    'stat',
                    $def,
                    "{$key} still leads with a percentage instead of its attack",
                );
            }
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

        foreach (Catalog::items() as $key => $def) {
            $this->assertContains($def['rarity'], Balance::RARITIES, "{$key} has no rarity");

            // §8 -- a gathering tool has no percentage to out-climb anything
            // with. Its base is a solid attack, which no ceiling applies to.
            if (! isset($def['stat'])) {
                continue;
            }

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
        foreach (Catalog::items() as $key => $def) {
            $this->assertArrayHasKey('tradeable', $def, "{$key} does not say whether it is an NFT");

            if ($def['tradeable']) {
                $this->assertNotSame('unique', $def['rarity'], "{$key} is a tradeable unique");
                $this->assertArrayHasKey('inputs', $def, "{$key} is tradeable but is not crafted");
                $this->assertArrayNotHasKey('goldPrice', $def, "{$key} bridges gold to NFT value");

                // §3.3 -- an NFT is crafted from tier 3 + tier 4, never tier 1-2 alone.
                $topTier = max(array_map(
                    fn (string $m) => Catalog::materialTier($m),
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
            'options' => array_fill(0, 3, ['stat' => 'yield', 'value' => Balance::OPTION_VALUE['legendary'][1] * Balance::OPTION_SCOPED_MULTIPLIER]),
        ]);

        $total = Formulas::aggregateStat($fat, 'yield', 'mining');

        $this->assertLessThanOrEqual(Balance::STAT_CAP['epic'], $total, 'options beat the rarity cap');
        $this->assertLessThanOrEqual(Balance::STAT_CEILING, $total, 'options beat the global ceiling');
    }

    /**
     * §8.0.1 -- a worn line may name one gathering line, and pays there alone.
     *
     * Armor works all five lines at once, which is exactly why a line it names
     * is narrower than a flat one. "No line is being worked" is one of the
     * elsewheres it pays nothing on, so a mining roll never shortens a journey.
     */
    public function test_a_scoped_option_pays_on_its_line_and_nowhere_else(): void
    {
        $kit = [[
            'key' => 'leather_armor',
            'durability' => 10,
            'equipped' => true,
            'options' => [['stat' => 'yield', 'value' => 0.04, 'scope' => 'mining']],
        ]];

        $this->assertSame(0.04, Formulas::aggregateStat($kit, 'yield', 'mining'));
        $this->assertSame(0.0, Formulas::aggregateStat($kit, 'yield', 'woodcutting'));
        $this->assertSame(0.0, Formulas::aggregateStat($kit, 'yield'));
    }

    /**
     * §8.0.1 -- a scoped roll is worth more than a flat one, because it is
     * worth nothing on the other four lines. Without the gap the pool would
     * read as a bad-luck table.
     */
    public function test_worn_gear_rolls_scoped_lines_and_pays_them_better(): void
    {
        $def = Catalog::item('ironwood_armor');
        $scoped = 0;
        $flat = 0;

        for ($seed = 1; $seed <= 200; $seed++) {
            foreach (Formulas::rollOptions($def, $seed) as $option) {
                $scope = $option['scope'] ?? null;

                $tiers = Formulas::optionTiersFor($def['rarity']);

                // §8.0.1 -- a line is either a percentage or a solid number,
                // and the two are read off different tables.
                if (($option['kind'] ?? 'percent') === 'flat') {
                    $this->assertGreaterThanOrEqual(
                        Balance::OPTION_FLAT_VALUE[$tiers[0]][0],
                        $option['value'],
                    );
                    $this->assertLessThanOrEqual(
                        Balance::OPTION_FLAT_VALUE[end($tiers)][1],
                        $option['value'],
                    );
                    $this->assertArrayNotHasKey('scope', $option, 'a flat line was scoped');

                    continue;
                }

                $floor = Balance::OPTION_VALUE[$tiers[0]][0];
                $ceiling = Balance::OPTION_VALUE[end($tiers)][1];

                if ($scope === null) {
                    $flat++;
                    $this->assertGreaterThanOrEqual($floor, $option['value']);
                    $this->assertLessThanOrEqual($ceiling, $option['value']);

                    continue;
                }

                $scoped++;
                $this->assertContains($scope, Catalog::SKILLS);
                $this->assertContains($option['stat'], Catalog::OPTION_SCOPED_STATS);
                $this->assertGreaterThanOrEqual(
                    $floor * Balance::OPTION_SCOPED_MULTIPLIER,
                    $option['value'],
                );
                $this->assertLessThanOrEqual(
                    $ceiling * Balance::OPTION_SCOPED_MULTIPLIER,
                    $option['value'],
                );
            }
        }

        $this->assertGreaterThan(0, $scoped, 'worn gear never rolled a scoped line');
        $this->assertGreaterThan(0, $flat, 'worn gear never rolled a flat line');
        $this->assertGreaterThan(1.0, Balance::OPTION_SCOPED_MULTIPLIER);
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

        // The pickaxe is a yield tool, yet it now shaves mine time on its line.
        $this->assertSame(0.02, Formulas::aggregateStat($kit, 'tripReduction', 'mining'));
        // ...and nowhere else, because options inherit the line-lock.
        $this->assertSame(0.0, Formulas::aggregateStat($kit, 'tripReduction', 'woodcutting'));
    }

    /**
     * §8.0.1 -- the count is a CEILING, not a quota.
     *
     * A crafted piece rolls anywhere between nothing and its rung's maximum, so
     * two of the same recipe are never the same object. Commons roll nothing
     * because their ceiling is nothing.
     */
    public function test_option_counts_never_pass_the_rarity_ceiling(): void
    {
        foreach (['common' => 'stone_axe', 'epic' => 'mythril_pickaxe'] as $rarity => $key) {
            $def = Catalog::item($key);
            $seen = [];

            for ($seed = 1; $seed <= 200; $seed++) {
                $rolled = Formulas::rollOptions($def, $seed);
                $seen[count($rolled)] = true;

                $this->assertLessThanOrEqual(
                    Balance::OPTION_ROLLS[$rarity],
                    count($rolled),
                    "{$key} rolled past its ceiling",
                );

                $tiers = Formulas::optionTiersFor($rarity);

                foreach ($rolled as $option) {
                    $table = ($option['kind'] ?? 'percent') === 'flat'
                        ? Balance::OPTION_FLAT_VALUE
                        : Balance::OPTION_VALUE;
                    $top = ($option['kind'] ?? 'percent') === 'flat'
                        ? 1.0
                        : Balance::OPTION_SCOPED_MULTIPLIER;

                    $this->assertGreaterThanOrEqual($table[$tiers[0]][0], $option['value']);
                    $this->assertLessThanOrEqual($table[end($tiers)][1] * $top, $option['value']);
                    $this->assertContains($option['stat'], Catalog::optionStatsFor($def));
                }

                // One line per (stat, scope): two "+2% mining yield" rows on one
                // item reads as a bug, and a tool has no scope to tell them apart.
                $lines = array_map(
                    static fn (array $o) => $o['stat'].'|'.($o['scope'] ?? ''),
                    $rolled,
                );
                $this->assertSame($lines, array_unique($lines), "{$key} rolled a line twice");

                // A tool is line-locked by its slot (§8 rule 1), so a scope on
                // it would be a second copy of a fact that is already true.
                foreach ($rolled as $option) {
                    $this->assertArrayNotHasKey('scope', $option, "{$key} scoped a tool line");
                }
            }

            // A common has one outcome and an epic has three, which is the
            // whole of "an option is a bonus, not part of the item".
            $this->assertCount(
                Balance::OPTION_ROLLS[$rarity] + 1,
                $seen,
                "{$key} never rolled every count it should",
            );
        }
    }

    /**
     * §8.0.1 -- every line rolls its own tier, drawn at or below the item's
     * rarity, so a legendary regularly carries a common-grade line.
     */
    public function test_a_line_may_be_drawn_from_any_tier_at_or_below_the_item(): void
    {
        $this->assertSame(['common'], Formulas::optionTiersFor('common'));
        $this->assertSame(
            ['common', 'uncommon', 'rare', 'epic'],
            Formulas::optionTiersFor('epic'),
        );

        $def = Catalog::item('mythril_pickaxe');
        $low = 0;
        $high = 0;

        for ($seed = 1; $seed <= 400; $seed++) {
            foreach (Formulas::rollOptions($def, $seed) as $option) {
                if ($option['value'] <= Balance::OPTION_VALUE['common'][1]) {
                    $low++;
                }
                if ($option['value'] >= Balance::OPTION_VALUE['epic'][0]) {
                    $high++;
                }
            }
        }

        $this->assertGreaterThan(0, $low, 'an epic never rolled a low-tier line');
        $this->assertGreaterThan(0, $high, 'an epic never rolled its own tier');
    }

    /**
     * §9.5.4 -- attack and defense are SOLID numbers, so a rolled line may be
     * one too. It adds; it does not climb toward §8.1's percentage ceiling.
     */
    public function test_a_flat_line_adds_to_the_pair_and_not_to_the_percentages(): void
    {
        $kit = [[
            'id' => 1,
            'key' => 'soldiers_sword',
            'durability' => 50,
            'equipped' => true,
            'options' => [
                ['stat' => 'attack', 'value' => 4, 'kind' => 'flat'],
                ['stat' => 'defense', 'value' => 3, 'kind' => 'flat'],
            ],
        ]];

        $def = Catalog::item('soldiers_sword');
        $pair = Formulas::combatPair($kit);

        $this->assertSame((int) $def['attack'] + 4, $pair['attack']);
        $this->assertSame((int) $def['defense'] + 3, $pair['defense']);

        // And it is nowhere near the percentage aggregate, which is a different
        // number with a ceiling on it.
        $this->assertSame(0.0, Formulas::aggregateStat($kit, 'defense'));
        $this->assertSame(0.0, Formulas::aggregateStat($kit, 'attack'));
    }

    /**
     * §8 rule 5 -- a flat line on a gathering tool is MINING attack (§7.3). It
     * bites harder into a hex and is worth nothing whatsoever in a fight.
     */
    public function test_a_flat_line_on_a_tool_is_mining_attack_and_never_reaches_a_fight(): void
    {
        $axe = CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => 'ironwood_axe',
            'durability' => 100,
            'equipped' => true,
            'options' => [['stat' => 'attack', 'value' => 5, 'kind' => 'flat']],
        ]);

        $def = Catalog::item('ironwood_axe');
        $bare = Formulas::toolAttack($def);

        $this->assertSame(
            $bare + 5,
            $this->game->lineToolAttack($this->character->fresh(), 'woodcutting'),
            'a rolled line did not sharpen the axe',
        );

        // A faster hex, and the same fight.
        $this->assertLessThan(
            Formulas::mineTime(3600, 0, 0.0, $bare)['total'],
            Formulas::mineTime(3600, 0, 0.0, $bare + 5)['total'],
        );

        $pair = Formulas::combatPair(
            $this->character->fresh()->items->map(fn ($i) => [
                'id' => $i->id,
                'key' => $i->item_key,
                'durability' => $i->durability,
                'equipped' => $i->equipped,
                'options' => $i->options ?? [],
            ])->all(),
        );

        $this->assertSame(0, $pair['attack'], 'an axe swung in a fight');
        $this->assertSame(0, $pair['defense']);
        $this->assertSame($axe->id, $axe->fresh()->id);
    }

    /**
     * §8.0.1 -- both kinds actually come out of the roller, and a flat one
     * never carries a scope.
     */
    public function test_a_roll_produces_both_flat_and_percentage_lines(): void
    {
        $def = Catalog::item('keepers_carapace');
        $flat = 0;
        $percent = 0;

        for ($seed = 1; $seed <= 300; $seed++) {
            foreach (Formulas::rollOptions($def, $seed) as $option) {
                if (($option['kind'] ?? 'percent') === 'flat') {
                    $flat++;
                    $this->assertContains($option['stat'], ['attack', 'defense']);
                    $this->assertIsInt($option['value']);
                    $this->assertArrayNotHasKey('scope', $option);

                    continue;
                }

                $percent++;
                $this->assertIsFloat($option['value']);
            }
        }

        $this->assertGreaterThan(0, $flat, 'no flat line ever rolled');
        $this->assertGreaterThan(0, $percent, 'no percentage line ever rolled');
    }

    /**
     * §9.5.8/§4 -- a win pays two kinds of tier-0, and they answer two
     * different questions.
     *
     * The trophy says WHAT you fought and is always there; the junk says WHERE
     * you fought it and turns up about two times in five. Both are worth a gold
     * and feed no recipe, which is the whole reason a combat faucet can be this
     * generous without touching §9.5.8's containment.
     */
    public function test_a_win_pays_a_trophy_every_time_and_the_ground_sometimes(): void
    {
        $monster = Monsters::ROSTER['moss_hound'];
        $trophy = Spoils::TROPHY_BY_TIER[$monster['tier']];
        $junk = null;
        foreach (Alchemy::JUNK as $key => $def) {
            if ($def['biome'] === 'forest') {
                $junk = $key;
            }
        }

        $this->assertNotNull($junk);

        $sawJunk = 0;
        for ($seed = 1; $seed <= 200; $seed++) {
            $bare = Drops::battleSpoils($monster, $seed);
            $onGround = Drops::battleSpoils($monster, $seed, 'forest');

            // The trophy is not a roll.
            $this->assertArrayHasKey($trophy, $bare, "no trophy off seed {$seed}");
            $this->assertGreaterThan(0, $bare[$trophy]);

            // And the ground is the ONLY difference between the two calls.
            $this->assertSame(
                $bare,
                array_diff_key($onGround, [$junk => true]),
                "the biome changed something other than the junk, seed {$seed}",
            );

            if (isset($onGround[$junk])) {
                $sawJunk++;
            }
        }

        // Two in five, loosely: this pins that it is a chance rather than
        // never or always, not the exact rate.
        $this->assertGreaterThan(40, $sawJunk, 'the ground never turned up');
        $this->assertLessThan(160, $sawJunk, 'the ground turned up every time');
    }

    /** §4 -- and every one of them is a gold apiece, feeding nothing. */
    public function test_every_tier_zero_drop_off_a_fight_is_worth_scrap_money(): void
    {
        foreach (Spoils::TROPHY_BY_TIER as $key) {
            $def = Catalog::material($key);

            $this->assertNotNull($def, "{$key} is not in the catalog");
            $this->assertSame(0, $def['tier']);
            $this->assertSame(1, $def['npcPrice']);
        }

        // §4 -- and nothing anywhere takes one as an input.
        foreach (Catalog::items() as $itemKey => $item) {
            foreach (array_keys($item['inputs'] ?? []) as $input) {
                $this->assertNotContains(
                    $input,
                    Spoils::TROPHY_BY_TIER,
                    "{$itemKey} wants a trophy as an input",
                );
            }
        }
    }

    // ------------------------------------------------------------- the slate

    /**
     * §8.4 -- one column holds both kinds, which only works while the two key
     * spaces stay disjoint.
     *
     * If a recipe key ever collided with an item key, `slateKind()` would
     * answer "processing" for something a player wrote down meaning the sword,
     * and the shopping list would quietly cost the wrong materials. It is a
     * property of the catalog rather than of the slate, so it is asserted here
     * rather than defended in code.
     */
    public function test_a_recipe_key_is_never_also_an_item_key(): void
    {
        $collisions = array_intersect(
            array_keys(Catalog::recipes()),
            array_keys(Catalog::items()),
        );

        $this->assertSame([], array_values($collisions));
    }

    /** §8.4 -- both kinds go on the slate, and nothing else does. */
    public function test_the_slate_takes_a_recipe_or_a_craftable_and_refuses_the_rest(): void
    {
        $this->assertSame('processing', $this->game->slateKind('planks'));
        $this->assertSame('craft', $this->game->slateKind('hewn_axe'));

        // A shop-only piece has no recipe, so there is nothing to gather for it.
        $this->assertNull($this->game->slateKind('stone_axe'));
        $this->assertNull($this->game->slateKind('wood'));
        $this->assertNull($this->game->slateKind('nothing_at_all'));

        $this->expectException(GameException::class);
        $this->game->saveToSlate($this->character, 'stone_axe');
    }

    /**
     * §8.4 -- ten, and the eleventh is refused rather than pushing one off.
     *
     * A list that quietly forgets is worse than one that says it is full, which
     * is the same argument §7.6 makes about the bag.
     */
    public function test_the_slate_stops_at_ten_and_says_so(): void
    {
        $keys = array_slice(array_keys(array_filter(
            Catalog::items(),
            static fn (array $def) => isset($def['inputs']),
        )), 0, Balance::SLATE_CAP + 1);

        $this->assertCount(Balance::SLATE_CAP + 1, $keys, 'not enough craftables to fill a slate');

        foreach (array_slice($keys, 0, Balance::SLATE_CAP) as $key) {
            $this->game->saveToSlate($this->character, $key);
        }

        $this->assertCount(Balance::SLATE_CAP, $this->game->slate($this->character));

        try {
            $this->game->saveToSlate($this->character, $keys[Balance::SLATE_CAP]);
            $this->fail('the eleventh line was written');
        } catch (GameException $e) {
            $this->assertSame('slate_full', $e->errorCode);
        }

        // And the eleventh did not displace anything.
        $this->assertSame(
            array_slice($keys, 0, Balance::SLATE_CAP),
            $this->game->slate($this->character),
        );
    }

    /**
     * §8.4 -- writing the same line twice is one line, and rubbing out a line
     * that was never there is not an error. Both are what a doubled tap does.
     */
    public function test_the_slate_is_idempotent_at_both_ends(): void
    {
        $this->game->saveToSlate($this->character, 'planks');
        $this->game->saveToSlate($this->character, 'planks');

        $this->assertSame(['planks'], $this->game->slate($this->character));

        $this->game->dropFromSlate($this->character, 'planks');
        $this->game->dropFromSlate($this->character, 'planks');

        $this->assertSame([], $this->game->slate($this->character));
    }

    /** §8.4 -- and the state carries it, because that is where the client reads it. */
    public function test_the_slate_rides_in_the_player_state(): void
    {
        $this->game->saveToSlate($this->character, 'ingots');
        $this->game->saveToSlate($this->character, 'hewn_axe');

        $state = $this->game->playerState($this->character->fresh());

        $this->assertSame(['ingots', 'hewn_axe'], $state['slate']);
    }

    /** §8.0.1 -- gold buys a plain item. An option is what a bench puts on one. */
    /**
     * §8.0.1 -- a rolled line is drawn from what the piece is FOR.
     *
     * The weapon slot used to fall through to the worn pool, which is how a
     * sword came off the bench carrying "+4% hunting yield" -- a work bonus on
     * the one slot in the game that never works (§8 rule 5). Three jobs, three
     * pools, and the sweep is over the whole catalog because the hole was a
     * missing branch rather than a wrong entry.
     */
    public function test_a_rolled_line_belongs_to_the_piece_it_is_on(): void
    {
        $work = ['yield', 'tripReduction', 'travelSpeed', 'processingSpeed'];
        $checked = ['tool' => 0, 'weapon' => 0, 'worn' => 0];

        foreach (Catalog::items() as $key => $def) {
            $slot = $def['slot'] ?? null;
            if ($slot === null) {
                continue;
            }

            $pool = Catalog::optionRollsFor($def);
            $stats = array_column($pool, 'stat');

            if (Catalog::skillForSlot($slot) !== null) {
                $checked['tool']++;
                // A tool is line-locked by its slot, so the road and the bench
                // are not its business and neither is a scope.
                $this->assertSame(['yield', 'tripReduction', 'attack'], $stats, "{$key}");
                $this->assertSame([null, null, null], array_column($pool, 'scope'), "{$key}");

                continue;
            }

            if ($slot === 'weapon') {
                $checked['weapon']++;

                foreach ($work as $stat) {
                    $this->assertNotContains($stat, $stats, "{$key} can roll a work bonus");
                }

                // §9.5.4 -- and a focus keeps nothing off you, of either kind.
                if (($def['family'] ?? null) === 'focus') {
                    $this->assertNotContains('defense', $stats, "{$key} is a focus that guards");
                }

                continue;
            }

            $checked['worn']++;
            // §9.5.4 -- worn gear is one set with two axes, so it is the one
            // pool that reaches every stat there is.
            foreach ([...$work, 'power', 'defense', 'attack'] as $stat) {
                $this->assertContains($stat, $stats, "{$key} cannot roll {$stat}");
            }
        }

        $this->assertGreaterThan(0, $checked['tool']);
        $this->assertGreaterThan(0, $checked['weapon']);
        $this->assertGreaterThan(0, $checked['worn']);
    }

    /**
     * The option pool is hand-mirrored, so it can drift.
     *
     * The almanac reads it on the client -- what a piece MAY roll is part of
     * what a piece is, and that screen is a pure read of the TS catalog. A pool
     * that disagreed would promise a line the bench cannot produce.
     */
    public function test_the_option_pool_agrees_between_php_and_typescript(): void
    {
        $ts = file_get_contents(base_path('resources/js/game/catalog.ts'))
            .file_get_contents(base_path('resources/js/game/balance.ts'));

        $list = function (string $name) use ($ts): array {
            $this->assertMatchesRegularExpression("/{$name}: OptionStat\[\] = \[([^\]]*)\]/s", $ts, "{$name} is not in catalog.ts");
            preg_match("/{$name}: OptionStat\[\] = \[([^\]]*)\]/s", $ts, $m);

            return array_values(array_filter(array_map(
                static fn (string $part) => trim($part, " \n'"),
                explode(',', $m[1]),
            )));
        };

        $this->assertSame(Catalog::OPTION_STATS_TOOL, $list('OPTION_STATS_TOOL'));
        $this->assertSame(Catalog::OPTION_STATS_WEAPON, $list('OPTION_STATS_WEAPON'));
        $this->assertSame(Catalog::OPTION_STATS_WORN, $list('OPTION_STATS_WORN'));
        $this->assertSame(Catalog::OPTION_SCOPED_STATS, $list('OPTION_SCOPED_STATS'));
        $this->assertSame(Catalog::OPTION_FLAT_TOOL, $list('OPTION_FLAT_TOOL'));
        $this->assertSame(Catalog::OPTION_FLAT_WORN, $list('OPTION_FLAT_WORN'));

        // The bands too: the almanac quotes them as a range, and a client that
        // read a different table would print a promise the server never made.
        foreach (Balance::OPTION_ROLLS as $rarity => $count) {
            $this->assertMatchesRegularExpression("/optionRolls: \{[^}]*{$rarity}: {$count},/s", $ts, "optionRolls.{$rarity}");
        }
        foreach (Balance::OPTION_VALUE as $tier => [$min, $max]) {
            $this->assertMatchesRegularExpression("/optionValue: \{[^}]*{$tier}: \[{$min}, {$max}\],/s", $ts, "optionValue.{$tier}");
        }
        foreach (Balance::OPTION_FLAT_VALUE as $tier => [$min, $max]) {
            $this->assertMatchesRegularExpression("/optionFlatValue: \{[^}]*{$tier}: \[{$min}, {$max}\],/s", $ts, "optionFlatValue.{$tier}");
        }
        $this->assertStringContainsString(
            'optionScopedMultiplier: '.(int) Balance::OPTION_SCOPED_MULTIPLIER,
            $ts,
        );
    }

    public function test_a_shop_item_never_carries_an_option(): void
    {
        $this->standAtWoodcuttingVillage();
        $this->character->gold = 100000;
        $this->character->save();

        for ($i = 0; $i < 8; $i++) {
            $item = $this->game->buyItem($this->character->fresh(), 'stone_axe');
            $this->assertSame([], $item->options ?? [], 'a shop sold a rolled item');
        }
    }

    /**
     * §8.5 -- a potion is spent, arms the action it names, and is spent again by
     * taking that action. Being spent is the sink (§11.1); nothing is permanent.
     */
    public function test_drinking_arms_a_charge_that_the_action_spends(): void
    {
        $this->character->consumables()->create(['item_key' => 'forest_draft', 'quantity' => 2]);

        $before = $this->game->bonuses($this->character->fresh(), 'woodcutting')['yield'];
        $buff = $this->game->useConsumable($this->character->fresh(), 'forest_draft');

        $this->assertSame('yield', $buff['stat']);
        $this->assertSame('woodcutting', $buff['scope']);
        $this->assertSame(1, $this->game->heldConsumable($this->character->fresh(), 'forest_draft'));

        $during = $this->game->bonuses($this->character->fresh(), 'woodcutting')['yield'];
        $this->assertGreaterThan($before, $during, 'drinking did nothing');

        // Nothing expires it. Only the work does.
        $this->game->spendBuffs($this->character->fresh(), 'woodcutting');

        $after = $this->game->bonuses($this->character->fresh(), 'woodcutting')['yield'];
        $this->assertSame($before, $after, 'a spent charge was still paying out');
        $this->assertSame([], $this->game->armedBuffs($this->character->fresh()));
    }

    /**
     * §8.5 -- the charge waits. A draft drunk in the mountains is still there
     * when the forest is, which is the whole reason it stopped being a clock.
     */
    public function test_a_charge_is_not_spent_by_another_action(): void
    {
        $this->game->useConsumable($this->giveDrink('forest_draft'), 'forest_draft');

        $this->game->spendBuffs($this->character->fresh(), 'mining');
        $this->assertCount(1, $this->game->armedBuffs($this->character->fresh()), 'mining burned a forest charge');

        $this->game->spendBuffs($this->character->fresh(), 'travel');
        $this->assertCount(1, $this->game->armedBuffs($this->character->fresh()), 'the road burned a forest charge');
    }

    /**
     * §8.5 -- a second of the same kind is the same effect twice, so the better
     * draft wins and the weaker one is refused before the flask is opened.
     */
    public function test_the_stronger_draft_wins_and_the_weaker_is_refused(): void
    {
        $this->character->consumables()->create(['item_key' => 'forest_draft', 'quantity' => 2]);
        $this->character->consumables()->create(['item_key' => 'forest_tonic', 'quantity' => 1]);

        $weak = Catalog::item('forest_draft')['value'];
        $strong = Catalog::item('forest_tonic')['value'];
        $this->assertGreaterThan($weak, $strong, 'the rungs are not what this test assumes');

        $this->game->useConsumable($this->character->fresh(), 'forest_draft');
        $once = $this->game->bonuses($this->character->fresh(), 'woodcutting')['yield'];

        // Up a rung: the stronger charge takes the place of the weaker one.
        $this->game->useConsumable($this->character->fresh(), 'forest_tonic');
        $upgraded = $this->game->bonuses($this->character->fresh(), 'woodcutting')['yield'];

        $this->assertGreaterThan($once, $upgraded, 'the better draft did not take');
        $this->assertCount(1, $this->game->armedBuffs($this->character->fresh()), 'two charges on one action');
        $this->assertSame($once + ($strong - $weak), round($upgraded, 10), 'the two potions stacked');

        // Back down a rung: refused, and the flask stays in the bag.
        try {
            $this->game->useConsumable($this->character->fresh(), 'forest_draft');
            $this->fail('a weaker draft was poured on top of a stronger one');
        } catch (GameException $e) {
            $this->assertSame('weaker_charge', $e->errorCode);
        }

        $this->assertSame(
            1,
            $this->game->heldConsumable($this->character->fresh(), 'forest_draft'),
            'the refused draft was drunk anyway',
        );
        $this->assertSame(
            $upgraded,
            $this->game->bonuses($this->character->fresh(), 'woodcutting')['yield'],
            'the refusal moved the bonus',
        );
    }

    /**
     * §8.5 -- different effects are not the same effect. Several may be held at
     * once, and each pays out on its own action.
     */
    public function test_several_different_charges_are_held_at_once(): void
    {
        $this->game->useConsumable($this->giveDrink('forest_draft'), 'forest_draft');
        $this->game->useConsumable($this->giveDrink('deepseam_draft'), 'deepseam_draft');
        $this->game->useConsumable($this->giveDrink('road_tonic'), 'road_tonic');

        $this->assertCount(3, $this->game->armedBuffs($this->character->fresh()));
    }

    /** §8.1 rule 1 -- a buff is inside the ceiling like everything else. */
    public function test_a_buff_cannot_push_a_stat_past_the_ceiling(): void
    {
        // Best legal gear, then drink on top of it.
        CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => 'mythril_pickaxe',
            'durability' => 100,
            'equipped' => true,
            'options' => [['stat' => 'yield', 'value' => Balance::OPTION_VALUE['legendary'][1] * Balance::OPTION_SCOPED_MULTIPLIER]],
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

        foreach (Catalog::items() as $key => $def) {
            $category = Catalog::category($def);
            $this->assertContains($category, Catalog::CATEGORIES, "{$key} has no bench");
            $seen[$category] = true;

            if ($category === 'consumable') {
                $this->assertTrue(! empty($def['consumable']), "{$key} has no slot but is not a consumable");
                $this->assertArrayNotHasKey('maxDurability', $def, "{$key} is drunk but wears out");
            } else {
                $this->assertArrayHasKey('maxDurability', $def, "{$key} is worn but never wears out");
            }
        }

        $this->assertSame(
            Catalog::CATEGORIES,
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
        $uniques = 0;

        foreach (Catalog::items() as $key => $def) {
            // Legendary MAY be defined -- §8.5's top rung of potions is -- but
            // the only bench that reaches it is a guild hall, and there are
            // none. The invariant is unreachability, not absence: a legendary
            // whose station were a capital would be forgeable today.
            if ($def['rarity'] === 'legendary') {
                $this->assertSame(
                    'guild',
                    $def['station'] ?? null,
                    "{$key} is legendary but does not need a guild hall",
                );
            }

            // Unique may be defined too, and for the same reason: the ladder is
            // easier to reason about whole than with a hole at the top. What it
            // may NOT have is a road. No bench, no shop, no recipe -- it drops,
            // and §8.0 stops tradeability at legendary because a tradeable drop
            // is precisely the grind->external-value faucet §2 exists to close.
            if ($def['rarity'] === 'unique') {
                $uniques++;
                $this->assertFalse($def['tradeable'], "{$key} is a tradeable unique");
                $this->assertArrayNotHasKey('inputs', $def, "{$key} is unique and craftable");
                $this->assertArrayNotHasKey('goldPrice', $def, "{$key} is unique and for sale");
                $this->assertArrayNotHasKey('station', $def, "{$key} is unique and has a bench");
                $this->assertNotEmpty($def['perk'] ?? null, "{$key} is unique with no fixed perk");
            }
        }

        $this->assertGreaterThan(0, $uniques, 'the top of the ladder is missing');

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
            CharacterItem::create([
                'character_id' => $this->character->id,
                'item_key' => $key,
                'durability' => Catalog::item($key)['maxDurability'],
                'equipped' => true,
            ]);
        }

        // Spawn is forest (§12 step 1), so this mine is the axe's line.
        $col = $this->character->col;
        $row = $this->character->row;
        $tile = $this->game->buildTile($col, $row, $this->game->now());
        $worn = Catalog::slotForSkill(Catalog::skillForMaterial($tile['material']));
        $this->assertSame('axe', $worn, 'spawn is no longer a forest hex');

        $job = $this->game->startMining($this->character->fresh(), $col, $row);
        $job->update(['ends_at' => $this->game->now() - 1]);
        $this->game->collectJob($this->character->fresh(), $job->id);

        foreach ($this->character->fresh()->items as $item) {
            $def = Catalog::item($item->item_key);

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
        $add = new ReflectionMethod($this->game, 'addMaterial');
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
        } catch (GameException $e) {
            $this->assertSame('insufficient', $e->errorCode);
        }
    }

    /** Anything the trader refuses can still be dropped -- that is the point. */
    public function test_unsellable_materials_can_still_be_thrown_away(): void
    {
        $add = new ReflectionMethod($this->game, 'addMaterial');
        $add->setAccessible(true);
        $add->invoke($this->game, $this->character, 'relic', 3);

        $this->standAtWoodcuttingVillage();

        try {
            $this->game->sellMaterial($this->character->fresh(), 'relic', 1);
            $this->fail('the trader bought a raid material');
        } catch (GameException $e) {
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
        } catch (GameException $e) {
            $this->assertSame('not_at_settlement', $e->errorCode);
        }

        try {
            $this->game->buyItem($this->character, 'stone_axe');
            $this->fail('bought gear with no shop in sight');
        } catch (GameException $e) {
            $this->assertSame('not_at_settlement', $e->errorCode);
        }
    }

    public function test_trading_works_once_standing_at_a_settlement(): void
    {
        $this->standAtWoodcuttingVillage();

        $reflection = new ReflectionMethod($this->game, 'addMaterial');
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

        $this->expectException(GameException::class);
        $this->game->buyItem($this->character, 'iron_hatchet');
    }

    /**
     * §8.0 -- every workbench reaches exactly as far as its tier allows, and no
     * recipe is stranded somewhere nothing can make it.
     */
    public function test_every_recipe_sits_at_a_bench_that_can_actually_make_it(): void
    {
        foreach (Catalog::items() as $key => $def) {
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

        $add = new ReflectionMethod($this->game, 'addMaterial');
        $add->setAccessible(true);
        foreach (['wood' => 40, 'ingots' => 40, 'planks' => 40, 'cloth' => 40, 'leather' => 40, 'heartknot' => 40] as $key => $qty) {
            $add->invoke($this->game, $this->character, $key, $qty);
        }

        // Common is fine here.
        $this->craftNow('hewn_axe');

        // Uncommon is not, even with every material in the bag.
        try {
            $this->game->startCraft($this->character->fresh(), 'iron_pickaxe');
            $this->fail('a village bench forged an uncommon pickaxe');
        } catch (GameException $e) {
            $this->assertSame('station', $e->errorCode);
            $this->assertStringContainsString('city', $e->getMessage());
        }
    }

    /** §3.2 -- gold stops at uncommon, at every settlement tier. */
    public function test_no_shop_anywhere_stocks_above_uncommon(): void
    {
        foreach (Catalog::items() as $key => $def) {
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
        foreach (Catalog::items() as $key => $def) {
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

        $this->expectException(GameException::class);
        $this->expectExceptionMessage('The trader will not touch that.');

        $this->game->sellMaterial($this->character, 'mythril_ore', 1);
    }

    /** §2 -- tier 3 materials are capped per wallet. */
    public function test_rare_materials_are_capped_per_wallet(): void
    {
        $service = new ReflectionMethod($this->game, 'addMaterial');
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
     * cost only the facts it cannot derive: what is worked out, who is standing
     * there, which §9.5.1 pack somebody has already fought, and where the
     * §9.5.7 corpses are. Shipping generated tiles was ~200KB per pan; this
     * guards against that creeping back in.
     */
    public function test_the_map_endpoint_sends_mutations_only(): void
    {
        $empty = $this->game->mapMutations($this->character);

        $this->assertSame(['depleted', 'occupied', 'cleared', 'carriers'], array_keys($empty));
        $this->assertSame([], $empty['depleted']);
        $this->assertSame([], $empty['occupied']);
        // Nothing has been fought yet, so nothing is subtracted.
        $this->assertSame([], $empty['cleared']);

        // A live mine is the one thing that has to show up.
        $this->game->startMining($this->character, $this->character->col, $this->character->row, Drops::GATHERING);

        // [col, row, bodies, seats] -- a gather is the hex worked with hands
        // (§4.0), so it is both a body at work and one of the two seats.
        $busy = $this->game->mapMutations($this->character->fresh());
        $this->assertSame(
            [[$this->character->col, $this->character->row, 1, 1]],
            $busy['occupied'],
        );

        // Small enough that the whole window is a rounding error on the wire.
        $this->assertLessThan(200, strlen(json_encode($busy)));
    }

    /**
     * §5.1 / §5.5 -- busy and shut are two different facts about a hex.
     *
     * The map fills a notch for anybody at work on the ground, whatever the
     * verb, because a hex somebody is standing on is not an empty one. Only
     * mining takes one of the two seats, so only mining can shut the hex --
     * and the two counts have to be able to disagree, or one of them is wrong.
     *
     * A hunt is the case that proves it: two hunters on a hex make it plainly
     * busy and leave both seats free.
     */
    public function test_a_hunt_is_a_body_at_work_and_never_one_of_the_two_seats(): void
    {
        [$col, $row] = $this->standOnAHerd();

        $this->equipBow();

        $hunter = $this->game->createCharacter(Player::create(['wallet' => '0xhunter']));
        $hunter->update(['col' => $col, 'row' => $row]);
        CharacterItem::create([
            'character_id' => $hunter->id,
            'item_key' => 'crude_bow',
            'durability' => 200,
            'equipped' => true,
            'options' => [],
        ]);

        $this->game->startHunt($hunter->fresh(), $col, $row);
        $this->game->startHunt($this->character->fresh(), $col, $row);

        $seen = $this->game->mapMutations($this->character->fresh())['occupied'];

        // Two bodies, no seats: busy, and open to anybody who came to dig.
        $this->assertSame([[$col, $row, 2, 0]], $seen);

        // And the ground agrees -- a hex two hunts deep still takes a miner.
        $this->assertSame(0, $this->game->buildTile($col, $row, $this->game->now())['slotsUsed']);
    }

    /**
     * §5.6 -- sight is one hex, and it is a server rule rather than a
     * rendering choice: a client that pans across the map must not be able to
     * learn where everyone else is mining.
     */
    public function test_the_map_endpoint_sees_only_two_hexes(): void
    {
        $sight = $this->game->sightRadius($this->character);
        $this->assertSame(Balance::SIGHT_RADIUS, $sight);

        // Somebody working a hex just outside it -- three hexes away, which the
        // old reach-as-sight rule would have shown and this one must not.
        $far = $this->game->createCharacter(Player::create(['wallet' => '0xfar']));
        $far->update(['col' => $this->character->col + $sight + 1, 'row' => $this->character->row]);
        $this->game->startMining($far, (int) $far->col, (int) $far->row, Drops::GATHERING);

        $this->assertSame([], $this->game->mapMutations($this->character)['occupied']);

        // Walk one hex and the same tile is knowable. Sight follows the
        // character, never the camera.
        $this->game->travelTo($this->character, (int) $this->character->col + 1, (int) $this->character->row);
        $this->arrive($this->character);

        $seen = $this->game->mapMutations($this->character->fresh())['occupied'];
        $this->assertSame([[(int) $far->col, (int) $far->row, 1, 1]], $seen);
    }

    /**
     * §5.6 -- on the road you are watching your feet.
     *
     * This is also what makes a long walk free: sight of zero means the map
     * query has nothing to scan, so a journey of two hundred hexes costs the
     * same two requests as a journey of one.
     */
    public function test_sight_closes_to_nothing_while_traveling(): void
    {
        // Somebody working the hex right next to you -- plainly in sight.
        $near = $this->game->createCharacter(Player::create(['wallet' => '0xnear']));
        $near->update(['col' => (int) $this->character->col + 1, 'row' => (int) $this->character->row]);
        $this->game->startMining($near, (int) $near->col, (int) $near->row, Drops::GATHERING);

        $this->assertCount(1, $this->game->mapMutations($this->character)['occupied']);

        $this->game->travelTo($this->character, (int) $this->character->col + 4, (int) $this->character->row);

        $this->assertSame(0, $this->game->sightRadius($this->character));
        $this->assertSame([], $this->game->mapMutations($this->character)['occupied']);

        // And it comes back the moment the walking stops.
        $this->arrive($this->character);
        $this->assertSame(
            Balance::SIGHT_RADIUS,
            $this->game->sightRadius($this->character->fresh()),
        );
    }

    /**
     * §5.6 -- the preview endpoint is bounded by the same disc.
     *
     * Without this it would be the map query in a slower form: one tile per
     * request, but nothing stopping a client from asking about every hex on the
     * map and reading off the haul, the timer and the miners on each.
     */
    public function test_a_hex_outside_sight_will_not_be_costed(): void
    {
        $col = (int) $this->character->col + Balance::SIGHT_RADIUS + 1;
        $row = (int) $this->character->row;

        $preview = $this->game->previewTile($this->character, $col, $row);

        $this->assertTrue($preview['unseen']);
        $this->assertFalse($preview['canMine']);
        $this->assertNull($preview['material'], 'an unscouted hex must not name its seam');
        $this->assertSame(0, $preview['yield']);
        $this->assertSame(0, $preview['seconds']);

        // The hex underfoot is distance 0, so it stays costed regardless.
        $underfoot = $this->game->previewTile(
            $this->character,
            (int) $this->character->col,
            (int) $this->character->row,
        );
        $this->assertFalse($underfoot['unseen']);
    }

    /**
     * §5.6 -- there is no reach any more. Anywhere on the map is walkable,
     * scouted or not, and the only thing it costs is the clock.
     */
    public function test_travel_reaches_any_hex_on_the_map_however_far(): void
    {
        $far = [
            'col' => (int) $this->character->col + 120,
            'row' => (int) $this->character->row,
        ];

        $travel = $this->game->travelTo($this->character, $far['col'], $far['row']);

        $this->assertGreaterThan(Balance::SIGHT_RADIUS, $travel['hexes']);
        $this->assertGreaterThan(0, $travel['endsAt'] - $travel['startedAt']);

        $this->arrive($this->character);
        $this->assertSame($far['col'], (int) $this->character->col);
    }

    /**
     * The edge of the map is the one place a road cannot go.
     *
     * §5.1 -- coordinates are signed and the origin is the middle, so the edge
     * is on all four sides and -1 is ordinary ground. Every one of the four is
     * checked: an inclusive bound is easy to get wrong on exactly one of them.
     */
    public function test_travel_refuses_to_leave_the_map(): void
    {
        $radius = Balance::mapRadius();

        // The corners themselves are on the map, and must stay walkable.
        $this->assertTrue(WorldGen::inBounds(-$radius, -$radius));
        $this->assertTrue(WorldGen::inBounds($radius, $radius));

        $off = [[-$radius - 1, 0], [$radius + 1, 0], [0, -$radius - 1], [0, $radius + 1]];

        foreach ($off as [$col, $row]) {
            $this->assertFalse(WorldGen::inBounds($col, $row));

            try {
                $this->game->travelTo($this->character->fresh(), $col, $row);
                $this->fail("travel to {$col},{$row} was allowed off the map");
            } catch (GameException $e) {
                $this->assertSame('off_map', $e->errorCode);
            }
        }
    }

    // ------------------------------------------------------------ explorer §7.5

    /** Put the road-job at a level, the way walking eventually would. */
    private function explorerAt(int $level): void
    {
        $this->character->jobLevels()
            ->where('job_key', 'explorer')
            ->update(['level' => $level, 'xp' => 0]);
        $this->character->unsetRelation('jobLevels');
    }

    /**
     * §7.5 -- walk far enough and the road hands it over.
     *
     * The real path rather than a loop of buyNode: claimWayfaring() is what the
     * game calls, so a test that reached the same rows another way would stop
     * proving the thing it is here to prove.
     */
    private function claimExplorerTo(int $level): void
    {
        $this->explorerAt($level);
        $this->game->claimWayfaring($this->character->fresh());
        $this->character->unsetRelation('nodes');
    }

    /**
     * §7.5 -- the road pays the Explorer and nobody else.
     *
     * Both halves matter. Walking has to level *something*, or a map with no
     * reach limit is just a long wait; and it must not level the character, or
     * the cheapest XP in the game would be pressing travel and going to bed.
     */
    public function test_walking_levels_the_explorer_and_never_the_character(): void
    {
        $before = $this->character->jobLevels()->where('job_key', 'explorer')->first();
        $characterXp = (int) $this->character->xp;

        $travel = $this->game->travelTo(
            $this->character,
            (int) $this->character->col + 6,
            (int) $this->character->row,
        );
        $this->arrive($this->character);

        $after = $this->character->jobLevels()->where('job_key', 'explorer')->first();

        $this->assertGreaterThan(
            (int) $before->xp,
            (int) $after->xp,
            'a six-hex walk taught the explorer nothing',
        );
        $this->assertSame($characterXp, (int) $this->character->fresh()->xp, 'travel paid character XP');

        // Six hexes is 30 XP, which is already past the 17 that job level 2
        // costs -- so the row shows level 2 and the remainder, not the total.
        $earned = $travel['hexes'] * Balance::EXPLORER_XP_PER_HEX;
        $this->assertSame(2, (int) $after->level);
        $this->assertSame($earned - Balance::jobXpForLevel(1), (int) $after->xp);
    }

    /** Stopping short pays for the hexes that happened, and no more. */
    public function test_a_journey_abandoned_pays_for_the_ground_covered(): void
    {
        $this->game->travelTo($this->character, (int) $this->character->col + 6, (int) $this->character->row);
        $this->rewind($this->character, 2 * $this->perHex($this->character) + 5);

        $stop = $this->game->cancelTravel($this->character);

        $this->assertSame(2, $stop['hexes']);
        $this->assertSame(
            2 * Balance::EXPLORER_XP_PER_HEX,
            (int) $this->character->jobLevels()->where('job_key', 'explorer')->value('xp'),
        );
    }

    /**
     * §7.5 -- the road hands its skills over, and they cost no point.
     *
     * They used to need pressing for, on the argument that a reward arriving on
     * its own is a panel that quietly changed. The press was the wrong answer to
     * a real problem: a wayfaring node cannot be declined, cannot be spent
     * elsewhere and has no wrong order to take it in, so the button's only
     * answer was yes. What the press was protecting -- that there is a MOMENT
     * where it is given to you -- is the client's job now: the state carries the
     * owned nodes, so a list that grew on its own is announced.
     *
     * If a wayfaring node ever cost a point, the hundred-point cap (§7.4.1)
     * would quietly become ninety-five, and the tree that is supposed to reward
     * walking would start competing with the benches instead.
     */
    public function test_explorer_skills_are_claimed_by_walking_and_cost_no_points(): void
    {
        // A character who has walked nowhere owns nothing.
        $this->assertNotContains('explorer.deep_pockets', $this->game->ownedNodes($this->character));

        // §7.5 -- one skill per level, not a row at a time. Level 2 opens the
        // first of row one and nothing else in it.
        $this->claimExplorerTo(2);
        $owned = $this->game->ownedNodes($this->character->fresh());
        $this->assertContains('explorer.deep_pockets', $owned);
        $this->assertNotContains('explorer.second_strap', $owned, 'the whole row opened for one level');
        $this->assertSame(0, $this->game->skillPoints($this->character)['spent']);

        $this->claimExplorerTo(4);

        $this->assertContains('explorer.second_strap', $this->game->ownedNodes($this->character->fresh()));
        $this->assertSame(
            0,
            $this->game->skillPoints($this->character->fresh())['spent'],
            'a free node was billed to the point ledger',
        );
    }

    /**
     * §7.5 -- and the walking is still the price: no level, no skill.
     *
     * The claim sweeps what the road has paid for and not one node further, so
     * a character parked at level 1 has nothing waiting however many character
     * levels they have banked.
     */
    public function test_the_road_hands_over_nothing_it_has_not_paid_for(): void
    {
        $this->character->update(['level' => 40]);
        $this->explorerAt(1);

        $this->assertSame([], $this->game->claimWayfaring($this->character->fresh()));
        $this->assertNotContains(
            'explorer.deep_pockets',
            $this->game->ownedNodes($this->character->fresh()),
        );

        $this->expectException(GameException::class);
        $this->game->buyNode($this->character->fresh(), 'explorer.deep_pockets');
    }

    /**
     * §7.5 -- walking claims it, and the walk is the only thing that does.
     *
     * End to end through travel rather than by poking the job level: this is
     * the hook, and a claim that fired anywhere else would be a second way in.
     */
    public function test_a_walk_long_enough_hands_the_first_skill_over(): void
    {
        $this->assertNotContains('explorer.deep_pockets', $this->game->ownedNodes($this->character));

        // Two Explorer levels is a short walk, and a walk is all it may cost.
        $this->explorerAt(1);
        $this->character->jobLevels()->where('job_key', 'explorer')->update([
            'xp' => Balance::jobXpForLevel(2) - Balance::EXPLORER_XP_PER_HEX,
        ]);
        $this->character->unsetRelation('jobLevels');

        $far = $this->game->travelTo($this->character->fresh(), (int) $this->character->col + 1, (int) $this->character->row);
        $this->assertNotNull($far);
        $this->arrive($this->character);

        $this->assertContains(
            'explorer.deep_pockets',
            $this->game->ownedNodes($this->character->fresh()),
            'the road paid for a skill and did not hand it over',
        );
    }

    /** §7.5 -- two hexes of eye on top of the base one, earned one at a time. */
    public function test_the_explorer_tree_widens_sight_and_then_stops(): void
    {
        $this->assertSame(Balance::SIGHT_RADIUS, $this->game->sightRadius($this->character));

        // High Ground, the end of row two. The eye is the rarest thing the road
        // pays in, so it arrives later than a strap does.
        $this->claimExplorerTo(12);
        $this->assertSame(Balance::SIGHT_RADIUS + 1, $this->game->sightRadius($this->character->fresh()));

        $this->claimExplorerTo(Balance::JOB_MAX_LEVEL);
        $this->assertSame(
            Balance::SIGHT_RADIUS + Balance::SKILL_SIGHT_CAP,
            $this->game->sightRadius($this->character->fresh()),
            'sight passed the cap that keeps the map query small',
        );
    }

    /**
     * §7.5 + §8.1 rule 1 -- the free tree cannot move a stat at all.
     *
     * The chain used to write travelSpeed and lean on the clamp to stay honest.
     * It writes nothing now, and that is a stronger rule than a clamp: a tree
     * that costs no skill points has no business touching the aggregate gear,
     * options and potions share. What it pays in is capability -- the eye and
     * the back -- and both of those are counts with their own caps.
     */
    public function test_a_maxed_explorer_moves_no_stat_whatsoever(): void
    {
        $this->explorerAt(Balance::JOB_MAX_LEVEL);

        $written = array_filter(
            Jobs::nodesFor('explorer'),
            fn (array $n) => $n['effect']['kind'] === 'stat',
        );

        $this->assertSame([], $written, 'a granted node writes a stat again');
        $this->assertSame(
            [],
            $this->game->nodeEffects($this->character->fresh())['stats'],
            'the chain reached the stat aggregate',
        );

        // And with nothing worn, every stat is still exactly zero: a full
        // fifteen rungs of walking buys no power of any kind.
        foreach ($this->game->bonuses($this->character->fresh()) as $stat => $value) {
            $this->assertSame(0.0, (float) $value, "{$stat} moved on a maxed explorer");
        }
    }

    // ----------------------------------------------------------------- bag §7.6

    /**
     * Put something on every strap, §7.6.
     *
     * The cap is roomier than the material list is long, so this walks the
     * three things that take a row -- stacks, then the potion shelf, then unworn
     * gear -- until the bag is full. One unit each, so what it fills is rows and
     * never weight: a test about straps must not accidentally be a test about
     * how heavy the bag is.
     *
     * Rows are written straight to the tables rather than granted through the
     * service, because the service is the thing under test here: `addMaterial`
     * refuses a new kind once the straps are gone, which is exactly the rule
     * these tests are setting up to exercise.
     *
     * @param  array<int,string>  $except  material keys to leave off the straps
     */
    private function fillStraps(array $except = [], int $leaveFree = 0): void
    {
        $target = Balance::BAG_ROWS - $leaveFree;

        $rows = fn () => $this->game->bag($this->character->fresh())['rows'];

        foreach (array_keys(Catalog::materials()) as $key) {
            if ($rows() >= $target) {
                return;
            }
            if (in_array($key, $except, true)) {
                continue;
            }
            CharacterMaterial::create([
                'character_id' => $this->character->id,
                'material_key' => $key,
                'quantity' => 1,
            ]);
        }

        foreach (Catalog::items() as $key => $def) {
            if ($rows() >= $target) {
                return;
            }
            if (empty($def['consumable'])) {
                continue;
            }
            $this->character->consumables()->create(['item_key' => $key, 'quantity' => 1]);
        }

        while ($rows() < $target) {
            CharacterItem::create([
                'character_id' => $this->character->id,
                'item_key' => 'stone_axe',
                'durability' => 40,
                'equipped' => false,
            ]);
        }
    }

    /** Somewhere to walk to that is definitely not here. */
    private function elsewhere(): array
    {
        return [(int) $this->character->col + 3, (int) $this->character->row];
    }

    /**
     * §7.6 -- the bag holds everything, and counts it two ways.
     *
     * Units are the weight, rows are how many separate things it is. A potion
     * is not a material and a spare axe is neither, but all three are in the
     * same pack and all three count -- otherwise "what do I carry" would only
     * ever be a question about ore.
     */
    public function test_the_bag_counts_materials_potions_and_unworn_gear(): void
    {
        $this->give(['wood' => 10, 'stone' => 5]);
        $this->character->consumables()->create(['item_key' => 'road_tonic', 'quantity' => 3]);
        CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => 'stone_axe',
            'durability' => 40,
            'equipped' => false,
        ]);

        $bag = $this->game->bag($this->character->fresh());

        $this->assertSame(19, $bag['units'], '10 wood + 5 stone + 3 tonics + 1 axe');
        $this->assertSame(4, $bag['rows'], 'two stacks, one shelf of tonics, one axe');
        $this->assertSame(Balance::BAG_UNITS, $bag['unitCap']);
        $this->assertSame(Balance::BAG_ROWS, $bag['rowCap']);
        $this->assertFalse($bag['over']);
    }

    /**
     * §7.6 -- worn is not carried, so equipping is itself a way to make room.
     *
     * A prospector who has committed to a line should not be charged a strap for
     * the tool that commitment is made of.
     */
    public function test_equipping_takes_a_tool_out_of_the_bag(): void
    {
        $item = CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => 'stone_axe',
            'durability' => 40,
            'equipped' => false,
        ]);

        $this->assertSame(1, $this->game->bag($this->character->fresh())['rows']);

        $this->game->equipItem($this->character->fresh(), $item->id);
        $this->assertSame(0, $this->game->bag($this->character->fresh())['rows']);

        $this->game->unequipItem($this->character->fresh(), $item->id);
        $this->assertSame(1, $this->game->bag($this->character->fresh())['rows']);
    }

    /**
     * §7.6 -- and taking something off is the one action that *adds* a row.
     *
     * With no strap free it stays on the belt, because the belt is the only
     * place left for it. Refused rather than silently dropped: an axe that
     * vanished because the bag was full would be the worst reading of the rule.
     */
    public function test_a_full_bag_will_not_let_you_take_your_axe_off(): void
    {
        $item = CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => 'stone_axe',
            'durability' => 40,
            'equipped' => true,
        ]);

        $this->fillStraps();

        try {
            $this->game->unequipItem($this->character->fresh(), $item->id);
            $this->fail('unequipped into a bag with no room');
        } catch (GameException $e) {
            $this->assertSame('no_room', $e->errorCode);
        }

        $this->assertTrue((bool) $item->fresh()->equipped, 'the axe came off anyway');
    }

    /**
     * §7.6 -- too much to carry means you do not carry it anywhere.
     *
     * The refusal is travel and only travel. It is the second one the map has,
     * after the edge (§5.6), and it is the only one a player can undo.
     */
    public function test_too_many_units_pins_you_to_the_hex(): void
    {
        $this->give(['wood' => Balance::BAG_UNITS + 1]);

        [$col, $row] = $this->elsewhere();

        try {
            $this->game->travelTo($this->character->fresh(), $col, $row);
            $this->fail('walked off with an overloaded bag');
        } catch (GameException $e) {
            $this->assertSame('overloaded', $e->errorCode);
        }

        // The way out is always in reach: drop one and the road opens.
        $this->game->discardMaterial($this->character->fresh(), 'wood', 1);
        $travel = $this->game->travelTo($this->character->fresh(), $col, $row);

        $this->assertSame($col, $travel['toCol']);
    }

    /**
     * §7.6 -- the second limit refuses instead of pinning.
     *
     * A row is a place to put a thing, not weight, and there is nowhere to put a
     * thing that has no strap. So a full bag turns away a kind it is not already
     * carrying -- and still takes more of a kind it is.
     */
    public function test_a_full_bag_turns_away_a_kind_it_is_not_carrying(): void
    {
        $spare = array_key_first(Catalog::materials());
        $this->fillStraps([$spare]);

        $bag = $this->game->bag($this->character->fresh());
        $this->assertSame(Balance::BAG_ROWS, $bag['rows']);
        $this->assertLessThan($bag['unitCap'], $bag['units'], 'this must be about rows, not weight');
        $this->assertFalse($bag['over'], 'full is not over -- the limit was never passed');

        // A kind it is not holding does not land.
        $this->give([$spare => 5]);
        $this->assertSame(0, $this->game->held($this->character->fresh(), $spare));
        $this->assertSame(Balance::BAG_ROWS, $this->game->bag($this->character->fresh())['rows']);

        // A kind it is holding still does: the limit is on variety, not amount.
        $carried = (string) $this->character->fresh()->materials()->value('material_key');
        $before = $this->game->held($this->character->fresh(), $carried);
        $this->give([$carried => 9]);
        $this->assertSame($before + 9, $this->game->held($this->character->fresh(), $carried));
    }

    /**
     * §7.6 -- and it is said before the hour of work, not after it.
     *
     * A dig whose haul has nowhere to land would be an hour spent for nothing,
     * which is the one way this rule could be worse than no rule.
     */
    public function test_a_full_bag_refuses_the_dig_rather_than_the_haul(): void
    {
        $this->equipToolForHere();
        $col = (int) $this->character->col;
        $row = (int) $this->character->row;
        $material = $this->game->previewTile($this->character->fresh(), $col, $row)['material'];

        // Every strap taken, and none of them by what this hex pays.
        $this->fillStraps([$material]);

        try {
            $this->game->startMining($this->character->fresh(), $col, $row);
            $this->fail('started a dig with nowhere to put it');
        } catch (GameException $e) {
            $this->assertSame('no_room', $e->errorCode);
        }

        // Nothing was spent and nothing was started: the tile is still free.
        $this->assertNull($this->game->miningTrip($this->character->fresh()));
    }

    /**
     * §7.6 -- an overloaded bag stops the road, and nothing else.
     *
     * Working the hex you are standing on, selling, processing and dropping all
     * have to keep working, because every one of them is a way out. A full bag
     * that also refused the ways to empty it would be a dead end rather than a
     * decision.
     */
    public function test_an_overloaded_bag_still_lets_you_work_and_sell(): void
    {
        $this->equipToolForHere();
        $this->give(['wood' => Balance::BAG_UNITS + 20]);
        $this->assertTrue($this->game->bag($this->character->fresh())['over']);

        $job = $this->game->startMining($this->character->fresh(), (int) $this->character->col, (int) $this->character->row);
        $this->assertNotNull($job);

        $dropped = $this->game->discardMaterial($this->character->fresh(), 'wood', 20);
        $this->assertSame(20, $dropped);
        $this->assertFalse($this->game->bag($this->character->fresh())['over']);
    }

    /**
     * §7.5 + §7.6 -- the road is the only thing that widens the bag, and it
     * stops where the caps do.
     *
     * Fifteen skills, ten units or four straps each, one every second job level
     * from 2 to 30. The climb is what is being tested here as much as the
     * ceiling: an early skill has to be felt, or a tree nobody spends points on
     * is a tree nobody notices.
     */
    public function test_the_explorer_tree_widens_the_bag_and_then_stops(): void
    {
        $bag = $this->game->bag($this->character);
        $this->assertSame(Balance::BAG_UNITS, $bag['unitCap']);
        $this->assertSame(Balance::BAG_ROWS, $bag['rowCap']);

        // Reaching the level is not having the skill (§7.5): it opens, and
        // then it is claimed.
        $this->explorerAt(2);
        $this->assertSame(
            Balance::BAG_UNITS,
            $this->game->bag($this->character->fresh())['unitCap'],
            'the pack widened without anybody claiming anything',
        );

        // The first skill, at Explorer 2: four hexes of walking, ten units of
        // pack. One skill per level, so the straps are still a level away.
        $this->claimExplorerTo(2);
        $bag = $this->game->bag($this->character->fresh());
        $this->assertSame(Balance::BAG_UNITS + 10, $bag['unitCap']);
        $this->assertSame(Balance::BAG_ROWS, $bag['rowCap'], 'the straps arrived with the room');

        $this->claimExplorerTo(4);
        $bag = $this->game->bag($this->character->fresh());
        $this->assertSame(Balance::BAG_UNITS + 10, $bag['unitCap']);
        $this->assertSame(Balance::BAG_ROWS + 4, $bag['rowCap']);

        $this->claimExplorerTo(Balance::JOB_MAX_LEVEL);
        $bag = $this->game->bag($this->character->fresh());
        $this->assertSame(Balance::BAG_UNITS + Balance::SKILL_BAG_UNITS_CAP, $bag['unitCap']);
        $this->assertSame(Balance::BAG_ROWS + Balance::SKILL_BAG_ROWS_CAP, $bag['rowCap']);
        $this->assertSame(200, $bag['unitCap'], 'the ceiling moved without the doc moving with it');
        $this->assertSame(50, $bag['rowCap'], 'the ceiling moved without the doc moving with it');
    }

    /** The generation parameters the client needs, and nothing player-specific. */
    public function test_world_config_carries_generation_parameters(): void
    {
        $config = $this->game->worldConfig();

        foreach (['seed', 'size', 'radius', 'biomeCell', 'rings', 'namePrefixes', 'dungeonSites'] as $key) {
            $this->assertArrayHasKey($key, $config, "world config is missing {$key}");
        }

        // §5.1 -- the map is square, so one radius describes it and the side is
        // derived. Two axes would be two chances to configure a rectangle the
        // ring maths does not expect.
        $this->assertSame(Balance::mapRadius() * 2 + 1, $config['size']);
        $this->assertArrayNotHasKey('cols', $config, 'the map is square; there is no separate width');

        $this->assertCount(5, $config['dungeonSites']);
        $this->assertLessThan(4000, strlen(json_encode($config)));
    }

    /** Walk the character to the village the spawn rule guarantees is in range. */
    /** @return array{col:int,row:int} */
    private function openNeighbor(int $col, int $row): array
    {
        foreach ([[1, 0], [0, 1], [-1, 0], [0, -1], [1, 1], [-1, -1]] as [$dc, $dr]) {
            if (WorldGen::settlementAt($col + $dc, $row + $dr) === null) {
                return ['col' => $col + $dc, 'row' => $row + $dr];
            }
        }

        $this->fail('a settlement completely surrounded by settlements');
    }

    private function standAtWoodcuttingVillage(): array
    {
        $range = Balance::SPAWN_VILLAGE_RADIUS;

        for ($dc = -$range; $dc <= $range; $dc++) {
            for ($dr = -$range; $dr <= $range; $dr++) {
                $s = WorldGen::settlementAt($this->character->col + $dc, $this->character->row + $dr);
                if ($s && in_array('woodcutting', $s['lines'], true)) {
                    $this->character->update(['col' => $s['col'], 'row' => $s['row']]);
                    $this->character->refresh();

                    return $s;
                }
            }
        }

        $this->fail('spawn guarantee broken: no woodcutting village in spawn radius');
    }

    /** One hex at a time: mine, wait, claim. No queue of hexes. */
    public function test_only_one_mine_at_a_time(): void
    {
        $this->equipToolForHere();
        $col = $this->character->col;
        $row = $this->character->row;

        $job = $this->game->startMining($this->character, $col, $row);

        // The tile still has a free slot, but the character does not.
        $preview = $this->game->previewTile($this->character->fresh(), $col, $row);
        $this->assertFalse($preview['canMine']);
        $this->assertStringContainsString('Finish that one first', $preview['reason']);

        // Finishing is not claiming: the mine still occupies you until collected.
        $job->update(['ends_at' => $this->game->now() - 1]);
        $preview = $this->game->previewTile($this->character->fresh(), $col, $row);
        $this->assertFalse($preview['canMine']);
        $this->assertStringContainsString('Claim it', $preview['reason']);

        // Collecting clears the mine. Whether the hex survived it is a separate
        // question (§5.1 -- one haul off a counted seam), so assert the gate
        // rather than the tile, then put the seam back so the gate is what the
        // last check is actually measuring.
        $this->game->collectJob($this->character->fresh(), $job->id);
        $this->assertNull($this->game->miningTrip($this->character->fresh()));

        Tiles::reset($col, $row);
        $this->assertTrue($this->game->previewTile($this->character->fresh(), $col, $row)['canMine']);
    }

    /**
     * §5.1 -- a hex holds a known number of hauls, and the count is not a roll.
     *
     * This is the whole of what replaced a 34% chance per mine. The interesting
     * property is that it is KNOWABLE: the client derives the capacity from the
     * seed, so a card can say "three of eight taken" and a prospector can decide
     * whether the seam is worth the walk back.
     */
    public function test_a_hex_holds_a_counted_number_of_hauls(): void
    {
        $col = (int) $this->character->col;
        $row = (int) $this->character->row;

        $capacity = WorldGen::tileExtractions(
            WorldGen::generateTile($col, $row, 0)['baseYield'],
        );

        $this->assertGreaterThanOrEqual(Balance::TILE_EXTRACTIONS_MIN, $capacity);
        $this->assertLessThanOrEqual(Balance::TILE_EXTRACTIONS_MAX, $capacity);

        $now = $this->game->now();

        // Every haul but the last leaves the seam open, and no two runs of the
        // same hex disagree -- there is nothing random to disagree about.
        for ($i = 1; $i < $capacity; $i++) {
            $state = Tiles::take($col, $row, $capacity, $now);
            $this->assertSame($i, $state['taken']);
            $this->assertFalse($state['depleted'], "hex closed early, on haul {$i}");
            $this->assertSame(0, $state['regrowsAt']);
        }

        $last = Tiles::take($col, $row, $capacity, $now);
        $this->assertTrue($last['depleted'], 'the last haul left the seam open');
        $this->assertSame($now + Balance::scaled(Balance::REGROW_MS), $last['regrowsAt']);

        // And a closed seam takes nothing more, so a double collection cannot
        // push it past its own clock.
        $again = Tiles::take($col, $row, $capacity, $now);
        $this->assertSame($last['regrowsAt'], $again['regrowsAt']);
        $this->assertSame($capacity, $again['taken']);
    }

    /**
     * §5.1 -- the count is SHARED. Everybody's mines come off the same seam.
     *
     * The anti-farm rule, and the same one that keeps a cleared pack cleared
     * for everybody (§9.5.1): you cannot have a hex to yourself.
     */
    public function test_the_seam_is_shared_between_characters(): void
    {
        $col = (int) $this->character->col;
        $row = (int) $this->character->row;
        $capacity = 8;
        $now = $this->game->now();

        Tiles::take($col, $row, $capacity, $now);

        $other = $this->game->createCharacter(
            Player::create(['wallet' => '0xshared', 'session_id' => '0xshared']),
        );
        $other->update(['col' => $col, 'row' => $row]);

        // Nothing about the second character resets the seam.
        $state = Tiles::take($col, $row, $capacity, $now);
        $this->assertSame(2, $state['taken'], 'the second character got a fresh hex');
    }

    /**
     * §5.1 -- what a hex is worth over its life is roughly level; what differs
     * is how many walks it costs to collect.
     *
     * The richest ground is emptied fastest, on purpose: a good hex is not a hex
     * you can sit on. Without the inverse the best seam on the map would be both
     * the biggest haul AND the longest-lived, which is a camp rather than a find.
     */
    public function test_a_richer_hex_is_worked_out_faster(): void
    {
        $previous = Balance::TILE_EXTRACTIONS_MAX + 1;

        for ($yield = Balance::TILE_YIELD_MIN; $yield <= Balance::TILE_YIELD_MAX; $yield++) {
            $count = WorldGen::tileExtractions($yield);

            $this->assertLessThanOrEqual($previous, $count, "yield {$yield} outlasts a poorer hex");
            $this->assertGreaterThanOrEqual(Balance::TILE_EXTRACTIONS_MIN, $count);
            $this->assertLessThanOrEqual(Balance::TILE_EXTRACTIONS_MAX, $count);
            $previous = $count;
        }

        // The ends of the band are the band, exactly.
        $this->assertSame(
            Balance::TILE_EXTRACTIONS_MAX,
            WorldGen::tileExtractions(Balance::TILE_YIELD_MIN),
        );
        $this->assertSame(
            Balance::TILE_EXTRACTIONS_MIN,
            WorldGen::tileExtractions(Balance::TILE_YIELD_MAX),
        );
    }

    /** A mine pins you to the hex you are working. */
    public function test_a_trip_stops_you_traveling(): void
    {
        $this->equipToolForHere();
        $col = $this->character->col;
        $row = $this->character->row;
        $job = $this->game->startMining($this->character, $col, $row);

        try {
            $this->game->travelTo($this->character, $col + 1, $row);
            $this->fail('traveled away from a running mine');
        } catch (GameException $e) {
            $this->assertSame('working', $e->errorCode);
        }
        $this->assertSame($col, $this->character->fresh()->col);

        // Dropping the mine forfeits the haul (§11.1) and frees you to move.
        $this->game->abandonJob($this->character, $job->id);
        $this->game->travelTo($this->character, $col + 1, $row);
        $this->arrive($this->character);
        $this->assertSame($col + 1, $this->character->fresh()->col);
    }

    /** §6.3 -- one run of a line at one settlement, unless the tree bought more. */
    public function test_only_one_run_of_a_line_at_one_settlement(): void
    {
        $settlement = $this->standAtWoodcuttingVillage();

        $addMaterial = new ReflectionMethod($this->game, 'addMaterial');
        $addMaterial->setAccessible(true);
        $addMaterial->invoke($this->game, $this->character, 'wood', 40);

        $this->game->startProcessing($this->character, $settlement['id'], 'planks', 1);

        try {
            $this->game->startProcessing($this->character->fresh(), $settlement['id'], 'planks', 1);
            $this->fail('queued a second line while already helping with one');
        } catch (GameException $e) {
            $this->assertSame('busy', $e->errorCode);
        }
    }

    /**
     * §6.1 + §8.4 -- work left at one settlement never closes another.
     *
     * The run cap used to be per CHARACTER across the whole map, so a run of
     * planks left at a village four days' walk away refused every saw pit in
     * the world -- while §8.4 argued in the same breath that "the real limit on
     * how much you have going at once is still the walking". Two rules about
     * one thing, disagreeing. The walking is the limit now.
     */
    public function test_work_left_behind_does_not_close_the_next_settlement(): void
    {
        $first = $this->standAtWoodcuttingVillage();
        $this->give(['wood' => 80, 'planks' => 8, 'heartknot' => 4]);

        $this->game->startProcessing($this->character->fresh(), $first['id'], 'planks', 1);

        $second = $this->anotherWoodcuttingSettlement($first);
        $this->character->update(['col' => $second['col'], 'row' => $second['row']]);

        $job = $this->game->startProcessing($this->character->fresh(), $second['id'], 'planks', 1);
        $this->assertSame('processing', $job->kind);
        $this->assertSame(
            2,
            $this->character->fresh()->jobs()->where('kind', 'processing')->count(),
            'a run at one village refused a run at another',
        );

        // And the bench is a different building again: the run parked here does
        // not stop a craft here, and neither of them stops the other's bank.
        $craft = $this->game->startCraft($this->character->fresh(), 'hewn_axe');
        $this->assertSame('craft', $craft->kind);
    }

    /**
     * §6.1 + §8.4 -- and the ceiling on how much may be out at once.
     *
     * A cap is still needed rather than none at all: §2 assumes thousands of
     * bots, and an unbounded queue of parked work is a wallet running two
     * hundred benches it never walks between.
     */
    public function test_ten_lots_of_work_is_the_ceiling(): void
    {
        $this->standAtWoodcuttingVillage();
        $this->give(['wood' => 400]);

        $where = $this->woodcuttingSettlements(Balance::OUTSTANDING_WORK_CAP + 1);

        for ($i = 0; $i < Balance::OUTSTANDING_WORK_CAP; $i++) {
            $this->character->update(['col' => $where[$i]['col'], 'row' => $where[$i]['row']]);
            $this->game->startProcessing($this->character->fresh(), $where[$i]['id'], 'planks', 1);
        }

        $this->assertSame(
            Balance::OUTSTANDING_WORK_CAP,
            $this->game->outstandingWork($this->character->fresh()),
            'ten separate settlements did not each take a run',
        );

        // The eleventh is refused, and refused at a settlement with nothing of
        // yours in it -- so it is the ceiling talking rather than the bench.
        $last = $where[Balance::OUTSTANDING_WORK_CAP];
        $this->character->update(['col' => $last['col'], 'row' => $last['row']]);

        try {
            $this->game->startProcessing($this->character->fresh(), $last['id'], 'planks', 1);
            $this->fail('an eleventh lot of work was left behind');
        } catch (GameException $e) {
            $this->assertSame('busy', $e->errorCode);
            $this->assertStringContainsString('10', $e->getMessage());
        }
    }

    /**
     * The nearest N settlements that run the woodcutting line, character first.
     *
     * @return list<array<string,mixed>>
     */
    private function woodcuttingSettlements(int $want): array
    {
        $found = [];

        for ($radius = 0; $radius <= 100 && count($found) < $want; $radius++) {
            for ($dc = -$radius; $dc <= $radius; $dc++) {
                for ($dr = -$radius; $dr <= $radius; $dr++) {
                    $s = WorldGen::settlementAt((int) $this->character->col + $dc, (int) $this->character->row + $dr);
                    if ($s && in_array('woodcutting', $s['lines'], true)) {
                        $found[$s['id']] = $s;
                    }
                }
            }
        }

        $this->assertGreaterThanOrEqual($want, count($found), 'not enough woodcutting settlements to test the ceiling');

        return array_slice(array_values($found), 0, $want);
    }

    /**
     * A settlement running the same line as this one, and never this one.
     *
     * @param  array<string,mixed>  $not
     * @return array<string,mixed>
     */
    private function anotherWoodcuttingSettlement(array $not): array
    {
        for ($radius = 1; $radius <= 40; $radius++) {
            for ($dc = -$radius; $dc <= $radius; $dc++) {
                for ($dr = -$radius; $dr <= $radius; $dr++) {
                    $s = WorldGen::settlementAt((int) $not['col'] + $dc, (int) $not['row'] + $dr);
                    if ($s && $s['id'] !== $not['id'] && in_array('woodcutting', $s['lines'], true)) {
                        return $s;
                    }
                }
            }
        }

        $this->fail('no second woodcutting settlement anywhere near the spawn');
    }

    /** §6.2 -- the helper bonus only covers the time you actually stood there. */
    public function test_walking_away_gives_back_the_presence_bonus(): void
    {
        $settlement = $this->standAtWoodcuttingVillage();
        $away = $this->openNeighbor($settlement['col'], $settlement['row']);

        // Arrive properly: presence is picked up by landing, not by existing.
        $this->character->update(['col' => $away['col'], 'row' => $away['row']]);
        $this->game->travelTo($this->character, $settlement['col'], $settlement['row']);
        $this->arrive($this->character);

        $addMaterial = new ReflectionMethod($this->game, 'addMaterial');
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
        // other players: one of the character's own would mine the
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

        $this->expectException(GameException::class);
        $this->game->startProcessing($this->character, $settlement['id'], 'planks', 1);
    }

    // -------------------------------------------------- processing jobs §6

    /** Run a whole planks job at the village and collect it. */
    private function runPlanks(int $batches = 1): array
    {
        $settlement = $this->standAtWoodcuttingVillage();
        $this->give(['wood' => 60]);

        $job = $this->game->startProcessing($this->character->fresh(), $settlement['id'], 'planks', $batches);
        $job->update(['ends_at' => $this->game->now() - 1]);

        return [$job, $this->game->collectJob($this->character->fresh(), $job->id)];
    }

    /**
     * §6 -- a finished run teaches the line that ran it, and only that line.
     *
     * The same rule the benches already follow: a Smith's forging does not
     * teach the Armorer, and sawing planks does not teach the Tanner. Without
     * it, running any one line would level all five and the five trees would be
     * one tree with five names.
     */
    public function test_a_finished_run_levels_its_own_processing_job_and_no_other(): void
    {
        [$job] = $this->runPlanks();

        $this->assertGreaterThan(
            0,
            (int) $this->character->fresh()->jobLevels()->where('job_key', 'sawyer')->value('xp'),
            'sawing planks taught the Sawyer nothing',
        );
        $this->assertSame(
            $job->quantity * Balance::JOB_XP_PER_PROCESS_UNIT,
            (int) $this->character->fresh()->jobLevels()->where('job_key', 'sawyer')->value('xp'),
        );

        foreach (['smelter', 'tanner', 'mason', 'weaver', 'smith'] as $other) {
            $this->assertSame(
                0,
                (int) $this->character->fresh()->jobLevels()->where('job_key', $other)->value('xp'),
                "{$other} learned from somebody else's saw pit",
            );
        }
    }

    /** §11.1 -- a run walked away from teaches nothing, like every other job. */
    public function test_an_abandoned_run_teaches_the_line_nothing(): void
    {
        $settlement = $this->standAtWoodcuttingVillage();
        $this->give(['wood' => 12]);

        $job = $this->game->startProcessing($this->character->fresh(), $settlement['id'], 'planks', 1);
        $this->game->abandonJob($this->character->fresh(), $job->id);

        $this->assertSame(
            0,
            (int) $this->character->fresh()->jobLevels()->where('job_key', 'sawyer')->value('xp'),
        );
    }

    /**
     * §8 rule 1 -- a processing node is line-locked exactly as a tool is.
     *
     * A Sawyer is faster at a saw pit and at nothing else. Left unlocked, three
     * processing trees would stack processingSpeed on every line at once, which
     * is the same stack the tool ladder is careful never to allow.
     */
    public function test_a_sawyers_speed_reaches_his_own_line_and_no_other(): void
    {
        $this->grantNodes(array_keys(Jobs::nodesFor('sawyer')));
        $character = $this->character->fresh();

        $sawing = $this->game->bonuses($character, 'processing', 'woodcutting')['processingSpeed'];
        $tanning = $this->game->bonuses($character, 'processing', 'hunting')['processingSpeed'];

        $this->assertGreaterThan(0, $sawing, 'a full Sawyer tree does nothing at a saw pit');
        $this->assertSame(0.0, $tanning, 'a Sawyer sped up a tannery');

        // And a saw pit is not a forest: the axe on the belt stays out of it.
        $this->assertSame(
            0.0,
            $this->game->bonuses($character, 'woodcutting')['processingSpeed'],
            'a processing node paid out on a mine',
        );
    }

    /**
     * §7.4.3 -- what a processing tree may thin, and where it stops.
     *
     * `costReduction` and `batch` both drain the §11 materials sink, so a maxed
     * Sawyer has to eat measurably less wood and hand back measurably more
     * planks -- and no more than the caps allow, whatever else is bought.
     */
    public function test_a_full_processing_tree_thins_the_run_within_its_caps(): void
    {
        $settlement = $this->standAtWoodcuttingVillage();
        $recipe = Catalog::recipe('planks');
        $batches = 4;

        $this->give(['wood' => 60]);
        $plain = $this->game->startProcessing($this->character->fresh(), $settlement['id'], 'planks', $batches);
        $plainSpent = 60 - $this->game->held($this->character->fresh(), 'wood');
        $this->game->abandonJob($this->character->fresh(), $plain->id);

        $this->grantNodes(array_keys(Jobs::nodesFor('sawyer')));

        $this->give(['wood' => 60 - $this->game->held($this->character->fresh(), 'wood')]);
        $skilled = $this->game->startProcessing($this->character->fresh(), $settlement['id'], 'planks', $batches);
        $skilledSpent = 60 - $this->game->held($this->character->fresh(), 'wood');

        $this->assertLessThan($plainSpent, $skilledSpent, 'a full tree ate as much wood as no tree');
        $this->assertGreaterThanOrEqual(
            $batches,
            $skilledSpent,
            'a run went below one input a batch, which is a hole in the §11 sink',
        );
        $this->assertGreaterThanOrEqual(
            (int) round($recipe['inputQty'] * $batches * (1 - Balance::SKILL_COST_REDUCTION_CAP)),
            $skilledSpent,
            'the cost reduction went past its cap',
        );

        $this->assertSame(
            $recipe['outputQty'] * $batches + Balance::SKILL_BATCH_CAP,
            (int) $skilled->quantity,
            'batch is not exactly the cap, per run rather than per batch',
        );
        $this->assertGreaterThan((int) $plain->quantity, (int) $skilled->quantity);
    }

    // -------------------------------------------------------- the shelf §3.2

    /**
     * §3.2 -- the shelf is priced by one rule, and the rule is two valuations.
     *
     * What a piece costs to MAKE -- parts marked up, plus bench time -- against
     * what it is WORTH, which is what it lasts. The price is the higher.
     *
     * Hand-picked numbers drift the moment the catalog grows, and these had
     * already drifted twice: first the gathering tools sat half again under
     * everything added after them, and then -- once durability alone was the
     * rule -- the village combat rung came out UNDER its own materials.
     */
    public function test_the_shelf_is_the_higher_of_its_two_floors(): void
    {
        $checked = 0;

        foreach (Catalog::items() as $key => $def) {
            $price = $def['goldPrice'] ?? 0;
            $max = $def['maxDurability'] ?? 0;
            $station = $def['station'] ?? null;
            $rate = Balance::STATION_GOLD_PER_DURABILITY[$station] ?? null;

            if ($price <= 0 || $max <= 0 || $rate === null) {
                continue;
            }

            $worth = $max * $rate;

            $parts = 0;
            foreach ($def['inputs'] ?? [] as $material => $qty) {
                $parts += (Catalog::material($material)['npcPrice'] ?? 0) * $qty;
            }

            $minutes = (Balance::CRAFT_BASE_SECONDS[$def['rarity']] ?? 0) / 60;
            $toMake = $parts * Balance::SHOP_MATERIAL_MARKUP
                + $minutes * Balance::GOLD_PER_CRAFT_MINUTE;

            $this->assertSame(
                (int) round(max($toMake, $worth)),
                $price,
                sprintf(
                    '%s is %dg; the rule says %d (to make %.1f, worth %.1f)',
                    $key, $price, (int) round(max($toMake, $worth)), $toMake, $worth,
                ),
            );
            $checked++;
        }

        $this->assertGreaterThan(20, $checked, 'the shelf sweep found almost nothing to check');
    }

    /**
     * §8 -- the shop must never undercut the bench.
     *
     * If a shelf price ever falls to what the parts are worth, crafting the
     * thing is a straight loss and the whole §8.0 ladder inverts: the floor of
     * the ladder would be the cheapest way to the top of it. This is the failure
     * a durability-only rule could not see, and it had already happened.
     */
    public function test_no_shop_item_costs_less_than_its_own_materials(): void
    {
        foreach (Catalog::items() as $key => $def) {
            $price = $def['goldPrice'] ?? 0;
            if ($price <= 0 || ! isset($def['inputs'])) {
                continue;
            }

            $parts = 0;
            foreach ($def['inputs'] as $material => $qty) {
                $parts += (Catalog::material($material)['npcPrice'] ?? 0) * $qty;
            }

            $this->assertGreaterThan(
                $parts,
                $price,
                "{$key} costs {$price}g and is made of {$parts}g of materials — buying beats crafting",
            );
        }
    }

    /**
     * The client and the server must agree on what a thing costs.
     *
     * Nothing at runtime notices when they drift: the shop draws the client's
     * number, the server charges its own, and the player is quietly lied to. It
     * had already happened -- five tools were 20/22/24/20/18 on the client and
     * 12/13/14/12/11 on the server -- and it only surfaced when resale started
     * quoting a price before the sale went through.
     *
     * catalog.ts is hand-maintained and the worldgen parity script does not
     * cover it, so this reads the file as text. Crude, and it catches the whole
     * class.
     */
    public function test_the_client_and_server_agree_on_every_price(): void
    {
        $sources = '';
        foreach (['catalog.ts', 'battlegear.ts', 'toptier.ts', 'components.ts', 'alchemy.ts'] as $file) {
            $path = base_path("resources/js/game/{$file}");
            if (is_file($path)) {
                $sources .= file_get_contents($path);
            }
        }

        $checked = 0;
        foreach (Catalog::items() as $key => $def) {
            if (($def['goldPrice'] ?? 0) <= 0) {
                continue;
            }

            // The item's own block, then the price inside it.
            if (! preg_match("/key: '".preg_quote($key, '/')."'(.{0,1200}?)goldPrice: (\d+)/s", $sources, $m)) {
                $this->fail("{$key} has a gold price on the server and none on the client");
            }

            $this->assertSame(
                $def['goldPrice'],
                (int) $m[2],
                "{$key} costs {$def['goldPrice']} on the server and {$m[2]} on the client",
            );
            $checked++;
        }

        $this->assertGreaterThan(20, $checked, 'the price sweep found almost nothing to check');
    }

    // ------------------------------------------------- selling gear back §8.2

    /** Buy a shop item at the village and hand back its row. */
    private function boughtItem(string $key = 'stone_axe'): CharacterItem
    {
        $this->standAtWoodcuttingVillage();
        $this->character->gold = 500;
        $this->character->save();

        return $this->game->buyItem($this->character->fresh(), $key);
    }

    /**
     * §4.0 -- one trade empties the pack of everything that reaches no tier.
     *
     * Tier zero is the whole test, so it takes the five biome scrap and the
     * five junk together. They are two different arguments about where a copper
     * came from and one chore to be rid of, and a button that cleared only half
     * the coppers would be a button you still had to finish by hand.
     */
    public function test_clearing_the_scrap_takes_every_tier_zero_stack(): void
    {
        $this->standAtWoodcuttingVillage();
        $this->give(['branch' => 4, 'thistle' => 3, 'wood' => 5, 'toadstool' => 2]);

        $before = (int) $this->character->fresh()->gold;
        $sale = $this->game->sellScrap($this->character->fresh());

        $this->assertSame(7, $sale['units']);
        $this->assertSame(2, $sale['rows'], 'scrap and junk are one chore, whatever else they are');
        $this->assertSame(7, $sale['gold'], 'tier zero is a copper each, §4.0');
        $this->assertSame($before + 7, (int) $this->character->fresh()->gold);

        $held = $this->character->fresh()->materials()->pluck('quantity', 'material_key');
        $this->assertSame(0, (int) ($held['branch'] ?? 0));
        $this->assertSame(0, (int) ($held['thistle'] ?? 0));

        // Everything that reaches a tier is left exactly where it was. The
        // trader pays badly for raw stock (§3.2) and whether that is worth
        // taking is a decision, not a chore.
        $this->assertSame(5, (int) $held['wood']);
        $this->assertSame(2, (int) $held['toadstool']);
    }

    /** An empty pack is told so, rather than answering with a sale of nothing. */
    public function test_clearing_the_scrap_refuses_when_there_is_none(): void
    {
        $this->standAtWoodcuttingVillage();
        $this->give(['wood' => 3]);

        $this->expectException(GameException::class);
        $this->game->sellScrap($this->character->fresh());
    }

    /** §3.2 -- the trader is an NPC who stands somewhere. So is this. */
    public function test_clearing_the_scrap_needs_a_settlement(): void
    {
        $this->give(['branch' => 4]);
        $this->assertNull($this->game->currentSettlement($this->character->fresh()));

        $this->expectException(GameException::class);
        $this->game->sellScrap($this->character->fresh());
    }

    /**
     * §3.2 -- and the make-cost fallback stops dead at the second rung.
     *
     * Without this the fallback would quietly hand every epic and legendary a
     * gold value, which is the bridge §2 exists to keep shut: minting is that
     * gear's exit (§8.0), and salvage is the one open at every rung.
     */
    public function test_gear_above_the_second_rung_has_no_price_at_all(): void
    {
        $this->standAtWoodcuttingVillage();

        $def = Catalog::item('mythril_pickaxe');
        $this->assertSame(0, Formulas::resaleBasis($def));
        $this->assertSame(0, Formulas::resaleValue($def, (int) $def['maxDurability']));

        $item = CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => 'mythril_pickaxe',
            'durability' => $def['maxDurability'],
            'equipped' => false,
            'options' => [],
        ]);

        $this->expectException(GameException::class);
        $this->game->sellItem($this->character->fresh(), $item->id);
    }

    /**
     * §2 -- and the bench must not become a gold press either.
     *
     * Same argument as the potions below: if a crafted piece sold for more than
     * the materials that went into it, the loop would be gather, craft, sell.
     * Half of the parts is strictly under the parts, and wear only ever takes
     * more off.
     */
    public function test_no_craftable_piece_sells_for_more_than_its_materials(): void
    {
        $checked = 0;

        foreach (Catalog::items() as $key => $def) {
            if (empty($def['inputs']) || ! empty($def['consumable'])) {
                continue;
            }

            $parts = Formulas::makeCost($def);
            if ($parts <= 0 || Formulas::resaleBasis($def) <= 0) {
                continue;
            }

            $checked++;

            // Undamaged, which is the best case a seller can present.
            $this->assertLessThan(
                $parts,
                Formulas::resaleValue($def, (int) ($def['maxDurability'] ?? 1)),
                "{$key} pays more sold than the materials it was made of",
            );
        }

        $this->assertGreaterThan(40, $checked, 'the sweep found almost no craftable gear');
    }

    /**
     * §8.2 -- and the same rule said the other way: the trader never pays for
     * the markup or the bench time, only for the parts.
     *
     * A shelf price is make-cost plus half again plus the minutes (§8.3), so it
     * is ALWAYS above what the thing is made of. Reading the parts first is what
     * keeps a stocked-and-craftable piece priced like the craft-only piece
     * beside it -- the two used to differ by nothing a player could see.
     */
    public function test_the_trader_prices_a_craftable_piece_off_its_parts_not_its_shelf_tag(): void
    {
        $stocked = Catalog::item('iron_broadsword');

        $this->assertGreaterThan(0, $stocked['goldPrice'] ?? 0, 'iron_broadsword is not stocked any more');
        $this->assertSame(Formulas::makeCost($stocked), Formulas::resaleBasis($stocked));
        $this->assertLessThan(
            $stocked['goldPrice'],
            Formulas::resaleBasis($stocked),
            'the shelf tag is not above what the piece is made of, so §8.3 has moved',
        );

        // Shop stock with no recipe has only the one price, and keeps it.
        $plain = Catalog::item('stone_axe');
        $this->assertSame([], $plain['inputs'] ?? []);
        $this->assertSame($plain['goldPrice'], Formulas::resaleBasis($plain));
    }

    /**
     * §8.2 -- a potion sells, by the flask, priced off its own recipe.
     *
     * The third exit a brew has. Gear already had one; consumables were the
     * only thing in the bag with no way out but the mouth.
     */
    public function test_a_potion_sells_for_half_of_what_its_reagents_fetched(): void
    {
        $this->standAtWoodcuttingVillage();
        $key = 'forest_draft';
        $def = Catalog::item($key);
        $this->character->consumables()->create(['item_key' => $key, 'quantity' => 5]);

        $before = (int) $this->character->fresh()->gold;
        $each = Formulas::consumableResale($def);
        $this->assertGreaterThan(0, $each, 'a common draft is worth nothing at all');

        $sale = $this->game->sellConsumable($this->character->fresh(), $key, 2);

        $this->assertSame($each * 2, $sale['gold']);
        $this->assertSame($before + $each * 2, (int) $this->character->fresh()->gold);
        $this->assertSame(
            3,
            (int) $this->character->fresh()->consumables()->where('item_key', $key)->value('quantity'),
            'the flasks that were not sold left the bag anyway',
        );
    }

    /**
     * §2 -- brewing must never be a way of turning reagents into more gold than
     * the reagents were worth.
     *
     * This is the whole reason the rate is half. The consumable bench takes
     * cheap raw and makes something a player wants; if the trader paid more for
     * the flask than for the pile that went into it, the loop would be brew,
     * sell, repeat -- a gold press with no work in it, run best by whoever has
     * the most wallets (§2).
     *
     * Checked with the Alchemist's thumb on the scale too: `brewExtra` tops out
     * at +35% flasks (§7.4.3), so the honest comparison is against a rack that
     * size. Nothing else in the game would notice the day this went over 1.
     */
    public function test_no_potion_is_worth_more_sold_than_the_reagents_that_made_it(): void
    {
        $checked = 0;

        foreach (Catalog::items() as $key => $def) {
            if (empty($def['consumable']) || empty($def['inputs'])) {
                continue;
            }

            $reagents = 0;
            foreach ($def['inputs'] as $material => $qty) {
                $reagents += (Catalog::material($material)['npcPrice'] ?? 0) * $qty;
            }

            if ($reagents <= 0) {
                continue;
            }

            $checked++;
            $flasks = 1 + Balance::SKILL_BREW_EXTRA_CAP;

            $this->assertLessThan(
                $reagents,
                Formulas::consumableResale($def) * $flasks,
                "{$key} pays more sold than its reagents did -- the bench is a gold press",
            );
        }

        $this->assertGreaterThan(20, $checked, 'the sweep found almost no potions');
    }

    /**
     * §3.2 -- gold stops at the second rung, and here that closes a real hole.
     *
     * Every epic and legendary draft wants a Tier 3 rare (§8.5), and those are
     * capped per wallet. A gold price on one would turn a capped rare into
     * uncapped coin, which is exactly the bridge §2 exists to keep shut.
     */
    public function test_the_trader_will_not_buy_a_potion_above_the_second_rung(): void
    {
        $this->standAtWoodcuttingVillage();

        $key = null;
        foreach (Catalog::items() as $candidate => $def) {
            if (! empty($def['consumable'])
                && Balance::rarityRank($def['rarity']) > Balance::rarityRank(Balance::SHOP_RARITY_CAP)) {
                $key = $candidate;
                break;
            }
        }

        $this->assertNotNull($key, 'there is no potion above uncommon to test with');
        $this->character->consumables()->create(['item_key' => $key, 'quantity' => 1]);

        $this->expectException(GameException::class);
        $this->game->sellConsumable($this->character->fresh(), $key, 1);
    }

    /** §3.2 -- the trader is an NPC who stands somewhere. So is this. */
    public function test_selling_a_potion_needs_a_settlement(): void
    {
        $this->character->consumables()->create(['item_key' => 'forest_draft', 'quantity' => 1]);
        $this->assertNull($this->game->currentSettlement($this->character->fresh()));

        $this->expectException(GameException::class);
        $this->game->sellConsumable($this->character->fresh(), 'forest_draft', 1);
    }

    /** §8.2 -- half the shelf price, and wear comes off the top. */
    public function test_gear_sells_for_half_price_scaled_by_what_is_left(): void
    {
        $item = $this->boughtItem();
        $def = Catalog::item('stone_axe');
        $before = (int) $this->character->fresh()->gold;

        // Full durability: exactly half, and nothing lost to rounding.
        $this->assertSame(
            (int) floor($def['goldPrice'] * Balance::RESALE_RATE),
            Formulas::resaleValue($def, $def['maxDurability']),
        );

        // Half worn, so half of half.
        $item->durability = (int) ($def['maxDurability'] / 2);
        $item->save();

        $expected = Formulas::resaleValue($def, (int) $item->durability);
        $this->assertGreaterThan(0, $expected);

        $sale = $this->game->sellItem($this->character->fresh(), $item->id);

        $this->assertSame($expected, $sale['gold']);
        $this->assertSame($before + $expected, (int) $this->character->fresh()->gold);
        $this->assertNull(
            $this->character->fresh()->items()->where('id', $item->id)->first(),
            'the piece survived the sale',
        );
    }

    /**
     * §3.2 -- the round trip must lose money, or a trader is a gold faucet with
     * no work in it.
     */
    public function test_buying_and_selling_back_never_profits(): void
    {
        foreach (Catalog::items() as $key => $def) {
            if (($def['goldPrice'] ?? 0) <= 0) {
                continue;
            }

            $this->assertLessThan(
                $def['goldPrice'],
                Formulas::resaleValue($def, $def['maxDurability']),
                "{$key} sells back for at least what it cost",
            );
        }
    }

    /**
     * §11.1 -- repairing must stay cheaper than churning, at every wear level.
     *
     * If selling a battered tool and buying a fresh one ever undercut the repair
     * bill, the repair sink would switch itself off and nobody would ever pay
     * it. The two rates are set independently -- RESALE_RATE here, the 0.6 the
     * NPC charges over in repairItem -- so nothing but this test keeps them in
     * the right order.
     */
    public function test_repairing_always_beats_selling_and_rebuying(): void
    {
        foreach (Catalog::items() as $key => $def) {
            $price = $def['goldPrice'] ?? 0;
            $max = $def['maxDurability'] ?? 0;
            if ($price <= 0 || $max <= 0) {
                continue;
            }

            // Craftable stock is in the sweep now. It used to be skipped
            // because a crafted piece fetched nothing at all; it fetches its
            // parts today, which is still well under the shelf tag, so churning
            // one is dearer than ever and the guarantee only got stronger.

            for ($durability = 0; $durability <= $max; $durability++) {
                $missing = $max - $durability;
                if ($missing <= 0) {
                    continue;
                }

                $repair = (int) ceil($price * ($missing / $max) * 0.6);
                $churn = $price - Formulas::resaleValue($def, $durability);

                $this->assertGreaterThan(
                    $repair,
                    $churn,
                    "{$key} at {$durability}/{$max} is cheaper to replace than to repair",
                );
            }
        }
    }

    /**
     * §7.4.3 -- `craftDurability` raises the MAX, and the piece keeps it.
     *
     * It used to write the bonus into the current fill and leave the ceiling at
     * the recipe's, which made the node worth exactly one craft: the bar read
     * past 100%, resale clamped the fraction back to 1, and the first mend set
     * durability to the catalog max and threw the extra away for good. A Smith
     * deep enough in the tree to buy it got a piece that was better right up
     * until it was repaired once.
     *
     * So the ceiling rides the ROW. This walks the whole life of one piece:
     * made high, worn down, mended, and still high.
     */
    public function test_a_well_made_piece_keeps_its_higher_ceiling_through_a_repair(): void
    {
        $this->standAtWoodcuttingVillage();

        $def = Catalog::item('hewn_axe');
        $base = (int) $def['maxDurability'];
        $raised = $base + 12;

        $item = CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => 'hewn_axe',
            'durability' => $raised,
            'max_durability' => $raised,
            'equipped' => false,
            'options' => [],
        ]);

        $this->assertSame($raised, $item->maxDurability(), 'the piece is not carrying its own ceiling');

        // Worn down, then mended: the repair fills it to the PIECE's ceiling.
        $item->durability = 10;
        $item->save();
        $this->give(array_map(fn ($q) => $q * 40, $def['inputs']));

        $this->game->repairItem($this->character->fresh(), $item->id);

        $this->assertSame(
            $raised,
            (int) $item->fresh()->durability,
            'the mend filled it to the recipe max and the bonus was lost',
        );
        $this->assertSame($raised, $item->fresh()->maxDurability());
    }

    /**
     * §7.4.3 -- and a piece nobody improved just uses the recipe's ceiling.
     *
     * Null on the row rather than a copy of the catalog figure, so a recipe
     * retuned tomorrow moves every ordinary piece with it and leaves the
     * well-made ones where their Smith put them.
     */
    public function test_an_ordinary_piece_takes_its_ceiling_from_the_recipe(): void
    {
        $item = $this->boughtItem();

        $this->assertNull($item->fresh()->max_durability);
        $this->assertSame(
            (int) Catalog::item('stone_axe')['maxDurability'],
            $item->fresh()->maxDurability(),
        );
    }

    /**
     * §8.2 -- the trader takes a piece off the bench, priced off its parts.
     *
     * This used to assert the opposite: no shelf price, no sale, salvage is the
     * exit. That put a common Hewn Axe in the same sentence as an epic Mythril
     * Pickaxe, while the reason given -- gold buys the bottom two rungs and
     * never the top -- only ever argued for excluding the top. A player with a
     * bagful of their own crafting found none of it listed at the trader, which
     * is how it surfaced.
     *
     * Through the real bench, because the value maths and the sale have to agree
     * about an item that actually came off one.
     */
    public function test_the_trader_takes_a_crafted_piece_priced_off_its_parts(): void
    {
        $this->standAtWoodcuttingVillage();
        $this->give(['wood' => 12, 'planks' => 8, 'heartknot' => 8]);

        $def = Catalog::item('hewn_axe');
        $this->assertSame(0, $def['goldPrice'] ?? 0, 'hewn_axe is stocked now, pick another craft-only piece');

        $axe = $this->craftNow('hewn_axe')['made'];
        $parts = Formulas::makeCost($def);
        $this->assertGreaterThan(0, $parts, 'a crafted axe is made of nothing');

        // Straight off the bench, so undamaged: exactly half of the parts.
        $expected = (int) floor($parts * Balance::RESALE_RATE);
        $before = (int) $this->character->fresh()->gold;

        $sale = $this->game->sellItem($this->character->fresh(), (int) $axe['itemId']);

        $this->assertSame($expected, $sale['gold']);
        $this->assertSame($before + $expected, (int) $this->character->fresh()->gold);
        $this->assertLessThan($parts, $sale['gold'], 'crafting and selling turned a profit');
    }

    /** A sale is a trade: the tool comes off the belt first, deliberately. */
    public function test_worn_gear_cannot_be_sold_off_the_belt(): void
    {
        $item = $this->boughtItem();
        $this->game->equipItem($this->character->fresh(), $item->id);

        try {
            $this->game->sellItem($this->character->fresh(), $item->id);
            $this->fail('sold the tool off the belt');
        } catch (GameException $e) {
            $this->assertSame('equipped', $e->errorCode);
        }

        // Stowing is the one tap that makes it sellable.
        $this->game->unequipItem($this->character->fresh(), $item->id);
        $this->assertGreaterThan(0, $this->game->sellItem($this->character->fresh(), $item->id)['gold']);
    }

    /** Nothing is taken for nothing: a piece worth no coin is refused, not eaten. */
    public function test_a_piece_worth_nothing_is_refused_rather_than_taken(): void
    {
        $item = $this->boughtItem();
        $item->durability = 0;
        $item->save();

        $before = (int) $this->character->fresh()->gold;

        try {
            $this->game->sellItem($this->character->fresh(), $item->id);
            $this->fail('a broken piece was taken for nothing');
        } catch (GameException $e) {
            $this->assertSame('worthless', $e->errorCode);
        }

        $this->assertSame($before, (int) $this->character->fresh()->gold);
        $this->assertNotNull(
            $this->character->fresh()->items()->where('id', $item->id)->first(),
            'the refused piece was eaten anyway',
        );
    }

    /** §6 -- there is nobody in a forest to sell an axe to. */
    public function test_gear_cannot_be_sold_away_from_a_settlement(): void
    {
        $item = $this->boughtItem();

        $open = $this->openNeighbor($this->character->col, $this->character->row);
        $this->character->update($open);

        try {
            $this->game->sellItem($this->character->fresh(), $item->id);
            $this->fail('sold to nobody');
        } catch (GameException $e) {
            $this->assertSame('not_at_settlement', $e->errorCode);
        }
    }

    // ----------------------------------------------------------- quests §12

    /** §12 -- a quest only advances on work the server actually witnessed. */
    public function test_a_haul_advances_a_gather_quest(): void
    {
        $this->assertSame(0, $this->questProgress('bare_hands'));

        $open = $this->openNeighbor($this->character->col, $this->character->row);
        $this->character->update($open);

        $job = $this->game->startMining($this->character->fresh(), $open['col'], $open['row'], Drops::GATHERING);
        $job->update(['ends_at' => $this->game->now() - 1]);
        $result = $this->game->collectJob($this->character->fresh(), $job->id);

        // Only what the quest actually names: a bare-handed forest mine brings
        // back rubbish alongside the branches, and none of it counts here.
        $branches = (int) ($result['gained']['branch'] ?? 0);
        $this->assertGreaterThan(0, $branches, 'a bare-handed forest mine brought back no branches');
        $this->assertSame(
            min($branches, Quests::DEFS['bare_hands']['goal']['target']),
            $this->questProgress('bare_hands'),
            'the haul did not reach the ledger',
        );
    }

    /**
     * §12 -- a counter is held at the target rather than run past it.
     *
     * A tally that keeps climbing after the goal is met is a number the panel
     * would have to apologise for.
     */
    public function test_a_finished_counter_stops_at_its_target(): void
    {
        $target = Quests::DEFS['short_road']['goal']['target'];

        $this->fireQuest('travel', $target * 3);

        // Read off the row rather than the ledger: short_road is still locked
        // behind the opening arc, and §12 counts work whether or not the quest
        // that wants it has been offered yet.
        $this->assertSame(
            $target,
            (int) $this->character->fresh()->quests()->where('quest_key', 'short_road')->value('progress'),
        );
    }

    /**
     * §12 -- work counts whether or not the quest asking for it is offered.
     *
     * A prospector who sold a bagful before anybody wrote the quest down has
     * still sold it, and being handed a task already done is a better welcome
     * than being told to start again.
     */
    public function test_work_done_before_a_quest_is_offered_still_counts(): void
    {
        $this->fireQuest('sell', 500);
        $this->fireQuest('gather', 50, 'branch');

        $this->game->claimQuest($this->character->fresh(), 'bare_hands');

        $this->assertTrue($this->questRow('first_coin')['complete'], 'the sale was forgotten');
    }

    /** §3.2 -- the reward is gold, it lands once, and the row is closed. */
    public function test_claiming_pays_gold_exactly_once(): void
    {
        $this->fireQuest('gather', 50, 'branch');
        $before = (int) $this->character->fresh()->gold;

        $reward = $this->game->claimQuest($this->character->fresh(), 'bare_hands');

        $this->assertSame(Quests::DEFS['bare_hands']['gold'], $reward['gold']);
        $this->assertSame(
            $before + $reward['gold'],
            (int) $this->character->fresh()->gold,
            'the gold did not reach the purse',
        );

        try {
            $this->game->claimQuest($this->character->fresh(), 'bare_hands');
            $this->fail('a quest paid twice');
        } catch (GameException $e) {
            $this->assertSame('already_claimed', $e->errorCode);
        }

        $this->assertSame(
            $before + $reward['gold'],
            (int) $this->character->fresh()->gold,
            'the second claim moved the purse',
        );
    }

    /** §12 -- an unfinished quest is not payable, however it is asked for. */
    public function test_an_unfinished_quest_cannot_be_claimed(): void
    {
        $this->expectException(GameException::class);
        $this->game->claimQuest($this->character->fresh(), 'bare_hands');
    }

    /**
     * §12 -- the chain advances on claiming, not on finishing.
     *
     * A quest whose prerequisite is unclaimed is not offered and cannot be
     * taken, so what is next stays legible.
     */
    public function test_a_locked_quest_is_neither_offered_nor_payable(): void
    {
        $keys = array_column($this->game->questPayload($this->character->fresh()), 'key');
        $this->assertContains('bare_hands', $keys);
        $this->assertNotContains('first_coin', $keys, 'a locked quest was offered');

        try {
            $this->game->claimQuest($this->character->fresh(), 'first_coin');
            $this->fail('claimed past a locked prerequisite');
        } catch (GameException $e) {
            $this->assertSame('locked', $e->errorCode);
        }

        // Claiming the one before it is what opens the next.
        $this->fireQuest('gather', 50, 'branch');
        $reward = $this->game->claimQuest($this->character->fresh(), 'bare_hands');

        $this->assertContains(
            'first_coin',
            array_column($reward['unlocked'], 'key'),
            'the receipt did not name what it opened',
        );
        $this->assertContains(
            'first_coin',
            array_column($this->game->questPayload($this->character->fresh()), 'key'),
        );
    }

    /**
     * §12 -- a measured goal is read off the character, never stored.
     *
     * "Am I level five" has a live answer, and a stored copy would eventually
     * disagree with the character it is about.
     */
    public function test_a_measured_goal_reads_the_character_rather_than_a_tally(): void
    {
        $this->claimThrough('traders_rate');

        $this->character->level = 1;
        $this->character->save();
        $this->assertFalse($this->questRow('journeyman')['complete']);

        $this->character->level = Quests::DEFS['journeyman']['goal']['target'];
        $this->character->save();

        $row = $this->questRow('journeyman');
        $this->assertTrue($row['complete'], 'a level the character has did not count');
        $this->assertSame(
            0,
            (int) ($this->character->quests()->where('quest_key', 'journeyman')->value('progress') ?? 0),
            'a measured goal wrote a tally',
        );
    }

    /** §12 -- claiming answers with a receipt, and the envelope says nothing. */
    public function test_a_claim_carries_no_message_for_a_toast(): void
    {
        $this->fireQuest('gather', 50, 'branch');

        $request = Request::create('/api/quests/bare_hands/claim', 'POST');
        $request->attributes->set('character', $this->character->fresh());

        $payload = (new QuestController($this->game))
            ->claim($request, 'bare_hands')
            ->getData(true);

        // The receipt carries the whole of it, so the envelope says nothing --
        // a toast reading "+10 gold" on top would be the same news twice.
        $this->assertNull($payload['message'], 'a claim toasted as well as paying');
        $this->assertSame('bare_hands', $payload['data']['quest']);
        $this->assertSame(Quests::DEFS['bare_hands']['gold'], $payload['data']['gold']);
    }

    /**
     * §12 -- the tutorial is gone, and the ledger is what replaced it.
     *
     * The old script's eleven steps were always the real game loop, so they are
     * the opening arc here: one quest with no prerequisite, and a single line
     * from it through buying, equipping, refining and crafting. A branch this
     * early would be a choice offered to somebody who does not yet know what
     * they are choosing between.
     */
    public function test_the_opening_arc_is_one_unbroken_line(): void
    {
        $this->assertFalse(
            Schema::hasColumn('characters', 'tutorial_step'),
            'the tutorial cursor outlived the tutorial',
        );

        $arc = ['bare_hands', 'first_coin', 'a_stone_axe', 'on_the_belt', 'the_real_thing',
            'saw_it_down', 'hewn_axe', 'back_to_the_trees'];

        $this->assertNull(Quests::DEFS['bare_hands']['requires'], 'nothing opens the ledger');

        foreach ($arc as $i => $key) {
            $this->assertArrayHasKey($key, Quests::DEFS, "{$key} is missing from the arc");

            if ($i > 0) {
                $this->assertSame(
                    $arc[$i - 1],
                    Quests::DEFS[$key]['requires'],
                    "{$key} does not follow the step before it",
                );
            }
        }

        // And only one quest is on offer at the start: the first of them.
        $this->assertSame(
            ['bare_hands'],
            array_column($this->game->questPayload($this->character->fresh()), 'key'),
        );
    }

    /** §12 -- buying and equipping are counted, so the arc is walkable. */
    public function test_buying_and_equipping_reach_the_ledger(): void
    {
        $this->claimThrough('first_coin');
        $this->give(['branch' => 0]);
        $this->character->gold = 500;
        $this->character->save();

        $this->standAtWoodcuttingVillage();
        $item = $this->game->buyItem($this->character->fresh(), 'stone_axe');

        $this->assertTrue($this->questRow('a_stone_axe')['complete'], 'the purchase missed the ledger');
        $this->game->claimQuest($this->character->fresh(), 'a_stone_axe');

        $this->game->equipItem($this->character->fresh(), $item->id);

        // Named by its slot rather than its key, and it still counts: a fire
        // goes up under both, so a quest may say whichever it means.
        $this->assertTrue($this->questRow('on_the_belt')['complete'], 'the equip missed the ledger');
    }

    /**
     * §4 -- collecting answers with a receipt, and the envelope says nothing.
     *
     * The haul modal carries every stack, both XP ladders, tool wear and
     * anything that would not fit. A toast beside it is the same news twice,
     * and it was a leftover from when a haul was one stack and a line of text
     * could hold it.
     */
    public function test_collecting_carries_no_message_for_a_toast(): void
    {
        $open = $this->openNeighbor($this->character->col, $this->character->row);
        $this->character->update($open);

        // Bare-handed, so the mine needs no tool on the belt. §4.0 pays scrap
        // rather than refusing, and a receipt for scrap is still a receipt.
        $job = $this->game->startMining(
            $this->character->fresh(),
            $open['col'],
            $open['row'],
            Drops::GATHERING,
        );
        $job->update(['ends_at' => $this->game->now() - 1]);

        $request = Request::create("/api/jobs/{$job->id}/collect", 'POST');
        $request->attributes->set('character', $this->character->fresh());

        $payload = (new MiningController($this->game))
            ->collect($request, $job->id)
            ->getData(true);

        $this->assertNull($payload['message'], 'a collect toasted as well as opening the receipt');
        $this->assertNotEmpty((array) $payload['data']['gained'], 'the receipt came back empty');
    }

    /** Fire a counted goal directly, standing in for the work behind it. */
    private function fireQuest(string $kind, int $amount, ?string $subject = null): void
    {
        $fire = new ReflectionMethod($this->game, 'fireQuest');
        $fire->setAccessible(true);
        $fire->invoke($this->game, $this->character->fresh(), $kind, $amount, $subject);
    }

    /**
     * Walk the chain to a given quest, claiming everything on the way.
     *
     * DEFS is written in unlock order, so a single pass is enough: every
     * prerequisite is already behind whatever needs it.
     */
    private function claimThrough(string $target): void
    {
        foreach (Quests::DEFS as $key => $def) {
            $goal = $def['goal'];

            if (in_array($goal['kind'], Quests::COUNTED, true)) {
                $this->fireQuest($goal['kind'], $goal['target'], $goal['subject']);
            } elseif ($goal['kind'] === 'level') {
                $this->character->level = max((int) $this->character->level, $goal['target']);
                $this->character->save();
            }

            $this->game->claimQuest($this->character->fresh(), $key);

            if ($key === $target) {
                return;
            }
        }

        $this->fail("{$target} is not on the chain");
    }

    private function questRow(string $key): array
    {
        foreach ($this->game->questPayload($this->character->fresh()) as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        $this->fail("{$key} is not on the ledger");
    }

    private function questProgress(string $key): int
    {
        return $this->questRow($key)['progress'];
    }
}
