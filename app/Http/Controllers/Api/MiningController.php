<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MiningController extends GameController
{
    /** Start a trip, §7.3. The client sends a tile, never a duration. */
    public function store(Request $request): JsonResponse
    {
        $character = $this->character($request);

        $validated = $request->validate([
            'col' => ['required', 'integer', 'min:0', 'max:4999'],
            'row' => ['required', 'integer', 'min:0', 'max:4999'],
        ]);

        $job = $this->game->startMining($character, (int) $validated['col'], (int) $validated['row']);

        return $this->respond(
            $character,
            $this->game->jobPayload($job),
            "Trip started at {$validated['col']},{$validated['row']}.",
        );
    }

    public function collect(Request $request, int $job): JsonResponse
    {
        $character = $this->character($request);
        $result = $this->game->collectJob($character, $job);
        $result['gained'] = (object) $result['gained'];

        $parts = [];
        foreach ((array) $result['gained'] as $key => $qty) {
            $name = \App\Game\Catalog::material($key)['name'] ?? $key;
            $parts[] = "{$qty} {$name}";
        }

        return $this->respond($character, $result, 'Collected '.implode(', ', $parts).'.');
    }

    /** §11.1 -- abandoning forfeits the partial haul. It is meant to sting. */
    public function destroy(Request $request, int $job): JsonResponse
    {
        $character = $this->character($request);
        $this->game->abandonJob($character, $job);

        return $this->respond($character, null, 'Trip abandoned. The partial haul is lost.');
    }
}
