<?php

declare(strict_types=1);

namespace App\Game;

use Illuminate\Support\Facades\Cache;

/**
 * §5.1 -- how much of a hex is gone, and when it comes back.
 *
 * What a hex IS comes out of the seed (WorldGen): its biome, its grade, its
 * haul, and how many hauls it has in it (WorldGen::tileExtractions). What the
 * seed cannot know is how many of those have already been taken -- so that
 * count, and the clock it starts when it runs out, is the whole of the state.
 *
 * It used to be a `tile_states` row per depleted hex, written on a 34% roll.
 * Two things were wrong with that. A chance made the one fact worth knowing --
 * is this seam worth coming back to -- unknowable in principle. And a table
 * meant the map query paid an index scan for state that is worthless the moment
 * its clock ends.
 *
 * The cache is the right shape for both: Redis in production, the array store
 * in tests, and nothing here cares which. A flush refreshes every worked hex on
 * the map, which costs a few seams that were going to regrow on their own.
 *
 * The count is SHARED. Everybody's trips come off the same seam, the way the
 * two mining slots are shared and the way clearing a pack clears it for
 * everybody (§9.5.1). That is the anti-farm rule: you cannot have a hex to
 * yourself, and you cannot re-roll one, because there is nothing to roll.
 *
 * THE COUNT ITSELF LAPSES, and that is deliberate rather than a Redis
 * concession. Every write carries the regrow window as its TTL, so a hex only
 * closes if it is worked all the way through inside one window -- a seam
 * somebody took one haul from and walked away from is whole again by the time
 * anybody returns. A hex is worn down by being *hammered*, not by being visited,
 * and the alternative is 25 million keys that never expire.
 */
final class Tiles
{
    public static function key(int $col, int $row): string
    {
        return "tile:{$col}:{$row}";
    }

    /** Seconds the regrow window runs for, floored at one so a write sticks. */
    private static function ttl(): int
    {
        return max(1, (int) ceil(Balance::scaled(Balance::REGROW_MS) / 1000));
    }

    /**
     * What is left of this hex: how many hauls are gone, and when it regrows.
     *
     * `regrowsAt` is zero on any hex still open, worked or not, so every caller
     * downstream can keep asking the one question it was already asking.
     *
     * @return array{taken:int,regrowsAt:int}
     */
    public static function state(int $col, int $row): array
    {
        return self::normalize(Cache::get(self::key($col, $row)));
    }

    /**
     * Take one haul out of this hex, and close it if that was the last.
     *
     * `$capacity` is the seed's answer (WorldGen::tileExtractions) rather than
     * something stored beside the count: a hex's size is derivable and its wear
     * is not, and keeping the two apart is what stops the cache holding a second
     * opinion about the world.
     *
     * @return array{taken:int,regrowsAt:int,depleted:bool}
     */
    public static function take(int $col, int $row, int $capacity, int $now): array
    {
        $state = self::state($col, $row);

        // A hex already regrowing takes nothing more. The caller gates on this
        // long before here; returning the state unchanged means a double
        // collection cannot push a closed seam past its own clock.
        if ($state['regrowsAt'] > $now) {
            return $state + ['depleted' => true];
        }

        $taken = $state['taken'] + 1;
        $depleted = $taken >= max(1, $capacity);
        $next = [
            'taken' => $taken,
            'regrowsAt' => $depleted ? $now + Balance::scaled(Balance::REGROW_MS) : 0,
        ];

        Cache::put(self::key($col, $row), $next, self::ttl());

        return $next + ['depleted' => $depleted];
    }

    /**
     * The state of many hexes, in one round trip.
     *
     * The caller hands over the hexes it is drawing -- at most thirty-seven,
     * since sight caps at three (§5.6) -- and gets back only the ones anybody
     * has worked, keyed "col,row". One MGET rather than thirty-seven GETs is the
     * whole reason this takes a list, and it is what replaced the map query's
     * index scan over `tile_states`.
     *
     * @param  list<array{0:int,1:int}>  $hexes
     * @return array<string,array{taken:int,regrowsAt:int}>
     */
    public static function statesAmong(array $hexes): array
    {
        if ($hexes === []) {
            return [];
        }

        $keys = array_map(static fn (array $h) => self::key($h[0], $h[1]), $hexes);
        $found = Cache::many($keys);

        $out = [];
        foreach ($hexes as $i => $hex) {
            $hit = $found[$keys[$i]] ?? null;
            if ($hit === null) {
                continue;
            }
            $out["{$hex[0]},{$hex[1]}"] = self::normalize($hit);
        }

        return $out;
    }

    /** Put a hex back the way the seed left it. */
    public static function reset(int $col, int $row): void
    {
        Cache::forget(self::key($col, $row));
    }

    /**
     * @param  mixed  $raw
     * @return array{taken:int,regrowsAt:int}
     */
    private static function normalize($raw): array
    {
        if (! is_array($raw)) {
            return ['taken' => 0, 'regrowsAt' => 0];
        }

        return [
            'taken' => max(0, (int) ($raw['taken'] ?? 0)),
            'regrowsAt' => max(0, (int) ($raw['regrowsAt'] ?? 0)),
        ];
    }
}
