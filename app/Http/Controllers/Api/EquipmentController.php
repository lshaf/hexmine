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

        // §8.2 -- whether the parts come out of the bag or over the counter.
        // The same verb either way, which is why it is a flag on the mend
        // rather than a second endpoint: what changes is who supplies the
        // materials, not what happens to the piece.
        $learned = $this->game->repairItem($character, $item, $request->boolean('coin'));

        // §8.2 -- and say what the mending taught, because it is the one part
        // of a repair that is not a bill. Silent, it would be a number the
        // player only found by watching a job level move on another screen.
        $note = 'Repaired.';
        if ($learned['gold'] > 0) {
            $note = "Repaired for {$learned['gold']} gold.";
        }
        if ($learned['jobXp'] > 0 && $learned['job'] !== null) {
            $job = Jobs::JOBS[$learned['job']]['name'];
            $note = rtrim($note, '.').". {$job} +{$learned['jobXp']} xp.";
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
