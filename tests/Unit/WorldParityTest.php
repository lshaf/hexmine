<?php

declare(strict_types=1);

namespace Tests\Unit;

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
        $seed = 0x5eed1a3f;

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
            [$coord, $biome, $ring, $material, $baseSeconds, $baseYield, $settlement, $dungeon, $propSeed]
                = explode('|', $line);

            [$col, $row] = array_map('intval', explode(',', $coord));
            $tile = WorldGen::generateTile($col, $row, 0);

            $s = $tile['settlement'];
            $actual = implode('|', [
                $coord,
                $tile['biome'],
                $tile['ring'],
                $tile['material'] ?? '-',
                $tile['baseSeconds'],
                $tile['baseYield'],
                $s ? $s['name'].':'.$s['tier'].':'.implode(',', $s['lines']) : '-',
                $tile['dungeon'] ? $tile['dungeon']['key'] : '-',
                $tile['propSeed'],
            ]);

            $this->assertSame($line, $actual, "tile {$coord} diverged from the TypeScript generator");

            // Silence unused-variable noise while keeping the destructure
            // readable as documentation of the fixture format.
            unset($biome, $ring, $material, $baseSeconds, $baseYield, $settlement, $dungeon, $propSeed);
        }
    }

    /** §9.1 -- exactly five dungeons, one per biome, all in the capital ring. */
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
