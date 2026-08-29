<?php

declare(strict_types=1);

use App\Game\Balance;
use App\Game\Catalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * §8.0.1 -- every rolled line is a solid number on the pair now, so the lines
 * already sitting on gear have to become ones.
 *
 * A percentage was the wrong unit for luck. It climbs toward §8.1's ceiling,
 * which is one invisible number every line on every piece is already climbing
 * toward, so a good roll and a bad one read the same on the plate -- and
 * §9.5.4 already says as much about the percentage twins: "+3% power moves a
 * common sword from 5 attack to 5". What is left is `attack` and `defense`,
 * solid, simply added, and felt the next time a pack stops you.
 *
 * Converted rather than dropped, the same way the last two pool changes were:
 * the roll was the bench's fault, not the owner's, and an idle game does not
 * take back what a player already had. Each stray keeps its TIER -- where its
 * old value sat inside the old percentage band is where its new one sits inside
 * the solid band -- and lands on whichever half of the pair its own piece is
 * eligible for.
 */
return new class extends Migration
{
    /** The percentage band each tier used to be worth, before this change. */
    private const WAS = [
        'common' => [0.01, 0.02],
        'uncommon' => [0.01, 0.03],
        'rare' => [0.02, 0.04],
        'epic' => [0.03, 0.05],
        'legendary' => [0.04, 0.06],
    ];

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

                    $fixed = $this->solidify($def, $options, (int) $row->id);
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
        // A percentage cannot be recovered from the solid number it became, and
        // inventing one would be worse than leaving the row correct.
    }

    /**
     * @param  array<string,mixed>  $def
     * @param  array<int,array<string,mixed>>  $options
     * @return array<int,array<string,mixed>>
     */
    private function solidify(array $def, array $options, int $id): array
    {
        $pool = array_column(Catalog::optionRollsFor($def), 'stat');
        if ($pool === []) {
            return [];
        }

        $taken = [];
        $out = [];

        foreach ($options as $i => $option) {
            $stat = (string) ($option['stat'] ?? '');

            // Already solid and already eligible: leave it exactly as it is.
            if (($option['kind'] ?? null) === 'flat' && in_array($stat, $pool, true)) {
                $taken[] = $stat;
                $out[] = $option;

                continue;
            }

            $choices = array_values(array_diff($pool, $taken));
            if ($choices === []) {
                // The piece already carries one of each half. That is the
                // roller's own ceiling (one line per stat), so there is nowhere
                // left to put this one.
                continue;
            }

            $pick = $choices[abs(crc32($id.':'.$i)) % count($choices)];
            $taken[] = $pick;

            $out[] = [
                'stat' => $pick,
                'value' => $this->carryTheTier($def, (float) ($option['value'] ?? 0), $option),
                'kind' => 'flat',
            ];
        }

        return $out;
    }

    /**
     * Where the old value sat in its band is where the new one sits in the
     * solid band of the same tier. A lucky roll stays lucky.
     *
     * @param  array<string,mixed>  $def
     * @param  array<string,mixed>  $option
     */
    private function carryTheTier(array $def, float $value, array $option): int
    {
        // A scoped line was worth double for naming one of five lines (§8.0.1's
        // old rule), so it is read against a doubled band or every one of them
        // would come out at the top of its tier.
        $premium = isset($option['scope']) ? 2.0 : 1.0;

        foreach (self::WAS as $tier => [$min, $max]) {
            $lo = $min * $premium;
            $hi = $max * $premium;

            if ($value > $hi + 1e-9) {
                continue;
            }

            $where = $hi > $lo ? max(0.0, ($value - $lo) / ($hi - $lo)) : 0.0;
            [$flatLo, $flatHi] = Balance::OPTION_FLAT_VALUE[$tier];

            return (int) max(1, round($flatLo + $where * ($flatHi - $flatLo)));
        }

        // Above every band it could have been rolled at -- a durability line,
        // which was counted in points rather than percent and lived for one
        // change. The top of the item's own rung is the honest answer.
        [, $flatHi] = Balance::OPTION_FLAT_VALUE[$def['rarity']] ?? Balance::OPTION_FLAT_VALUE['common'];

        return (int) $flatHi;
    }
};
