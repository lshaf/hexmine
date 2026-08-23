<?php

declare(strict_types=1);

namespace App\Game;

use Illuminate\Support\Facades\Cache;

/**
 * §16 -- the small amount of the world that has to reach a client unasked.
 *
 * Almost nothing does. Terrain is a pure function of (col, row, seed) (§5), a
 * pack is a hash of the hex and the hour (§9.5.1), and every clock the player
 * owns is a timestamp they were already handed. What is left is the handful of
 * facts that are somebody ELSE'S decision and change the ground under you:
 *
 *   - a pack somebody settled, which is shared (§9.5.1)
 *   - a corpse raised or taken, which anybody in sight can see (§9.5.7)
 *
 * A cache-backed ring rather than a queue or a broadcast driver, because the
 * requirement is small and the failure mode has to be harmless: this is a
 * NOTIFICATION that state moved, never the state itself. A client that misses
 * an event, reconnects late, or reads a flushed cache is one `/api/map` behind
 * and nothing worse -- which is exactly what it would have been before any of
 * this existed. Nothing here is authoritative and nothing here is durable.
 *
 * WebSockets were considered and are overkill at this scale (§16): the traffic
 * is one-way, low-rate and lossy-tolerant, which is the shape SSE is for.
 */
final class Live
{
    /** The monotonic head. Cache::increment is atomic wherever it is backed. */
    private const SEQ = 'live:seq';

    /**
     * How many events stay readable. A client polling every few seconds cannot
     * fall this far behind without having disconnected, and one that has is
     * better served by a fresh /api/map than by a replay.
     */
    public const WINDOW = 256;

    /** Seconds an event stays readable. Long enough for a reconnect, no longer. */
    public const TTL = 300;

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function push(string $type, array $payload): void
    {
        // add() seeds the counter without clobbering a live one; increment()
        // then returns the id this event owns.
        Cache::add(self::SEQ, 0, self::TTL * 4);
        $id = (int) Cache::increment(self::SEQ);

        Cache::put("live:ev:{$id}", ['type' => $type] + $payload, self::TTL);
    }

    /** The id a fresh subscriber should start from: everything before it is history. */
    public static function head(): int
    {
        return (int) (Cache::get(self::SEQ) ?? 0);
    }

    /**
     * Events after `$cursor`, and the new cursor.
     *
     * Reads at most WINDOW ids in one round trip. A cursor further behind than
     * that is snapped forward rather than replayed -- see the note above about
     * this being a notification rather than a source of truth.
     *
     * @return array{events:list<array<string,mixed>>,cursor:int}
     */
    public static function since(int $cursor): array
    {
        $head = self::head();

        if ($cursor >= $head) {
            return ['events' => [], 'cursor' => $head];
        }

        $from = max($cursor, $head - self::WINDOW) + 1;
        $keys = [];
        for ($id = $from; $id <= $head; $id++) {
            $keys[] = "live:ev:{$id}";
        }

        $found = Cache::many($keys);

        $events = [];
        foreach ($keys as $key) {
            if (! empty($found[$key])) {
                $events[] = $found[$key];
            }
        }

        return ['events' => $events, 'cursor' => $head];
    }
}
