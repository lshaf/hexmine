<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StateController extends GameController
{
    /** The authoritative snapshot the client renders. */
    public function show(Request $request): JsonResponse
    {
        $character = $this->character($request);

        return response()->json($this->game->playerState($character));
    }
}
