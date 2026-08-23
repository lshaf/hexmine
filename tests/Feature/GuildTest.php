<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\GameException;
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
        $this->game->updateGuild($this->character->fresh(), ['recruitment' => \App\Models\Guild::CLOSED]);
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
        $this->game->updateGuild($officer->fresh(), ['recruitment' => \App\Models\Guild::CLOSED]);
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
     * §10.0.3 -- exactly 1024 colours, and the column can hold nothing else.
     * Not a URL, not a file, not a data URI.
     */
    public function test_a_flag_is_1024_colours_and_nothing_else(): void
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
     * §8.0 / §10.0 -- the hall is the legendary bench, and it is the guild's
     * own. Founding one is what puts the top rung in reach.
     */
    public function test_legendary_is_made_at_your_own_hall_and_nowhere_else(): void
    {
        $legendary = collect(\App\Game\Catalog::items())
            ->filter(fn (array $d) => ($d['rarity'] ?? null) === 'legendary' && ! empty($d['inputs']))
            ->keys()
            ->first();

        $this->assertNotNull($legendary, 'no legendary recipe to test with');

        $capital = $this->standAt('capital');
        $this->purse(Balance::GUILD_FOUNDING_COST);

        // Standing at the best bench in the game, with no guild.
        try {
            $this->game->startCraft($this->character->fresh(), $legendary);
            $this->fail('legendary work came off a capital bench');
        } catch (GameException $e) {
            $this->assertSame('station', $e->errorCode);
            $this->assertStringContainsString('guild', $e->getMessage());
        }

        $guild = $this->game->foundGuild($this->character->fresh(), $this->identity());
        $this->assertTrue($this->game->atOwnGuildHall($this->character->fresh()));

        // §10.5 -- and the hall alone is not the bench. A capital reaches epic,
        // so a brand-new hall standing in one reaches epic too until somebody
        // pays for the rung above it.
        try {
            $this->game->startCraft($this->character->fresh(), $legendary);
            $this->fail('legendary came off a bench nobody had built');
        } catch (GameException $e) {
            $this->assertSame('station', $e->errorCode);
            $this->assertStringContainsString('epic', $e->getMessage());
        }

        $this->purse(Balance::guildFacilityCost(1));
        $this->game->donateToGuild($this->character->fresh(), Balance::guildFacilityCost(1));
        $this->game->upgradeGuildFacility($this->character->fresh(), 'bench');

        $this->assertSame('legendary', $this->game->guildBenchReach($guild->fresh()));

        // The station gate is open now; what refuses is the shopping list.
        try {
            $this->game->startCraft($this->character->fresh(), $legendary);
        } catch (GameException $e) {
            $this->assertNotSame('station', $e->errorCode, 'the built bench did not open');
        }

        // Walk away and it closes again: it is a place, not a permission.
        $this->character->col = (int) $capital['col'] + 3;
        $this->character->save();
        $this->assertFalse($this->game->atOwnGuildHall($this->character->fresh()));
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
     * §10.5 -- the bench climbs from what the ground under it already reached,
     * which is what stops the early levels being money thrown away.
     */
    public function test_the_bench_climbs_from_the_settlement_it_stands_in(): void
    {
        $this->standAt('capital');
        $this->purse(Balance::GUILD_FOUNDING_COST);
        $capitalGuild = $this->game->foundGuild($this->character->fresh(), $this->identity());

        // A capital already reaches epic (§8.0), so one level is legendary.
        $this->assertSame('epic', $this->game->guildBenchReach($capitalGuild));
        $this->assertSame(1, $this->game->guildBenchMaxLevel($capitalGuild));

        $other = $this->game->createCharacter(
            Player::create(['wallet' => '0xcity', 'session_id' => 'city']),
        );
        $this->standAt('city', $other);
        $this->purse(Balance::GUILD_FOUNDING_COST, $other);
        $cityGuild = $this->game->foundGuild(
            $other->fresh(),
            $this->identity('The Second Watch', 'TSW'),
        );

        // A city reaches uncommon, so the same hall is three levels off the top.
        $this->assertSame('uncommon', $this->game->guildBenchReach($cityGuild));
        $this->assertSame(3, $this->game->guildBenchMaxLevel($cityGuild));

        $cityGuild->bench_level = 3;
        $cityGuild->save();
        $this->assertSame('legendary', $this->game->guildBenchReach($cityGuild->fresh()));

        // And nothing is above legendary to buy.
        $this->assertNull($this->game->guildFacilityNextCost($cityGuild->fresh(), 'bench'));
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

        $this->game->updateGuild($this->character->fresh(), ['recruitment' => \App\Models\Guild::APPROVAL]);

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
        $this->game->updateGuild($this->character->fresh(), ['recruitment' => \App\Models\Guild::APPROVAL]);

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
        $this->game->updateGuild($this->character->fresh(), ['recruitment' => \App\Models\Guild::APPROVAL]);

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
