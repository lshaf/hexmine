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

        $item = $this->game->craftItem($character, $validated['item']);
        $name = Catalog::item($item->item_key)['name'];

        return $this->respond($character, [
            'id' => (string) $item->id,
            'key' => $item->item_key,
            'durability' => $item->durability,
            'equipped' => $item->equipped,
        ], "Crafted {$name}.");
    }
}
