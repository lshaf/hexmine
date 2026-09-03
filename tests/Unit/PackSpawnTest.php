<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Game\Balance;
use App\Game\Monsters;
use App\Game\Packs;
use App\Game\WorldGen;
use Tests\TestCase;

/**
 * §9.5.1 -- where packs stand, and the three rules that keep them honest.
 *
 * Spawn is a hash, so nothing about it is stored and nothing about it can be
 * checked by reading a table. The fixture pins the exact answers (WorldParity);
 * this pins the SHAPE -- the rates per ring, the exclusions, and the stagger --
 * which is what a tuning change is allowed to move and a bug is not.
 */
final class PackSpawnTest extends TestCase
{
    /** Every other hex across the whole map: enough to measure a 2% ring. */
    private function sample(): array
    {
        $radius = Balance::mapRadius();
        $out = [];

        for ($col = -$radius; $col <= $radius; $col += 2) {
            for ($row = -$radius; $row <= $radius; $row += 2) {
                $out[] = WorldGen::generateTile($col, $row, 0);
            }
        }

        return $out;
    }

    /**
     * §9.5.1 -- the outer ring is nearly safe and the center is the worst of
     * it. That gradient is the whole reason the road inward means anything.
     */
    public function test_pack_density_climbs_ring_by_ring(): void
    {
        $tiles = [];
        $packs = [];

        foreach ($this->sample() as $tile) {
            $ring = $tile['ring'];
            $tiles[$ring] = ($tiles[$ring] ?? 0) + 1;
            if ($tile['pack'] !== null) {
                $packs[$ring] = ($packs[$ring] ?? 0) + 1;
            }
        }

        $rates = [];
        foreach (Balance::PACK_CHANCE as $ring => $target) {
            $this->assertGreaterThan(100, $tiles[$ring] ?? 0, "too few {$ring} hexes to measure");

            $rate = ($packs[$ring] ?? 0) / $tiles[$ring];
            $rates[$ring] = $rate;

            // Generous: settlements, water and dungeon mouths are excluded, so
            // the measured rate sits a little under its target by construction.
            $this->assertEqualsWithDelta(
                $target,
                $rate,
                $target * 0.35,
                "{$ring} packs are nowhere near their chance",
            );
        }

        $this->assertTrue(
            $rates['outer'] < $rates['mid']
                && $rates['mid'] < $rates['inner']
                && $rates['inner'] < $rates['center'],
            'the ring gradient is not monotonic: '.json_encode($rates),
        );
    }

    /**
     * §9.5.1 -- a pack on a capital would lock a whole region out of the only
     * five-line bench it has, which is grief rather than hazard. Water and
     * dungeon mouths are excluded for the same reason nothing else stands on a
     * lake: the thing placed there wins.
     */
    public function test_nothing_camps_on_a_settlement_a_mouth_or_open_water(): void
    {
        $checked = ['settlement' => 0, 'dungeon' => 0, 'water' => 0];

        foreach ($this->sample() as $tile) {
            foreach (array_keys($checked) as $kind) {
                if (($tile[$kind] ?? null) === null) {
                    continue;
                }

                $checked[$kind]++;
                $this->assertNull(
                    $tile['pack'],
                    "a pack is standing on a {$kind} at {$tile['col']},{$tile['row']}",
                );
            }
        }

        foreach ($checked as $kind => $seen) {
            $this->assertGreaterThan(0, $seen, "the sample holds no {$kind} to test");
        }
    }

