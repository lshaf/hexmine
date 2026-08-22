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
        // centre, and both far corners -- which are now opposite in sign (§5.1).
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
                $tile['propSeed'],
            ]);
        }

        file_put_contents($path, implode("\n", $lines)."\n");
        $this->info('Wrote '.count($lines).' tiles to tests/Fixtures/worldgen.txt');
        $this->info('Wrote tests/Fixtures/world-config.json');
        $this->comment('Review the diff: changed lines are terrain that moved under existing characters.');

        return self::SUCCESS;
    }
}
