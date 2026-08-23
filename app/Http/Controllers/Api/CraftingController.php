<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Game\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CraftingController extends GameController
{
    /**
     * §8.4 -- put a thing on the bench. It is not made until it is collected.
     *
     * The response is the job rather than the item, because there is no item
     * yet: what comes back is where it is being made and when it will be done.
     */
    public function store(Request $request): JsonResponse
    {
        $character = $this->character($request);

        $validated = $request->validate([
            'item' => ['required', 'string'],
        ]);

        $job = $this->game->startCraft($character, $validated['item']);
        $def = Catalog::item($validated['item']);

        return $this->respond(
            $character,
            $this->game->jobPayload($job),
            "{$def['name']} is on the bench. Come back for it.",
        );
    }
}
