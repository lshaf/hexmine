<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Game\Balance;
use App\Game\Monsters;
use PHPUnit\Framework\TestCase;

/**
 * §9.5.2 -- a monster's level, and the ladder it is quoted on.
 */
final class MonsterLevelTest extends TestCase
{
    /**
     * The whole point of the number is that it is comparable to a character's
     * own, and it is comparable only because Balance::EQUIP_LEVEL makes a level
     * bound the rung you may wear. The generator carries its own copy of that
     * table; if the two drift, every monster is quoted on a scale nothing else
     * uses and nobody finds out.
     */
    public function test_the_bands_are_the_equipment_ladder(): void
    {
        // §9.5.4's measured ladder: common answers tier 1, rare answers tiers
        // 1-3, epic and legendary answer the center.
        foreach ([1 => 'common', 2 => 'uncommon', 3 => 'rare', 4 => 'epic'] as $tier => $rarity) {
            $peers = array_filter(Monsters::ROSTER, static fn (array $m) => $m['tier'] === $tier);

            $this->assertSame(
                Balance::equipLevel($rarity),
                min(array_column($peers, 'level')),
                "tier {$tier} does not start on the {$rarity} rung",
            );
        }
    }

    /**
     * A tier never overlaps the one above it. Half the band is spent inside a
     * tier for exactly this reason: what separates a tier-2 from a tier-3 must
     * never be smaller than what separates two tier-2s, or the number stops
     * meaning "how hard" and starts meaning "which profile".
     */
    public function test_the_tiers_never_overlap(): void
    {
        $byTier = [];
        foreach (Monsters::ROSTER as $m) {
            $byTier[$m['tier']][] = $m['level'];
        }

        ksort($byTier);
        $ceiling = 0;
        foreach ($byTier as $tier => $levels) {
            $this->assertGreaterThan($ceiling, min($levels), "tier {$tier} reaches under the tier below");
            $ceiling = max($levels);
        }
    }

    /**
     * And within a tier the harder fight reads higher. The level is derived
     * from attack, guard and staying power, so a brute must never come out
     * under a carapace of its own tier -- that would make the number decorative.
     */
    public function test_a_harder_peer_reads_higher(): void
    {
        foreach (Monsters::ROSTER as $key => $m) {
            foreach (Monsters::ROSTER as $other => $n) {
                if ($m['tier'] !== $n['tier']) {
                    continue;
                }

                $harder = ($m['attack'] + $m['defense'] + $m['hp'] / 3)
                    <=> ($n['attack'] + $n['defense'] + $n['hp'] / 3);

                $this->assertSame(
                    $harder,
                    $m['level'] <=> $n['level'],
                    "{$key} and {$other} rank differently by threat than by level",
                );
            }
        }
    }

    /** Every monster carries one, because the pin draws it for whatever is out. */
    public function test_every_monster_has_a_level(): void
    {
        foreach (Monsters::ROSTER as $key => $m) {
            $this->assertArrayHasKey('level', $m, "{$key} has no level");
            $this->assertGreaterThanOrEqual(1, $m['level']);
        }
    }
}
