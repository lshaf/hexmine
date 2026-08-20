<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MapController extends GameController
{
    /**
     * Generation parameters. Fetched once at boot; the client generates terrain
     * from these, so panning costs no network at all.
     */
    public function world(Request $request): JsonResponse
    {
        $this->character($request);

        return response()->json($this->game->worldConfig());
    }

    /**
     * What the client cannot derive: depletion timers and miner occupancy, for
     * the tiles within the character's sight.
     *
     * No parameters, deliberately. Sight is the character's travel range, which
     * the server already knows, so there is nothing here for a caller to widen.
     * Dragging the map asks for nothing at all -- terrain is derived from the
     * seed (§5) -- and this fires only when the character moves or a job changes.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->game->mapMutations($this->character($request)));
    }

    /** What a trip on this tile would cost and give, computed server-side. */
    public function preview(Request $request, int $col, int $row): JsonResponse
    {
        $character = $this->character($request);

        return response()->json($this->game->previewTile($character, $col, $row));
    }
}