    /**
     * §9.5.1 -- the per-hex offset. Without it every pack in the world appears
     * and vanishes on one heartbeat, which is a rhythm players set a watch by.
     */
    public function test_packs_do_not_all_expire_at_the_same_moment(): void
    {
        $untils = [];

        foreach ($this->sample() as $tile) {
            if ($tile['pack'] !== null) {
                $untils[] = $tile['pack']['until'];
            }
        }

        $this->assertGreaterThan(50, count($untils), 'too few packs to say anything');

        $lifetime = Balance::scaled(Balance::PACK_LIFETIME_MS);
        $distinct = count(array_unique($untils));

        $this->assertGreaterThan(
            count($untils) / 4,
            $distinct,
            'packs are expiring in lockstep, so the bucket offset is not doing its job',
        );

        // And none of them lives longer than one bucket.
        foreach ($untils as $until) {
            $this->assertGreaterThan(0, $until);
            $this->assertLessThanOrEqual($lifetime, $until);
        }
    }

    /**
     * §9.5.2 -- a pack is drawn from its own COUNTRY's pool, at its own ring.
     *
     * The biome decides which five you can meet at all and the ring decides
     * which of them are out. Both halves are asserted here, and the first is
     * the one biome-locking exists for: a Moss Hound on a badlands hex would
     * mean crossing the map changes nothing about what is on the road.
     */
    public function test_a_pack_is_drawn_from_its_own_biome_and_ring(): void
    {
        $seen = [];
        $byBiome = [];

        foreach ($this->sample() as $tile) {
            if ($tile['pack'] === null) {
                continue;
            }

            $key = $tile['pack']['key'];
            $biome = $tile['biome'];
            $seen[$tile['ring']][$key] = true;
            $byBiome[$biome][$key] = true;

            $this->assertContains(
                $key,
                Monsters::BY_BIOME_RING[$biome][$tile['ring']] ?? [],
                "{$key} turned up on {$biome}/{$tile['ring']}, which does not hold it",
            );

            // The roster's own answer has to agree with where it was found.
            $this->assertSame(
                $biome,
                Monsters::ROSTER[$key]['biome'],
                "{$key} stands on {$biome} and belongs to another country",
            );
        }

        // Every country turned up, and none of them is one creature repeated.
        foreach (array_keys(Monsters::BY_BIOME_RING) as $biome) {
            $this->assertGreaterThanOrEqual(
                3,
                count($byBiome[$biome] ?? []),
                "{$biome} barely fields a roster",
            );
        }

        // §9.5.2 -- and every ring still runs all three PROFILES across the
        // map, which is the rule that predates biomes: the read that wears a
        // weapon harder is met on the safe rim rather than for the first time
        // at tier 3.
        foreach (['outer', 'mid', 'inner', 'center'] as $ring) {
            $profiles = array_unique(array_map(
                static fn (string $key) => Monsters::ROSTER[$key]['profile'],
                array_keys($seen[$ring] ?? []),
            ));

            sort($profiles);
            $this->assertSame(
                ['brute', 'carapace', 'swift'],
                $profiles,
                "the {$ring} ring does not run all three profiles",
            );
        }
    }

    /**
     * §9.5.1 -- the one bit the hash cannot know. Clearing is shared: whoever
     * fought it removed it for everybody, which is the anti-farm rule.
     */
    public function test_a_cleared_pack_reports_itself_cleared(): void
    {
        $tile = null;
        foreach ($this->sample() as $candidate) {
            if ($candidate['pack'] !== null) {
                $tile = $candidate;
                break;
            }
        }

        $this->assertNotNull($tile, 'no pack anywhere on the map to clear');
        $pack = $tile['pack'];

        $one = [['col' => $tile['col'], 'row' => $tile['row'], 'bucket' => $pack['bucket']]];

        $this->assertSame([], Packs::clearedAmong($one), 'an unfought pack reported itself gone');

        Packs::clear($tile['col'], $tile['row'], $pack['bucket'], $pack['until'], 0);

        $this->assertTrue(Packs::isCleared($tile['col'], $tile['row'], $pack['bucket']));
        $this->assertSame([[$tile['col'], $tile['row']]], Packs::clearedAmong($one));

        // The next bucket is a different pack and a different key.
        $this->assertFalse(Packs::isCleared($tile['col'], $tile['row'], $pack['bucket'] + 1));
    }
}
