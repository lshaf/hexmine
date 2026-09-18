<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\BattleGear;
use App\Game\Dungeons;
use App\Game\DungeonService;
use App\Game\Formulas;
use App\Game\GameService;
use App\Game\WorldGen;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §9.6.4 -- fighting together.
 *
 * The load-bearing assertion here is the one about DEFENSE. Summing the pair
 * outright is the obvious implementation and it is catastrophic: six people's
 * guard against one attack drives `attack - defense` under the floor, the
 * guardian chips for one unit a round forever, and a six-party becomes
 * unkillable. Everything else in this file is texture; that one is the design.
 */
final class DungeonPartyTest extends TestCase
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

    /** @return array{0: list<array{attack:int,defense:int,pool:int,skills:array}>} */
    private function party(int $n, int $attack = 4518, int $defense = 6277, int $pool = 84000): array
    {
        return array_fill(0, $n, [
            'attack' => $attack, 'defense' => $defense, 'pool' => $pool, 'skills' => [],
        ]);
    }

    /**
     * A party of one is the solo resolver, exactly. If these ever disagree there
     * are two combat systems in the game and only one of them is tested.
     */
    public function test_a_party_of_one_is_the_solo_exchange(): void
    {
        $monster = Dungeons::guardian('windhollow', 'easy', 10, 1);

        for ($seed = 0; $seed < 25; $seed++) {
            $solo = Formulas::resolveBattle(4518, 6277, 84000, $monster, $seed, []);
            $party = Formulas::resolvePartyBattle($this->party(1), $monster, $seed);

            $this->assertSame($solo['won'], $party['won'], "seed {$seed}: outcomes differ");
            $this->assertSame($solo['rounds'], $party['rounds'], "seed {$seed}: rounds differ");
            $this->assertSame($solo['damageDealt'], $party['damageDealt'], "seed {$seed}: damage differs");
            $this->assertSame($solo['damageTaken'], $party['members'][0]['damageTaken'], "seed {$seed}: bill differs");
        }
    }

    /**
     * §9.6.4 -- DEFENSE DOES NOT STACK, and this is the test that matters.
     *
     * Against a guardian scaled to the roster, a six-party must still be able to
     * lose and must still take real damage. If defense summed, `attack -
     * defense` would go under the chip floor and the party would take one unit a
     * round for sixty rounds -- a thousandth of what it should.
     */
    public function test_defense_does_not_stack(): void
    {
        $monster = Dungeons::guardian('windhollow', 'easy', 10, Balance::DUNGEON_PARTY_MAX);
        $taken = 0;
        $runs = 40;

        for ($seed = 0; $seed < $runs; $seed++) {
            $fight = Formulas::resolvePartyBattle($this->party(Balance::DUNGEON_PARTY_MAX), $monster, $seed);
            $taken += array_sum(array_column($fight['members'], 'damageTaken'));
        }

        $perHead = $taken / $runs / Balance::DUNGEON_PARTY_MAX;

        $this->assertGreaterThan(
            10000,
            $perHead,
            'a six-party took almost nothing: defense is stacking and the guard has collapsed to the chip floor'
        );
    }

    /**
     * §9.6.4 -- the bill per head is flat across roster sizes, which is what
     * makes party size buy composition rather than power.
     */
    public function test_the_bill_per_head_is_flat_across_party_sizes(): void
    {
        $perHead = [];

        foreach ([1, 2, 4, Balance::DUNGEON_PARTY_MAX] as $n) {
            $monster = Dungeons::guardian('windhollow', 'easy', 10, $n);
            $total = 0;
            $runs = 30;

            for ($seed = 0; $seed < $runs; $seed++) {
                $fight = Formulas::resolvePartyBattle($this->party($n), $monster, $seed * 7919 + 13);
                $total += array_sum(array_column($fight['members'], 'damageTaken'));
            }

            $perHead[$n] = $total / $runs / $n;
        }

        $smallest = min($perHead);
        $largest = max($perHead);

        $this->assertLessThan(
            1.35,
            $largest / $smallest,
            'the cost per head swings with party size: '.json_encode(array_map('intval', $perHead))
        );
    }

    /**
     * §9.6.4 -- it swings at whoever is in the way, which is the shield's job.
     *
     * Counted in ANSWERS, not in damage, and the difference is the whole point
     * of the mechanic. Measuring damage gets this exactly backwards: the wall
     * takes the most swings and the least damage, because a 9,000 guard against
     * an 8,700 attack absorbs almost all of it. Asserting on damage "proved"
     * the armored one was being ignored when it was in fact eating every blow
     * and shrugging them off -- which is what a shieldbearer is for.
     */
    public function test_it_swings_at_the_one_in_the_way(): void
    {
        $monster = Dungeons::guardian('windhollow', 'easy', 10, 3);

        // A wall, and two people behind it.
        $party = [
            ['attack' => 2000, 'defense' => 9000, 'pool' => 90000, 'skills' => []],
            ['attack' => 5000, 'defense' => 1000, 'pool' => 90000, 'skills' => []],
            ['attack' => 5000, 'defense' => 1000, 'pool' => 90000, 'skills' => []],
        ];

        $answers = [0, 0, 0];

        for ($seed = 0; $seed < 40; $seed++) {
            $fight = Formulas::resolvePartyBattle($party, $monster, $seed * 31 + 7);

            foreach ($fight['log'] as $entry) {
                foreach ($entry['answers'] ?? [] as $answer) {
                    $answers[$answer['member']]++;
                }
            }
        }

        $behind = ($answers[1] + $answers[2]) / 2;

        $this->assertGreaterThan(
            $behind,
            $answers[0],
            'the armored one is not drawing the swings, so a shieldbearer has no job in a party'
        );

        // And the other half of it: standing in the way is survivable, or the
        // role is a way to die first rather than a way to hold a line.
        $wall = 0;
        $rest = 0;

        for ($seed = 0; $seed < 40; $seed++) {
            $fight = Formulas::resolvePartyBattle($party, $monster, $seed * 31 + 7);
            $wall += $fight['members'][0]['damageTaken'];
            $rest += ($fight['members'][1]['damageTaken'] + $fight['members'][2]['damageTaken']) / 2;
        }

        $this->assertLessThan(
            $rest,
            $wall,
            'the wall takes more swings AND more damage, which makes guard worth nothing'
        );
    }

    /** §9.6.4 -- down is not dead, and it thins the answers from then on. */
    public function test_a_downed_member_leaves_the_fight(): void
    {
        $monster = Dungeons::guardian('ashpit', 'hard', 10, 3);

        // One of them is nearly spent, so it goes down early.
        $party = $this->party(3);
        $party[2]['pool'] = 900;

        $sawItDown = false;

        for ($seed = 0; $seed < 40 && ! $sawItDown; $seed++) {
            $fight = Formulas::resolvePartyBattle($party, $monster, $seed * 17 + 3);

            if ($fight['members'][2]['down']) {
                $sawItDown = true;
                $this->assertSame(0, $fight['members'][2]['left'], 'down but with a pool left');
                $this->assertLessThanOrEqual(900, $fight['members'][2]['damageTaken']);
            }
        }

        $this->assertTrue($sawItDown, 'a member with a pool of 900 never went down');
    }

    // ------------------------------------------------------- through the service

    private function atMouth(string $wallet): array
    {
        $site = WorldGen::dungeonSites()[0];
        $character = $this->game->createCharacter(Player::create(['wallet' => $wallet]));
        $character->col = $site['col'];
        $character->row = $site['row'];
        $character->save();

        return [$character, $site['dungeon']['key']];
    }

    private function arm(Character $character, string $rarity = 'rare'): void
    {
        $bySlot = [];
        foreach (BattleGear::ITEMS as $key => $def) {
            if ($def['rarity'] === $rarity || isset($bySlot[$def['slot']])) {
                $bySlot[$def['slot']] ??= $key;
            }
        }

        foreach ($bySlot as $key) {
            $def = BattleGear::ITEMS[$key];
            CharacterItem::create([
                'character_id' => $character->id,
                'item_key' => $key,
                'equipped' => true,
                'durability' => $def['maxDurability'],
                'max_durability' => $def['maxDurability'],
                'quality' => 0,
                'options' => [],
            ]);
        }
    }

    /**
     * §9.5.3 -- A PINNED HEX ALWAYS HAS AN EXIT, even with nothing left to
     * fight with.
     *
     * This is a regression test for a bug I wrote and the suite caught: the
     * party builder skipped anybody whose pool was empty, which is right for a
     * bystander and catastrophic for the caller. A prospector whose gear is gone
     * could not close, and could not walk, and was simply stuck there.
     */
    public function test_an_empty_kit_can_still_close_and_lose(): void
    {
        [$character, $key] = $this->atMouth('0xspent');
        $session = $this->dungeons->open($character, $key, 'tools', 'easy');
        $this->dungeons->enter($character);
        $session = $session->fresh();

        $member = $this->dungeons->memberFor($character, $this->game->now());
        [$col, $row] = Dungeons::seededHexes($session->floorSeed(1))[0];
        $member->fill(['col' => $col, 'row' => $row, 'busy_until_ms' => null])->save();

        $this->assertSame(0, $this->game->combatProfile($character)['pool'], 'this test needs an empty kit');

        $result = $this->dungeons->fight($character);

        $this->assertFalse($result['won']);
        $this->assertSame(1, $result['party'], 'the caller was left out of their own fight');
    }

    /** §9.6.4 -- everybody standing on the hex is in it, and nobody else. */
    public function test_the_hex_decides_who_is_in_the_fight(): void
    {
        [$owner, $key] = $this->atMouth('0xparty0');
        $session = $this->dungeons->open($owner, $key, 'tools', 'easy');

        $mates = [];
        for ($i = 1; $i <= 2; $i++) {
            [$mate] = $this->atMouth('0xparty'.$i);
            $this->dungeons->join($mate, $session->code);
            $mates[] = $mate;
        }

        foreach ([$owner, ...$mates] as $who) {
            $this->arm($who);
            $this->dungeons->enter($who);
        }

        $session = $session->fresh();
        [$col, $row] = Dungeons::seededHexes($session->floorSeed(1))[0];

        // Two of the three converge; the third stays on the landing.
        foreach ([$owner, $mates[0]] as $who) {
            $this->dungeons->memberFor($who, $this->game->now())
                ->fill(['col' => $col, 'row' => $row, 'busy_until_ms' => null])->save();
        }

        $result = $this->dungeons->fight($owner);

        $this->assertSame(2, $result['party'], 'the party is not the hex');
        $this->assertCount(2, $result['members']);

        $ids = array_column($result['members'], 'character');
        $this->assertContains($owner->id, $ids);
        $this->assertContains($mates[0]->id, $ids);
        $this->assertNotContains($mates[1]->id, $ids, 'somebody on another hex was charged for this fight');
    }

    /**
     * §9.6.4 -- NOTHING SPLITS. Each member rolls the table for themselves,
     * which is what lets §9.6.8 quote a rate a player can read.
     */
    public function test_each_member_is_paid_in_full(): void
    {
        [$owner, $key] = $this->atMouth('0xpay0');
        $session = $this->dungeons->open($owner, $key, 'tools', 'easy');
        [$mate] = $this->atMouth('0xpay1');
        $this->dungeons->join($mate, $session->code);

        foreach ([$owner, $mate] as $who) {
            $this->arm($who);
            $this->dungeons->enter($who);
        }

        $session = $session->fresh();
        [$col, $row] = Dungeons::seededHexes($session->floorSeed(1))[0];

        foreach ([$owner, $mate] as $who) {
            $this->dungeons->memberFor($who, $this->game->now())
                ->fill(['col' => $col, 'row' => $row, 'busy_until_ms' => null])->save();
        }

        $result = $this->dungeons->fight($owner);

        if (! $result['won']) {
            $this->markTestSkipped('this seed lost; the payout is only asserted on a win');
        }

        $this->assertCount(2, $result['members']);

        foreach ($result['members'] as $share) {
            $this->assertGreaterThan(0, $share['gold'], 'a member was not paid');
            $this->assertGreaterThan(0, $share['characterXp'], 'a member earned no XP');
        }

        // And the hex clears exactly once however many swung at it.
        $this->assertSame(1, $session->fresh()->killsOn(1));
    }
}
