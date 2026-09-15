<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\Dungeons;
use App\Game\DungeonService;
use App\Game\GameException;
use App\Game\GameService;
use App\Game\WorldGen;
use App\Models\Character;
use App\Models\DungeonClear;
use App\Models\DungeonSession;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §9.6.1 -- opening a session, joining one, and the gates on the way down.
 */
final class DungeonSessionTest extends TestCase
{
    use RefreshDatabase;

    private GameService $game;

    private DungeonService $dungeons;

    protected function setUp(): void
    {
        parent::setUp();

        config(['game.packs' => false]);
        Dungeons::forget();

        $this->game = app(GameService::class);
        $this->dungeons = app(DungeonService::class);
    }

    private function character(string $wallet): Character
    {
        return $this->game->createCharacter(Player::create(['wallet' => $wallet]));
    }

    /** Put a character on a mouth, which is the only place any of this happens. */
    private function atMouth(string $wallet): array
    {
        $site = WorldGen::dungeonSites()[0];
        $character = $this->character($wallet);
        $character->col = $site['col'];
        $character->row = $site['row'];
        $character->save();

        return [$character, $site['dungeon']['key']];
    }

    public function test_a_session_opens_at_a_mouth_and_nowhere_else(): void
    {
        [$character, $key] = $this->atMouth('0xopen');

        $session = $this->dungeons->open($character, $key, 'tools', 'easy');

        $this->assertSame($key, $session->dungeon);
        $this->assertSame(Balance::DUNGEON_CODE_LENGTH, strlen($session->code));
        $this->assertSame(1, $session->members()->count());
        $this->assertNull($session->locked_at_ms, 'a fresh session is open to newcomers');

        // A step off the mouth and the same call is refused.
        $character->col += 3;
        $character->save();

        $this->expectException(GameException::class);
        $this->dungeons->open($character, $key, 'tools', 'easy');
    }

    /** §9.6.1 -- twelve hours, through scaled() like every other clock. */
    public function test_a_session_stands_for_twelve_hours(): void
    {
        [$character, $key] = $this->atMouth('0xclock');
        $session = $this->dungeons->open($character, $key, 'armor', 'hard');

        $this->assertSame(
            Balance::scaled(Balance::DUNGEON_SESSION_MS),
            $session->expires_at_ms - $session->created_at_ms,
        );
    }

    /**
     * §9.6.2 -- the secret is on the row and in no payload.
     *
     * Checked on `toArray()` rather than on a hand-written response, because the
     * leak this guards against is the one nobody wrote: a model eager-loaded
     * into a state payload, an exception carrying it, a debug dump.
     */
    public function test_the_secret_never_leaves_the_server(): void
    {
        [$character, $key] = $this->atMouth('0xsecret');
        $session = $this->dungeons->open($character, $key, 'weapons', 'easy');

        $this->assertNotSame('', $session->secret, 'a session with no secret is a floor anybody can compute');
        $this->assertArrayNotHasKey('secret', $session->toArray());
        $this->assertStringNotContainsString($session->secret, json_encode($session));
        $this->assertStringNotContainsString($session->secret, json_encode($session->fresh()));
    }

    public function test_a_code_lets_somebody_in_and_the_mouth_is_still_asked_for(): void
    {
        [$owner, $key] = $this->atMouth('0xowner');
        $session = $this->dungeons->open($owner, $key, 'tools', 'easy');

        // Holding the code is not standing at the mouth.
        $far = $this->character('0xfar');
        try {
            $this->dungeons->join($far, $session->code);
            $this->fail('joined from off the map');
        } catch (GameException) {
        }

        [$mate] = $this->atMouth('0xmate');
        $joined = $this->dungeons->join($mate, $session->code);

        $this->assertSame($session->id, $joined->id);
        $this->assertSame(2, $session->fresh()->members()->count());
    }

    /** §9.6.1 -- one to six, and the sixth is the last. */
    public function test_a_roster_stops_at_six(): void
    {
        [$owner, $key] = $this->atMouth('0xcap0');
        $session = $this->dungeons->open($owner, $key, 'tools', 'easy');

        for ($i = 1; $i < Balance::DUNGEON_PARTY_MAX; $i++) {
            [$mate] = $this->atMouth('0xcap'.$i);
            $this->dungeons->join($mate, $session->code);
        }

        $this->assertSame(Balance::DUNGEON_PARTY_MAX, $session->fresh()->members()->count());

        [$late] = $this->atMouth('0xcaplate');
        $this->expectException(GameException::class);
        $this->dungeons->join($late, $session->code);
    }

