<?php

declare(strict_types=1);

use App\Game\Balance;
use App\Game\Catalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * §8.0.1 -- point every stored line at something its own piece is for.
 *
 * The weapon slot used to draw from the worn pool, so swords came off the
 * bench carrying "+4% hunting yield" -- a line on the one slot in the game
 * that does no work at all (§8 rule 5). The pool is fixed; this is the gear
 * already in bags.
 *
 * Retargeted rather than dropped. The roll was the bench's fault, not the
 * owner's, and a line taken away is an idle game removing something a player
 * had. So each stray keeps its kind and its worth and is pointed at the piece's
 * own pool instead -- with the scoped premium given back, since a weapon has no
 * gathering line to scope to and a doubled band would otherwise stay doubled.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('character_items')
            ->whereNotNull('options')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $def = Catalog::item($row->item_key);
                    if ($def === null) {
                        continue;
                    }

                    $options = json_decode((string) $row->options, true);
                    if (! is_array($options) || $options === []) {
                        continue;
                    }

                    $fixed = $this->retarget($def, $options, (int) $row->id);
                    if ($fixed === $options) {
                        continue;
                    }

                    DB::table('character_items')
                        ->where('id', $row->id)
                        ->update(['options' => json_encode($fixed)]);
                }
            });
    }

    public function down(): void
    {
        // A line cannot be un-retargeted: what it used to say is not stored
        // anywhere, and inventing one would be worse than leaving it correct.
    }

    /**
     * @param  array<string,mixed>  $def
     * @param  array<int,array<string,mixed>>  $options
     * @return array<int,array<string,mixed>>
     */
    private function retarget(array $def, array $options, int $id): array
    {
        $pool = Catalog::optionRollsFor($def);
        $key = static fn (array $entry): string => ($entry['kind'] ?? 'percent')
            .'|'.($entry['stat'] ?? '')
            .'|'.($entry['scope'] ?? '');

        $legal = array_map($key, $pool);
        $taken = [];
        $out = [];

        foreach ($options as $i => $option) {
            if (in_array($key($option), $legal, true)) {
                $taken[] = $key($option);
                $out[] = $option;

                continue;
            }

            $kind = $option['kind'] ?? 'percent';

            // Same kind, or the value band it was rolled against means nothing.
            $choices = array_values(array_filter(
                $pool,
                static fn (array $entry) => ($entry['kind'] ?? 'percent') === $kind
                    && ! in_array($key($entry), $taken, true),
            ));

            if ($choices === []) {
                // Nothing left to point it at -- the piece already carries one
                // of everything its kind can be. Dropping is the only honest
                // answer, and it cannot happen with any pool in the catalog.
                continue;
            }

            $pick = $choices[abs(crc32($id.':'.$i)) % count($choices)];
            $taken[] = $key($pick);

            $line = ['stat' => $pick['stat']];
            $value = (float) ($option['value'] ?? 0);

            // The scoped premium is paid for by naming a line. An unscoped
            // replacement has not paid it, so it does not keep it.
            if (($option['scope'] ?? null) !== null && ($pick['scope'] ?? null) === null) {
                $value /= Balance::OPTION_SCOPED_MULTIPLIER;
            }

            $line['value'] = $kind === 'flat' ? (int) max(1, round($value)) : round($value, 2);
            if ($kind === 'flat') {
                $line['kind'] = 'flat';
            }
            if (($pick['scope'] ?? null) !== null) {
                $line['scope'] = $pick['scope'];
            }

            $out[] = $line;
        }

        return $out;
    }
};
