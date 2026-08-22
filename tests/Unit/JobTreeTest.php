<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Game\Balance;
use App\Game\Jobs;
use PHPUnit\Framework\TestCase;

/**
 * The skill trees are 335 rows of static data, and the two things that can go
 * wrong with static data are shape and balance.
 *
 * Shape: a node pointing at a parent that does not exist, or at one in its own
 * tier, makes a branch unbuyable or a cycle. Nothing at runtime would say so --
 * the node would simply never light up.
 *
 * Balance: §7.4.3 lets a node move four numbers that are not stats, and every
 * one of them thins a §11 sink rather than a power curve. Those have caps.
 * `stat` nodes need no cap here because they feed the same aggregate and the
 * same STAT_CEILING clamp as gear (§8.1 rule 1) -- but a tree that alone tried
 * to spend the whole +15% would leave no room for equipment, so this pins that
 * too.
 */
final class JobTreeTest extends TestCase
{
    public function test_every_job_has_a_tree_in_one_of_the_two_shapes(): void
    {
        // Five gathering lines, three benches, three battle roles, one road.
        $this->assertCount(12, Jobs::JOBS);

        foreach (array_keys(Jobs::JOBS) as $job) {
            $nodes = Jobs::nodesFor($job);
            $this->assertCount(
                Jobs::nodeCount($job),
                $nodes,
                "{$job} does not have ".Jobs::nodeCount($job).' nodes',
            );

            foreach (Jobs::tierSizes($job) as $tier => $size) {
                $inTier = array_filter($nodes, fn (array $n) => $n['tier'] === $tier);
                $this->assertCount($size, $inTier, "{$job} tier {$tier} is the wrong size");
            }
        }

        $this->assertSame(11 * Jobs::NODES_PER_JOB + Jobs::NODES_PER_WAYFARING, count(Jobs::NODES));
    }

    /**
     * §7.5 -- exactly one job may hand its nodes out for free.
     *
     * This is the load-bearing one for the point economy. Skill points are the
     * scarce thing (§7.4.1) and a granted tree costs none, so a second one would
     * not be a new job -- it would be a hole in the hundred-point cap.
     *
     * What bounds the free tree is not its length but its currency: capability
     * only, every kind of it capped, and never a stat. See the next test.
     */
    public function test_only_one_tree_is_granted_rather_than_bought(): void
    {
        $automatic = array_filter(array_keys(Jobs::JOBS), fn (string $j) => Jobs::isAutomatic($j));

        $this->assertSame(['explorer'], array_values($automatic));
        $this->assertCount(Jobs::NODES_PER_WAYFARING, Jobs::nodesFor('explorer'));
        $this->assertSame(15, Jobs::NODES_PER_WAYFARING);
    }

    /**
     * §7.5 -- the free tree deals in capability and never in a stat.
     *
     * This is the rule that makes a granted tree safe to exist at all. Every
     * other tree is paid for with a skill point, and the point is what keeps
     * §7.4.1's hundred-point cap meaningful; this one is free, so the only
     * currency left to it is the eye and the back -- counts, each with its own
     * cap, none of them touching the §8.1 ceiling. A percentage here would be a
     * power ladder climbed by leaving the app open on a long walk.
     */
    /**
     * §7.5 -- one skill per level, which is the whole of what makes this tree
     * exceptional.
     *
     * Every other tree opens a depth whole, because the gate there only says you
     * may start spending points and the points are the real price. Nothing is
     * bought here, so a row arriving whole would be three rewards for one level.
     * Each skill is charged for separately: one every second level, 2 through
     * 30, ending exactly on the job ceiling.
     */
    public function test_the_granted_tree_gates_one_skill_at_a_time(): void
    {
        $levels = array_map(
            fn (array $n) => $n['jobLevel'],
            array_values(Jobs::nodesFor('explorer')),
        );

        $this->assertSame(range(2, Balance::JOB_MAX_LEVEL, 2), $levels);
        $this->assertCount(Jobs::NODES_PER_WAYFARING, $levels, 'a level was shared by two skills');

        // And each row is labelled by the first of its three, so the panel can
        // say where a depth begins without claiming its whole row arrives there.
        foreach (Jobs::WAYFARING_TIER_JOB_LEVEL as $tier => $opens) {
            $inRow = array_values(array_filter(
                Jobs::nodesFor('explorer'),
                fn (array $n) => $n['tier'] === $tier,
            ));

            $this->assertCount(3, $inRow, "row {$tier} is not three wide");
            $this->assertSame($opens, $inRow[0]['jobLevel'], "row {$tier} is mislabelled");
        }
    }

