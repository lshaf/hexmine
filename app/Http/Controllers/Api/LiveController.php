<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Game\Live;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LiveController extends GameController
{
    /**
     * How long one connection is held before the client is asked to come back.
     *
     * A stream costs a worker for as long as it is open, so it is bounded
     * rather than eternal: EventSource reconnects on its own, and a short cap
     * turns "a worker per player forever" into "a worker per player for half a
     * minute at a time". The client hands its cursor back on reconnect, so
     * nothing is missed across the gap.
     */
    private const HOLD_SECONDS = 25;

    private function holdSeconds(): float
    {
        // Configurable so a test can hold for nothing at all: an assertion
        // about headers should not cost twenty-five seconds of real time.
        return (float) config('game.live_hold_seconds', self::HOLD_SECONDS);
    }

    /** How often the ring is checked while the connection is held. */
    private const POLL_MICROSECONDS = 700_000;

    /**
     * §16 -- SSE, one way, for the few facts that are somebody else's decision.
     *
     * It carries NOTIFICATIONS, never state: every event says where something
     * moved and nothing about what it moved to. A client that hears one asks
     * /api/map for the disc it is allowed to see, which is the same request it
     * would have made anyway and is still bounded by sight (§5.6). That is what
     * keeps this from becoming a scanner: the stream cannot tell you anything
     * you could not have asked for.
     */
    public function stream(Request $request): StreamedResponse
    {
        // Fresh subscribers start at the head: what happened before you were
        // listening is history, and /api/map already told you about it.
        $cursor = (int) $request->query('cursor', (string) Live::head());

        $hold = $this->holdSeconds();

        $response = new StreamedResponse(function () use ($cursor, $hold) {
            $deadline = microtime(true) + $hold;

            // Tell the browser how long to wait before reconnecting, and give
            // it a cursor immediately so a reconnect never replays or skips.
            echo "retry: 2000\n\n";
            $this->flush();

            while (microtime(true) < $deadline) {
                ['events' => $events, 'cursor' => $cursor] = Live::since($cursor);

                foreach ($events as $event) {
                    echo 'id: '.$cursor."\n";
                    echo 'event: '.$event['type']."\n";
                    echo 'data: '.json_encode($event)."\n\n";
                }

                if ($events === []) {
                    // A comment, so proxies that buffer silence let go of it.
                    echo ": keep-alive {$cursor}\n\n";
                }

                $this->flush();

                if (connection_aborted()) {
                    return;
                }

                usleep(self::POLL_MICROSECONDS);
            }

            echo "event: bye\n";
            echo 'data: '.json_encode(['cursor' => $cursor])."\n\n";
            $this->flush();
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        // nginx buffers text/event-stream by default, which turns a live
        // stream into a 25-second silence followed by everything at once.
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }

        @flush();
    }
}
