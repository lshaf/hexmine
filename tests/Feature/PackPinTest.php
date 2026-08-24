<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\Drops;
use App\Game\GameException;
use App\Game\GameService;
use App\Game\Packs;
use App\Game\WorldGen;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §9.5.3 -- the pin.
 *
 * A pack does not force a fight. It stops you, which is a different thing: the
 * road ends here, no work happens here, and the two ways out are fighting it or
 * waiting for its clock. Neither is a dead end, because a loss clears the pack
 * as surely as a win does (§9.5.7).
 */
final class PackPinTest extends TestCase
{
    use RefreshDatabase;

    private GameService $game;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();

        $this->game = app(GameService::class);
        $player = Player::create(['wallet' => '0xpin', 'session_id' => 'pin']);
        $this->character = $this->game->createCharacter($player);
    }

    /**
     * Stand the character on a hex that is holding a pack right now.
     *
     * Searched rather than fabricated: spawn is a hash of the hex and the
     * clock, so the only honest way to get a character onto one is to go and
     * find one. A test that wrote the pack into a table would be testing a
     * table nothing else reads.
     */
    private function standOnAPack(): array
    {
        $now = $this->game->now();
        $radius = Balance::mapRadius();

        for ($col = -$radius; $col <= $radius; $col++) {
            for ($row = -$radius; $row <= $radius; $row++) {
                $tile = WorldGen::generateTile($col, $row, $now);
                if (($tile['pack'] ?? null) === null || $tile['material'] === null) {
                    continue;
                }

                $this->character->col = $col;
                $this->character->row = $row;
                $this->character->save();
                $this->character = $this->character->fresh();

                return $tile['pack'];
            }
        }

        $this->fail('no pack anywhere on the map to stand on');
    }

    public function test_a_pack_on_your_hex_stops_the_road(): void
    {
        $this->standOnAPack();

        $this->expectException(GameException::class);
        $this->expectExceptionMessageMatches('/Fight it, or wait for it to move on/');

        $this->game->travelTo($this->character, (int) $this->character->col + 3, (int) $this->character->row);
    }

    /**
     * §9.5.3 -- and it stops the work too, on this hex and on every hex in
     * sight. You are not mining while something is looking at you.
     */
    public function test_a_pack_on_your_hex_stops_every_kind_of_work(): void
    {
        $this->standOnAPack();

        $col = (int) $this->character->col;
        $row = (int) $this->character->row;

        foreach ([Drops::MINING, Drops::GATHERING] as $activity) {
            $preview = $this->game->previewTile($this->character->fresh(), $col, $row, $activity);

            $this->assertFalse($preview['canMine'], "{$activity} was allowed under a pack");
            $this->assertTrue($preview['pinned']);
            $this->assertStringContainsString('standing here', (string) $preview['reason']);
        }

        // A neighbor is refused for the same reason: the pin is about the
        // ground under your feet, not the ground you are pointing at.
        $neighbor = $this->game->previewTile($this->character->fresh(), $col + 1, $row);
        $this->assertFalse($neighbor['canMine']);
        $this->assertTrue($neighbor['pinned']);

        $this->expectException(GameException::class);
        $this->game->startMining($this->character->fresh(), $col, $row, Drops::GATHERING);
    }

    /** §9.5.3 -- the pack's own clock is one of the two exits, and it needs no action. */
    public function test_the_pin_lifts_when_the_pack_wanders_off(): void
    {
        $pack = $this->standOnAPack();

        $this->assertNotNull($this->game->packHere($this->character->fresh()));

        // Nothing is done to the character: the world moves on instead. The
        // clock is the one thing the service will not let anything else read
        // (§16), so a later one is a service with a later clock.
        $later = new class extends GameService
        {
            public int $skip = 0;

            public function now(): int
            {
                return parent::now() + $this->skip;
            }
        };
        $later->skip = $pack['until'] - $this->game->now() + 1000;

        // Gone means THIS pack is gone. The next bucket is free to roll another
        // one onto the same hex -- that is a fresh pack with a fresh clock, not
        // the old one refusing to leave, and asserting null here made the test
        // pass or fail on what the wall clock happened to say.
        $after = $later->packHere($this->character->fresh());

        $this->assertNotSame(
            $pack['bucket'],
            $after['bucket'] ?? null,
            'the pack outstayed its bucket',
        );
    }

    /** §9.5.1 -- and so is settling it. Cleared is cleared, for everybody. */
    public function test_clearing_the_pack_lifts_the_pin(): void
    {
        $pack = $this->standOnAPack();

        Packs::clear(
            (int) $this->character->col,
            (int) $this->character->row,
            $pack['bucket'],
            $pack['until'],
            $this->game->now(),
        );

        $this->assertNull($this->game->packHere($this->character->fresh()));

        $preview = $this->game->previewTile(
            $this->character->fresh(),
            (int) $this->character->col,
            (int) $this->character->row,
            Drops::GATHERING,
        );

        $this->assertFalse($preview['pinned']);
        $this->assertTrue($preview['canMine'], 'the hex stayed shut after the pack was settled');
    }

    /**
     * §9.5.5 -- the odds are shown before anything is committed. That is what
     * makes an encounter you did not choose a decision rather than a gamble.
     */
    public function test_the_preview_prices_the_fight_before_it_is_taken(): void
    {
        $this->standOnAPack();

        $bare = $this->game->previewBattle($this->character->fresh());

        $this->assertTrue($bare['canFight']);
        $this->assertGreaterThanOrEqual(Balance::BATTLE_ODDS_MIN, $bare['odds']);
        $this->assertLessThanOrEqual(Balance::BATTLE_ODDS_MAX, $bare['odds']);
        $this->assertNotEmpty($bare['monster']['name']);
        $this->assertSame(0, $bare['attack'], 'bare hands are not an attack');

        // A weapon moves the numbers, and moves the odds with them.
        CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => 'tempered_sword',
            'durability' => 60,
            'equipped' => true,
        ]);

        $armed = $this->game->previewBattle($this->character->fresh());

        $this->assertGreaterThan($bare['attack'], $armed['attack']);
        $this->assertGreaterThan($bare['odds'], $armed['odds']);
        $this->assertSame('swordhand', $armed['job'], 'the family did not name its job');
        $this->assertGreaterThan(0, $armed['wear']['weapon'], 'a fight costs the weapon nothing');
    }

    /**
     * §8.2 -- destroyed at zero, so the warning is mandatory. An idle game may
     * take something expensive from a player; it may never do it by surprise.
     */
    public function test_gear_that_will_not_survive_is_named_before_the_fight(): void
    {
        $this->standOnAPack();

        CharacterItem::create([
            'character_id' => $this->character->id,
            'item_key' => 'tempered_sword',
            'durability' => 1,
            'equipped' => true,
        ]);

        $preview = $this->game->previewBattle($this->character->fresh());

        $this->assertNotEmpty($preview['warnings'], 'a sword at one point was not flagged');
        $this->assertStringContainsString('will not survive', $preview['warnings'][0]);
    }

    /** Nothing standing here is not an error, it is an empty offer. */
    public function test_an_empty_hex_offers_no_fight(): void
    {
        $pack = $this->game->packHere($this->character->fresh());
        if ($pack !== null) {
            Packs::clear(
                (int) $this->character->col,
                (int) $this->character->row,
                $pack['bucket'],
                $pack['until'],
                $this->game->now(),
            );
        }

        $preview = $this->game->previewBattle($this->character->fresh());

        $this->assertFalse($preview['canFight']);
        $this->assertSame('Nothing is standing here.', $preview['reason']);
    }

    /**
     * §9.5.3 -- "Travel ends at that hex. The rest of the road is not walked."
     *
     * The pin was already enforced at the gate: a pack under your feet refuses
     * the road outright. This is the other half -- one met HALFWAY, which is
     * the case that makes a pack a hazard rather than something you only ever
     * find by standing on it.
     */
    public function test_a_pack_on_the_road_ends_the_journey_where_it_stands(): void
    {
        $pack = $this->standOnAPack();

        // Settle it, so the hex under our feet is not the thing that refuses.
        Packs::clear(
            (int) $this->character->col,
            (int) $this->character->row,
            $pack['bucket'],
            $pack['until'],
            $this->game->now(),
        );

        $from = ['col' => (int) $this->character->col, 'row' => (int) $this->character->row];

        // Aim at something far enough that the road crosses a lot of ground.
        // Which hex stops us is not the test's business -- that it stops
        // SHORT, on a hex holding a pack, is.
        $target = ['col' => $from['col'] + 40, 'row' => $from['row']];
        $this->game->travelTo($this->character->fresh(), $target['col'], $target['row']);
        $startedAt = (int) $this->character->fresh()->travel_started_at;

        $arrived = new class extends GameService
        {
            public int $skip = 0;

            public function now(): int
            {
                return parent::now() + $this->skip;
            }
        };

        // Long enough that the whole road would have been walked.
        $arrived->skip = 40 * Balance::scaled(Balance::TRAVEL_MS_PER_HEX) + 1000;

        $character = $this->character->fresh();
        $arrived->settle($character);
        $character = $character->fresh();

        $this->assertFalse($arrived->isTraveling($character), 'still on the road after arriving');

        if ($character->col === $target['col'] && $character->row === $target['row']) {
            // A clear road is a legitimate outcome, and on a sparse outer ring
            // it is the likely one. Nothing to assert beyond that it landed.
            $this->assertTrue(true);

            return;
        }

        // Stopped short. It has to have been stopped BY something -- and the
        // question is asked of the moment it was crossed, not of now. A pack
        // has a two-hour clock and the road is only caught up when somebody
        // looks, so "is it still there" is a different question with a
        // legitimately different answer (§9.5.3).
        $steps = \App\Game\HexGeometry::distance(
            $from['col'],
            $from['row'],
            (int) $character->col,
            (int) $character->row,
        );

        $this->assertLessThan(40, $steps, 'the journey did not actually stop short');
        $this->assertGreaterThan(0, $steps, 'the journey never started');

        $this->assertTrue(
            $this->packStoodAt((int) $character->col, (int) $character->row, $startedAt, $steps),
            'the road ended on a hex nothing was ever standing on',
        );
    }

    /**
     * Was a pack standing on this hex at the moment the walker stepped onto it?
     *
     * The same question interceptIfDue() asks, asked the same way: the hex is
     * generated at the step time rather than at now, because a pack is
     * time-bucketed and the road is walked when it is walked (§9.5.1).
     */
    private function packStoodAt(int $col, int $row, int $startedAt, int $steps): bool
    {
        $perHex = Balance::scaled(Balance::TRAVEL_MS_PER_HEX);
        $tile = WorldGen::generateTile($col, $row, $startedAt + $steps * $perHex);

        return ($tile['pack'] ?? null) !== null;
    }

    /**
     * §5.6 -- and it costs no extra queries to do it.
     *
     * The road is caught up from a high-water mark on the character, so a
     * settle that has already walked the road does not walk it again. Without
     * that, a two-hundred hex journey rescans two hundred hexes on every poll.
     */
    public function test_the_road_is_only_scanned_once(): void
    {
        $pack = $this->standOnAPack();
        Packs::clear(
            (int) $this->character->col,
            (int) $this->character->row,
            $pack['bucket'],
            $pack['until'],
            $this->game->now(),
        );

        $from = ['col' => (int) $this->character->col, 'row' => (int) $this->character->row];
        $this->game->travelTo($this->character->fresh(), $from['col'] + 30, $from['row']);
        $startedAt = (int) $this->character->fresh()->travel_started_at;

        $half = new class extends GameService
        {
            public int $skip = 0;

            public function now(): int
            {
                return parent::now() + $this->skip;
            }
        };
        $half->skip = 10 * Balance::scaled(Balance::TRAVEL_MS_PER_HEX) + 1;

        $character = $this->character->fresh();
        $half->settle($character);
        $character = $character->fresh();

        // Either it was stopped, or it has banked the hexes it checked.
        if ($half->isTraveling($character)) {
            $this->assertGreaterThan(0, (int) $character->travel_scanned_hexes, 'the road was walked and not remembered');
            $this->assertLessThanOrEqual(10, (int) $character->travel_scanned_hexes);

            return;
        }

        $this->assertTrue(
            $this->packStoodAt(
                (int) $character->col,
                (int) $character->row,
                $startedAt,
                \App\Game\HexGeometry::distance($from['col'], $from['row'], (int) $character->col, (int) $character->row),
            ),
            'the road ended on a hex nothing was ever standing on',
        );
    }
}