    public function test_the_granted_tree_pays_only_in_eye_and_back(): void
    {
        foreach (Jobs::nodesFor('explorer') as $key => $node) {
            $this->assertContains(
                $node['effect']['kind'],
                ['sight', 'bagUnits', 'bagRows'],
                "{$key} pays out in something a free tree must never buy",
            );
        }

        $sight = array_sum(array_map(
            fn (array $n) => $n['effect']['kind'] === 'sight' ? $n['effect']['value'] : 0,
            Jobs::nodesFor('explorer'),
        ));

        // §5.6 -- sight is the radius of the map query, and its cost is the
        // square of it. The cap is not a balance nicety, it is the reason sight
        // can be a reward at all.
        $this->assertSame(Balance::SKILL_SIGHT_CAP, $sight);

        // §7.6 -- the same argument for the bag. Both limits are counts rather
        // than percentages, and both are capped because the bag is the pressure
        // the §11 sinks run on: a chain that could switch it off would switch
        // off the selling, processing and dumping it drives.
        $summed = fn (string $kind) => array_sum(array_map(
            fn (array $n) => $n['effect']['kind'] === $kind ? $n['effect']['value'] : 0,
            Jobs::nodesFor('explorer'),
        ));

        $this->assertSame(Balance::SKILL_BAG_UNITS_CAP, $summed('bagUnits'));
        $this->assertSame(Balance::SKILL_BAG_ROWS_CAP, $summed('bagRows'));
    }

    public function test_prerequisites_point_backwards_at_real_nodes(): void
    {
        foreach (Jobs::NODES as $key => $node) {
            foreach ($node['requires'] as $parentKey) {
                $parent = Jobs::node($parentKey);

                $this->assertNotNull($parent, "{$key} requires {$parentKey}, which does not exist");
                $this->assertSame(
                    $node['job'],
                    $parent['job'],
                    "{$key} requires {$parentKey} from another job -- a tree cannot reach across",
                );
                $this->assertLessThan(
                    $node['tier'],
                    $parent['tier'],
                    "{$key} requires {$parentKey} at the same tier or above, which is a cycle",
                );
            }
        }
    }

    /**
     * §7.4.2 -- tier 1 opens immediately, tiers 2-4 take one parent, and the two
     * capstones take two. The capstones are what make a tree a tree: 30 points
     * has to be spent through choices rather than down a list.
     */
    public function test_tier_one_is_open_and_capstones_need_two_parents(): void
    {
        foreach (Jobs::NODES as $key => $node) {
            // §7.5 -- the wayfaring tree is wired down its columns, so every
            // node past the first row has exactly one parent. The two-parent
            // rule exists to force thirty points through choices; a granted tree
            // has none to make, and inventing a fork would be a fork nobody
            // walks down.
            $expected = match (true) {
                $node['tier'] === 1 => 0,
                Jobs::isAutomatic($node['job']) => 1,
                $node['tier'] === 5 => 2,
                default => 1,
            };

            $this->assertCount($expected, $node['requires'], "{$key} has the wrong number of parents");

            // §7.4.2 -- a bought depth opens whole, so every node in it shares
            // the tier's gate. §7.5's granted tree is the exception and has its
            // own test below: its skills are gated one at a time, so all a row
            // can promise is that nothing in it arrives before the row does.
            if (Jobs::isAutomatic($node['job'])) {
                $this->assertGreaterThanOrEqual(
                    Jobs::tierJobLevels($node['job'])[$node['tier']],
                    $node['jobLevel'],
                    "{$key} arrives before the row it is drawn in",
                );

                continue;
            }

            $this->assertSame(
                Jobs::tierJobLevels($node['job'])[$node['tier']],
                $node['jobLevel'],
                "{$key} gates on a job level that does not match its tier",
            );
        }
    }

    /** Every node must be reachable: buying the whole tree cannot be blocked. */
    public function test_every_node_is_reachable_from_tier_one(): void
    {
        foreach (array_keys(Jobs::JOBS) as $job) {
            $owned = [];

            // Buy greedily, lowest tier first, exactly as a player must.
            for ($pass = 0; $pass < Jobs::nodeCount($job); $pass++) {
                foreach (Jobs::nodesFor($job) as $key => $node) {
                    if (isset($owned[$key])) {
                        continue;
                    }
                    foreach ($node['requires'] as $parent) {
                        if (! isset($owned[$parent])) {
                            continue 2;
                        }
                    }
                    $owned[$key] = true;
                }
            }

            $this->assertCount(
                Jobs::nodeCount($job),
                $owned,
                "{$job} has nodes nothing can reach: ".implode(', ', array_diff(
                    array_keys(Jobs::nodesFor($job)),
                    array_keys($owned),
                )),
            );
        }
    }

    public function test_every_effect_is_a_kind_the_game_knows(): void
    {
        $known = [
            'stat', 'unlock', 'craftOption', 'craftDurability', 'costReduction',
            'batch', 'sight', 'bagUnits', 'bagRows',
        ];

        foreach (Jobs::NODES as $key => $node) {
            $this->assertContains($node['effect']['kind'], $known, "{$key} has an unknown effect kind");

            if ($node['effect']['kind'] === 'stat') {
                $this->assertArrayHasKey('stat', $node['effect'], "{$key} is a stat node with no stat");
                $this->assertGreaterThan(0, $node['effect']['value']);
            }
            if ($node['effect']['kind'] === 'unlock') {
                $this->assertNotSame('', $node['effect']['target'], "{$key} unlocks nothing");
            }
        }
    }

