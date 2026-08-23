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

    /**
     * §9.5.5 -- settle it. Takes no coordinates for the same reason the
     * preview does not, and no confirmation flag either: the odds and the
     * warning were on the preview, and asking twice is not a safeguard.
     */
    public function store(Request $request): JsonResponse
    {
        $character = $this->character($request);

        // No message: the result plate is the whole report, and a toast beside
        // it would be the same news twice -- the worse of the two, since a
        // fight can destroy something (§8.2) and a status line cannot say so.
        return $this->respond($character, $this->game->fight($character));
    }
}
