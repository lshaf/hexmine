<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Game\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CraftingController extends GameController
{
    public function store(Request $request): JsonResponse
    {
        $character = $this->character($request);

        $validated = $request->validate([
            'item' => ['required', 'string'],
        ]);

        $made = $this->game->craftItem($character, $validated['item']);
        $def = Catalog::item($made->item_key);

        // §8.4 -- a potion comes back as a stack, not an object. It has no id
        // worth handing out, no durability and no slot, so it reports a count.
        if (! empty($def['consumable'])) {
            return $this->respond(
                $character,
                ['key' => $made->item_key, 'quantity' => $made->quantity],
                "Brewed {$def['name']}. You have {$made->quantity}.",
            );
        }

        return $this->respond($character, [
            'id' => (string) $made->id,
            'key' => $made->item_key,
            'durability' => $made->durability,
            'equipped' => $made->equipped,
            'options' => $made->options ?? [],
        ], "Crafted {$def['name']}.");
    }
}