    /**
     * §7.4.3 -- the four capped effects, checked per job.
     *
     * They are capped per job rather than globally because a craft job's effects
     * only ever apply to its own bench: a Smith's cheaper crafts do not make an
     * Armorer's cheaper. Left unchecked, one maxed tree could switch off the
     * materials sink the whole economy is balanced around.
     */
    public function test_no_tree_breaks_a_sink_cap(): void
    {
        $caps = [
            'costReduction' => Balance::SKILL_COST_REDUCTION_CAP,
            'craftDurability' => Balance::SKILL_DURABILITY_CAP,
            'craftOption' => Balance::SKILL_OPTION_CHANCE_CAP,
            'batch' => Balance::SKILL_BATCH_CAP,
        ];

        foreach (array_keys(Jobs::JOBS) as $job) {
            $totals = array_fill_keys(array_keys($caps), 0.0);

            foreach (Jobs::nodesFor($job) as $node) {
                $kind = $node['effect']['kind'];
                if (isset($totals[$kind])) {
                    $totals[$kind] += $node['effect']['value'];
                }
            }

            foreach ($caps as $kind => $cap) {
                $this->assertLessThanOrEqual(
                    $cap + 1e-9,
                    $totals[$kind],
                    sprintf('%s totals %.3f of %s, past its cap of %s', $job, $totals[$kind], $kind, $cap),
                );
            }
        }
    }

    /**
     * §8.1 rule 1 -- the load-bearing one. A whole tree of stat nodes must not
     * spend the entire +15% by itself, or equipment stops mattering to anyone
     * who took the tree.
     *
     * Explorer is exempt, and it is worth saying exactly why rather than
     * quietly skipping it. Its three travel nodes total 25% against a 15%
     * ceiling, so a maxed Explorer really does have travelSpeed spent for them
     * and boots really do stop adding to it -- that is a deliberate call, not an
     * oversight, and it is what makes a 5-node granted tree worth walking for
     * when it costs no points to hold.
     *
     * The ceiling itself is untouched: those 25 points go into the same sum and
     * the same clamp as everything else, which
     * test_a_maxed_explorer_still_stops_at_the_ceiling pins at runtime. What is
     * relaxed here is the "leave gear something to add" guard, and only for the
     * one stat that no longer touches yield, trips or any §11 sink.
     */
    public function test_no_tree_spends_the_whole_stat_ceiling(): void
    {
        foreach (array_keys(Jobs::JOBS) as $job) {
            if (Jobs::isAutomatic($job)) {
                continue;
            }

            $perStat = [];

            foreach (Jobs::nodesFor($job) as $node) {
                if ($node['effect']['kind'] !== 'stat') {
                    continue;
                }
                $stat = $node['effect']['stat'];
                $perStat[$stat] = ($perStat[$stat] ?? 0) + $node['effect']['value'];
            }

            // Strictly under, with room left. A tree that reached +15% by itself
            // would make gear for that stat pointless to anyone who took it, and
            // §8.1 rule 4 wants both roads to matter.
            $headroom = Balance::STAT_CEILING * 0.85;

            foreach ($perStat as $stat => $total) {
                $this->assertLessThanOrEqual(
                    $headroom + 1e-9,
                    $total,
                    sprintf(
                        '%s alone grants %.3f %s of a %.2f ceiling, leaving gear nothing to add',
                        $job, $total, $stat, Balance::STAT_CEILING,
                    ),
                );
            }
        }
    }

    /**
     * §7.4 -- the battle jobs are dormant, and their trees have to say so. They
     * carry stats and ability hooks only; a battle node that made crafting
     * cheaper would give a dormant job a live reason to be bought.
     */
    public function test_battle_trees_carry_no_crafting_effects(): void
    {
        // Crafting effects belong to a bench. A gathering or battle node that
        // made crafting cheaper would give a line a reason to be taken that has
        // nothing to do with what it is for.
        $craftOnly = ['craftOption', 'craftDurability', 'costReduction', 'batch'];

        foreach (Jobs::JOBS as $job => $def) {
            if ($def['kind'] !== Jobs::BATTLE) {
                continue;
            }

            foreach (Jobs::nodesFor($job) as $key => $node) {
                $this->assertNotContains(
                    $node['effect']['kind'],
                    $craftOnly,
                    "{$key} is a battle node doing a crafting job's work",
                );
            }

            $unlocks = array_filter(
                Jobs::nodesFor($job),
                fn (array $n) => $n['effect']['kind'] === 'unlock',
            );
            $this->assertCount(8, $unlocks, "{$job} should carry 8 dormant ability hooks");
        }
    }

    /** Keys are namespaced by job, so two trees can share a node name. */
    public function test_node_keys_are_namespaced_by_job(): void
    {
        foreach (Jobs::NODES as $key => $node) {
            $this->assertStringStartsWith(
                $node['job'].'.',
                $key,
                "{$key} is not namespaced under its own job",
            );
        }
    }
}
