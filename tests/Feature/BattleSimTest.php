<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Balance;
use App\Game\Jobs;
use App\Game\Monsters;
use App\Http\Controllers\Api\BattleSimController;
use App\Models\Character;
use App\Models\GameJob;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * §9.5 -- the battle bench.
 *
 * The rule that makes it worth having: it runs the REAL exchange and the REAL
 * tree aggregation, so what it measures is what a player would meet. A bench
 * that reimplemented either would be a second opinion that drifts, and the
 * first thing it does when it drifts is lie confidently.
 */
final class BattleSimTest extends TestCase
{
    use RefreshDatabase;

    private function bench(): array
    {
        return app(BattleSimController::class)->index()->getData(true);
    }

    private function fight(array $input): array
    {
        return app(BattleSimController::class)
            ->store(Request::create('/api/battle-sim', 'POST', $input))
            ->getData(true);
    }

    /**
     * Every monster carries its own key.
     *
     * Monsters::ROSTER is keyed by the array key alone, so a row handed over
     * raw has no `key` on it -- and the bench had no selectable monster at all
     * until this did. The kind of bug that is invisible in a payload dump and
     * total on screen.
     */
    public function test_the_bench_hands_over_monsters_you_can_select(): void
    {
        $bench = $this->bench();

        $this->assertCount(count(Monsters::ROSTER), $bench['monsters']);

        foreach ($bench['monsters'] as $monster) {
            $this->assertArrayHasKey('key', $monster, 'a monster row cannot be selected by');
            $this->assertArrayHasKey($monster['key'], Monsters::ROSTER);
            $this->assertSame(Monsters::ROSTER[$monster['key']]['name'], $monster['name']);
        }
    }

    /** §7.4 -- and the three battle trees, so the bench picks nodes rather than figures. */
    public function test_the_bench_serves_the_battle_trees(): void
    {
        $bench = $this->bench();

        $this->assertSame(
            ['shieldbearer', 'swordhand', 'runecaster'],
            array_keys($bench['jobs']),
        );

        foreach ($bench['nodes'] as $key => $node) {
            $this->assertSame(
                Jobs::BATTLE,
                Jobs::JOBS[$node['job']]['kind'],
                "{$key} is not a battle node and has no business on the bench",
            );
        }

        // Every node of all three, so nothing a player could buy is unreachable
        // here. A bench missing a node is a shape it cannot measure.
        $this->assertCount(90, $bench['nodes']);
    }

    /**
     * §7.4 -- picked nodes aggregate the way the game aggregates them.
     *
     * The whole tree of the family in the slot, and the answer has to be the
     * capped one: twenty points of pair clamps to SKILL_PAIR_CAP, and the bench
     * must not be able to measure a Swordhand nobody could build.
     */
    public function test_picked_nodes_are_aggregated_and_capped_like_a_character(): void
    {
        $nodes = array_keys(array_filter(
            Jobs::NODES,
            static fn (array $n): bool => $n['job'] === 'swordhand',
        ));

        $sim = $this->fight([
            'monster' => 'barrow_knight',
            'gear' => ['the_last_argument', 'longwatch_carapace', 'unmoved_sabatons', 'gauntlets_of_the_last_word'],
            'nodes' => $nodes,
            'seed' => 7,
        ]);

        $this->assertSame('sword', $sim['family']);
        $this->assertLessThanOrEqual(Balance::SKILL_PAIR_CAP, $sim['tree']['attack']);
        $this->assertLessThanOrEqual(Balance::SKILL_BATTLE_POWER_CAP, $sim['tree']['skillPower']);
        $this->assertLessThanOrEqual(Balance::SKILL_BATTLE_COOLDOWN_CAP, $sim['tree']['skillCooldown']);
        $this->assertLessThanOrEqual(Balance::SKILL_BATTLE_STUN_CAP, $sim['tree']['skillStun']);
        $this->assertGreaterThan(0, $sim['tree']['attack'], 'a whole tree bought nothing');

        // And it reaches the fight: the cooldowns the exchange ran on are the
        // shortened ones, not the catalog's.
        foreach ($sim['skills'] as $skill) {
            $this->assertLessThan(
                15,
                $skill['cooldown'],
                "{$skill['name']} kept its catalog cooldown despite a maxed tree",
            );
        }
    }

    /** A tree bought for one family is worth nothing carrying another (§7.4). */
    public function test_a_tree_does_not_pay_out_through_the_wrong_weapon(): void
    {
        $nodes = array_keys(array_filter(
            Jobs::NODES,
            static fn (array $n): bool => $n['job'] === 'runecaster',
        ));

        $sim = $this->fight([
            'monster' => 'moss_hound',
            'gear' => ['the_last_argument'],
            'nodes' => $nodes,
            'seed' => 3,
        ]);

        $this->assertSame('sword', $sim['family']);
        $this->assertSame(0, $sim['tree']['attack'], 'a runecaster tree paid out through a sword');
        $this->assertEqualsWithDelta(0.0, $sim['tree']['skillPower'], 1e-9);
    }

    /**
     * The seed is an input, so the same press is the same fight.
     *
     * A bench that answered differently every time you pressed it would be a
     * slot machine rather than an instrument -- the point is changing ONE thing
     * and seeing what moved.
     */
    public function test_the_same_seed_is_the_same_fight(): void
    {
        // A matchup the swing can actually move. Against a wall like the
        // Thornback a common sword is chip-floored every single round
        // (§9.5.5), so BATTLE_SWING has nothing to multiply and two seeds
        // legitimately produce the identical fight -- which would make this
        // test fail for a reason that is the model working.
        $input = [
            'monster' => 'slag_ogre',
            'gear' => ['the_last_argument', 'longwatch_carapace', 'unmoved_sabatons', 'gauntlets_of_the_last_word'],
            'seed' => 42,
            'runs' => 10,
        ];

        $a = $this->fight($input);
        $b = $this->fight($input);

        $this->assertSame($a['log'], $b['log']);
        $this->assertSame($a['bill'], $b['bill']);
        $this->assertSame($a['over'], $b['over']);

        $c = $this->fight(['seed' => 43] + $input);
        $this->assertNotSame($a['log'], $c['log'], 'the seed changed nothing');
    }

    /** §9.5.6 -- and it charges the real bill, off the real split. */
    public function test_it_charges_the_bill_a_real_fight_would(): void
    {
        $sim = $this->fight([
            'monster' => 'barrow_knight',
            'gear' => ['notched_sword', 'padded_jack', 'studded_boots', 'knuckle_wraps'],
            'seed' => 11,
        ]);

        $this->assertSame(
            (int) round($sim['damageTaken'] * Balance::BATTLE_WEAR_RATE),
            $sim['bill'],
            'the bench and §9.5.6 disagree about what a fight costs',
        );

        foreach ($sim['wear'] as $row) {
            $this->assertLessThanOrEqual($row['of'], $row['lost'], "{$row['name']} was billed past what it holds");
        }
    }

    /** It takes no character and writes nothing: a pure function of its input. */
    public function test_the_bench_touches_nothing(): void
    {
        $before = [Character::count(), GameJob::count(), Player::count()];

        $this->fight([
            'monster' => 'ash_revenant',
            'gear' => ['the_last_argument'],
            'seed' => 5,
            'runs' => 25,
        ]);

        $this->assertSame($before, [Character::count(), GameJob::count(), Player::count()]);
    }
}
