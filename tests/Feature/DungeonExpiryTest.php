<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Dungeons;
use App\Game\DungeonService;
use App\Game\GameService;
use App\Game\WorldGen;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §9.6.1 -- the twelve-hour bell, and what it does and does not need.
 */
final class DungeonExpiryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The bell needs no sweeper, and this is the test that says so.
     *
     * Every read of a membership is already filtered on a LIVE session, so an
     * expired one stops being yours without anybody deleting a row -- the
     * character is out, the world opens back up, and a new session may be
     * opened. Listing eviction as missing work was wrong.
     *
     * The rows must in fact SURVIVE, which is the part that would have been
     * broken by tidying them away: §9.6.8's weekly cap counts memberships in the
     * last seven days, so a sweeper would hand every wallet unlimited runs.
     */
    public function test_the_bell_releases_a_character_without_deleting_anything(): void
    {
        config(['game.packs' => false]);
        Dungeons::forget();

        $game = app(GameService::class);
        $dungeons = app(DungeonService::class);

        $site = WorldGen::dungeonSites()[0];
        $character = $game->createCharacter(Player::create(['wallet' => '0xbell']));
        $character->col = $site['col'];
        $character->row = $site['row'];
        $character->save();

        $session = $dungeons->open($character, $site['dungeon']['key'], 'tools', 'easy');
        $dungeons->enter($character);

        $now = $game->now();
        $this->assertNotNull($dungeons->memberFor($character, $now));

        // Ring the bell.
        $session->update(['expires_at_ms' => $now - 1]);

        $this->assertNull($dungeons->memberFor($character, $game->now()), 'still in a closed session');

        // The world opens back up: no guard refuses any more.
        $game->requireNotUnderground($character);

        // And the row is still there, which is what the weekly cap counts.
        $this->assertSame(1, $session->members()->count(), 'the membership was swept away');

        // A fresh session opens against the same character.
        $again = $dungeons->open($character, $site['dungeon']['key'], 'armor', 'hard');
        $this->assertNotSame($session->id, $again->id);
    }
}
