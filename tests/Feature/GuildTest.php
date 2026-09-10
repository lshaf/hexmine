<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\Catalog;
use App\Game\GameException;
use App\Game\Formulas;
use App\Game\GameService;
use App\Game\WorldGen;
use App\Models\Character;
use App\Models\Guild;
use App\Models\GuildMember;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §10.0 -- founding a guild, joining one, and running it.
 *
 * A guild is a PLACE before it is a roster: it stands in a city or a capital,
 * it costs twenty thousand gold, and the hall it puts there is the only bench
 * in the game that reaches legendary (§8.0).
 */
final class GuildTest extends TestCase
{
    use RefreshDatabase;

    private GameService $game;

    private Character $character;

    /** @var array<string,array<string,mixed>> tier -> a settlement of that tier */
    private static array $found = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['game.packs' => false]);

        $this->game = app(GameService::class);
        $player = Player::create(['wallet' => '0xguild', 'session_id' => 'guild']);
        $this->character = $this->game->createCharacter($player);
    }

    /**
     * Stand on a settlement of at least this tier. Searched rather than
     * fabricated, like everything else about the world.
     *
     * @return array<string,mixed>
     */
    private function standAt(string $tier, ?Character $who = null): array
    {
        $who ??= $this->character;

        // The world is a pure function of the seed, so where the nearest
        // capital is does not change between tests -- and scanning for one is
        // most of a minute across a class this size.
        if (isset(self::$found[$tier])) {
            $s = self::$found[$tier];
            $who->col = (int) $s['col'];
            $who->row = (int) $s['row'];
            $who->save();

            return $s;
        }

        $radius = Balance::mapRadius();

        // §5.2 -- capitals stand in the inner ring, so a spiral out of an outer
        // ring spawn would have to cross most of the map to find one. Start
        // where the tier actually lives instead.
        $fromCol = $tier === 'capital' ? 0 : (int) $who->col;
        $fromRow = $tier === 'capital' ? 0 : (int) $who->row;

        for ($ring = 1; $ring < 2 * $radius; $ring++) {
            for ($dc = -$ring; $dc <= $ring; $dc++) {
                for ($dr = -$ring; $dr <= $ring; $dr++) {
                    if (max(abs($dc), abs($dr)) !== $ring) {
                        continue;
                    }

                    $col = $fromCol + $dc;
                    $row = $fromRow + $dr;
                    if (abs($col) > $radius || abs($row) > $radius) {
                        continue;
                    }

                    $s = WorldGen::settlementAt($col, $row);
                    if ($s === null || $s['tier'] !== $tier) {
                        continue;
                    }

                    $who->col = (int) $s['col'];
                    $who->row = (int) $s['row'];
                    $who->save();

                    return self::$found[$tier] = $s;
                }
            }
        }

        $this->fail("no {$tier} anywhere near the spawn");
    }

    /**
     * §10.6 -- put the owner on dead ground and buy it.
     *
     * Searched rather than fabricated, like everything else about the world:
     * §5.2 says half the outer rim never carried a seam, so the nearest waste
     * is never far. The treasury is topped up here because what this helper is
     * for is the land, not the saving up.
     *
     * @return array<string,mixed>
     */
    private function claimLandFor(Guild $guild, ?Character $who = null): array
    {
        $who ??= $this->character;
        $who = $who->fresh();

        $radius = Balance::mapRadius();
        $found = null;

        for ($ring = 0; $ring < 60 && $found === null; $ring++) {
            for ($dc = -$ring; $dc <= $ring && $found === null; $dc++) {
                for ($dr = -$ring; $dr <= $ring && $found === null; $dr++) {
                    if ($ring > 0 && max(abs($dc), abs($dr)) !== $ring) {
                        continue;
                    }

                    $col = (int) $who->col + $dc;
                    $row = (int) $who->row + $dr;
                    if (abs($col) > $radius || abs($row) > $radius) {
                        continue;
                    }

                    $tile = $this->game->buildTile($col, $row, $this->game->now());
                    if (($tile['dead'] ?? false) && ! ($tile['water'] ?? false)
                        && ($tile['settlement'] ?? null) === null
                        && ($tile['dungeon'] ?? null) === null) {
                        $found = [$col, $row];
                    }
                }
            }
        }

        $this->assertNotNull($found, '§5.2 promises dead ground, and there was none nearby');

        $who->update(['col' => $found[0], 'row' => $found[1]]);

        $guild->gold = Balance::GUILD_LAND_COST;
        $guild->save();

        $this->game->claimGuildLand($who->fresh());

        return $guild->fresh()->landSettlement();
    }

    private function purse(int $gold, ?Character $who = null): void
    {
        $who ??= $this->character;
        $who->gold = $gold;
        $who->save();
    }

    /** @return array<string,mixed> */
    private function identity(string $name = 'The Long Watch', string $code = 'TLW'): array
    {
        return ['name' => $name, 'code' => $code, 'description' => 'We walk the ring.'];
    }

    /** §10.0 -- a hall stands in a city or a capital. Never a village. */
    public function test_a_guild_cannot_be_founded_in_a_village(): void
    {
        $this->standAt('village');
        $this->purse(Balance::GUILD_FOUNDING_COST);

        try {
            $this->game->foundGuild($this->character->fresh(), $this->identity());
            $this->fail('a guild was founded in a village');
        } catch (GameException $e) {
            $this->assertSame('wrong_station', $e->errorCode);
        }

        $this->assertSame(0, Guild::count());
        $this->assertSame(
            Balance::GUILD_FOUNDING_COST,
            (int) $this->character->fresh()->gold,
            'a refused founding still took the gold',
        );
    }

    /**
     * §10.0 -- twenty thousand gold, and it is the point rather than a price
     * tag: §11.2's largest sink is capital bidding, and this is the second.
     */
    public function test_founding_costs_the_founder_twenty_thousand_gold(): void
    {
        $city = $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST - 1);

        try {
            $this->game->foundGuild($this->character->fresh(), $this->identity());
            $this->fail('a guild was founded on credit');
        } catch (GameException $e) {
            $this->assertSame('poor', $e->errorCode);
            $this->assertStringContainsString('1 short', $e->getMessage());
        }

        $this->purse(Balance::GUILD_FOUNDING_COST + 500);
        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());

        $this->assertSame(500, (int) $this->character->fresh()->gold);
        $this->assertSame($city['id'], $guild->settlement_id);
        $this->assertSame((int) $city['col'], $guild->col);

        // §10.0.2 -- the founder is the owner, and is in it.
        $row = GuildMember::where('character_id', $this->character->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(GuildMember::OWNER, $row->role);
    }

    /** §10.0 -- one guild each, enforced by an index rather than by hope. */
    public function test_a_character_belongs_to_one_guild(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST * 3);

        $this->game->foundGuild($this->character->fresh(), $this->identity());

        $this->expectException(GameException::class);
        $this->expectExceptionMessageMatches('/already in a guild/');

        $this->game->foundGuild($this->character->fresh(), $this->identity('Second Wind', 'SW'));
    }

    /** A name and a code are how everybody else points at you. Both are unique. */
    public function test_names_and_codes_are_taken_once(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST);
        $this->game->foundGuild($this->character->fresh(), $this->identity());

        $rival = $this->game->createCharacter(
            Player::create(['wallet' => '0xrivalguild', 'session_id' => 'rivalguild']),
        );
        $this->standAt('city', $rival);
        $this->purse(Balance::GUILD_FOUNDING_COST * 2, $rival);

        try {
            $this->game->foundGuild($rival->fresh(), $this->identity('The Long Watch', 'XYZ'));
            $this->fail('two guilds took one name');
        } catch (GameException $e) {
            $this->assertSame('taken', $e->errorCode);
        }

        try {
            $this->game->foundGuild($rival->fresh(), $this->identity('Something Else', 'TLW'));
            $this->fail('two guilds took one code');
        } catch (GameException $e) {
            $this->assertSame('taken', $e->errorCode);
        }
    }

    /**
     * §10.0.1 -- the recruiting flag IS the join flow. Closed guilds are not
     * listed at all, rather than listed and refused.
     */
    public function test_only_recruiting_guilds_are_listed_and_joinable(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST);
        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());

        $this->assertCount(1, $this->game->recruitingGuilds());

        $walker = $this->game->createCharacter(
            Player::create(['wallet' => '0xwalker', 'session_id' => 'walker']),
        );

        // Open: walk in, from anywhere. A guild is a place, but joining one is
        // a decision rather than a journey.
        $this->game->joinGuild($walker->fresh(), $guild->id);
        $this->assertSame($guild->id, $this->game->guildOf($walker->fresh())?->id);

        // Closed: gone from the list, and refused to anybody who asks anyway.
        $this->game->updateGuild($this->character->fresh(), ['recruitment' => Guild::CLOSED]);
        $this->assertSame([], $this->game->recruitingGuilds());

        $late = $this->game->createCharacter(
            Player::create(['wallet' => '0xlate', 'session_id' => 'late']),
        );

        $this->expectException(GameException::class);
        $this->expectExceptionMessageMatches('/not taking anybody on/');
        $this->game->joinGuild($late->fresh(), $guild->id);
    }

    /**
     * §10.0.2 -- the last owner may not walk away from a guild that still has
     * members. A guild nobody can close would sit on its name forever.
     */
    public function test_the_owner_hands_over_or_disbands(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST);
        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());

        $second = $this->game->createCharacter(
            Player::create(['wallet' => '0xsecond', 'session_id' => 'second']),
        );
        $this->game->joinGuild($second->fresh(), $guild->id);

        try {
            $this->game->leaveGuild($this->character->fresh());
            $this->fail('the owner walked out on a guild with members in it');
        } catch (GameException $e) {
            $this->assertSame('owner', $e->errorCode);
        }

        // Handing over is ONE move: a guild with two owners for even one
        // request is a guild either of them can disband.
        $this->game->setMemberRole($this->character->fresh(), $second->id, GuildMember::OWNER);

        $this->assertSame(
            GuildMember::OWNER,
            GuildMember::where('character_id', $second->id)->value('role'),
        );
        $this->assertSame(
            GuildMember::OFFICER,
            GuildMember::where('character_id', $this->character->id)->value('role'),
        );

        // And now the old owner may go.
        $this->game->leaveGuild($this->character->fresh());
        $this->assertNull($this->game->guildOf($this->character->fresh()));
        $this->assertNotNull(Guild::find($guild->id));
    }

    /** A guild with nobody in it is not a guild, and must not hold its name. */
    public function test_the_last_one_out_disbands_it(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST);
        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());

        $this->game->leaveGuild($this->character->fresh());

        $this->assertNull(Guild::find($guild->id));
        $this->assertSame(0, GuildMember::count());
    }

    /** §10.0.2 -- an officer holds the door; only the owner owns the face. */
    public function test_an_officer_may_open_the_door_and_nothing_else(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST);
        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());

        $officer = $this->game->createCharacter(
            Player::create(['wallet' => '0xofficer', 'session_id' => 'officer']),
        );
        $this->game->joinGuild($officer->fresh(), $guild->id);
        $this->game->setMemberRole($this->character->fresh(), $officer->id, GuildMember::OFFICER);

        // May close the door.
        $this->game->updateGuild($officer->fresh(), ['recruitment' => Guild::CLOSED]);
        $this->assertSame(Guild::CLOSED, Guild::find($guild->id)->recruitment);

        // May not repaint the guild.
        try {
            $this->game->updateGuild($officer->fresh(), ['description' => 'mine now']);
            $this->fail('an officer rewrote the guild');
        } catch (GameException $e) {
            $this->assertSame('forbidden', $e->errorCode);
        }

        // May not remove the owner.
        try {
            $this->game->removeMember($officer->fresh(), $this->character->id);
            $this->fail('an officer removed the owner');
        } catch (GameException $e) {
            $this->assertSame('forbidden', $e->errorCode);
        }
    }

    /**
     * §10.0.3 -- exactly 1024 colors, and the column can hold nothing else.
     * Not a URL, not a file, not a data URI.
     */
    public function test_a_flag_is_1024_colors_and_nothing_else(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST);

        foreach (['https://example.com/flag.png', base64_encode('too short'), 'not base64 at all!!'] as $bad) {
            try {
                $this->game->foundGuild(
                    $this->character->fresh(),
                    $this->identity() + ['flag' => $bad],
                );
                $this->fail('a flag that was not a flag was accepted');
            } catch (GameException $e) {
                $this->assertSame('invalid', $e->errorCode);
            }
        }

        $flag = base64_encode(str_repeat("\x8f\xbf\x7f", Balance::GUILD_FLAG_SIZE ** 2));
        $guild = $this->game->foundGuild(
            $this->character->fresh(),
            $this->identity() + ['flag' => $flag],
        );

        $this->assertSame($flag, $guild->flag);
        $this->assertSame(
            Balance::GUILD_FLAG_BYTES,
            strlen((string) base64_decode((string) $guild->flag, true)),
        );
    }

    /**
     * §8.0/§10.6 -- EPIC is a guild's own land, and nowhere else.
     *
     * A capital used to reach it and does not any more: the last rung a player
     * can craft is one a roster had to buy a hex for, name and level to
     * fifteen. That is the whole bargain of §10.6.
     */
    public function test_epic_is_made_on_your_own_land_and_nowhere_else(): void
    {
        $epic = collect(Catalog::items())
            ->filter(fn (array $d) => ($d['rarity'] ?? null) === 'epic' && ! empty($d['inputs']))
            ->keys()
            ->first();

        $this->assertNotNull($epic, 'no epic recipe to test with');

        $this->standAt('capital');
        $this->purse(Balance::GUILD_FOUNDING_COST);

        // Standing at the best bench the MAP offers, with no guild.
        try {
            $this->game->startCraft($this->character->fresh(), $epic);
            $this->fail('epic work came off a capital bench');
        } catch (GameException $e) {
            $this->assertSame('station', $e->errorCode);
            $this->assertStringContainsString('guild', $e->getMessage());
        }

        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());

        // §10.6 -- founding is an address, not a workshop. Until the guild
        // holds land there is nowhere for this to be made at all.
        $this->assertFalse($this->game->atOwnGuildHall($this->character->fresh()));

        $land = $this->claimLandFor($guild);
        $this->assertTrue($this->game->atOwnGuildHall($this->character->fresh()));

        // And the ground alone is not the bench: a claim buys the hex and
        // nothing standing on it.
        try {
            $this->game->startCraft($this->character->fresh(), $epic);
            $this->fail('epic came off a bench nobody had built');
        } catch (GameException $e) {
            $this->assertSame('station', $e->errorCode);
        }

        // Level fifteen is where epic opens (§10.6).
        $guild->land_craft_level = 15;
        $guild->save();

        $this->assertSame('epic', Balance::guildLandCraftCap(15));

        // The station gate is open now; what refuses is the shopping list.
        try {
            $this->game->startCraft($this->character->fresh(), $epic);
        } catch (GameException $e) {
            $this->assertNotSame('station', $e->errorCode, 'the built bench did not open');
        }
    }

    /**
     * §8.0 -- and legendary is not crafted at all any more.
     *
     * It drops (§9.2), so there is no station in the game that reaches it and
     * the refusal says so rather than pointing at a bench nobody can build.
     */
    public function test_legendary_is_never_crafted_anywhere(): void
    {
        $legendary = collect(Catalog::items())
            ->filter(fn (array $d) => ($d['rarity'] ?? null) === 'legendary' && ! empty($d['inputs']))
            ->keys()
            ->first();

        $this->assertNull(Balance::stationForRarity('legendary'));

        $this->standAt('capital');
        $this->purse(Balance::GUILD_FOUNDING_COST);
        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());
        $this->claimLandFor($guild);

        $guild->land_craft_level = Balance::GUILD_LAND_MAX_LEVEL;
        $guild->save();

        try {
            $this->game->startCraft($this->character->fresh(), $legendary);
            $this->fail('a legendary was crafted');
        } catch (GameException $e) {
            $this->assertSame('station', $e->errorCode);
            $this->assertStringContainsString('drops', $e->getMessage());
        }
    }

    /**
     * §10.5 -- gold into the treasury does not come back out.
     *
     * Same rule §10.4 puts on a bidding donation, for the same reason: a pot
     * that can be emptied again is a pot whose size can be scouted, and a
     * contribution you can take back is not a contribution.
     */
    public function test_a_donation_leaves_the_purse_and_is_recorded_against_the_member(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST + 5_000);
        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());

        $this->assertSame(0, (int) $guild->gold);

        $this->game->donateToGuild($this->character->fresh(), 3_000);

        $this->assertSame(3_000, (int) $guild->fresh()->gold);
        $this->assertSame(2_000, (int) $this->character->fresh()->gold);
        $this->assertSame(
            3_000,
            (int) GuildMember::where('character_id', $this->character->id)->value('donated'),
            '§10.2 -- the guild has to know who carried it',
        );

        // And it will not take what is not there.
        try {
            $this->game->donateToGuild($this->character->fresh(), 999_999);
            $this->fail('a donation was made on credit');
        } catch (GameException $e) {
            $this->assertSame('poor', $e->errorCode);
        }

        $this->assertSame(3_000, (int) $guild->fresh()->gold);
    }

    /**
     * §10.5 -- the owner alone spends it, because §10.0.2 keeps the
     * irreversible things with them and this is the most irreversible of all.
     */
    public function test_only_the_owner_spends_the_treasury(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST);
        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());

        $officer = $this->game->createCharacter(
            Player::create(['wallet' => '0xofficer', 'session_id' => 'officer']),
        );
        $this->game->joinGuild($officer->fresh(), $guild->id);
        $this->game->setMemberRole($this->character->fresh(), $officer->id, GuildMember::OFFICER);

        $guild->gold = Balance::guildFacilityCost(1);
        $guild->save();

        try {
            $this->game->upgradeGuildFacility($officer->fresh(), 'hall');
            $this->fail('an officer spent the treasury');
        } catch (GameException $e) {
            $this->assertSame('forbidden', $e->errorCode);
        }

        $this->game->upgradeGuildFacility($this->character->fresh(), 'hall');

        $this->assertSame(1, (int) $guild->fresh()->hall_level);
        $this->assertSame(0, (int) $guild->fresh()->gold, 'the level was not paid for');
    }

    /**
     * §10.5 -- a facility level is paid out of the treasury, never on credit,
     * and the price climbs steeply enough to be a roster's project.
     */
    public function test_a_facility_is_paid_for_out_of_the_treasury(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST);
        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());

        try {
            $this->game->upgradeGuildFacility($this->character->fresh(), 'hall');
            $this->fail('an empty treasury bought a facility');
        } catch (GameException $e) {
            $this->assertSame('poor', $e->errorCode);
        }

        $this->assertGreaterThan(
            Balance::guildFacilityCost(1),
            Balance::guildFacilityCost(2),
            'the curve has to climb, or the treasury stops being a sink',
        );

        $this->assertGreaterThan(
            Balance::GUILD_FOUNDING_COST,
            Balance::guildFacilityCost(1),
            'the first level must cost more than the hall did',
        );
    }

    /**
     * §10.6 -- the land's two ladders, and what each level opens.
     */
    public function test_the_land_levels_open_lines_and_rungs(): void
    {
        $this->assertSame(0, Balance::guildLandLines(0), 'an unlevelled line ran anyway');
        $this->assertNull(Balance::guildLandCraftCap(0), 'an unbuilt bench reached a rung');

        // A line a level for five, so a guild passes a capital's four at five.
        for ($level = 1; $level <= 5; $level++) {
            $this->assertSame($level, Balance::guildLandLines($level));
        }
        $this->assertSame(5, Balance::guildLandLines(Balance::GUILD_LAND_MAX_LEVEL));

        // And the rungs, ending on §8.0's own guild cap.
        $this->assertSame('common', Balance::guildLandCraftCap(1));
        $this->assertSame('uncommon', Balance::guildLandCraftCap(5));
        $this->assertSame('rare', Balance::guildLandCraftCap(10));
        $this->assertSame('epic', Balance::guildLandCraftCap(15));
        $this->assertSame(
            Balance::STATION_RARITY_CAP['guild'],
            Balance::guildLandCraftCap(Balance::GUILD_LAND_MAX_LEVEL),
            'a maxed land reaches past section 8.0 own cap',
        );

        // The curve climbs, or the treasury stops being a sink.
        for ($level = 2; $level <= Balance::GUILD_LAND_MAX_LEVEL; $level++) {
            $this->assertGreaterThan(
                Balance::guildLandLevelCost($level - 1),
                Balance::guildLandLevelCost($level),
            );
        }
    }

    /**
     * §10.6/§5.2 -- a claim lands on DEAD ground and nowhere else.
     *
     * This is the rule that makes guild land safe for the map: a hex that never
     * carried a seam and never will is a hex nothing is taken out of the world
     * by building on. The wastes stop being scenery.
     */
    public function test_land_is_claimed_on_dead_ground_and_nowhere_else(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST);
        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());

        // Standing on the city it was founded in, which is not dead ground.
        $guild->gold = Balance::GUILD_LAND_COST;
        $guild->save();

        try {
            $this->game->claimGuildLand($this->character->fresh());
            $this->fail('a guild built on a living hex');
        } catch (GameException $e) {
            $this->assertContains($e->errorCode, ['not_dead', 'occupied']);
        }

        $this->assertFalse($guild->fresh()->hasLand());
        $this->assertSame(
            Balance::GUILD_LAND_COST,
            (int) $guild->fresh()->gold,
            'a refused claim spent the treasury',
        );

        $land = $this->claimLandFor($guild);

        $this->assertSame('guild', $land['tier']);
        $this->assertSame(0, (int) $guild->fresh()->gold, 'the claim did not cost the treasury');
        $tile = $this->game->buildTile($land['col'], $land['row'], $this->game->now());
        $this->assertTrue($tile['dead'], 'the claim landed on living ground');
    }

    /** §10.6 -- and one to a guild, so the map cannot be bought up. */
    public function test_a_guild_holds_one_hex(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST);
        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());
        $this->claimLandFor($guild);

        $guild->refresh();
        $guild->gold = Balance::GUILD_LAND_COST;
        $guild->save();

        $this->expectException(GameException::class);
        $this->game->claimGuildLand($this->character->fresh());
    }

    /**
     * §10.6 -- the land is a PLACE, so standing on it is standing at a
     * settlement, and every path that takes one works on it.
     */
    public function test_the_land_answers_as_a_settlement(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST);
        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());
        $land = $this->claimLandFor($guild);

        $here = $this->game->currentSettlement($this->character->fresh());

        $this->assertNotNull($here, 'a guild land was not somewhere you can stand');
        $this->assertSame($land['id'], $here['id']);
        $this->assertSame('guild', $here['tier']);

        // Nothing runs until it is levelled once, which is the rule that makes
        // a claim the beginning of the work rather than the end of it.
        $this->assertSame([], $here['lines']);

        $guild->land_processing_level = 3;
        $guild->save();

        $this->assertCount(
            3,
            $this->game->currentSettlement($this->character->fresh())['lines'],
        );
    }

    /**
     * §10.6 -- the land is named by its guild, and may be renamed.
     *
     * Unlike a prospector's own name (§7), which is spent the first time it is
     * used: a person is recognised by their name and a place is not.
     */
    public function test_a_guild_names_its_own_ground(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST);
        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());
        $this->claimLandFor($guild);

        // Unnamed, it answers to the guild's own name rather than to nothing.
        $this->assertSame($guild->name, $guild->fresh()->landSettlement()['name']);

        $this->game->nameGuildLand($this->character->fresh(), 'Hollow Reach');
        $this->assertSame('Hollow Reach', $guild->fresh()->landSettlement()['name']);

        $this->game->nameGuildLand($this->character->fresh(), 'Second Thoughts');
        $this->assertSame('Second Thoughts', $guild->fresh()->landSettlement()['name']);
    }

    /**
     * §10.6 -- five glyphs, so how far a guild has got is legible off the map.
     */
    public function test_the_glyph_steps_with_the_overall_level(): void
    {
        $this->assertSame(1, Balance::guildLandGlyphTier(0, 0));
        $this->assertSame(
            Balance::GUILD_LAND_GLYPH_TIERS,
            Balance::guildLandGlyphTier(Balance::GUILD_LAND_MAX_LEVEL, Balance::GUILD_LAND_MAX_LEVEL),
        );

        // It never skips and never goes backwards, which is what makes it
        // readable as "how far along are they".
        $seen = 1;
        for ($p = 0; $p <= Balance::GUILD_LAND_MAX_LEVEL; $p++) {
            for ($c = 0; $c <= Balance::GUILD_LAND_MAX_LEVEL; $c++) {
                $tier = Balance::guildLandGlyphTier($p, $c);
                $this->assertGreaterThanOrEqual(1, $tier);
                $this->assertLessThanOrEqual(Balance::GUILD_LAND_GLYPH_TIERS, $tier);
            }
        }

        for ($total = 0; $total <= Balance::GUILD_LAND_MAX_LEVEL * 2; $total++) {
            $tier = Balance::guildLandGlyphTier($total, 0);
            $this->assertLessThanOrEqual($seen + 1, $tier, 'the glyph skipped a step');
            $seen = max($seen, $tier);
        }
    }

    /**
     * §10.6 -- anybody may fund a guild, in it or not.
     *
     * Gold bridges to nothing external (§3.2), so moving it carries none of the
     * weight §3.1's no-P2P-trade rule is protecting. What stays members-only is
     * the CREDIT: the roster wants to know who carried it, and an outsider is
     * not on the roster to be asked about.
     */
    public function test_anybody_may_fund_a_guild(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST);
        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());

        $outsider = $this->game->createCharacter(
            Player::create(['wallet' => '0xpatron', 'session_id' => 'patron']),
        );
        $this->purse(5000, $outsider);

        $before = (int) $guild->fresh()->gold;
        $this->game->donateToGuild($outsider->fresh(), 5000, $guild->id);

        $this->assertSame($before + 5000, (int) $guild->fresh()->gold);
        $this->assertSame(0, (int) $outsider->fresh()->gold);

        // And no row on a roster they are not on.
        $this->assertSame(
            0,
            GuildMember::where('guild_id', $guild->id)
                ->where('character_id', $outsider->id)
                ->count(),
        );
    }

    /**
     * §10.6 -- the fee on a guild's own land: a quarter off for a member, and
     * half of what is actually paid into the treasury.
     *
     * Half of what is PAID rather than of what was charged, so a member's
     * discount thins the guild's cut as well as their own bill -- you cannot
     * take half of money nobody handed over. And half rather than all, because
     * a guild taking the whole fee would make its own land free to its own
     * members by the back door.
     */
    public function test_a_member_pays_less_and_half_of_it_comes_home(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST);
        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());
        $this->claimLandFor($guild);

        $guild->refresh();
        $guild->land_processing_level = 5;
        $guild->land_craft_level = 5;
        $guild->gold = 0;
        $guild->save();

        $land = $guild->fresh()->landSettlement();
        $handled = 400;

        $full = Formulas::benchFee($handled);
        $this->assertGreaterThan(0, $full, 'the bench charges nothing to be used');

        $charge = new \ReflectionMethod($this->game, 'chargeBenchFee');

        // A member, standing on their own guild's land.
        $this->purse(100000);
        $paid = $charge->invoke($this->game, $this->character->fresh(), $land, $handled, 'bench');

        $this->assertSame(
            (int) ceil($full * (1 - Balance::GUILD_LAND_MEMBER_DISCOUNT)),
            $paid,
            'a member paid the full fee on their own ground',
        );
        $this->assertLessThan($full, $paid);

        $this->assertSame(
            (int) floor($paid * Balance::GUILD_LAND_FEE_SHARE),
            (int) $guild->fresh()->gold,
            'the guild took the wrong share of what was paid',
        );

        // An outsider pays the full fee, and half of THAT comes home too.
        $outsider = $this->game->createCharacter(
            Player::create(['wallet' => '0xvisitor', 'session_id' => 'visitor']),
        );
        $this->purse(100000, $outsider);

        $before = (int) $guild->fresh()->gold;
        $theirs = $charge->invoke($this->game, $outsider->fresh(), $land, $handled, 'bench');

        $this->assertSame($full, $theirs, 'a visitor got the member discount');
        $this->assertSame(
            $before + (int) floor($full * Balance::GUILD_LAND_FEE_SHARE),
            (int) $guild->fresh()->gold,
        );

        // And the guild never takes more than it was handed.
        $this->assertLessThan($theirs, (int) floor($theirs * Balance::GUILD_LAND_FEE_SHARE));
    }

    /**
     * §6 -- and nowhere else pays a guild anything.
     *
     * A capital's fee is the NPC's, and a guild standing somewhere is not a
     * reason for the world's gold to start flowing to it.
     */
    public function test_an_ordinary_bench_pays_no_guild(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST + 100000);
        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());

        $guild->refresh();
        $guild->gold = 0;
        $guild->save();

        $here = $this->game->currentSettlement($this->character->fresh());
        $this->assertSame('city', $here['tier']);

        $charge = new \ReflectionMethod($this->game, 'chargeBenchFee');
        $paid = $charge->invoke($this->game, $this->character->fresh(), $here, 400, 'bench');

        $this->assertSame(Formulas::benchFee(400), $paid, 'a city gave a member a discount');
        $this->assertSame(0, (int) $guild->fresh()->gold, 'a city fee reached a treasury');
    }

    /**
     * §10.6 -- a level is BUILT, not bought.
     *
     * It used to land the instant it was paid for, which made the most
     * expensive thing a guild can do the only thing in the game with no clock
     * on it. The gold goes now and the level arrives later.
     */
    public function test_a_level_takes_time_to_build(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST);
        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());
        $this->claimLandFor($guild);

        $cost = Balance::guildLandLevelCost(1);
        $guild->refresh();
        $guild->gold = $cost;
        $guild->save();

        $this->game->upgradeGuildLand($this->character->fresh(), 'processing');
        $guild->refresh();

        // Paid for, and standing at nothing.
        $this->assertSame(0, (int) $guild->gold, 'the build did not cost the treasury');
        $this->assertSame(0, (int) $guild->land_processing_level, 'the level landed instantly');
        $this->assertSame('processing', $guild->land_building);
        $this->assertGreaterThan($this->game->now(), (int) $guild->land_built_at);

        // Still nothing, a moment later.
        $this->assertSame(
            0,
            $this->game->settleGuildLand($guild->fresh())->land_processing_level,
        );

        // Wind the clock back rather than sleeping, like every other timer here.
        $guild->land_built_at = $this->game->now() - 1;
        $guild->save();

        $settled = $this->game->settleGuildLand($guild->fresh());

        $this->assertSame(1, (int) $settled->land_processing_level);
        $this->assertNull($settled->land_building, 'the build did not clear');
        $this->assertNull($settled->land_built_at);

        // And it lands once. Settling again is a no-op, which is what makes it
        // safe to call on every read path (§16).
        $this->assertSame(
            1,
            (int) $this->game->settleGuildLand($settled->fresh())->land_processing_level,
            'a finished build was handed over twice',
        );
    }

    /**
     * §10.6 -- and it finishes on its OWN, with nothing to claim.
     *
     * A level is not carried home, so unlike a mine or a bench run there is
     * nobody who has to come back for it. Reading the guild is enough, which is
     * what makes an hour offline and an hour watching the same (§16).
     */
    public function test_a_finished_build_lands_without_being_collected(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST);
        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());
        $this->claimLandFor($guild);

        $guild->refresh();
        $guild->land_building = 'craft';
        $guild->land_built_at = $this->game->now() - 1;
        $guild->save();

        // Nothing collected: just somebody asking about the guild.
        $read = $this->game->guildOf($this->character->fresh());

        $this->assertSame(1, (int) $read->land_craft_level);
        $this->assertSame('common', Balance::guildLandCraftCap(1));

        // And the same through the map, which is the other read path.
        $land = $this->game->guildLandAt((int) $guild->land_col, (int) $guild->land_row);
        $this->assertSame('common', $land['craftCap']);
        $this->assertNull($land['building']);
    }

    /**
     * §10.6 -- one build at a time. A guild builds one thing at a time, and
     * that is what keeps WHICH ladder to climb a decision.
     */
    public function test_the_yard_builds_one_thing_at_a_time(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST);
        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());
        $this->claimLandFor($guild);

        $guild->refresh();
        $guild->gold = 10_000_000;
        $guild->save();

        $this->game->upgradeGuildLand($this->character->fresh(), 'processing');
        $spent = (int) $guild->fresh()->gold;

        try {
            $this->game->upgradeGuildLand($this->character->fresh(), 'craft');
            $this->fail('two builds went up at once');
        } catch (GameException $e) {
            $this->assertSame('busy', $e->errorCode);
        }

        $this->assertSame($spent, (int) $guild->fresh()->gold, 'a refused build spent gold');
    }

    /** §10.6 -- the clock climbs with the level, and goes through scaled(). */
    public function test_a_higher_level_takes_longer_to_build(): void
    {
        $seen = 0;
        for ($level = 1; $level <= Balance::GUILD_LAND_MAX_LEVEL; $level++) {
            $ms = Balance::guildLandBuildMs($level);
            $this->assertGreaterThan($seen, $ms, "level {$level} builds no slower than the one below");
            $seen = $ms;
        }

        // Compared in ONE unit: CRAFT_BASE_SECONDS is seconds and this is
        // milliseconds, and the first version of this test compared them raw
        // and passed only because scaled() clamps to a one-second floor.
        $craftMs = (int) (max(Balance::CRAFT_BASE_SECONDS) * 1000);
        $runMs = (int) (26 * 60 * 1000);  // §6's longest run: banding a frame.

        // Even the FIRST level outlasts any single run at a bench, because this
        // is a building rather than an object.
        $this->assertGreaterThan(
            $runMs,
            Balance::GUILD_BUILD_BASE_MS,
            'the first level goes up quicker than a frame is banded',
        );

        // And the last is in another league entirely -- ten hours against the
        // longest craft in the game.
        $this->assertGreaterThan(
            $craftMs * 10,
            Balance::GUILD_BUILD_BASE_MS * Balance::GUILD_LAND_MAX_LEVEL,
            'a maxed ladder is an afternoon',
        );
    }

    /** §10.5 -- a hall seats what it has been built to seat, and no more. */
    public function test_a_full_hall_turns_arrivals_away(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST);
        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());

        $this->assertSame(
            Balance::guildRosterCap(0),
            $this->game->guildRosterCap($guild),
            'a hall with no Hall levels still seats the flat base',
        );

        // Fill it to the brim without walking anybody in the long way.
        $seats = $this->game->guildRosterCap($guild);
        for ($i = 1; $i < $seats; $i++) {
            GuildMember::create([
                'guild_id' => $guild->id,
                'character_id' => $this->game->createCharacter(
                    Player::create(['wallet' => "0xseat{$i}", 'session_id' => "seat{$i}"]),
                )->id,
                'role' => GuildMember::MEMBER,
                'joined_at' => 0,
            ]);
        }

        $latecomer = $this->game->createCharacter(
            Player::create(['wallet' => '0xlate', 'session_id' => 'late']),
        );

        try {
            $this->game->joinGuild($latecomer->fresh(), $guild->id);
            $this->fail('a full hall took one more');
        } catch (GameException $e) {
            $this->assertSame('full', $e->errorCode);
        }

        // A Hall level is what makes room, which is the whole argument for it.
        $guild->hall_level = 1;
        $guild->save();

        $this->game->joinGuild($latecomer->fresh(), $guild->fresh()->id);
        $this->assertSame($seats + 1, $guild->fresh()->members()->count());
    }

    /**
     * §10.0.1 -- the second half of the door. Open says you may knock; approval
     * says whether knocking is enough.
     *
     * Two flags rather than three states, because they answer two questions
     * that move independently: a guild closes for a week without becoming a
     * guild that vets, and one that vets does not stop when it reopens.
     */
    public function test_a_guild_that_vets_takes_names_instead_of_members(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST);
        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());

        $this->game->updateGuild($this->character->fresh(), ['recruitment' => Guild::APPROVAL]);

        $hopeful = $this->game->createCharacter(
            Player::create(['wallet' => '0xhopeful', 'session_id' => 'hopeful']),
        );

        $result = $this->game->joinGuild($hopeful->fresh(), $guild->id);

        $this->assertTrue($result['applied'], 'a vetting guild let somebody walk in');
        $this->assertNull($this->game->guildOf($hopeful->fresh()));
        $this->assertSame([(string) $guild->id], $this->game->pendingApplicationsOf($hopeful->fresh()));

        // Asking twice is asking once.
        try {
            $this->game->joinGuild($hopeful->fresh(), $guild->id);
            $this->fail('one prospector queued twice');
        } catch (GameException $e) {
            $this->assertSame('applied', $e->errorCode);
        }

        // The owner sees the name on the roster payload.
        $mine = $this->game->guildPayload($this->game->guildOf($this->character->fresh()), true);
        $this->assertCount(1, $mine['applications']);
        $this->assertSame((string) $hopeful->id, $mine['applications'][0]['characterId']);

        $this->game->decideApplication($this->character->fresh(), $hopeful->id, true);

        $this->assertSame($guild->id, $this->game->guildOf($hopeful->fresh())?->id);
        $this->assertSame([], $this->game->pendingApplicationsOf($hopeful->fresh()));
    }

    /** §10.0.1 -- and turning somebody away leaves them free to ask elsewhere. */
    public function test_a_refusal_only_takes_the_name_off_the_list(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST);
        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());
        $this->game->updateGuild($this->character->fresh(), ['recruitment' => Guild::APPROVAL]);

        $hopeful = $this->game->createCharacter(
            Player::create(['wallet' => '0xturned', 'session_id' => 'turned']),
        );
        $this->game->joinGuild($hopeful->fresh(), $guild->id);

        $this->game->decideApplication($this->character->fresh(), $hopeful->id, false);

        $this->assertNull($this->game->guildOf($hopeful->fresh()));
        $this->assertSame([], $this->game->pendingApplicationsOf($hopeful->fresh()));

        // And they may ask again, which is the difference between a refusal and
        // a ban. Bans are not designed and must not arrive by accident.
        $again = $this->game->joinGuild($hopeful->fresh(), $guild->id);
        $this->assertTrue($again['applied']);
    }

    /**
     * §10.0 -- joining takes exactly one of them, so a name put down elsewhere
     * is an answer to a question no longer being asked.
     */
    public function test_joining_tears_up_every_other_application(): void
    {
        $this->standAt('city');
        $this->purse(Balance::GUILD_FOUNDING_COST);
        $vetting = $this->game->foundGuild($this->character->fresh(), $this->identity());
        $this->game->updateGuild($this->character->fresh(), ['recruitment' => Guild::APPROVAL]);

        $open = $this->game->createCharacter(
            Player::create(['wallet' => '0xopenowner', 'session_id' => 'openowner']),
        );
        $this->standAt('city', $open);
        $this->purse(Balance::GUILD_FOUNDING_COST, $open);
        $openGuild = $this->game->foundGuild($open->fresh(), $this->identity('Second Wind', 'SW'));

        $hopeful = $this->game->createCharacter(
            Player::create(['wallet' => '0xboth', 'session_id' => 'both']),
        );

        $this->game->joinGuild($hopeful->fresh(), $vetting->id);
        $this->assertCount(1, $this->game->pendingApplicationsOf($hopeful->fresh()));

        // Walks into the open one instead.
        $this->game->joinGuild($hopeful->fresh(), $openGuild->id);

        $this->assertSame($openGuild->id, $this->game->guildOf($hopeful->fresh())?->id);
        $this->assertSame([], $this->game->pendingApplicationsOf($hopeful->fresh()));
    }
}
