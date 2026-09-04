<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Game\Jobs;
use App\Game\Skills;
use PHPUnit\Framework\TestCase;

/**
 * §7.4 -- the ladder is a faithful view of the node table, and nothing else.
 *
 * The whole safety of collapsing 495 nodes into 95 skills rests on one claim:
 * a rank IS a node, so the effects, the level gates and the caps are the table
 * they always were and the balance has not moved. These are the assertions that
 * make that claim checkable rather than asserted in a comment.
 */
final class SkillLadderTest extends TestCase
{
    /** Every node is a rank of exactly one skill, and no rank invents a node. */
    public function test_the_ladder_covers_every_node_exactly_once(): void
    {
        $seen = [];

        foreach (Skills::ALL as $key => $skill) {
            foreach ($skill['ranks'] as $rank) {
                $this->assertArrayHasKey($rank['node'], Jobs::NODES, "{$key} names a node that is gone");
                $this->assertArrayNotHasKey($rank['node'], $seen, "{$rank['node']} is a rank of two skills");
                $seen[$rank['node']] = $key;
            }
        }

        $this->assertCount(
            count(Jobs::NODES),
            $seen,
            'a node fell out of the ladder, so a point somewhere buys nothing',
        );
    }

    /** A rank carries its node's own level, and a ladder climbs. */
    public function test_a_rank_carries_its_nodes_level_and_the_ladder_climbs(): void
    {
        foreach (Skills::ALL as $key => $skill) {
            $last = 0;

            foreach ($skill['ranks'] as $i => $rank) {
                $node = Jobs::NODES[$rank['node']];

                $this->assertSame($node['jobLevel'], $rank['level'], "{$key} rank " . ($i + 1) . ' moved its gate');
                $this->assertGreaterThanOrEqual($last, $rank['level'], "{$key} does not climb");
                $last = $rank['level'];
            }
        }
    }

    /** Every rank of a skill belongs to the skill's own job and kind. */
    public function test_a_skill_is_one_job_and_one_kind(): void
    {
        foreach (Skills::ALL as $key => $skill) {
            $this->assertArrayHasKey($skill['job'], Jobs::JOBS, "{$key} names no job");

            foreach ($skill['ranks'] as $rank) {
                $node = Jobs::NODES[$rank['node']];
                $this->assertSame($skill['job'], $node['job'], "{$key} mixes two jobs");
                $this->assertSame($skill['kind'], $node['effect']['kind'], "{$key} mixes two kinds");
            }
        }
    }

    /**
     * §9.5.9 -- a battle skill is one rank, because owning it IS the effect.
     *
     * Three of them are three entries and never three ranks of one: they are
     * different tricks, and a rank ladder would say Sunder is a better
     * Onslaught.
     */
    public function test_a_battle_skill_is_a_one_rank_skill(): void
    {
        $found = 0;

        foreach (Skills::ALL as $key => $skill) {
            if ($skill['kind'] !== 'battleSkill') {
                continue;
            }

            $found++;
            $this->assertCount(1, $skill['ranks'], "{$key} has more than one rank");
        }

        // Three families of three (§9.5.9), and the roster is not allowed to
        // shrink quietly.
        $this->assertSame(9, $found, 'the battle skills are not all here');
    }

    /** nodesUpTo() is a prefix of the ladder, which is what makes a rank cumulative. */
    public function test_holding_a_rank_is_holding_every_rank_under_it(): void
    {
        foreach (Skills::ALL as $key => $skill) {
            $all = array_column($skill['ranks'], 'node');

            for ($rank = 0; $rank <= count($all); $rank++) {
                $this->assertSame(
                    array_slice($all, 0, $rank),
                    Skills::nodesUpTo($key, $rank),
                    "{$key} at rank {$rank} is not a prefix of its own ladder",
                );
            }
        }
    }

    /** Past the top there is no rank, which is what refuses a purchase. */
    public function test_there_is_no_level_past_the_last_rank(): void
    {
        foreach (Skills::ALL as $key => $skill) {
            $top = count($skill['ranks']);

            $this->assertNotNull(Skills::levelForRank($key, $top));
            $this->assertNull(Skills::levelForRank($key, $top + 1), "{$key} has a rank past its last");
        }
    }

    /**
     * §7.4 -- the point of the exercise, pinned as a number.
     *
     * Explorer is the example that made the argument: fifteen nodes, two
     * skills. If a future edit splits Straps back into eleven named nodes this
     * fails, which is the whole reason it is here.
     */
    public function test_the_collapse_actually_collapsed(): void
    {
        $this->assertCount(2, Skills::forJob('explorer'), 'Explorer is not two skills');
        $this->assertCount(4, Skills::forJob('woodcutting'));

        $this->assertLessThan(
            count(Jobs::NODES) / 4,
            count(Skills::ALL),
            'the ladder is barely shorter than the node table it replaced',
        );
    }
}
