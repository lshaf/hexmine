<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Game\Catalog;
use App\Game\Jobs;
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
        $learned = $this->game->repairItem($character, $item);

        // §8.2 -- and say what the mending taught, because it is the one part
        // of a repair that is not a bill. Silent, it would be a number the
        // player only found by watching a job level move on another screen.
        $note = 'Repaired.';
        if ($learned['jobXp'] > 0 && $learned['job'] !== null) {
            $job = Jobs::JOBS[$learned['job']]['name'];
            $note = "Repaired. {$job} +{$learned['jobXp']} xp.";
        }

        return $this->respond($character, $learned, $note);
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
