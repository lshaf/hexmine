<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Game\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The bag itself, §11.1.
 *
 * Selling lives in ShopController because it needs a trader standing in front of
 * you. Throwing things away does not -- you can do that anywhere, and out in the
 * field it is the only way to make room. Nothing comes back for it.
 */
class InventoryController extends GameController
{
    public function discard(Request $request): JsonResponse
    {
        $character = $this->character($request);

        $validated = $request->validate([
            'material' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        $dropped = $this->game->discardMaterial(
            $character,
            $validated['material'],
            (int) $validated['quantity'],
        );

        $name = Catalog::material($validated['material'])['name'] ?? $validated['material'];

        return $this->respond($character, ['dropped' => $dropped], "Threw away {$dropped} {$name}.");
    }

    /**
     * §8.5 -- drink one.
     *
     * The buff it starts runs on the server clock and expires on its own, which
     * is the sink (§11.1). Drinking a second of the same kind refreshes the
     * clock rather than stacking.
     */
    public function drink(Request $request): JsonResponse
    {
        $character = $this->character($request);

        $validated = $request->validate([
            'item' => ['required', 'string'],
        ]);

        $buff = $this->game->useConsumable($character, $validated['item']);
        $name = Catalog::item($validated['item'])['name'];
        $minutes = max(1, (int) round(($buff['expiresAt'] - $this->game->now()) / 60000));
        $unit = $minutes === 1 ? 'minute' : 'minutes';

        return $this->respond($character, $buff, "Drank {$name}. It holds for {$minutes} {$unit}.");
    }
}
