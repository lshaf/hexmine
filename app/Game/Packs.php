<?php

declare(strict_types=1);

namespace App\Game;

use Illuminate\Support\Facades\Cache;

/**
 * §9.5.1 -- the one thing about a pack that cannot be derived.
 *
 * Where a pack stands is a hash (WorldGen::packAt), so an unmet pack costs no
 * storage and every client agrees about it for free. What the hash cannot know
 * is whether somebody has already fought it -- and since resolution clears the
 * pack whether it was won or lost, that single bit is the whole of the state.
 *
 * It lives in the cache rather than a table because it is worthless the moment
 * its bucket ends: the key carries the bucket, and the TTL is the rest of that
 * bucket. Redis in production, the array store in tests; nothing here cares
 * which. A flush un-clears every pack, which costs a few fights that were
 * already going to respawn on their own.
 *
 * Clearing is SHARED. Whoever fights it removes it for everybody, the way a
 * worked seam closes for everybody -- and that is the anti-farm rule: you
 * cannot re-roll a pack, because after the roll there is no pack.
 */
final class Packs
{
    /**
     * §5.5 -- the hunt keeps its own flag under the same machinery.
     *
     * A separate prefix rather than a separate class: the question is
     * identical -- has somebody already settled the thing standing on this hex
     * this bucket -- and the answer is stored, expired and read the same way.
     * Two classes would be one file of duplicated TTL arithmetic waiting to
     * drift, and a pack and an animal can stand on one hex at once, so the two
     * flags have to be independent rather than one bit shared.
     */
    public const PACK = 'pack';

    public const HUNT = 'hunt';

    public static function key(int $col, int $row, int $bucket, string $kind = self::PACK): string
    {
        return "{$kind}:{$col}:{$row}:{$bucket}";
    }

    /** Seconds of life left in this bucket, floored at one so a write sticks. */
    private static function ttl(int $until, int $now): int
    {
        return max(1, (int) ceil(($until - $now) / 1000));
    }

    /** Mark this pack settled, win or lose. It does not come back this bucket. */
    public static function clear(int $col, int $row, int $bucket, int $until, int $now, string $kind = self::PACK): void
    {
        Cache::put(self::key($col, $row, $bucket, $kind), true, self::ttl($until, $now));
    }

    public static function isCleared(int $col, int $row, int $bucket, string $kind = self::PACK): bool
    {
        return (bool) Cache::get(self::key($col, $row, $bucket, $kind), false);
    }

    /**
     * Which of these packs are already settled, in one round trip.
     *
     * The caller hands over the hexes it has generated a pack for -- at most
     * thirty-seven, since sight caps at three (§5.6) -- and gets back the
     * "col,row" of the ones that are gone. One MGET rather than thirty-seven
     * GETs is the whole reason this takes a list.
     *
     * @param  list<array{col:int,row:int,bucket:int,kind?:string}>  $packs
     * @return list<array{0:int,1:int}>
     */
    public static function clearedAmong(array $packs): array
    {
        if ($packs === []) {
            return [];
        }

        $keys = array_map(
            static fn (array $p) => self::key($p['col'], $p['row'], $p['bucket'], $p['kind'] ?? self::PACK),
            $packs,
        );

        $found = Cache::many($keys);

        $out = [];
        foreach ($packs as $i => $pack) {
            if (! empty($found[$keys[$i]])) {
                $out[] = [$pack['col'], $pack['row']];
            }
        }

        return $out;
    }
}
