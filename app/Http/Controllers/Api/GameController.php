<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Game\GameService;
use App\Http\Controllers\Controller;
use App\Models\Character;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared plumbing for the game API.
 *
 * Every mutating endpoint answers with the complete fresh player state, not a
 * patch. An idle game with hour-long timers cannot afford client/server drift,
 * and full-state responses make a whole class of desync bugs impossible.
 */
abstract class GameController extends Controller
{
    public function __construct(protected readonly GameService $game) {}

    protected function character(Request $request): Character
    {
        /** @var Character $character */
        $character = $request->attributes->get('character');

        // Settle time-based state before anything reads or writes it.
        $this->game->settle($character);

        return $character;
    }

    /** The standard envelope: what happened, plus the new authoritative state. */
    protected function respond(Character $character, mixed $data = null, ?string $message = null): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'state' => $this->game->playerState($character->fresh()),
            'message' => $message,
        ]);
    }
}