    /**
     * §9.6.1 -- the carry, and it is a §2 rule rather than a convenience.
     *
     * A session joinable at any depth means a maxed party clears to floor nine,
     * a fresh wallet walks in on the code, and it collects the floor-ten roll
     * having fought nothing.
     */
    public function test_the_roster_locks_at_the_first_descent(): void
    {
        [$owner, $key] = $this->atMouth('0xlock');
        $session = $this->dungeons->open($owner, $key, 'tools', 'easy');

        $this->dungeons->enter($owner);

        $this->assertNotNull($session->fresh()->locked_at_ms);
        $this->assertSame($owner->id, $session->fresh()->first_character_id);

        [$late] = $this->atMouth('0xlocklate');
        $this->expectException(GameException::class);
        $this->dungeons->join($late, $session->code);
    }

    /** You land on the entrance of floor one, which holds nothing. */
    public function test_entering_puts_you_on_the_landing(): void
    {
        [$character, $key] = $this->atMouth('0xenter');
        $session = $this->dungeons->open($character, $key, 'tools', 'easy');

        $member = $this->dungeons->enter($character);
        [$col, $row] = Dungeons::entrance($session->fresh()->floorSeed(1));

        $this->assertSame(1, $member->floor);
        $this->assertSame($col, $member->col);
        $this->assertSame($row, $member->row);
        $this->assertTrue($member->isInside());
        $this->assertNull($this->dungeons->standingOn($session->fresh(), 1, $col, $row));
    }

    /** §5.6 -- a step is one hex and it costs what a hex costs. */
    public function test_a_step_is_one_hex_and_costs_the_clock(): void
    {
        [$character, $key] = $this->atMouth('0xstep');
        $this->dungeons->open($character, $key, 'tools', 'easy');
        $member = $this->dungeons->enter($character);

        $target = [$member->col + 1, $member->row];

        try {
            $this->dungeons->step($character, $member->col + 4, $member->row);
            $this->fail('walked four hexes in one step');
        } catch (GameException) {
        }

        $moved = $this->dungeons->step($character, ...$target);
        $this->assertSame($target[0], $moved->col);
        $this->assertNotNull($moved->busy_until_ms);
        $this->assertTrue($moved->isBusy($this->game->now()));

        // And every verb refuses while the step is still being taken.
        $this->expectException(GameException::class);
        $this->dungeons->step($character, $moved->col + 1, $moved->row);
    }

    /**
     * §9.6.2 -- the stair does not open until six have fallen, and the guardian
     * is the sixth.
     */
    public function test_the_stair_waits_for_six(): void
    {
        [$character, $key] = $this->atMouth('0xgate');
        $session = $this->dungeons->open($character, $key, 'tools', 'easy');
        $member = $this->dungeons->enter($character);
        $session = $session->fresh();

        [$sc, $sr] = Dungeons::stair($session->floorSeed(1));

        // Standing on the stair with nothing killed: the guardian has not
        // roused, and the floor has not been paid for.
        $member->fill(['col' => $sc, 'row' => $sr, 'busy_until_ms' => null])->save();

        $this->assertNull(
            $this->dungeons->standingOn($session, 1, $sc, $sr),
            'the guardian roused with nothing dead'
        );

        try {
            $this->dungeons->descend($character);
            $this->fail('went down with an empty tally');
        } catch (GameException) {
        }

        // Five fall, and the guardian is on its feet.
        $this->fell($session, $character, 1, 5);
        $this->assertNotNull(
            $this->dungeons->standingOn($session->fresh(), 1, $sc, $sr),
            'five have fallen and it still will not rouse'
        );

        // Five is still short of the gate, and it is the stair that says so.
        try {
            $this->dungeons->descend($character);
            $this->fail('went down on five');
        } catch (GameException) {
        }

        // The guardian is the sixth: the stair hex clears, and both gates open
        // at once with no errands left over.
        DungeonClear::create([
            'dungeon_session_id' => $session->id,
            'floor' => 1,
            'col' => $sc,
            'row' => $sr,
            'character_id' => $character->id,
            'guardian' => true,
            'killed_at_ms' => $this->game->now(),
        ]);

        $session = $session->fresh();
        $this->assertSame(6, $session->killsOn(1));

        $member->fill(['busy_until_ms' => null])->save();
        $down = $this->dungeons->descend($character);

        $this->assertSame(2, $down->floor);
        $this->assertSame(Dungeons::entrance($session->floorSeed(2)), [$down->col, $down->row]);
    }

