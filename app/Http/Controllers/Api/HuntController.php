<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Game\Balance;
use App\Game\Drops;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §5.5 -- the hunt.
 *
 * There is no UI of its own here, and no endpoint of its own beyond this one:
 * §7.3 already makes hunting the same arithmetic as a mine, so a hunt IS a
 * mine — the same job, the same clock, the same haul plate — on the animal
 * standing on the hex rather than on the seam under it. The dock says Hunt and
 * everything behind the button is the mining path.
 */
final class HuntController extends GameController
{
    public function store(Request $request): JsonResponse
    {
        $character = $this->character($request);

        $validated = $request->validate([
            'col' => ['required', 'integer', 'min:'.(-Balance::mapRadius()), 'max:'.Balance::mapRadius()],
            'row' => ['required', 'integer', 'min:'.(-Balance::mapRadius()), 'max:'.Balance::mapRadius()],
        ]);

        // §5.5 -- a hunt is a mine, so it starts one. The activity is the whole
        // difference: it decides what is being worked and what comes off it,
        // and every other thing about the job is a mine's.
        $job = $this->game->startMining(
            $character,
            (int) $validated['col'],
            (int) $validated['row'],
            Drops::HUNTING,
        );

        return $this->respond(
            $character,
            $this->game->jobPayload($job),
            "Hunt started at {$validated['col']},{$validated['row']}.",
        );
    }
}
