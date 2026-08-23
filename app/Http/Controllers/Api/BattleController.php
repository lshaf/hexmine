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
     * §9.5.5 -- close with it. Takes no coordinates for the same reason the
     * preview does not, and no confirmation flag either: the odds and what a
     * loss costs were both on the preview, and asking twice is not a safeguard.
     *
     * Answers with the JOB, not the outcome. A fight takes time now, and the
     * report comes off the collect like every other piece of work.
     */
    public function store(Request $request): JsonResponse
    {
        $character = $this->character($request);
        $job = $this->game->startBattle($character);

        return $this->respond(
            $character,
            $this->game->jobPayload($job),
            'You close with it.',
        );
    }
}
