<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\Catalog;
use App\Game\GameException;
use App\Game\GameService;
use App\Game\WorldGen;
use App\Models\Character;
use App\Models\GameJob;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * §8.4 -- a bench takes time, and it hands over where it was left.
 *
 * Crafting used to be instant, which made a capital a vending machine: carry
 * the materials in, walk out with the item. Two rules turn it back into a
 * place. It runs on a clock, and the finished thing is claimed AT the bench --
 * so choosing which settlement to use is a decision about a walk you will have
 * to make twice.
 */
final class BenchJobTest extends TestCase
{
    use RefreshDatabase;

    private GameService $game;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->game = app(GameService::class);
        $player = Player::create(['wallet' => '0xbench', 'session_id' => 'bench']);
        $this->character = $this->game->createCharacter($player);
    }

    /**
     * Stand at the woodcutting village every spawn is guaranteed one of (§5.4).
     * Searched rather than fabricated, like the rest of the world.
     */
    private function standAtVillage(): array
    {
        $range = Balance::SPAWN_VILLAGE_RADIUS;

        for ($dc = -$range; $dc <= $range; $dc++) {
            for ($dr = -$range; $dr <= $range; $dr++) {
                $s = WorldGen::settlementAt(
                    (int) $this->character->col + $dc,
                    (int) $this->character->row + $dr,
                );

                if ($s && in_array('woodcutting', $s['lines'], true)) {
                    $this->character->update(['col' => $s['col'], 'row' => $s['row']]);
                    $this->character->refresh();

                    return $s;
                }
            }
        }

        $this->fail('spawn guarantee broken: no woodcutting village in spawn radius');
    }

    private function give(array $stock): void
    {
        $add = new ReflectionMethod($this->game, 'addMaterial');
        $add->setAccessible(true);

        foreach ($stock as $key => $qty) {
            $add->invoke($this->game, $this->character, $key, $qty);
        }

        $this->character = $this->character->fresh();
    }

    private function stockForAxe(): void
    {
        $this->give(['wood' => 20, 'planks' => 12, 'heartknot' => 12]);
    }

    /** §8.4 -- the bench holds it. Nothing is made at the moment you ask. */
    public function test_a_craft_takes_time_and_makes_nothing_up_front(): void
    {
        $this->standAtVillage();
        $this->stockForAxe();

        $before = $this->character->items()->count();
        $job = $this->game->startCraft($this->character->fresh(), 'hewn_axe');

        $this->assertSame('craft', $job->kind);
        $this->assertGreaterThan($this->game->now(), (int) $job->ends_at, 'the bench finished instantly');
        $this->assertSame($before, $this->character->fresh()->items()->count(), 'the axe existed before it was made');

        // The materials, though, are gone: they are on the bench.
        $this->assertLessThan(20, $this->game->held($this->character->fresh(), 'wood'));

        try {
            $this->game->collectJob($this->character->fresh(), $job->id);
            $this->fail('an unfinished craft was collected');
        } catch (GameException $e) {
            $this->assertSame('not_ready', $e->errorCode);
        }

        $job->update(['ends_at' => $this->game->now() - 1]);
        $made = $this->game->collectJob($this->character->fresh(), $job->id);

        $this->assertSame('hewn_axe', $made['made']['key']);
        $this->assertSame($before + 1, $this->character->fresh()->items()->count());
    }

    /**
     * §7.4 -- the receipt has to say what the bench taught.
     *
     * The XP was always granted; the plate reported a flat zero for it, because
     * a craft earns no §7.2 skill XP and no character XP, and those were the
     * only two figures on the receipt. An hour at the anvil that levels a trade
     * must not read as an hour that did nothing.
     */
    public function test_a_finished_craft_reports_the_job_it_taught(): void
    {
        $this->standAtVillage();
        $this->stockForAxe();

        $job = $this->game->startCraft($this->character->fresh(), 'hewn_axe');
        $job->update(['ends_at' => $this->game->now() - 1]);

        $before = $this->character->fresh()->jobLevels()->where('job_key', 'smith')->value('xp') ?? 0;
        $result = $this->game->collectJob($this->character->fresh(), $job->id);

        $this->assertSame('smith', $result['job'], 'an axe is the weapon bench, so it is the Smith that learns');
        $this->assertGreaterThan(0, $result['jobXp'], 'the receipt reported no job XP for a finished craft');

        // And what it reported is what was actually written down, rather than a
        // figure the plate makes up on its own.
        $after = $this->character->fresh()->jobLevels()->where('job_key', 'smith')->value('xp') ?? 0;
        $this->assertSame($before + $result['jobXp'], $after);
    }

    /** §8.4 -- a bench is a place, not a queue you can stack five deep. */
    public function test_one_craft_at_a_time_per_bench(): void
    {
        $this->standAtVillage();
        $this->stockForAxe();

        $this->game->startCraft($this->character->fresh(), 'hewn_axe');

        $this->expectException(GameException::class);
        $this->expectExceptionMessageMatches('/already have something on the bench/');

        $this->game->startCraft($this->character->fresh(), 'hewn_axe');
    }

    /**
     * §8.4 + §6.1 -- the benches queue the way the lines do: five slots, shared,
     * first-come-first-served.
     */
    public function test_the_bench_queue_fills_up_and_refuses(): void
    {
        $bench = $this->standAtVillage();
        $this->stockForAxe();

        // Five foreign crafts take the whole bank. They belong to other players
        // because one of the character's own would mine the one-bench-each rule
        // first, and the queue would never be tested.
        for ($i = 0; $i < Balance::BENCH_SLOTS; $i++) {
            $other = $this->game->createCharacter(
                Player::create(['wallet' => "0xbench{$i}"]),
            );

            GameJob::create([
                'character_id' => $other->id,
                'kind' => 'craft',
                'status' => 'active',
                'settlement_id' => $bench['id'],
                'output_key' => 'hewn_axe',
                'quantity' => 1,
                'skill_key' => 'woodcutting',
                'started_at' => $this->game->now(),
                'ends_at' => $this->game->now() + 60000,
            ]);
        }

        try {
            $this->game->startCraft($this->character->fresh(), 'hewn_axe');
            $this->fail('a sixth bench was handed out');
        } catch (GameException $e) {
            $this->assertSame('queue_full', $e->errorCode);
        }

        // And the queue is drawn from the same count the refusal used.
        $station = $this->game->station($this->character->fresh(), $bench['id']);
        $this->assertCount(Balance::BENCH_SLOTS, $station['bench']);
        $this->assertSame(
            Balance::BENCH_SLOTS,
            count(array_filter($station['bench'], fn (array $s) => $s['owner'] !== null)),
        );
    }

    /**
     * §8.4 -- and the two banks are counted apart. A busy forge is not a busy
     * saw pit, which is what a single count made it.
     */
    public function test_a_craft_never_takes_a_processing_slot(): void
    {
        $bench = $this->standAtVillage();
        $this->stockForAxe();
        $this->give(['wood' => 40]);

        for ($i = 0; $i < Balance::BENCH_SLOTS; $i++) {
            $other = $this->game->createCharacter(
                Player::create(['wallet' => "0xmix{$i}"]),
            );

            GameJob::create([
                'character_id' => $other->id,
                'kind' => 'craft',
                'status' => 'active',
                'settlement_id' => $bench['id'],
                'output_key' => 'hewn_axe',
                'quantity' => 1,
                'skill_key' => 'woodcutting',
                'started_at' => $this->game->now(),
                'ends_at' => $this->game->now() + 60000,
            ]);
        }

        $job = $this->game->startProcessing($this->character->fresh(), $bench['id'], 'planks', 1);
        $this->assertSame('processing', $job->kind);

        $station = $this->game->station($this->character->fresh(), $bench['id']);
        $this->assertSame(
            1,
            count(array_filter($station['slots'], fn (array $s) => $s['owner'] !== null)),
            'a craft was counted against the processing queue',
        );
    }

    /**
     * §8.4 -- the rule that makes the bench a location rather than a mailbox.
     */
    public function test_a_finished_craft_is_claimed_at_the_bench_that_made_it(): void
    {
        $bench = $this->standAtVillage();
        $this->stockForAxe();

        $job = $this->game->startCraft($this->character->fresh(), 'hewn_axe');
        $job->update(['ends_at' => $this->game->now() - 1]);

        // Walk off. The axe is finished, and it is finished over there.
        $this->character->col = (int) $bench['col'] + 4;
        $this->character->save();

        try {
            $this->game->collectJob($this->character->fresh(), $job->id);
            $this->fail('a craft was claimed from the other side of the map');
        } catch (GameException $e) {
            $this->assertSame('not_present', $e->errorCode);
            $this->assertStringContainsString($bench['name'], $e->getMessage());
        }

        // Walk back, and it is yours.
        $this->character->col = (int) $bench['col'];
        $this->character->save();

        $made = $this->game->collectJob($this->character->fresh(), $job->id);
        $this->assertSame('hewn_axe', $made['made']['key']);
    }

    /** §6 -- and the same rule for a processing run, for the same reason. */
    public function test_a_processing_run_is_claimed_where_it_was_started(): void
    {
        $bench = $this->standAtVillage();
        $this->give(['wood' => 30]);

        $line = $bench['lines'][0];
        $recipe = collect(Catalog::recipes())
            ->filter(fn (array $r) => $r['skill'] === $line)
            ->keys()
            ->first();

        $this->give([Catalog::recipe($recipe)['input'] => 30]);

        $job = $this->game->startProcessing($this->character->fresh(), $bench['id'], $recipe, 1);
        $job->update(['ends_at' => $this->game->now() - 1]);

        $this->character->row = (int) $bench['row'] + 3;
        $this->character->save();

        try {
            $this->game->collectJob($this->character->fresh(), $job->id);
            $this->fail('a run was claimed from somewhere else');
        } catch (GameException $e) {
            $this->assertSame('not_present', $e->errorCode);
        }

        $this->character->row = (int) $bench['row'];
        $this->character->save();

        $collected = $this->game->collectJob($this->character->fresh(), $job->id);
        $this->assertNotEmpty($collected['gained']);
    }

    /**
     * §7.6 -- the strap is asked for twice: before the work, and again when the
     * thing is handed over. An hour is long enough to fill a bag, and the
     * answer is a refusal rather than a lost item -- it stays on the bench.
     */
    public function test_a_full_bag_leaves_the_thing_on_the_bench(): void
    {
        $this->standAtVillage();
        $this->stockForAxe();

        $job = $this->game->startCraft($this->character->fresh(), 'hewn_axe');
        $job->update(['ends_at' => $this->game->now() - 1]);

        // Fill every strap with something else.
        $rows = [];
        foreach (array_keys(Catalog::materials()) as $key) {
            if (count($rows) >= Balance::BAG_ROWS) {
                break;
            }
            $rows[$key] = 1;
        }
        $this->give($rows);

        try {
            $this->game->collectJob($this->character->fresh(), $job->id);
            $this->fail('an axe was forced into a full bag');
        } catch (GameException $e) {
            $this->assertSame('no_room', $e->errorCode);
        }

        // Still there, and still claimable once there is room.
        $this->assertNotNull($this->character->fresh()->jobs()->where('id', $job->id)->first());
    }

    /** §8.4 -- the payload says where it is, because a claim needs you there. */
    public function test_the_job_says_which_bench_is_holding_it(): void
    {
        $bench = $this->standAtVillage();
        $this->stockForAxe();

        $payload = $this->game->jobPayload($this->game->startCraft($this->character->fresh(), 'hewn_axe'));

        $this->assertSame('craft', $payload['kind']);
        $this->assertSame($bench['name'], $payload['settlementName']);
        $this->assertSame((int) $bench['col'], $payload['col']);
        $this->assertSame((int) $bench['row'], $payload['row']);
        $this->assertSame('hewn_axe', $payload['output']);
    }
}
