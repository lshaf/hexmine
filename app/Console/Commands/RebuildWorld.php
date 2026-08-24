<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Game\Balance;
use App\Game\GameService;
use App\Game\WorldGen;
use App\Models\Character;
use App\Models\GameJob;
use App\Models\Player;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Put stored state back in step with the world the seed now describes.
 *
 * Nothing about the map is stored (§5), which is exactly why changing the seed
 * or the radius is cheap -- and exactly why the handful of rows that DO name a
 * coordinate go stale the moment it changes. Three kinds:
 *
 *   tile_states   depletion timers on hexes whose material has changed
 *   jobs_queue    trips pinned to a tile, and processing runs pinned to a
 *                 settlement that may no longer stand
 *   characters    standing somewhere that may now be off the map entirely
 *
 * Everything a player earned -- levels, skills, jobs, nodes, gold, bag, gear --
 * is untouched. This moves them, it does not reset them.
 *
 * Run it after any change to GAME_MAP_RADIUS or GAME_MAP_SEED, alongside
 * `game:worldgen-fixture` and `npm run parity`.
 */
class RebuildWorld extends Command
{
    protected $signature = 'game:rebuild-world
        {--dry-run : Report what would change and write nothing}
        {--all : Re-place every character, not only the stranded ones}';

    protected $description = 'Re-place characters and clear tile state after the map seed or radius changes';

    public function handle(GameService $game): int
    {
        $dry = (bool) $this->option('dry-run');
        $radius = Balance::mapRadius();

        $this->info(sprintf(
            'Map radius %d (%d a side), seed 0x%x.%s',
            $radius,
            Balance::mapSize(),
            Balance::mapSeed(),
            $dry ? ' DRY RUN -- nothing will be written.' : '',
        ));

        $jobs = GameJob::count();

        $moves = [];
        foreach (Character::with('player')->get() as $character) {
            $col = (int) $character->col;
            $row = (int) $character->row;

            $stranded = ! WorldGen::inBounds($col, $row);
            if (! $stranded && ! $this->option('all')) {
                continue;
            }

            $spawn = $game->pickSpawn($this->seedFor($character));
            $moves[] = [$character, $col, $row, $spawn, $stranded];
        }

        $this->table(
            ['character', 'from', 'to', 'why'],
            array_map(fn (array $m) => [
                $m[0]->id.' '.$m[0]->name,
                "{$m[1]},{$m[2]}",
                "{$m[3]['col']},{$m[3]['row']}",
                $m[4] ? 'off the map' : 'requested',
            ], $moves),
        );

        $this->line("jobs to abandon: {$jobs}");

        if ($dry) {
            $this->comment('Dry run. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        // §5.1 -- depletion is a fact about a material that is no longer there,
        // and it lives in the cache rather than a table now (Game\Tiles). There
        // is no key pattern to walk, so the whole store goes: everything in it
        // is derived world state with a clock on it, and the worst a flush costs
        // is a few seams and packs regrowing early.
        Cache::flush();
        $this->line('cleared the derived world cache: depletion and settled packs.');

        DB::transaction(function () use ($moves) {
            // A trip is pinned to a hex and a processing run to a settlement.
            // Both may now be somewhere else, or nowhere, so neither can be
            // collected honestly. Deleted, not flagged: that is what abandoning
            // a job already does (§11.1 forfeits the partial yield), and a
            // status nothing else writes would be a status nothing else reads.
            GameJob::query()->delete();

            foreach ($moves as [$character, , , $spawn]) {
                $character->update(['col' => $spawn['col'], 'row' => $spawn['row']]);
            }
        });

        $this->info(sprintf(
            'Cleared %d tile state(s), abandoned %d job(s), re-placed %d character(s).',
            $tiles,
            $jobs,
            count($moves),
        ));

        return self::SUCCESS;
    }

    /**
     * The same seed createCharacter() uses, so a character lands where it would
     * have landed had it been minted into this world in the first place.
     */
    private function seedFor(Character $character): int
    {
        $wallet = $character->player instanceof Player ? $character->player->wallet : null;

        return crc32($wallet ?? (string) $character->id);
    }
}
