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
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §9.6.4 -- a fight on a floor, and the bill it leaves.
 *
 * The point of these is that nothing here is a second combat system: the pair,
 * the pool, the skills and the wear are the road fight's, reached through
 * `combatProfile()` and the two wear calls. A dungeon with its own arithmetic
 * would drift from the road within a patch.
 */
final class DungeonFightTest extends TestCase
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

    private function inside(string $wallet): array
    {
        $site = WorldGen::dungeonSites()[0];
        $character = $this->game->createCharacter(Player::create(['wallet' => $wallet]));
        $character->col = $site['col'];
        $character->row = $site['row'];
        $character->save();

        $session = $this->dungeons->open($character, $site['dungeon']['key'], 'tools', 'easy');
        $member = $this->dungeons->enter($character);

        return [$character, $session->fresh(), $member];
    }

    /** Put the character on a hex the cohort guarantees holds something. */
    private function onAMonster(Character $character, $session, int $index = 0): array
    {
        $member = $this->dungeons->memberFor($character, $this->game->now());
        [$col, $row] = Dungeons::seededHexes($session->floorSeed($member->floor))[$index];

        $member->fill(['col' => $col, 'row' => $row, 'busy_until_ms' => null])->save();

        return [$col, $row];
    }

    public function test_a_fight_needs_something_to_fight(): void
    {
        [$character, $session] = $this->inside('0xnothing');

        // The landing holds nothing, by rule.
        $this->expectException(GameException::class);
        $this->dungeons->fight($character);
    }

    /**
     * §9.6.2 -- a win writes the clear, and the clear is the kill count.
     *
     * Nothing stores a tally: the gate is COUNT(*) over the rows, so the number
     * on the screen and the number the stair reads cannot disagree.
     */
    public function test_a_win_clears_the_hex_and_counts_toward_the_floor(): void
    {
        [$character, $session] = $this->inside('0xwin');
        [$col, $row] = $this->onAMonster($character, $session);

        $this->assertSame(0, $session->killsOn(1));

        $result = $this->dungeons->fight($character);

        if (! $result['won']) {
            // A fresh character can lose, and that is a legitimate outcome
            // (§9.6.6) -- but then nothing is cleared and nothing is counted.
            $this->assertSame(0, $session->fresh()->killsOn(1));
            $this->assertNotNull($this->dungeons->standingOn($session->fresh(), 1, $col, $row));

            return;
        }

        $this->assertSame(1, $session->fresh()->killsOn(1));
        $this->assertNull($this->dungeons->standingOn($session->fresh(), 1, $col, $row));
        $this->assertGreaterThan(0, $result['gold'], 'a win paid nothing');
        $this->assertSame(1, $result['kills']);
        $this->assertFalse($result['floorOpen'], 'one kill opened the floor');
    }

    /** §9.5.3 -- while it is standing on you, the hex is its. */
    public function test_a_monster_on_your_hex_refuses_the_road(): void
    {
        [$character, $session] = $this->inside('0xpin');
        [$col, $row] = $this->onAMonster($character, $session);

        $this->expectException(GameException::class);
        $this->dungeons->walk($character, $col + 1, $row);
    }

    /** §9.5.6 -- a fight bills the kit, and it is the road's own bill. */
    public function test_a_fight_wears_the_kit(): void
    {
        [$character, $session] = $this->inside('0xwear');
        $this->onAMonster($character, $session);

        $before = $character->items()->sum('durability');
        $result = $this->dungeons->fight($character);

        if ($result['damageTaken'] > 0) {
            $this->assertLessThan(
                $before,
                $character->fresh()->items()->sum('durability'),
                'a fight that hurt cost nothing',
            );
        }

        // And what it cost is a quarter of what it took, whoever took it.
        $this->assertSame($result['damageTaken'] > 0, $result['wear'] !== []);
    }

    /** §9.6.6 -- a loss puts you at the landing, and takes nothing from the bag. */
    public function test_a_loss_wakes_you_at_the_landing(): void
    {
        [$character, $session] = $this->inside('0xdie');

        // Strip the kit down so the pool empties: an unarmed character against a
        // floor monster is the honest version of walking in over your head.
        $character->items()->update(['durability' => 1, 'equipped' => false]);
        $this->onAMonster($character, $session);

        $rows = $character->materials()->count();
        $result = $this->dungeons->fight($character);

        $this->assertFalse($result['won'], 'an empty kit won anyway');

        $member = $this->dungeons->memberFor($character, $this->game->now());
        $this->assertSame(1, $member->floor);
        $this->assertSame(Dungeons::entrance($session->floorSeed(1)), [$member->col, $member->row]);
        $this->assertSame($rows, $character->fresh()->materials()->count(), 'the bag was robbed');
    }

    /**
     * §9.6.2 -- the guardian is not on its feet until five have fallen, and it
     * scales with the roster rather than with the reader.
     */
    public function test_the_guardian_waits_and_then_stands(): void
    {
        [$character, $session] = $this->inside('0xguard');
        [$sc, $sr] = Dungeons::stair($session->floorSeed(1));

        $this->assertNull($this->dungeons->standingOn($session, 1, $sc, $sr));

        for ($i = 0; $i < Balance::DUNGEON_FLOOR_KILLS - 1; $i++) {
            DungeonClear::create([
                'dungeon_session_id' => $session->id,
                'floor' => 1,
                'col' => Dungeons::seededHexes($session->floorSeed(1))[$i][0],
                'row' => Dungeons::seededHexes($session->floorSeed(1))[$i][1],
                'character_id' => $character->id,
                'killed_at_ms' => $this->game->now(),
            ]);
        }

        $guardian = $this->dungeons->standingOn($session->fresh(), 1, $sc, $sr);

        $this->assertNotNull($guardian);
        $this->assertTrue($guardian['guardian']);
        $this->assertSame(5, $guardian['tier']);
        $this->assertSame(Dungeons::GUARDIANS[$session->dungeon]['name'], $guardian['name']);
    }

    /** Nothing inside is reachable from outside a session. */
    public function test_every_verb_refuses_without_a_session(): void
    {
        $character = $this->game->createCharacter(Player::create(['wallet' => '0xnone']));

        foreach (['enter', 'fight', 'descend', 'leave'] as $verb) {
            try {
                $this->dungeons->{$verb}($character);
                $this->fail("{$verb} worked with no session");
            } catch (GameException) {
                $this->assertTrue(true);
            }
        }
    }
}