    /** §9.6.2 -- and the count is the roster's, never one member's. */
    public function test_the_six_are_summed_across_the_roster(): void
    {
        [$owner, $key] = $this->atMouth('0xsum0');
        $session = $this->dungeons->open($owner, $key, 'tools', 'easy');

        $mates = [];
        for ($i = 1; $i < 4; $i++) {
            [$mate] = $this->atMouth('0xsum'.$i);
            $this->dungeons->join($mate, $session->code);
            $mates[] = $mate;
        }

        $this->dungeons->enter($owner);
        $session = $session->fresh();

        // One apiece from four different members still counts four.
        $this->fell($session, $owner, 1, 1, 0);
        foreach ($mates as $n => $mate) {
            $this->fell($session, $mate, 1, 1, $n + 1);
        }

        $this->assertSame(4, $session->fresh()->killsOn(1));
        $this->assertFalse(Dungeons::floorOpen($session->fresh()->killsOn(1)));

        $this->fell($session, $owner, 1, 2, 10);
        $this->assertTrue(Dungeons::floorOpen($session->fresh()->killsOn(1)));
    }

    /** §9.6.2 -- a monster that has fallen does not come back. */
    public function test_a_cleared_hex_stays_cleared(): void
    {
        [$character, $key] = $this->atMouth('0xclear');
        $session = $this->dungeons->open($character, $key, 'tools', 'easy');
        $this->dungeons->enter($character);
        $session = $session->fresh();

        $seed = $session->floorSeed(1);
        [$col, $row] = Dungeons::seededHexes($seed)[0];

        $this->assertNotNull($this->dungeons->standingOn($session, 1, $col, $row));

        DungeonClear::create([
            'dungeon_session_id' => $session->id,
            'floor' => 1,
            'col' => $col,
            'row' => $row,
            'character_id' => $character->id,
            'killed_at_ms' => $this->game->now(),
        ]);

        $this->assertNull($this->dungeons->standingOn($session->fresh(), 1, $col, $row));
    }

    /**
     * §9.6.8 -- the cap is a RATE, and it is one of three things holding §2 shut.
     */
    public function test_a_wallet_runs_three_dungeons_a_week(): void
    {
        [$character, $key] = $this->atMouth('0xweek');

        for ($i = 0; $i < Balance::DUNGEON_SESSIONS_PER_WEEK; $i++) {
            $session = $this->dungeons->open($character, $key, 'tools', 'easy');
            // Close it out so the next open is not refused for being in one.
            $session->update(['expires_at_ms' => $this->game->now() - 1]);
        }

        $this->expectException(GameException::class);
        $this->dungeons->open($character, $key, 'tools', 'easy');
    }

    /** One session at a time, however many the week allows. */
    public function test_one_session_at_a_time(): void
    {
        [$character, $key] = $this->atMouth('0xtwice');
        $this->dungeons->open($character, $key, 'tools', 'easy');

        $this->expectException(GameException::class);
        $this->dungeons->open($character, $key, 'armor', 'hard');
    }

    /** A contract names one of three categories and one of two difficulties. */
    public function test_a_contract_is_one_of_six(): void
    {
        [$character, $key] = $this->atMouth('0xcontract');

        $this->expectException(GameException::class);
        $this->dungeons->open($character, $key, 'trinkets', 'easy');
    }

    /**
     * §9.6 -- underground is a second coordinate space, not a place on the map.
     *
     * A session leaves the character's world hex exactly where it was, which is
     * correct and is also the trap: every overworld verb still reads a valid hex
     * and works it. Found in a browser by walking a character to floor two and
     * then sending them on a two-hundred-hex journey across the world.
     */
    public function test_the_overworld_is_closed_while_you_are_underground(): void
    {
        [$character, $key] = $this->atMouth('0xunder');
        $this->dungeons->open($character, $key, 'tools', 'easy');

        // At the mouth but not down yet: the world is still yours.
        $this->game->requireNotUnderground($character);

        $this->dungeons->enter($character);

        foreach ([
            'travel' => fn () => $this->game->travelTo($character, 10, 10),
            'mine' => fn () => $this->game->startMining($character, (int) $character->col, (int) $character->row),
            'fight' => fn () => $this->game->startBattle($character),
        ] as $verb => $call) {
            try {
                $call();
                $this->fail("{$verb} worked from inside a dungeon");
            } catch (GameException $e) {
                $this->assertStringContainsString('dungeon', strtolower($e->getMessage()), "{$verb} refused for the wrong reason");
            }
        }

        // And walking out gives it back.
        $this->dungeons->leave($character);
        $this->game->requireNotUnderground($character);
    }

    /** Drop `$n` monsters on a floor, starting at cohort index `$from`. */
    private function fell(DungeonSession $session, Character $by, int $floor, int $n, int $from = 0): void
    {
        $hexes = Dungeons::seededHexes($session->floorSeed($floor));

        for ($i = 0; $i < $n; $i++) {
            [$col, $row] = $hexes[($from + $i) % count($hexes)];

            DungeonClear::firstOrCreate([
                'dungeon_session_id' => $session->id,
                'floor' => $floor,
                'col' => $col,
                'row' => $row,
            ], [
                'character_id' => $by->id,
                'guardian' => false,
                'killed_at_ms' => $this->game->now(),
            ]);
        }
    }
}
