<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Game\Balance;
use App\Game\Catalog;
use App\Game\Hash;
use App\Game\WorldGen;
use Tests\TestCase;

/**
 * Pins world generation to a golden fixture.
 *
 * The map is 25 million tiles and is never stored -- it is derived from
 * (col, row, seed) on demand. That makes generation effectively permanent: an
 * accidental change does not corrupt a table, it silently rewrites the world
 * under every character who already lives in it. Depleted tiles regrow into a
 * different biome, settlements move, and nothing in the database looks wrong.
 *
 * So the output is frozen. Regenerate deliberately, and read the diff:
 *
 *   php artisan game:worldgen-fixture
 *
 * The hash fixtures below come from the JavaScript reference implementation and
 * document why Hash.php is full of explicit 32-bit masking.
 */
final class WorldParityTest extends TestCase
{
    /**
     * Captured from JavaScript's Math.imul / >>> semantics, which is what the
     * PHP port has to reproduce bit for bit.
     */
    public function test_hash_matches_javascript(): void
    {
        $seed = 0x5EED1A3F;

        $expected = [
            '0,0' => 2588526122,
            '1,0' => 2332994760,
            '1316,550' => 2932877162,
            '2500,2500' => 1525831456,
            '-3,7' => 2611041978,
            '4999,4999' => 2763728160,
            '123456,-98765' => 1958525429,
            '-1,-1' => 1786882640,
        ];

        foreach ($expected as $coord => $hash) {
            [$x, $y] = array_map('intval', explode(',', $coord));
            $this->assertSame($hash, Hash::hash2($x, $y, $seed), "hash2({$coord})");
        }
    }

    public function test_generated_tiles_match_the_typescript_fixture(): void
    {
        $fixture = __DIR__.'/../Fixtures/worldgen.txt';
        $this->assertFileExists($fixture, 'Regenerate with: php artisan game:worldgen-fixture');

        $lines = array_values(array_filter(
            array_map('trim', file($fixture, FILE_IGNORE_NEW_LINES)),
            fn (string $l) => $l !== '',
        ));

        $this->assertNotEmpty($lines);

        foreach ($lines as $line) {
            [$coord, $biome, $variant, $ring, $material, $dead, $baseSeconds, $baseYield, $extractions, $settlement, $dungeon, $water, $pack, $hunt, $pocket, $propSeed]
                = explode('|', $line);

            [$col, $row] = array_map('intval', explode(',', $coord));
            $tile = WorldGen::generateTile($col, $row, 0);

            $s = $tile['settlement'];
            $actual = implode('|', [
                $coord,
                $tile['biome'],
                $tile['variant'],
                $tile['ring'],
                $tile['material'] ?? '-',
                $tile['dead'] ? 'dead' : '-',
                $tile['hp'],
                $tile['baseYield'],
                $tile['extractions'],
                $s ? $s['name'].':'.$s['tier'].':'.implode(',', $s['lines']) : '-',
                $tile['dungeon'] ? $tile['dungeon']['key'] : '-',
                $tile['water'] ?? '-',
                $tile['pack'] ? $tile['pack']['key'].':'.$tile['pack']['bucket'] : '-',
                // §5.5 -- the animal, pinned for the same reason as the pack: it
                // is always there on two biomes, so a drift here is a drift on
                // half the workable map.
                $tile['hunt'] ? $tile['hunt']['key'].':'.$tile['hunt']['bucket'] : '-',
                // §5.7 -- rich ground, pinned like the pack: a drift takes half
                // again on the haul away from a hex somebody is standing on.
                $tile['pocketUntil'] ?? '-',
                $tile['propSeed'],
            ]);

            $this->assertSame($line, $actual, "tile {$coord} diverged from the TypeScript generator");

            // Silence unused-variable noise while keeping the destructure
            // readable as documentation of the fixture format.
            unset($biome, $variant, $ring, $material, $dead, $baseSeconds, $baseYield, $extractions, $settlement, $dungeon, $water, $pack, $hunt, $pocket, $propSeed);
        }
    }

