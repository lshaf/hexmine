<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\Dailies;
use App\Game\GameException;
use App\Game\GameService;
use App\Game\Quests;
use App\Models\Character;
use App\Models\CharacterDaily;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * §12.2 -- the day's three.
 *
 * What these pin is the argument rather than the numbers: the rate cap, the
 * "workable from where you are standing" rule the field lane rests on, and the
 * fact that a daily resets where a quest does not.
 */
final class DailyTest extends TestCase
{
    use RefreshDatabase;

    private GameService $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = app(GameService::class);
    }

    // -------------------------------------------------------------- the pool

    /**
     * A claim names a task and nothing else, exactly as a quest claim does, so
     * two lanes may never use one key.
     */
    public function test_task_keys_are_unique_across_every_lane(): void
    {
        $seen = [];

        foreach (Dailies::DEFS as $lane => $tasks) {
            foreach (array_keys($tasks) as $key) {
                $this->assertArrayNotHasKey($key, $seen, "{$key} is in two lanes, and a claim names a key alone");
                $seen[$key] = $lane;
            }
        }

        $this->assertSame(count($seen), count(Dailies::all()));
    }

    /** A daily key and a quest key share a namespace on screen; keep them apart. */
    public function test_no_daily_shares_a_key_with_a_quest(): void
    {
        $this->assertSame(
            [],
            array_intersect(array_keys(Dailies::all()), array_keys(Quests::DEFS)),
        );
    }

    /**
     * §12.2 -- the field lane is the one that is always workable from the hex
     * you are standing on, and that is only true while nothing in it names a
     * material. The map takes days to cross (§5.6); a 24-hour task wanting iron
     * ore, handed to somebody in a forest, is a taunt rather than a task.
     */
    public function test_nothing_in_the_field_lane_names_a_material(): void
    {
        foreach (Dailies::DEFS['field'] as $key => $def) {
            $this->assertSame('gather', $def['goal']['kind'], "{$key} is not field work");
            $this->assertNull($def['goal']['subject'], "{$key} names a subject and so can be undoable");
        }
    }

    /**
     * The same argument, one step weaker, for the other two: a village runs one
     * processing line of the five (§6) and which one is not the player's choice,
     * so a daily may never name one either.
     */
    public function test_no_task_anywhere_narrows_its_counter(): void
    {
        foreach (Dailies::all() as $key => $def) {
            $this->assertNull($def['goal']['subject'], "{$key} narrows a counter");
        }
    }

    /** Every goal rides a counter §12 already had -- nothing here is a new verb. */
    public function test_every_goal_rides_an_existing_counter(): void
    {
        foreach (Dailies::all() as $key => $def) {
            $this->assertContains($def['goal']['kind'], Quests::COUNTED, "{$key}");
        }
    }

    /**
     * §12.2 -- the cap is a rate, so the richest possible day has to stay small.
     * This is the assert that notices a template drifting upward.
     */
    public function test_the_richest_possible_day_stays_little_money(): void
    {
        $most = 0;

        foreach (Dailies::LANES as $lane) {
            $most += max(array_column(Dailies::DEFS[$lane], 'gold'));
        }

        $this->assertLessThanOrEqual(150, $most, 'a day pays more than a day should');
    }

    // ---------------------------------------------------------------- the draw

    public function test_a_day_is_one_task_from_each_lane(): void
    {
        $today = Dailies::forDay(1, Dailies::dayIndex($this->game->now()));

        $this->assertSame(Dailies::LANES, array_keys($today));

        foreach ($today as $lane => $key) {
            $this->assertArrayHasKey($key, Dailies::DEFS[$lane]);
        }
    }

    /** Derived, so the same character on the same day always gets the same three. */
    public function test_the_draw_is_stable_for_a_character_and_a_day(): void
    {
        $this->assertSame(Dailies::forDay(7, 900), Dailies::forDay(7, 900));
    }

    /** And it moves -- otherwise a "daily" is a fixed list with a reset button. */
    public function test_the_draw_moves_across_days(): void
    {
        $seen = [];
        for ($day = 0; $day < 40; $day++) {
            $seen[implode('/', Dailies::forDay(3, $day))] = true;
        }

        $this->assertGreaterThan(3, count($seen), 'the draw barely moves');
    }

    public function test_two_characters_are_not_handed_the_same_day(): void
    {
        $day = 500;
        $same = 0;
        for ($id = 1; $id <= 40; $id++) {
            if (Dailies::forDay($id, $day) === Dailies::forDay(1, $day)) {
                $same++;
            }
        }

        $this->assertLessThan(20, $same, 'the draw ignores the character');
    }

    /** A day is a duration, so a fast clock shortens it (§7.4.4 exempts XP only). */
    public function test_the_day_runs_on_the_scaled_clock(): void
    {
        $this->assertSame(Balance::scaled(Dailies::DAY_MS), Dailies::dayLengthMs());
    }

    // -------------------------------------------------------------- the ledger

    public function test_the_state_carries_todays_three_and_when_they_turn(): void
    {
        $character = $this->character();
        $payload = $this->game->dailyPayload($character);

        $this->assertCount(3, $payload['tasks']);
        $this->assertSame(Dailies::LANES, array_column($payload['tasks'], 'lane'));
        $this->assertGreaterThan($this->game->now(), $payload['resetsAt']);

        foreach ($payload['tasks'] as $task) {
            $this->assertSame(0, $task['progress']);
            $this->assertFalse($task['complete']);
            $this->assertFalse($task['claimed']);
        }
    }

    public function test_work_credits_todays_task_and_then_pays(): void
    {
        $character = $this->character();
        $task = $this->fieldTask($character);
        $def = Dailies::task($task);

        $this->fire($character, 'gather', $def['goal']['target'], 'wood');

        $payload = $this->game->dailyPayload($character->fresh());
        $row = collect($payload['tasks'])->firstWhere('key', $task);
        $this->assertTrue($row['complete']);

        $before = (int) $character->fresh()->gold;
        $result = $this->game->claimDaily($character->fresh(), $task);

        $this->assertSame($def['gold'], $result['gold']);
        $this->assertSame($before + $def['gold'], (int) $character->fresh()->gold);
    }

    public function test_a_daily_pays_once_a_day(): void
    {
        $character = $this->character();
        $task = $this->fieldTask($character);

        $this->fire($character, 'gather', Dailies::task($task)['goal']['target'], 'wood');
        $this->game->claimDaily($character->fresh(), $task);

        $this->expectException(GameException::class);
        $this->game->claimDaily($character->fresh(), $task);
    }

    public function test_an_unfinished_task_is_refused(): void
    {
        $character = $this->character();

        $this->expectException(GameException::class);
        $this->game->claimDaily($character, $this->fieldTask($character));
    }

    /**
     * §12.2 -- the rate cap is only real while a client cannot name whichever of
     * the eleven it likes. Three a day, and the other eight are not offered.
     */
    public function test_a_task_that_is_not_one_of_todays_is_refused(): void
    {
        $character = $this->character();
        $today = Dailies::forDay((int) $character->id, Dailies::dayIndex($this->game->now()));

        $other = collect(array_keys(Dailies::all()))
            ->first(fn (string $key) => ! in_array($key, $today, true));

        // Finished as far as the counter is concerned -- it is still refused,
        // because it was never asked for.
        CharacterDaily::create([
            'character_id' => $character->id,
            'day' => Dailies::dayIndex($this->game->now()),
            'task_key' => $other,
            'progress' => 9999,
        ]);

        try {
            $this->game->claimDaily($character->fresh(), $other);
            $this->fail('a task nobody was asked to run paid out');
        } catch (GameException $e) {
            $this->assertSame('not_today', $e->errorCode);
        }
    }

    /**
     * §12.2 -- only today's work counts, which is exactly where this and the
     * quest ledger disagree. A quest credits work done before it was offered; a
     * daily whose tally carried over would be a quest with a slower name.
     */
    public function test_yesterdays_progress_does_not_credit_today(): void
    {
        $character = $this->character();
        $today = Dailies::dayIndex($this->game->now());
        $task = $this->fieldTask($character);

        CharacterDaily::create([
            'character_id' => $character->id,
            'day' => $today - 1,
            'task_key' => $task,
            'progress' => Dailies::task($task)['goal']['target'],
        ]);

        $row = collect($this->game->dailyPayload($character)['tasks'])->firstWhere('key', $task);
        $this->assertSame(0, $row['progress']);
        $this->assertFalse($row['complete']);
    }

    /** One hook, two ledgers: the same haul moves a quest and a daily at once. */
    public function test_one_hook_credits_both_ledgers(): void
    {
        $character = $this->character();
        $task = $this->fieldTask($character);

        $this->fire($character, 'gather', 5, 'branch');

        $quest = collect($this->game->questPayload($character->fresh()))
            ->firstWhere('key', 'bare_hands');
        $daily = collect($this->game->dailyPayload($character->fresh())['tasks'])
            ->firstWhere('key', $task);

        $this->assertSame(5, $quest['progress']);
        $this->assertSame(5, $daily['progress']);
    }

    // ------------------------------------------------------------------ helpers

    private function character(): Character
    {
        return $this->game->createCharacter(Player::create(['wallet' => 'wallet-daily']));
    }

    private function fieldTask(Character $character): string
    {
        return Dailies::forDay((int) $character->id, Dailies::dayIndex($this->game->now()))['field'];
    }

    private function fire(Character $character, string $kind, int $amount, ?string $subject = null): void
    {
        $fire = new ReflectionMethod($this->game, 'fireGoal');
        $fire->invoke($this->game, $character, $kind, $amount, $subject);
    }
}
