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
     * What the client cannot derive for a viewport: depletion timers and miner
     * occupancy. Everything else about a tile is a pure function of its
     * coordinates (§5), so this is all a pan needs to ask for.
     */
    public function index(Request $request): JsonResponse
    {
        $this->character($request);

        $validated = $request->validate([
            'col' => ['required', 'integer', 'min:0', 'max:4999'],
            'row' => ['required', 'integer', 'min:0', 'max:4999'],
            // Bounded so a caller cannot ask the server to scan the whole map.
            'w' => ['nullable', 'numeric', 'min:100', 'max:2400'],
            'h' => ['nullable', 'numeric', 'min:100', 'max:2400'],
        ]);

        return response()->json($this->game->mapMutations(
            (int) $validated['col'],
            (int) $validated['row'],
            (float) ($validated['w'] ?? 900),
            (float) ($validated['h'] ?? 620),
        ));
    }

    /** What a trip on this tile would cost and give, computed server-side. */
    public function preview(Request $request, int $col, int $row): JsonResponse
    {
        $character = $this->character($request);

        return response()->json($this->game->previewTile($character, $col, $row));
    }
}
