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
        $hexes = \App\Game\HexGeometry::distance(
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

        $distance = \App\Game\HexGeometry::distance($from['col'], $from['row'], $to['col'], $to['row']);
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

        $range = Balance::SPAWN_VILLAGE_RADIUS;
        $found = null;
        for ($dc = -$range; $dc <= $range && ! $found; $dc++) {
            for ($dr = -$range; $dr <= $range && ! $found; $dr++) {
                $s = \App\Game\WorldGen::settlementAt($this->character->col + $dc, $this->character->row + $dr);
                if ($s && in_array('woodcutting', $s['lines'], true)) {
                    $found = $s;
                }
            }
        }

        $this->assertNotNull($found, 'no woodcutting village within spawn radius');
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

            $job = $this->game->startMining($character, $character->col, $character->row);
            $job->update(['ends_at' => $this->game->now() - 1, 'quantity' => 5]);

            $before = $character->fresh()->xp;
            $this->game->collectJob($character->fresh(), $job->id);
            $earned[$scale] = $character->fresh()->xp - $before;
        }

        config(['game.time_scale' => 1]);

        $this->assertGreaterThan(0, $earned[1], 'a finished trip paid no XP at all');
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
     * (28 mining trips a day unequipped, 48 on the 30-minute floor, plus the
     * processing those hauls feed). The target is level 100 at about six months.
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

        // The first level should cost a few trips, not a fraction of one: a
        // character that gains four levels on its first haul has no curve at all.
        $firstTrip = 5 * 4 * 0.6;
        $this->assertGreaterThan(
            2 * $firstTrip,
            Balance::xpForLevel(1),
            'level 2 arrives in under two mining trips',
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

        for ($col = 0; $col < Balance::MAP_COLS; $col++) {
            for ($row = 0; $row < Balance::MAP_ROWS; $row++) {
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

        // Laravel's Session\Store replaces an id that is not 40 alphanumerics
        // with a fresh random one, which would silently defeat this test.
        $sessionId = str_repeat('a1b2', 10);
        $wallet = '0x'.substr(hash('sha256', $sessionId), 0, 40);

        // The row exists, orphaned from its session.
        $player = Player::create(['wallet' => $wallet, 'session_id' => null]);

        $resolve = new \ReflectionMethod(\App\Http\Middleware\ResolveCharacter::class, 'resolvePlayer');
        $middleware = new \App\Http\Middleware\ResolveCharacter($this->game);

        $request = \Illuminate\Http\Request::create('/api/state');
        $session = new \Illuminate\Session\Store('test', new \Illuminate\Session\ArraySessionHandler(120), $sessionId);
        $request->setLaravelSession($session);

        $resolved = $resolve->invoke($middleware, $request);

        $this->assertNotNull($resolved);
        $this->assertSame($player->id, $resolved->id, 'a new row was created instead of rebinding');
        $this->assertSame($sessionId, $resolved->session_id);
        $this->assertSame(1, Player::where('wallet', $wallet)->count());
    }

    /** Equip a working bow, so the hunting line has its §8.0 tool. */
    private function equipBow(): void
    {
        \App\Models\CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => 'crude_bow',
            'durability' => 200,
            'equipped' => true,
            'options' => [],
        ]);
    }

    /** §5.5 -- a herd pays pelt for a bow, and essence on top often enough to matter. */
    public function test_a_herd_pays_pelt_and_bridges_to_the_raid_track(): void
    {
        [$col, $row] = $this->standOnAHerd();
        $this->equipBow();

        $preview = $this->game->previewHunt($this->character->fresh(), $col, $row);
        $this->assertTrue($preview['canHunt'], $preview['reason'] ?? '');
        $this->assertSame('pelt', $preview['material']);
        $this->assertFalse($preview['scrap']);
        $this->assertSame(Balance::HUNT_ESSENCE_CHANCE, $preview['essenceChance']);

        $essence = 0;
        for ($i = 0; $i < 40; $i++) {
            $character = $this->character->fresh();
            $character->update(['ap' => Balance::apMax($character->level)]);

            $job = $this->game->startHunt($character->fresh(), $col, $row);
            $job->update(['ends_at' => $this->game->now() - 1]);

            $result = $this->game->collectJob($this->character->fresh(), $job->id);
            $this->assertGreaterThan(0, $result['gained']['pelt']);
            $essence += $result['gained']['essence'] ?? 0;
        }

        // At 35% over forty hunts, never seeing one would be a bug, not luck.
        $this->assertGreaterThan(0, $essence, 'forty hunts produced no essence at all');
    }

    /** §4.0 / §8.0 -- bare hands take scrap, and never reach a Tier 4 material. */
    public function test_bare_hands_hunt_scrap_and_never_essence(): void
    {
        [$col, $row] = $this->standOnAHerd();

        $preview = $this->game->previewHunt($this->character->fresh(), $col, $row);
        $this->assertSame('torn_hide', $preview['material']);
        $this->assertTrue($preview['scrap']);
        // The sharp end: no bow means the bridge to the raid track is shut.
        $this->assertSame(0.0, $preview['essenceChance']);

        for ($i = 0; $i < 25; $i++) {
            $character = $this->character->fresh();
            $character->update(['ap' => Balance::apMax($character->level)]);

            $job = $this->game->startHunt($character->fresh(), $col, $row);
            $job->update(['ends_at' => $this->game->now() - 1]);

            $result = $this->game->collectJob($this->character->fresh(), $job->id);
            $this->assertGreaterThan(0, $result['gained']['torn_hide']);
            $this->assertArrayNotHasKey('essence', $result['gained']);
        }
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
            $others[] = $this->game->startMining($other->fresh(), $col, $row);
        }
        $this->assertCount(2, $others);

        // And collecting the hunt never writes a depletion row.
        $job->update(['ends_at' => $this->game->now() - 1]);
        $this->game->collectJob($this->character->fresh(), $job->id);
        $this->assertSame(0, \App\Models\TileState::where('col', $col)->where('row', $row)->count());
    }

    /** A person is in one place: a hunt blocks a dig, and a dig blocks a hunt. */
    public function test_a_hunt_and_a_dig_exclude_each_other(): void
    {
        [$col, $row] = $this->standOnAHerd();
        $this->equipBow();

        $this->game->startHunt($this->character->fresh(), $col, $row);
        $this->assertFalse($this->game->previewTile($this->character->fresh(), $col, $row)['canMine']);

        try {
            $this->game->startMining($this->character->fresh(), $col, $row);
            $this->fail('dug a hex while already hunting it');
        } catch (\App\Game\GameException $e) {
            $this->assertSame('blocked', $e->errorCode);
        }
    }

    /** §5.5 -- herds wander. A hex without one cannot be hunted. */
    public function test_a_hex_with_no_herd_cannot_be_hunted(): void
    {
        $preview = $this->game->previewHunt(
            $this->character,
            (int) $this->character->col,
            (int) $this->character->row,
        );

        // Spawn is forest (see the spawn test), so there is never a herd here.
        $this->assertFalse($preview['canHunt']);
        $this->assertStringContainsString('No herd', $preview['reason']);

        try {
            $this->game->startHunt($this->character, (int) $this->character->col, (int) $this->character->row);
            $this->fail('hunted a hex with no herd on it');
        } catch (\App\Game\GameException $e) {
            $this->assertSame('blocked', $e->errorCode);
        }
    }

    /** §5.6 -- a herd is live state, so it is bounded by the sight disc. */
    public function test_a_herd_outside_sight_will_not_be_costed(): void
    {
        [$col, $row] = $this->standOnAHerd();

        // Half a map away in both axes, so this is well outside any sight the
        // Explorer chain can reach -- the scan above starts at 0,0, so simply
        // standing there could leave the herd inside the disc.
        $this->character->update([
            'col' => ($col + intdiv(Balance::MAP_COLS, 2)) % Balance::MAP_COLS,
            'row' => ($row + intdiv(Balance::MAP_ROWS, 2)) % Balance::MAP_ROWS,
        ]);

        $preview = $this->game->previewHunt($this->character->fresh(), $col, $row);
        $this->assertTrue($preview['unseen']);
        $this->assertNull($preview['herdUntil']);
        $this->assertFalse($preview['canHunt']);
    }

    private function give(array $materials): void
    {
        $add = new \ReflectionMethod($this->game, 'addMaterial');
        foreach ($materials as $key => $qty) {
            $add->invoke($this->game, $this->character->fresh(), $key, $qty);
        }
    }

    /** Buy nodes directly, for tests that care about the effect not the gate. */
    private function grantNodes(array $keys): void
    {
        foreach ($keys as $key) {
            \App\Models\CharacterNode::create([
                'character_id' => $this->character->id,
                'node_key' => $key,
            ]);
        }
    }

    public function test_every_job_exists_from_the_start_at_level_one(): void
    {
        // Three benches, three battle roles, and the road (§7.5). The five
        // gathering lines have no row: their level is the CharacterSkill one.
        $this->assertCount(7, $this->character->jobLevels()->get());

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

        $levels = new \ReflectionMethod($this->game, 'jobLevels');
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
     * Without this, three gathering trees would stack yield on every trip at
     * once -- which is precisely the shortcut the line-locked tool ladder is
     * built to prevent.
     */
    public function test_a_gathering_node_only_counts_on_its_own_line(): void
    {
        $this->character->level = Balance::MAX_LEVEL;
        $this->character->save();

        // Every yield node the Woodcutting tree carries.
        $keys = [];
        foreach (\App\Game\Jobs::nodesFor('woodcutting') as $key => $node) {
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
        } catch (\App\Game\GameException $e) {
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
        } catch (\App\Game\GameException $e) {
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
        } catch (\App\Game\GameException $e) {
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
        } catch (\App\Game\GameException $e) {
            $this->assertSame('requires', $e->errorCode);
        }
    }

    /** §7.4.2 -- a capstone takes two parents, which is what makes it a tree. */
    public function test_a_capstone_needs_both_of_its_parents(): void
    {
        $this->character->level = 100;
        $this->character->save();
        $this->setJobLevel('smith', Balance::JOB_MAX_LEVEL);

        $capstone = \App\Game\Jobs::node('smith.the_named_blade');
        $this->assertCount(2, $capstone['requires']);

        $this->grantNodes([$capstone['requires'][0]]);

        try {
            $this->game->buyNode($this->character->fresh(), 'smith.the_named_blade');
            $this->fail('bought a capstone with only one parent');
        } catch (\App\Game\GameException $e) {
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
        $this->give(['planks' => 8]);

        $before = $this->character->jobLevels()->where('job_key', 'smith')->first()->xp;
        $this->game->craftItem($this->character->fresh(), 'hewn_axe');
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
        $this->give(['planks' => 8, 'cloth' => 8, 'leather' => 8]);

        $this->game->craftItem($this->character->fresh(), 'hewn_axe');
        $this->game->craftItem($this->character->fresh(), 'work_gloves');

        // Settlements sit on worked ground, so step off it before digging.
        $open = $this->openNeighbour($this->character->col, $this->character->row);
        $this->character->update($open);
        $this->character->refresh();

        $job = $this->game->startMining($this->character->fresh(), $open['col'], $open['row']);
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

        // Every node in the two trees that carry `yield`, bought outright.
        $keys = [];
        foreach (['smith', 'alchemist'] as $job) {
            foreach (array_keys(\App\Game\Jobs::nodesFor($job)) as $key) {
                $keys[] = $key;
            }
        }
        $this->grantNodes($keys);

        // Best-in-slot on the line, plus a running potion on the same stat.
        \App\Models\CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => 'ironwood_axe',
            'durability' => 200,
            'equipped' => true,
            'options' => [['stat' => 'yield', 'value' => 0.03]],
        ]);
        $this->character->buffs()->create([
            'item_key' => 'forest_draught',
            'stat' => 'yield',
            'value' => 0.03,
            'expires_at' => $this->game->now() + 600000,
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

        // Every cost node the Smith tree has.
        $keys = [];
        foreach (\App\Game\Jobs::nodesFor('smith') as $key => $node) {
            if ($node['effect']['kind'] === 'costReduction') {
                $keys[] = $key;
            }
        }
        $this->grantNodes($keys);

        $this->give(['planks' => 40]);
        $before = $this->game->held($this->character->fresh(), 'planks');
        $this->game->craftItem($this->character->fresh(), 'hewn_axe');
        $spent = $before - $this->game->held($this->character->fresh(), 'planks');

        $this->assertGreaterThan(0, $spent, 'a maxed Smith crafted out of thin air');
        $this->assertLessThan(4, $spent, 'the discount did nothing at all');
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
        $this->game->startMining($far, (int) $far->col, (int) $far->row);

        $this->assertSame([], $this->game->mapMutations($this->character)['occupied']);

        // Walk one hex and the same tile is knowable. Sight follows the
        // character, never the camera.
        $this->game->travelTo($this->character, (int) $this->character->col + 1, (int) $this->character->row);
        $this->arrive($this->character);

        $seen = $this->game->mapMutations($this->character->fresh())['occupied'];
        $this->assertSame([[(int) $far->col, (int) $far->row, 1]], $seen);
    }

    /**
     * §5.6 -- on the road you are watching your feet.
     *
     * This is also what makes a long walk free: sight of zero means the map
     * query has nothing to scan, so a journey of two hundred hexes costs the
     * same two requests as a journey of one.
     */
    public function test_sight_closes_to_nothing_while_travelling(): void
    {
        // Somebody working the hex right next to you -- plainly in sight.
        $near = $this->game->createCharacter(Player::create(['wallet' => '0xnear']));
        $near->update(['col' => (int) $this->character->col + 1, 'row' => (int) $this->character->row]);
        $this->game->startMining($near, (int) $near->col, (int) $near->row);

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

    /** The edge of the map is the one place a road cannot go. */
    public function test_travel_refuses_to_leave_the_map(): void
    {
        $this->expectException(\App\Game\GameException::class);
        $this->game->travelTo($this->character, -1, (int) $this->character->row);
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
     * §7.5 -- the chain is granted, and granted means free.
     *
     * If a wayfaring node ever cost a point, the hundred-point cap (§7.4.1)
     * would quietly become ninety-five, and the tree that is supposed to reward
     * walking would start competing with the benches instead.
     */
    public function test_explorer_skills_arrive_unbought_and_cost_no_points(): void
    {
        // §7.5 -- a character who has walked nowhere owns nothing. The first
        // row waits for Explorer 2, not 1: a granted node has no point paying
        // for it, so the walk is the price.
        $this->assertNotContains('explorer.deep_pockets', $this->game->ownedNodes($this->character));

        // A row arrives whole, exactly as a bought tree's depth opens whole.
        $this->explorerAt(2);
        $owned = $this->game->ownedNodes($this->character->fresh());
        foreach (['deep_pockets', 'second_strap', 'rolled_blanket'] as $key) {
            $this->assertContains("explorer.{$key}", $owned);
        }
        $this->assertNotContains('explorer.even_load', $owned, 'row two arrived early');
        $this->assertSame(0, $this->game->skillPoints($this->character)['spent']);

        $this->explorerAt(9);

        $this->assertContains('explorer.even_load', $this->game->ownedNodes($this->character));
        $this->assertSame(
            0,
            $this->game->skillPoints($this->character)['spent'],
            'a granted node was billed to the point ledger',
        );
    }

    /** And what is granted is not for sale, however many points you are holding. */
    public function test_an_explorer_skill_cannot_be_bought(): void
    {
        $this->character->update(['level' => 40]);
        $this->explorerAt(30);

        $this->expectException(\App\Game\GameException::class);
        $this->game->buyNode($this->character->fresh(), 'explorer.horizon_line');
    }

    /** §7.5 -- two hexes of eye on top of the base one, earned one at a time. */
    public function test_the_explorer_chain_widens_sight_and_then_stops(): void
    {
        $this->assertSame(Balance::SIGHT_RADIUS, $this->game->sightRadius($this->character));

        // High Ground, row two. The eye is the rarest thing the road pays in,
        // so it arrives later than a strap does.
        $this->explorerAt(9);
        $this->assertSame(Balance::SIGHT_RADIUS + 1, $this->game->sightRadius($this->character->fresh()));

        $this->explorerAt(Balance::JOB_MAX_LEVEL);
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
            \App\Game\Jobs::nodesFor('explorer'),
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

        foreach (array_keys(\App\Game\Catalog::materials()) as $key) {
            if ($rows() >= $target) {
                return;
            }
            if (in_array($key, $except, true)) {
                continue;
            }
            \App\Models\CharacterMaterial::create([
                'character_id' => $this->character->id,
                'material_key' => $key,
                'quantity' => 1,
            ]);
        }

        foreach (\App\Game\Catalog::items() as $key => $def) {
            if ($rows() >= $target) {
                return;
            }
            if (empty($def['consumable'])) {
                continue;
            }
            $this->character->consumables()->create(['item_key' => $key, 'quantity' => 1]);
        }

        while ($rows() < $target) {
            \App\Models\CharacterItem::create([
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
        \App\Models\CharacterItem::create([
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
        $item = \App\Models\CharacterItem::create([
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
        $item = \App\Models\CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => 'stone_axe',
            'durability' => 40,
            'equipped' => true,
        ]);

        $this->fillStraps();

        try {
            $this->game->unequipItem($this->character->fresh(), $item->id);
            $this->fail('unequipped into a bag with no room');
        } catch (\App\Game\GameException $e) {
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
        } catch (\App\Game\GameException $e) {
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
        $spare = array_key_first(\App\Game\Catalog::materials());
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
        $col = (int) $this->character->col;
        $row = (int) $this->character->row;
        $material = $this->game->previewTile($this->character, $col, $row)['material'];

        // Every strap taken, and none of them by what this hex pays.
        $this->fillStraps([$material]);

        $ap = (int) $this->character->fresh()->ap;

        try {
            $this->game->startMining($this->character->fresh(), $col, $row);
            $this->fail('started a dig with nowhere to put it');
        } catch (\App\Game\GameException $e) {
            $this->assertSame('no_room', $e->errorCode);
        }

        $this->assertSame($ap, (int) $this->character->fresh()->ap, 'a refused dig still charged AP');
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
     * Five rows of three, ten units or four straps a node, at job levels 2, 9,
     * 16, 23 and 30. The climb is what is being tested here as much as the
     * ceiling: an early row has to be felt, or a tree nobody spends points on is
     * a tree nobody notices.
     */
    public function test_the_explorer_chain_widens_the_bag_and_then_stops(): void
    {
        $bag = $this->game->bag($this->character);
        $this->assertSame(Balance::BAG_UNITS, $bag['unitCap']);
        $this->assertSame(Balance::BAG_ROWS, $bag['rowCap']);

        // Row one, at Explorer 2: four hexes of walking, and a whole row of
        // three arrives at once -- twenty units of room and four straps.
        $this->explorerAt(2);
        $bag = $this->game->bag($this->character->fresh());
        $this->assertSame(Balance::BAG_UNITS + 20, $bag['unitCap']);
        $this->assertSame(Balance::BAG_ROWS + 4, $bag['rowCap']);

        // Row two is about 290 hexes further on, and pays one of each again.
        $this->explorerAt(9);
        $bag = $this->game->bag($this->character->fresh());
        $this->assertSame(Balance::BAG_UNITS + 30, $bag['unitCap']);
        $this->assertSame(Balance::BAG_ROWS + 8, $bag['rowCap']);

        $this->explorerAt(Balance::JOB_MAX_LEVEL);
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
        $range = Balance::SPAWN_VILLAGE_RADIUS;

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

        $this->fail('spawn guarantee broken: no woodcutting village in spawn radius');
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
