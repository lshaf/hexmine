<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Game\Balance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The character itself, §7 -- which is currently only its name.
 *
 * Validation here is the shape of the request; the RULE is GameService's, so
 * that "letters and digits, and nobody else's" is answered in one place whether
 * it is asked by this route or by anything later.
 */
class CharacterController extends GameController
{
    public function rename(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:'.Balance::CHARACTER_NAME_MAX],
        ]);

        $character = $this->game->renameCharacter($this->character($request), $data['name']);

        return $this->respond($character, ['name' => $character->name], 'You are '.$character->name.' now.');
    }
}
