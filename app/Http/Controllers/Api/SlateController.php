<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Game\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The slate, §8.4 -- ten recipes a prospector means to make.
 *
 * Two verbs rather than one toggle, for the same reason equip and unequip are
 * two routes: a toggle asks the server to work out what the client already
 * knows, and two taps in flight against it flip to whichever arrives last.
 *
 * The list itself is never fetched. It rides in the state like everything else
 * that moves, because what a player is short of moves with every haul.
 */
class SlateController extends GameController
{
    public function store(Request $request): JsonResponse
    {
        $character = $this->character($request);

        $validated = $request->validate([
            'recipe' => ['required', 'string', 'max:64'],
        ]);

        $slate = $this->game->saveToSlate($character, $validated['recipe']);

        return $this->respond($character, ['slate' => $slate], 'Written on the slate: '.$this->name($validated['recipe']).'.');
    }

    public function destroy(Request $request, string $recipe): JsonResponse
    {
        $character = $this->character($request);

        $slate = $this->game->dropFromSlate($character, $recipe);

        return $this->respond($character, ['slate' => $slate], 'Rubbed off the slate: '.$this->name($recipe).'.');
    }

    /** What the line is called, in the catalog's own words. */
    private function name(string $recipe): string
    {
        return Catalog::recipe($recipe)['name']
            ?? Catalog::item($recipe)['name']
            ?? $recipe;
    }
}
