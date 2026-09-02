<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\Catalog;
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
     *
     * The sweep is over every task at every GRADE, because the table's figures
     * are only the `B` version and the richest day is three S tasks.
     */
    public function test_the_richest_possible_day_stays_little_money(): void
    {
        $most = 0;

        foreach (Dailies::LANES as $lane) {
            $best = 0;
            foreach (array_keys(Dailies::DEFS[$lane]) as $key) {
                foreach (array_keys(Dailies::GRADES) as $grade) {
                    $best = max($best, Dailies::graded($key, $grade)['gold']);
                }
            }
            $most += $best;
        }

        $this->assertLessThanOrEqual(400, $most, 'a day pays more than a day should');
    }

    /**
     * §12.2 -- and the day a player actually gets has to stay where it was.
     *
     * The ceiling above is the rare day; this is the ordinary one, and it is
     * the number that decides whether grades were variance or a raise. Weighted
     * by how often each grade and each task comes up, an average day pays about
     * a hundred gold -- *less* than the flat day paid before grades existed,
     * which is what makes S worth noticing rather than worth farming.
     */
    public function test_an_average_day_did_not_get_richer(): void
    {
        $weights = array_sum(array_column(Dailies::GRADES, 'weight'));
        $expected = 0.0;

        foreach (Dailies::LANES as $lane) {
            $keys = array_keys(Dailies::DEFS[$lane]);
            foreach ($keys as $key) {
                foreach (Dailies::GRADES as $grade => $spec) {
                    $expected += Dailies::graded($key, $grade)['gold']
                        * ($spec['weight'] / $weights)
                        / count($keys);
                }
            }
        }

        $this->assertLessThanOrEqual(120, $expected, 'grades turned into a raise');
    }

    /**
     * §12.2 -- **every task pays less than the work it asks for**, at every
     * grade. This is the second half of the faucet's safety and it was prose.
     *
     * Costed against **scrap**, at a gold a unit (§4.0), which is the least any
     * of it can possibly be worth: the field lane counts anything off any hex,
     * so a bare-handed day really is a pile of branches. The realistic figure is
     * about a third, because raw sells for more than scrap -- but the claim has
     * to survive the worst reading of "units", not the flattering one.
     *
     * A `sell` goal is costed at face value, since its target IS gold taken off
     * a trader: paying more for taking it than it was worth would be a trader
     * paying twice.
     */
    public function test_no_task_at_any_grade_pays_more_than_the_work(): void
    {
        foreach (Dailies::all() as $key => $_) {
            foreach (array_keys(Dailies::GRADES) as $grade) {
                $def = Dailies::graded($key, $grade);
                $goal = $def['goal'];

                $worth = match ($goal['kind']) {
                    'gather' => $goal['target'] * $this->cheapestUnit(),
                    'sell' => $goal['target'],
                    // A run, a craft and a walk have no gold price to compare
                    // against -- and none of them hands you anything sellable
                    // that the gather lane has not already been costed for.
                    default => null,
                };

                if ($worth === null) {
                    continue;
                }

                $this->assertLessThan(
                    $worth,
                    $def['gold'],
                    "{$key} at {$grade} pays {$def['gold']} for work worth {$worth}",
                );
            }
        }
    }

    // --------------------------------------------------------------- grades

    /**
     * §12.2 -- a grade is the same errand asked bigger, so both halves move and
     * both move the same way. A grade that paid more for less would be a
     * discount wearing a letter.
     */
    public function test_a_higher_grade_asks_more_and_pays_more(): void
    {
        $ladder = array_keys(Dailies::GRADES);

        foreach (Dailies::all() as $key => $_) {
            foreach ($ladder as $i => $grade) {
                if ($i === 0) {
                    continue;
                }

                $under = Dailies::graded($key, $ladder[$i - 1]);
                $over = Dailies::graded($key, $grade);

                $this->assertGreaterThan(
                    $under['gold'],
                    $over['gold'],
                    "{$key}: {$grade} does not pay more than {$ladder[$i - 1]}",
                );
                // Not *strictly* greater: a target of one cannot be asked for
                // less than one, so the smallest tasks compress at the bottom.
                $this->assertGreaterThanOrEqual(
                    $under['goal']['target'],
                    $over['goal']['target'],
                    "{$key}: {$grade} asks less than {$ladder[$i - 1]}",
                );
            }
        }
    }

    /** Nothing is ever asked for zero of, whatever the multiplier does. */
    public function test_no_grade_can_ask_for_nothing(): void
    {
        foreach (Dailies::all() as $key => $_) {
            foreach (array_keys(Dailies::GRADES) as $grade) {
                $this->assertGreaterThanOrEqual(
                    1,
                    Dailies::graded($key, $grade)['goal']['target'],
                    "{$key} at {$grade} is finished before it is read",
                );
            }
        }
    }

    /**
     * §12.2 -- all four turn up, and S is the rare one.
     *
     * A grade nobody is ever handed is a grade that does not exist, and one
     * handed out as often as the rest is not a grade at all -- it is a coin
     * flip with four faces.
     */
    public function test_every_grade_is_drawn_and_s_is_the_rare_one(): void
    {
        $seen = array_fill_keys(array_keys(Dailies::GRADES), 0);

        for ($id = 1; $id <= 60; $id++) {
            for ($day = 0; $day < 30; $day++) {
                foreach (Dailies::forDay($id, $day) as $draw) {
                    $seen[$draw['grade']]++;
                }
            }
        }

        foreach ($seen as $grade => $count) {
            $this->assertGreaterThan(0, $count, "{$grade} is never handed to anybody");
        }

        $this->assertLessThan($seen['C'], $seen['S'], 'S is not rare');
        $this->assertLessThan($seen['B'], $seen['S'], 'S is not rare');
        $this->assertLessThan($seen['A'], $seen['S'], 'S is not rare');
    }

    /**
     * §12.2 -- the seed is made of immutable things, and claiming a name is the
     * one thing about a prospector that changes.
     *
     * This is the assert that would have caught the tempting version of the
     * seed. Progress is filed under the task keys the draw produced, so a seed
     * that moved when a name was claimed would re-roll the day underneath a
     * player who had already half-finished it: banked progress orphaned, and a
     * finished task answering "that is not one of today's" to its own claim.
     */
    public function test_claiming_a_name_does_not_re_roll_the_day(): void
    {
        $character = $this->character();
        $day = Dailies::dayIndex($this->game->now());

        $before = Dailies::forDay($this->game->dailyIdentity($character), $day);

        // Bank some progress against today's field task, then take the name.
        $this->fire($character, 'gather', 3, 'wood');
        $this->game->renameCharacter($character, 'Grubstake');

        $fresh = $character->fresh();
        $after = Dailies::forDay($this->game->dailyIdentity($fresh), $day);

        $this->assertSame($before, $after, 'naming yourself re-rolled the day');

        $row = collect($this->game->dailyPayload($fresh)['tasks'])
            ->firstWhere('key', $before['field']['key']);
        $this->assertSame(3, $row['progress'], 'the banked progress was orphaned');
    }

    /**
     * The grade rides its own hash, so retuning the grade table can never
     * shuffle which task somebody was given.
     */
    public function test_the_grade_draw_is_stable_and_separate_from_the_task(): void
    {
        $this->assertSame(Dailies::forDay(11, 400), Dailies::forDay(11, 400));

        $grades = [];
        for ($day = 0; $day < 40; $day++) {
            $grades[Dailies::forDay(5, $day)['field']['grade']] = true;
        }

        $this->assertGreaterThan(1, count($grades), 'one character only ever sees one grade');
    }

    // ---------------------------------------------------------------- the draw

    public function test_a_day_is_one_task_from_each_lane(): void
    {
        $today = Dailies::forDay(Dailies::identity('wallet-x', 1700000000, 1), Dailies::dayIndex($this->game->now()));

        $this->assertSame(Dailies::LANES, array_keys($today));

        foreach ($today as $lane => $draw) {
            $this->assertArrayHasKey($draw['key'], Dailies::DEFS[$lane]);
            $this->assertArrayHasKey($draw['grade'], Dailies::GRADES);
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
            $seen[implode('/', Dailies::keysForDay(3, $day))] = true;
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
        // §12.2 -- the GRADED figures: what the table says is only the B
        // version, and this character was not necessarily handed that one.
        $def = $this->fieldGraded($character);

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

        $this->fire($character, 'gather', $this->fieldGraded($character)['goal']['target'], 'wood');
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
        $today = Dailies::keysForDay($this->game->dailyIdentity($character), Dailies::dayIndex($this->game->now()));

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
            'progress' => $this->fieldGraded($character)['goal']['target'],
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
        return $this->fieldDraw($character)['key'];
    }

    /**
     * §4.0 -- the least a unit out of a hex can possibly be worth.
     *
     * Read off the catalog rather than written down here: scrap sells for a
     * gold and every raw material must sell for more, which §4.0 makes a rule
     * rather than a tuning value -- so the floor is whatever the cheapest thing
     * a trader will take is, and it moves if the catalog ever does.
     */
    private function cheapestUnit(): int
    {
        $prices = array_filter(array_column(Catalog::materials(), 'npcPrice'));

        return (int) min($prices);
    }

    /** The field lane's whole draw -- the task AND the grade it came out at. */
    private function fieldDraw(Character $character): array
    {
        return Dailies::forDay($this->game->dailyIdentity($character), Dailies::dayIndex($this->game->now()))['field'];
    }

    /** What today's version of the field task actually asks for and pays. */
    private function fieldGraded(Character $character): array
    {
        $draw = $this->fieldDraw($character);

        return Dailies::graded($draw['key'], $draw['grade']);
    }

    private function fire(Character $character, string $kind, int $amount, ?string $subject = null): void
    {
        $fire = new ReflectionMethod($this->game, 'fireGoal');
        $fire->invoke($this->game, $character, $kind, $amount, $subject);
    }
}
