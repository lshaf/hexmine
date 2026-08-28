<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Game\Balance;
use App\Game\Drops;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MiningController extends GameController
{
    /** Start a mine, §7.3. The client sends a tile, never a duration. */
    public function store(Request $request): JsonResponse
    {
        $character = $this->character($request);

        $validated = $request->validate([
            'col' => ['required', 'integer', 'min:'.(-Balance::mapRadius()), 'max:'.Balance::mapRadius()],
            'row' => ['required', 'integer', 'min:'.(-Balance::mapRadius()), 'max:'.Balance::mapRadius()],
        ]);

        $job = $this->game->startMining($character, (int) $validated['col'], (int) $validated['row']);

        return $this->respond(
            $character,
            $this->game->jobPayload($job),
            "Mine started at {$validated['col']},{$validated['row']}.",
        );
    }

    /** §4.0 -- work the same hex by hand. No tool, and none required. */
    public function gather(Request $request): JsonResponse
    {
        $character = $this->character($request);

        $validated = $request->validate([
            'col' => ['required', 'integer', 'min:'.(-Balance::mapRadius()), 'max:'.Balance::mapRadius()],
            'row' => ['required', 'integer', 'min:'.(-Balance::mapRadius()), 'max:'.Balance::mapRadius()],
        ]);

        $job = $this->game->startMining(
            $character,
            (int) $validated['col'],
            (int) $validated['row'],
            Drops::GATHERING,
        );

        return $this->respond(
            $character,
            $this->game->jobPayload($job),
            "Gathering by hand at {$validated['col']},{$validated['row']}.",
        );
    }

    /**
     * §4 -- collect a finished mine.
     *
     * Answers with no message on purpose. The client opens the haul receipt over
     * this, and that plate carries the whole of it: every stack, the assay bar,
     * both XP ladders, tool wear and anything that would not fit. A toast
     * reading "Collected 4 Wood, 1 Toadstool." alongside it is the same news
     * twice, said worse -- and it was a leftover from when a haul WAS one stack
     * and a line of text could hold it.
     */
    public function collect(Request $request, int $job): JsonResponse
    {
        $character = $this->character($request);
        $result = $this->game->collectJob($character, $job);

        // Cast so an empty haul serialises as {} rather than [], which is what
        // the client's Record<MaterialKey, number> expects.
        //
        // Guarded because this one endpoint collects every kind of job, and a
        // fight is the one that comes back with no haul in it at all (§9.5.5):
        // its receipt is an exchange and a set of consequences, with no material
        // ledger anywhere in it.
        if (array_key_exists('gained', $result)) {
            $result['gained'] = (object) $result['gained'];
        }

        return $this->respond($character, $result);
    }

    /** §11.1 -- abandoning forfeits the partial haul. It is meant to sting. */
    public function destroy(Request $request, int $job): JsonResponse
    {
        $character = $this->character($request);
        $this->game->abandonJob($character, $job);

        return $this->respond($character, null, 'Mine abandoned. The partial reward is lost.');
    }
}
