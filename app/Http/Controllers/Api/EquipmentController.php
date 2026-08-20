<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Game\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EquipmentController extends GameController
{
    public function equip(Request $request, int $item): JsonResponse
    {
        $character = $this->character($request);
        $this->game->equipItem($character, $item);

        return $this->respond($character, null, 'Equipped.');
    }

    public function unequip(Request $request, int $item): JsonResponse
    {
        $character = $this->character($request);
        $this->game->unequipItem($character, $item);

        return $this->respond($character, null, 'Unequipped.');
    }

    public function repair(Request $request, int $item): JsonResponse
    {
        $character = $this->character($request);
        $this->game->repairItem($character, $item);

        return $this->respond($character, null, 'Repaired.');
    }

    /** §8.2 -- discard returns a small salvage, so obsolete gear has an exit. */
    public function destroy(Request $request, int $item): JsonResponse
    {
        $character = $this->character($request);
        $salvage = $this->game->discardItem($character, $item);

        $parts = [];
        foreach ($salvage as $key => $qty) {
            $name = Catalog::material($key)['name'] ?? $key;
            $parts[] = "{$qty} {$name}";
        }

        return $this->respond(
            $character,
            null,
            $parts === [] ? 'Discarded.' : 'Salvaged '.implode(', ', $parts).'.',
        );
    }
}
