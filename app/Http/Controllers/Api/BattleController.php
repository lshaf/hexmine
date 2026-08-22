<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BattleController extends GameController
{
    /**
     * §9.5.5 -- what the fight on this hex would cost, before anything is spent.
     *
     * Takes no coordinates on purpose. The pin is about the ground under your
     * feet (§9.5.3), so there is only ever one fight on offer and asking about
     * somebody else's would be a scanner.
     */
    public function preview(Request $request): JsonResponse
    {
        return response()->json($this->game->previewBattle($this->character($request)));
    }
}
