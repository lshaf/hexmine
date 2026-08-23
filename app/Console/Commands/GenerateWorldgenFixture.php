<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Game\Balance;
use App\Game\GameService;
use App\Game\WorldGen;
use Illuminate\Console\Command;

/**
 * Regenerates the frozen world-generation fixture.
 *
 * Run this only when a generation change is deliberate, then read the diff:
 * every changed line is terrain that moved under characters already standing
 * on it. See tests/Unit/WorldParityTest.php.
 */
class GenerateWorldgenFixture extends Command
{
    protected $signature = 'game:worldgen-fixture';

    protected $description = 'Regenerate the frozen world-generation fixture';

    public function handle(): int
    {
        $path = base_path('tests/Fixtures/worldgen.txt');

        // The generation parameters the client is handed at boot. Frozen next to
        // the tiles so `npm run parity` can configure the TypeScript generator
        // exactly as the server would, without a running app.
        file_put_contents(
            base_path('tests/Fixtures/world-config.json'),
            json_encode(app(GameService::class)->worldConfig(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."
",
        );

        // Strides coprime with the map so 240 samples spread over the whole
        // sheet, and sized from Balance rather than pinned to one map: sampling
        // coordinates that fall off the map would leave the guard testing
        // nothing.
        $size = Balance::mapSize();
        $radius = Balance::mapRadius();

        $coords = [];
        for ($i = 0; $i < 240; $i++) {
            $coords[] = [($i * 977) % $size - $radius, ($i * 613 + 31) % $size - $radius];
        }

        // The four that have to be in every fixture: a dungeon mouth, the dead
        // center, and both far corners -- which are now opposite in sign (§5.1).
        $mouth = WorldGen::dungeonSites()[0];
        $coords[] = [$mouth['col'], $mouth['row']];
        $coords[] = [0, 0];
        $coords[] = [-$radius, -$radius];
        $coords[] = [$radius, $radius];

        $lines = [];
        foreach ($coords as [$col, $row]) {
            $tile = WorldGen::generateTile($col, $row, 0);
            $s = $tile['settlement'];

            $lines[] = implode('|', [
                "{$col},{$row}",
                $tile['biome'],
                $tile['variant'],
                $tile['ring'],
                $tile['material'] ?? '-',
                $tile['baseSeconds'],
                $tile['baseYield'],
                $s ? $s['name'].':'.$s['tier'].':'.implode(',', $s['lines']) : '-',
                $tile['dungeon'] ? $tile['dungeon']['key'] : '-',
                $tile['water'] ?? '-',
                // §9.5.1 -- at now = 0 the bucket is fixed, so a pack is as
                // pinnable as terrain. Both generators offset the bucket per
                // hex; a drift in that offset moves packs under characters who
                // are standing on one, so it belongs in the fixture.
                $tile['pack'] ? $tile['pack']['key'].':'.$tile['pack']['bucket'] : '-',
                $tile['propSeed'],
            ]);
        }

        file_put_contents($path, implode("\n", $lines)."\n");
        $this->info('Wrote '.count($lines).' tiles to tests/Fixtures/worldgen.txt');

        $this->writeWaterChart(dirname($path).'/water.txt');
        $this->info('Wrote tests/Fixtures/water.txt');
        $this->info('Wrote tests/Fixtures/world-config.json');
        $this->comment('Review the diff: changed lines are terrain that moved under existing characters.');

        return self::SUCCESS;
    }

    /**
     * §5.3 -- a picture of the water, one character per hex.
     *
     * The scattered sample above catches a handful of water tiles out of 244,
     * which is nowhere near enough to pin a shoreline: what breaks between the
     * two generators is a boundary test landing a hair either side of an edge,
     * and that only shows up where there are edges. A dense box has thousands
     * of them, costs eighty lines, and can be read by eye -- the river is
     * visible in the diff when it moves.
     */
    private function writeWaterChart(string $path): void
    {
        $radius = Balance::mapRadius();
        $colMin = max(-$radius, -60);
        $colMax = min($radius, 59);
        $rowMin = max(-$radius, -140);
        $rowMax = min($radius, -61);

        $rows = ["{$colMin},{$rowMin} .. {$colMax},{$rowMax}"];

        for ($row = $rowMin; $row <= $rowMax; $row++) {
            $line = '';
            for ($col = $colMin; $col <= $colMax; $col++) {
                $line .= match (WorldGen::waterAt($col, $row)) {
                    'lake' => 'O',
                    'river' => '~',
                    default => '.',
                };
            }
            $rows[] = $line;
        }

        file_put_contents($path, implode("\n", $rows)."\n");
    }
}
