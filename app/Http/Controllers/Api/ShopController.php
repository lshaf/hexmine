<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Game\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The NPC trader, §3.2.
 *
 * Gold comes in at a deliberately poor rate and buys basic-tier items only.
 * Nothing here touches anything tradeable: gold has no bridge to NFT value
 * (§3.3), which is why sellMaterial() refuses rare and raid materials outright.
 */
class ShopController extends GameController
{
    public function purchase(Request $request): JsonResponse
    {
        $character = $this->character($request);

        $validated = $request->validate([
            'item' => ['required', 'string'],
        ]);

        $item = $this->game->buyItem($character, $validated['item']);
        $name = Catalog::item($item->item_key)['name'];

        return $this->respond($character, [
            'id' => (string) $item->id,
            'key' => $item->item_key,
            'durability' => $item->durability,
            'equipped' => $item->equipped,
            'options' => $item->options ?? [],
        ], "Bought {$name}.");
    }

    public function sell(Request $request): JsonResponse
    {
        $character = $this->character($request);

        $validated = $request->validate([
            'material' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        $gold = $this->game->sellMaterial($character, $validated['material'], (int) $validated['quantity']);
        $name = Catalog::material($validated['material'])['name'] ?? $validated['material'];

        return $this->respond(
            $character,
            ['gold' => $gold],
            "Sold {$validated['quantity']} {$name} for {$gold} gold.",
        );
    }

    /**
     * §8.2 -- sell one piece of gear back, at half its shelf price scaled by
     * what is left of it.
     *
     * Its own endpoint rather than a flag on sell(): materials go by key and
     * quantity because they stack, and a piece of equipment is one object with
     * an id and a durability. One route taking either would be two calls wearing
     * a coat.
     */
    public function sellEquipment(Request $request, int $item): JsonResponse
    {
        $character = $this->character($request);
        $sale = $this->game->sellItem($character, $item);

        return $this->respond(
            $character,
            ['gold' => $sale['gold']],
            "Sold {$sale['name']} for {$sale['gold']} gold.",
        );
    }
}
