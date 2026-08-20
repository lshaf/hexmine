<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettlementController extends GameController
{
    /** Station state: the shared five-slot public queue, §6.1. */
    public function show(Request $request, string $settlement): JsonResponse
    {
        $character = $this->character($request);

        return response()->json($this->game->station($character, $settlement));
    }

    public function processing(Request $request, string $settlement): JsonResponse
    {
        $character = $this->character($request);

        $validated = $request->validate([
            'recipe' => ['required', 'string'],
            'batches' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $job = $this->game->startProcessing(
            $character,
            $settlement,
            $validated['recipe'],
            (int) ($validated['batches'] ?? 1),
        );

        $name = \App\Game\Catalog::recipe($validated['recipe'])['name'] ?? 'Work';
        $where = $this->game->settlement($settlement)['name'];

        return $this->respond($character, $this->game->jobPayload($job), "{$name} queued at {$where}.");
    }

    public function travel(Request $request): JsonResponse
    {
        $character = $this->character($request);

        $validated = $request->validate([
            'col' => ['required', 'integer', 'min:0', 'max:4999'],
            'row' => ['required', 'integer', 'min:0', 'max:4999'],
        ]);

        $settlement = $this->game->travelTo($character, (int) $validated['col'], (int) $validated['row']);

        return $this->respond(
            $character,
            null,
            $settlement !== null ? "Arrived at {$settlement['name']}." : 'Moved.',
        );
    }
}
