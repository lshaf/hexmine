<?php

declare(strict_types=1);

use App\Game\Balance;
use App\Game\Catalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * §8.0.1 -- the worn pool narrowed, so the gear already in bags has to follow.
 *
 * Three things changed under it. `yield` no longer comes out of a worn piece
 * unpointed -- it belongs to the work, and unpointed it was five bonuses in one
 * garment. `processingSpeed` left every pool, because a bench clock belongs to
 * a building rather than to a body. And `tripReduction` stopped existing
 * outright (§7.3) a change earlier, which left at least one stored line naming
 * a stat nothing reads -- worth nothing, and printed as a stat with no name.
 *
 * Retargeted rather than dropped, for the same reason the last pool change was:
 * the roll was the bench's fault, not the owner's, and an idle game does not
 * take back something a player already had. Each stray keeps its kind and its
 * worth and is pointed at its own piece's pool -- and the scoped premium is
 * settled in whichever direction the move went, since a pointed line is worth
 * double precisely because it pays on one line out of five.
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
            // A durability line is never reached from here: it is the one kind
            // no older row can be carrying.
            $choices = array_values(array_filter(
                $pool,
                static fn (array $entry) => ($entry['kind'] ?? 'percent') === $kind
                    && ! in_array($key($entry), $taken, true),
            ));

            if ($choices === []) {
                // Nothing left to point it at: the piece already carries one of
                // everything its kind can be, which is a state the roller could
                // not have produced either (it dedups by stat and scope and
                // stops when the pool runs dry). Dropping is the only answer.
                continue;
            }

            $pick = $choices[abs(crc32($id.':'.$i)) % count($choices)];
            $taken[] = $key($pick);

            $line = ['stat' => $pick['stat']];
            $value = (float) ($option['value'] ?? 0);

            // The premium is paid for by naming a line, so it is settled in
            // whichever direction the move went. An unpointed replacement has
            // not paid it; a pointed one now pays on one line out of five and
            // has to be worth what that costs.
            $wasScoped = ($option['scope'] ?? null) !== null;
            $isScoped = ($pick['scope'] ?? null) !== null;

            if ($wasScoped && ! $isScoped) {
                $value /= Balance::OPTION_SCOPED_MULTIPLIER;
            }
            if (! $wasScoped && $isScoped) {
                $value *= Balance::OPTION_SCOPED_MULTIPLIER;
            }

            $line['value'] = $kind === 'flat' ? (int) max(1, round($value)) : round($value, 2);
            if ($kind === 'flat') {
                $line['kind'] = 'flat';
            }
            if ($isScoped) {
                $line['scope'] = $pick['scope'];
            }

            $out[] = $line;
        }

        return $out;
    }
};
