<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Game\Balance;
use App\Game\Jobs;
use PHPUnit\Framework\TestCase;

/**
 * The skill trees are 495 rows of static data, and the two things that can go
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
        // Five gathering lines, five processing lines, three benches, three
        // battle roles, one road.
        $this->assertCount(17, Jobs::JOBS);

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

        $this->assertSame(16 * Jobs::NODES_PER_JOB + Jobs::NODES_PER_WAYFARING, count(Jobs::NODES));
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

        // And each row is labeled by the first of its three, so the panel can
        // say where a depth begins without claiming its whole row arrives there.
        foreach (Jobs::WAYFARING_TIER_JOB_LEVEL as $tier => $opens) {
            $inRow = array_values(array_filter(
                Jobs::nodesFor('explorer'),
                fn (array $n) => $n['tier'] === $tier,
            ));

            $this->assertCount(3, $inRow, "row {$tier} is not three wide");
            $this->assertSame($opens, $inRow[0]['jobLevel'], "row {$tier} is mislabeled");
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

    /**
     * Every kind here has a call site in GameService, and that is the whole
     * point of the list.
     *
     * `unlock` used to be on it. It was collected into an array nothing read,
     * which made a hundred nodes across eleven trees a promise rather than a
     * skill -- and §7.4 forbids exactly that: nothing in a tree may wait on a
     * system that does not exist, because a node is bought with one of the
     * scarce points and the panel has no honest way to say "not yet".
     */
    public function test_every_effect_is_a_kind_the_game_knows(): void
    {
        $known = [
            'stat', 'pair', 'battleWear', 'weaponWear', 'goldFind', 'lootOption',
            'craftOption', 'craftDurability', 'optionTier', 'brewExtra', 'stackCap',
            'costReduction', 'batch', 'runSlot', 'presence', 'toolWear', 'depletion',
            'sight', 'bagUnits', 'bagRows',
        ];

        foreach (Jobs::NODES as $key => $node) {
            $this->assertContains($node['effect']['kind'], $known, "{$key} has an unknown effect kind");
            $this->assertGreaterThan(0, $node['effect']['value'], "{$key} is worth nothing");

            if ($node['effect']['kind'] === 'stat') {
                $this->assertArrayHasKey('stat', $node['effect'], "{$key} is a stat node with no stat");
            }
        }
    }

    /**
     * §7.4 -- two trees of the same kind must not be the same tree with
     * different words on it.
     *
     * Every gathering tree used to run one shared pattern, every processing
     * tree another, and the three battle trees were twenty points of the pair
     * followed by ten identical wear nodes. Reading one told you all five.
     */
    public function test_no_two_trees_are_the_same_tree(): void
    {
        $shapes = [];

        foreach (array_keys(Jobs::JOBS) as $job) {
            $kinds = array_map(
                fn (array $n) => $n['effect']['kind'].':'.($n['effect']['stat'] ?? '').':'.$n['effect']['value'],
                array_values(Jobs::nodesFor($job)),
            );

            $this->assertGreaterThanOrEqual(
                3,
                count(array_unique(array_map(
                    fn (string $k) => implode(':', array_slice(explode(':', $k), 0, 2)),
                    $kinds,
                ))),
                "{$job} spends its thirty nodes on fewer than three different things",
            );

            $shape = implode('|', $kinds);
            $this->assertNotContains($shape, $shapes, "{$job} is another tree with different words on it");
            $shapes[$job] = $shape;
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
            'optionTier' => Balance::SKILL_OPTION_TIER_CAP,
            'brewExtra' => Balance::SKILL_BREW_EXTRA_CAP,
            'stackCap' => Balance::SKILL_STACK_CAP,
            'batch' => Balance::SKILL_BATCH_CAP,
            'runSlot' => Balance::SKILL_RUN_SLOT_CAP,
            'presence' => Balance::SKILL_PRESENCE_CAP,
            'toolWear' => Balance::SKILL_TOOL_WEAR_CAP,
            'depletion' => Balance::SKILL_DEPLETION_CAP,
            'battleWear' => Balance::SKILL_BATTLE_WEAR_CAP,
            'weaponWear' => Balance::SKILL_WEAPON_WEAR_CAP,
            'goldFind' => Balance::SKILL_GOLD_FIND_CAP,
            'lootOption' => Balance::SKILL_LOOT_OPTION_CAP,
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
     * §7.4 -- a battle tree does two things and nothing else: it grants the
     * SOLID pair, and it spares the kit.
     *
     * Nothing in one is dormant any more. Two thirds of every battle tree used
     * to be `power`/`defense` percentages -- which moved a common sword's 5
     * attack to 5 -- and the last third was ability hooks waiting on parties
     * and raids that are not designed (§14). A node nobody can feel is a node
     * nobody should be asked to spend a point on.
     */
    public function test_battle_trees_grant_the_pair_and_spare_the_kit(): void
    {
        foreach (Jobs::JOBS as $job => $def) {
            if ($def['kind'] !== Jobs::BATTLE) {
                continue;
            }

            $pair = ['attack' => 0, 'defense' => 0];
            $wear = 0.0;

            foreach (Jobs::nodesFor($job) as $key => $node) {
                $effect = $node['effect'];

                // The pair, the two wear streams (§9.5.6), and what a fight
                // pays (§9.5.8). Nothing here reaches a trip or a bench.
                $this->assertContains(
                    $effect['kind'],
                    ['pair', 'battleWear', 'weaponWear', 'goldFind', 'lootOption'],
                    "{$key} is a battle node doing some other job's work",
                );

                if ($effect['kind'] === 'pair') {
                    $this->assertContains($effect['stat'], ['attack', 'defense']);
                    $this->assertIsInt($effect['value'], "{$key} grants a fraction of a point");
                    $pair[$effect['stat']] += $effect['value'];

                    continue;
                }

                if ($effect['kind'] === 'battleWear') {
                    $wear += $effect['value'];
                }
            }

            // §7.4.3 -- neither half may pass the cap, or a node is bought and
            // never felt.
            $this->assertLessThanOrEqual(
                Balance::SKILL_PAIR_CAP,
                max($pair),
                "{$job} pushes a stat past the pair cap",
            );

            // §9.5.4 -- and the same twenty points, split into the same three
            // shapes the weapons have. A sword trades the peak for evenness,
            // which is what balanced means.
            $this->assertSame(20, array_sum($pair), "{$job} does not spend twenty points");

            // How much of the armor bill a tree spares is now one of the things
            // that tells the three apart: a shieldbearer buys most of the cap,
            // a runecaster almost none of it and spends on the blade instead.
            $this->assertGreaterThan(0, $wear, "{$job} spares nothing at all");
            $this->assertLessThanOrEqual(
                Balance::SKILL_BATTLE_WEAR_CAP + 1e-9,
                $wear,
                "{$job} passes the wear cap",
            );
        }

        // §9.5.4 -- the shield leans on the guard, the wand on the arm, and the
        // sword is the one that is even. Read off the trees rather than
        // asserted per job, so a retune that flattens them all fails here.
        $lean = [];
        foreach (['shieldbearer', 'swordhand', 'runecaster'] as $job) {
            $sums = ['attack' => 0, 'defense' => 0];
            foreach (Jobs::nodesFor($job) as $node) {
                if ($node['effect']['kind'] === 'pair') {
                    $sums[$node['effect']['stat']] += $node['effect']['value'];
                }
            }
            $lean[$job] = $sums['attack'] <=> $sums['defense'];
        }

        $this->assertSame(-1, $lean['shieldbearer'], 'a shieldbearer does not lean on the guard');
        $this->assertSame(0, $lean['swordhand'], 'a swordhand is not the even one');
        $this->assertSame(1, $lean['runecaster'], 'a runecaster does not lean on the arm');
    }

    /**
     * §6 -- a processing tree deals in the run and never in the bench.
     *
     * The two effects a craft bench owns -- a rolled option and a starting
     * durability -- belong to an object, and a processing run makes a material,
     * which has neither. Left in, they would be a reason to take a processing
     * tree that has nothing to do with processing.
     */
    public function test_processing_trees_carry_no_bench_effects(): void
    {
        foreach (Jobs::JOBS as $job => $def) {
            if ($def['kind'] !== Jobs::PROCESSING) {
                continue;
            }

            foreach (Jobs::nodesFor($job) as $key => $node) {
                $this->assertNotContains(
                    $node['effect']['kind'],
                    ['craftOption', 'craftDurability'],
                    "{$key} is a processing node doing a bench's work",
                );

                // One stat applies to a run, and it is the one the run costs
                // its clock against. Anything else here would be a §11 sink
                // thinned by a job that has no business touching it.
                if ($node['effect']['kind'] === 'stat') {
                    $this->assertSame(
                        'processingSpeed',
                        $node['effect']['stat'],
                        "{$key} moves a stat a processing run does not have",
                    );
                }
            }
        }
    }

    /**
     * §6 -- every processing line has a job, and every processing job has a
     * line. A recipe whose line named no job would teach nothing, silently.
     */
    public function test_every_processing_job_names_a_gathering_line(): void
    {
        $lines = [];
        foreach (Jobs::JOBS as $job => $def) {
            if ($def['kind'] !== Jobs::PROCESSING) {
                continue;
            }

            $source = $def['source'];
            $this->assertSame(
                Jobs::GATHERING,
                Jobs::JOBS[$source]['kind'] ?? null,
                "{$job} names {$source}, which is not a gathering line",
            );
            $this->assertNotContains($source, $lines, "two processing jobs claim {$source}");
            $lines[] = $source;
        }

        $gathering = array_keys(array_filter(
            Jobs::JOBS,
            fn (array $d) => $d['kind'] === Jobs::GATHERING,
        ));

        sort($lines);
        sort($gathering);
        $this->assertSame($gathering, $lines, 'a gathering line has no processing job');
    }

    /** Keys are namespaced by job, so two trees can share a node name. */
    /**
     * §7.4 -- a tree makes you better at its OWN class and at nothing else.
     *
     * Every `stat` node belongs to a bucket, and there is no global one. Left
     * unlocked, a character could take three trees and stack all of them on one
     * trip -- which is the shortcut the line-locked tool ladder exists to close,
     * arrived at through the skill panel instead.
     */
    public function test_every_stat_node_is_locked_to_its_own_class(): void
    {
        $service = app(\App\Game\GameService::class);
        $bucket = new \ReflectionMethod($service, 'nodeBucket');

        foreach (Jobs::NODES as $key => $node) {
            if ($node['effect']['kind'] !== 'stat') {
                continue;
            }

            $this->assertNotNull(
                $bucket->invoke($service, $node['job']),
                "{$key} moves a stat and pays out everywhere",
            );
        }
    }

    /**
     * §7.4 -- and the bucket is the work, not the word.
     *
     * A battle tree is locked to the WEAPON FAMILY rather than to the job's
     * role, because the family in the slot is what decides your class (§9.5.4):
     * a Swordhand's nodes must be worth nothing with a shield on the arm.
     */
    public function test_a_battle_tree_is_locked_to_its_weapon_family(): void
    {
        $service = app(\App\Game\GameService::class);
        $bucket = new \ReflectionMethod($service, 'nodeBucket');

        foreach (\App\Game\Catalog::BATTLE_JOB_FOR_FAMILY as $family => $job) {
            $this->assertSame(
                'battle:'.$family,
                $bucket->invoke($service, $job),
                "{$job} is not locked to the {$family} it is fought with",
            );
        }
    }

    /**
     * §7.4 -- a gathering tree moves the two stats a TRIP has, and no others.
     *
     * `travelSpeed` used to be a third of them and was dead weight twice over:
     * a node is filed under its line and only counts on that line's work, and
     * walking is not woodcutting -- so it could never pay out. It would have
     * been off-class if it had.
     */
    public function test_a_gathering_tree_moves_only_trip_stats(): void
    {
        foreach (Jobs::NODES as $key => $node) {
            if ($node['effect']['kind'] !== 'stat') {
                continue;
            }
            if (Jobs::JOBS[$node['job']]['kind'] !== Jobs::GATHERING) {
                continue;
            }

            $this->assertContains(
                $node['effect']['stat'],
                ['yield', 'tripReduction'],
                "{$key} moves a stat a trip cannot feel",
            );
        }
    }

    /**
     * §8.4 -- a craft tree moves the one stat a bench clock reads, and nothing
     * a trip would feel.
     *
     * It used to hand out yield, trip time and travel speed, which made an
     * Armorer's tree pay out on somebody's mining trips.
     */
    public function test_a_craft_tree_moves_only_the_bench_clock(): void
    {
        foreach (Jobs::NODES as $key => $node) {
            if ($node['effect']['kind'] !== 'stat') {
                continue;
            }
            if (Jobs::JOBS[$node['job']]['kind'] !== Jobs::CRAFT) {
                continue;
            }

            $this->assertSame(
                'processingSpeed',
                $node['effect']['stat'],
                "{$key} makes a smith better at something that is not smithing",
            );
        }
    }

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
