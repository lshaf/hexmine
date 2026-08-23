<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Game\Live;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * §16 -- the notification ring behind the SSE route.
 *
 * What is tested here is deliberately narrow, because the thing itself is:
 * events are notifications that somebody else's decision moved the ground, they
 * are lossy on purpose, and nothing reads them for truth. A client that misses
 * one is a single /api/map behind, which is where it was before the stream
 * existed.
 */
final class LiveStreamTest extends TestCase
{
    use RefreshDatabase;

    /** A fresh subscriber starts at the head: what happened before is history. */
    public function test_a_new_subscriber_sees_nothing_that_came_before_it(): void
    {
        Live::push('pack.cleared', ['col' => 1, 'row' => 1]);

        $cursor = Live::head();
        $this->assertSame(['events' => [], 'cursor' => $cursor], Live::since($cursor));
    }

    public function test_events_arrive_in_order_and_advance_the_cursor(): void
    {
        $cursor = Live::head();

        Live::push('pack.cleared', ['col' => 3, 'row' => 4]);
        Live::push('carrier.raised', ['col' => 5, 'row' => 6]);

        ['events' => $events, 'cursor' => $next] = Live::since($cursor);

        $this->assertCount(2, $events);
        $this->assertSame('pack.cleared', $events[0]['type']);
        $this->assertSame(3, $events[0]['col']);
        $this->assertSame('carrier.raised', $events[1]['type']);
        $this->assertGreaterThan($cursor, $next);

        // And reading again from the new cursor is empty, not a replay.
        $this->assertSame([], Live::since($next)['events']);
    }

    /**
     * A cursor further behind than the window is snapped forward rather than
     * replayed. Lossy on purpose: a client this far behind has disconnected,
     * and is better served by a fresh map than by a backlog.
     */
    public function test_a_cursor_past_the_window_is_snapped_forward(): void
    {
        for ($i = 0; $i < Live::WINDOW + 20; $i++) {
            Live::push('pack.cleared', ['col' => $i, 'row' => 0]);
        }

        $events = Live::since(0)['events'];

        $this->assertLessThanOrEqual(Live::WINDOW, count($events));
        $this->assertGreaterThan(0, count($events));
    }

    /**
     * The route answers as a stream, and only to somebody with a character.
     *
     * Cookie-authenticated like the rest of the API (§2's sybil seam lives in
     * ResolveCharacter), so an anonymous connection is refused before a worker
     * is held open for twenty-five seconds.
     */
    public function test_the_stream_route_answers_as_an_event_stream(): void
    {
        $this->get('/api/live')->assertStatus(401);

        // Held for no time at all: this asserts the shape of the response, and
        // an assertion about headers should not cost twenty-five seconds.
        config(['game.live_hold_seconds' => 0]);

        $response = $this
            ->withoutMiddleware(\App\Http\Middleware\ResolveCharacter::class)
            ->get('/api/live?cursor='.Live::head());

        $response->assertOk();
        $this->assertStringStartsWith(
            'text/event-stream',
            (string) $response->headers->get('Content-Type'),
        );
        $response->assertHeader('X-Accel-Buffering', 'no');
    }
}