    /**
     * §5.3 -- the shoreline, hex by hex, against the TypeScript generator.
     *
     * The scattered sample above catches a handful of water tiles out of 244.
     * What can actually diverge here is a boundary test landing a hair either
     * side of an edge, and that only shows up where there are edges -- so this
     * walks a dense box, which has thousands of them.
     */
    public function test_the_water_chart_matches_the_typescript_generator(): void
    {
        $fixture = __DIR__.'/../Fixtures/water.txt';
        $this->assertFileExists($fixture, 'Regenerate with: php artisan game:worldgen-fixture');

        $lines = file($fixture, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        [$from] = explode(' .. ', array_shift($lines));
        [$colMin, $rowMin] = array_map('intval', explode(',', $from));

        $this->assertNotEmpty($lines);

        $lake = 0;
        $river = 0;

        foreach ($lines as $r => $expected) {
            $actual = '';
            for ($c = 0; $c < strlen($expected); $c++) {
                $kind = WorldGen::waterAt($colMin + $c, $rowMin + $r);
                $actual .= match ($kind) {
                    'lake' => 'O',
                    'river' => '~',
                    default => '.',
                };
            }

            $this->assertSame($expected, $actual, 'water row '.($rowMin + $r).' moved');

            $lake += substr_count($actual, 'O');
            $river += substr_count($actual, '~');
        }

        // A chart with no water in it would pass every assertion above and
        // guard nothing at all.
        $this->assertGreaterThan(0, $lake, 'the charted box holds no lake');
        $this->assertGreaterThan(0, $river, 'the charted box holds no waterway');
    }

    /** §5.3 -- water is never workable, and never has anything to work. */
    public function test_water_carries_no_material(): void
    {
        $radius = Balance::mapRadius();
        $now = 0;
        $seen = 0;

        for ($col = -$radius; $col <= $radius && $seen < 60; $col += 3) {
            for ($row = -$radius; $row <= $radius && $seen < 60; $row += 3) {
                if (WorldGen::waterAt($col, $row) === null) {
                    continue;
                }

                $tile = WorldGen::generateTile($col, $row, $now);

                // A settlement or a dungeon mouth wins the hex outright, so
                // those are the tiles where waterAt() and the tile disagree.
                if ($tile['settlement'] !== null || $tile['dungeon'] !== null) {
                    continue;
                }

                $seen++;
                $this->assertNotNull($tile['water'], "water at {$col},{$row} vanished from the tile");
                $this->assertNull($tile['material'], "water at {$col},{$row} carries a material");
            }
        }

        $this->assertGreaterThan(0, $seen, 'the sample found no water at all');
    }

    /** §9.1 -- exactly five dungeons, one per biome, all in the capital ring. */
    /**
     * §5.2 -- each ring carries the share of workable ground it is meant to.
     *
     * Balance::MINEABLE_SHARE is the design number, in the units it was decided
     * in; Balance::BARREN_THRESHOLD is where the field has to be cut to produce
     * it. Nothing derives one from the other -- the water, the towns and the
     * five dungeon mouths take their own bite of the same ground, and the field
     * is smooth rather than uniform -- so the thresholds are CALIBRATED by
     * scripts/calibrate_barren.php and this is what keeps them honest.
     *
     * It fails when a share is edited without recalibrating, when the map seed
     * moves, or when anything else starts competing for the same hexes. The fix
     * is to re-run the calibrator and paste, never to widen the tolerance.
     *
     * Sampled on a stride rather than tile by tile: a quarter of 160,801 hexes
     * is forty thousand, which pins a percentage far tighter than one point.
     */
    public function test_every_ring_carries_the_share_of_workable_ground_it_promises(): void
    {
        $radius = Balance::mapRadius();
        $total = [];
        $seams = [];

        for ($col = -$radius; $col <= $radius; $col += 2) {
            for ($row = -$radius; $row <= $radius; $row += 2) {
                $tile = WorldGen::generateTile($col, $row, 0);
                $ring = $tile['ring'];
                $total[$ring] = ($total[$ring] ?? 0) + 1;
                if ($tile['material'] !== null) {
                    $seams[$ring] = ($seams[$ring] ?? 0) + 1;
                }
            }
        }

        foreach (Balance::MINEABLE_SHARE as $ring => $share) {
            $this->assertArrayHasKey($ring, $total, "no {$ring} ring tiles were sampled");

            $actual = ($seams[$ring] ?? 0) / $total[$ring];

            $this->assertEqualsWithDelta(
                $share,
                $actual,
                0.015,
                sprintf(
                    'the %s ring is %.1f%% workable, not %.1f%% -- re-run scripts/calibrate_barren.php',
                    $ring,
                    $actual * 100,
                    $share * 100,
                ),
            );
        }
    }

    /**
     * §2 / §4 -- Tier 3 is contested ground and nowhere else, ever.
     *
     * The two middle grades leak onto the rings outside their own at a few per
     * cent, so that a recipe wanting one is cookable at the moment it would
     * actually be an upgrade rather than only where it is already outclassed.
     * The epic row is the one that may not: it is §4's Tier 3, capped per
     * wallet, and the gate behind every mintable recipe. A lucky Tier 3 on the
     * safe rim would be the grind->NFT path §2 exists to close, and it would
     * arrive as a tuning tweak nobody read as one.
     *
     * Swept over the real map rather than over the weight table, because the
     * table is one of two places this could go wrong and the roll is the other.
     */
    public function test_tier_three_never_spawns_outside_the_contested_ring(): void
    {
        $radius = Balance::mapRadius();
        $seen = 0;

        for ($col = -$radius; $col <= $radius; $col++) {
            for ($row = -$radius; $row <= $radius; $row++) {
                $tile = WorldGen::generateTile($col, $row, 0);
                if ($tile['material'] === null) {
                    continue;
                }

                if ((Catalog::material($tile['material'])['tier'] ?? 0) !== 3) {
                    continue;
                }

                $seen++;
                $this->assertContains(
                    $tile['ring'],
                    ['inner', 'center'],
                    "Tier 3 turned up at {$col},{$row} in the {$tile['ring']} ring",
                );
            }
        }

        $this->assertGreaterThan(500, $seen, 'the sweep found almost no Tier 3');
    }

    /**
     * §5.2 -- and every grade below it IS findable on the rim, thinly.
     *
     * The point of the leak: a grade sealed inside the ring that already
     * outclasses it is a recipe nobody ever cooks. Thin enough to be luck --
     * about one hex in fifty for an uncommon, one in two hundred for a rare --
     * and never thin enough to be nothing.
     */
    public function test_the_middle_grades_turn_up_on_the_rim_as_a_lucky_find(): void
    {
        $radius = Balance::mapRadius();
        $tiers = [];
        $rim = 0;

        for ($col = -$radius; $col <= $radius; $col += 2) {
            for ($row = -$radius; $row <= $radius; $row += 2) {
                $tile = WorldGen::generateTile($col, $row, 0);
                if ($tile['material'] === null || $tile['ring'] !== 'outer') {
                    continue;
                }

                $rim++;
                $grade = str_contains((string) $tile['variant'], '_')
                    ? substr((string) $tile['variant'], strrpos((string) $tile['variant'], '_') + 1)
                    : 'common';
                $tiers[$grade] = ($tiers[$grade] ?? 0) + 1;
            }
        }

        $this->assertGreaterThan(0, $rim);

        foreach (['uncommon', 'rare'] as $grade) {
            $share = ($tiers[$grade] ?? 0) / $rim;

            $this->assertGreaterThan(0, $share, "{$grade} ground is sealed out of the rim");
            $this->assertLessThan(
                0.05,
                $share,
                "{$grade} is {$share} of the rim -- that is a supply, not a lucky find",
            );
        }
    }

    /**
     * §5.2 -- dead ground wears its biome's own colour, and that is the rule.
     *
     * It carries a flag rather than a variant of its own, so a hex with no seam
     * in it draws exactly the fill a living hex of that biome draws. §13.2 puts
     * props inside sight and nothing else out there, so the whole tell is what
     * STANDS on the hex -- which makes a waste invisible from across the map and
     * plain from one hex away.
     *
     * That is the difference between a map you read and a map you walk. A dead
     * variant with a tint of its own was tried first and it answered the
     * question for free.
     */
    public function test_dead_ground_is_not_a_variant_and_keeps_its_biome_colour(): void
    {
        $radius = Balance::mapRadius();
        $checked = 0;

        for ($col = -$radius; $col <= $radius; $col += 7) {
            for ($row = -$radius; $row <= $radius; $row += 7) {
                $tile = WorldGen::generateTile($col, $row, 0);
                if (! $tile['dead']) {
                    continue;
                }

                $checked++;

                // The biome's base variant, exactly as a living hex of the same
                // country would carry -- no key of its own to hang a tint on.
                $this->assertSame(
                    $tile['biome'],
                    $tile['variant'],
                    "dead ground at {$col},{$row} has stopped wearing its biome",
                );
                $this->assertNull($tile['material'], "dead ground at {$col},{$row} has a seam");
            }
        }

        $this->assertGreaterThan(100, $checked, 'the sample found almost no dead ground');
    }

    /**
     * §5.2 -- dead ground arrives in regions, not as speckle.
     *
     * §5.3 wants a mentally navigable map and gets it by clustering the biomes;
     * half an outer ring of independently-rolled dead hexes would undo that at
     * a stroke. A clustered field shows up as neighbours agreeing far more
     * often than chance. At the outer ring's ~48% cut independent rolls would
     * agree p^2 + (1-p)^2 = about HALF the time; the clustered field agrees
     * roughly nine times in ten, the remainder being hexes on the edge of a
     * region. The bar sits between the two, well clear of both.
     */
    public function test_dead_ground_comes_in_regions_rather_than_speckle(): void
    {
        $agree = 0;
        $pairs = 0;

        for ($col = -60; $col <= 60; $col++) {
            for ($row = -60; $row <= 60; $row++) {
                $here = WorldGen::isBarren($col, $row, 'outer');
                foreach ([[1, 0], [0, 1]] as [$dc, $dr]) {
                    $pairs++;
                    if ($here === WorldGen::isBarren($col + $dc, $row + $dr, 'outer')) {
                        $agree++;
                    }
                }
            }
        }

        $this->assertGreaterThan(
            0.8,
            $agree / $pairs,
            'neighbouring hexes disagree too often -- the field has stopped clustering',
        );
    }

    /**
     * §5.2 -- the center is ordinary contested ground, not a hole in the map.
     *
     * It was barren of everything while the dungeon mouths were the only thing
     * standing there. Now it rolls on the inner ring's own table, so it carries
     * the same grades at the same rate -- including Tier 3 -- and it pays the
     * contested premium rather than the 0.0 a barren ring paid.
     */
    public function test_the_center_is_contested_ground_and_pays_like_it(): void
    {
        $this->assertSame(
            WorldGen::ringYield('inner'),
            WorldGen::ringYield('center'),
            'the center pays a different premium from the ring it belongs to',
        );

        $radius = Balance::mapRadius();
        $seams = 0;
        $rares = 0;

        for ($col = -$radius; $col <= $radius; $col++) {
            for ($row = -$radius; $row <= $radius; $row++) {
                if (WorldGen::ringOf($col, $row) !== 'center') {
                    continue;
                }

                $tile = WorldGen::generateTile($col, $row, 0);
                if ($tile['material'] === null) {
                    continue;
                }

                $seams++;
                if (str_ends_with((string) $tile['variant'], '_epic')) {
                    $rares++;
                }
            }
        }

        $this->assertGreaterThan(0, $seams, 'the center gave up no material at all');
        $this->assertGreaterThan(
            0,
            $rares,
            'the center carries no Tier 3, so it is not rolling on the inner table',
        );
    }

    public function test_there_are_exactly_five_dungeons(): void
    {
        $sites = WorldGen::dungeonSites();

        $this->assertCount(5, $sites);
        foreach ($sites as $site) {
            $this->assertSame('center', WorldGen::ringOf($site['col'], $site['row']));
        }
        $this->assertCount(5, array_unique(array_column(array_column($sites, 'dungeon'), 'biome')));
    }
}
