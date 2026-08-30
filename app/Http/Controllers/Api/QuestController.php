<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Game\Dailies;
use App\Game\Quests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §12 -- the ledger, both halves of it.
 *
 * Split the same way the skill trees are (§7.4): the catalog is static and
 * identical for everyone, so it is served once from here, while where a
 * particular character stands rides in the player state with everything else
 * that moves.
 *
 * The dailies ride along on the same request rather than getting one of their
 * own. Their pool is static in exactly the same way -- which three are *yours*
 * today is derived per character and lives in the state -- and they are drawn on
 * the same screen, so a second round trip would buy nothing.
 */
class QuestController extends GameController
{
    /** Both catalogs, cacheable and player-independent. */
    public function index(): JsonResponse
    {
        return response()->json([
            'quests' => Quests::DEFS,
            // §12.2 -- flattened, each task carrying the lane it was drawn from.
            'dailies' => Dailies::all(),
        ]);
    }

    /**
     * Take the gold. Every gate is in the service, not here: the client draws a
     * list, the server decides what is payable (§16).
     *
     * Answers with no message on purpose. A claim is not a line of chat -- the
     * client opens a receipt over it, which can say what was earned, what the
     * purse is now, and what the claim just opened up. A toast saying "+40 gold"
     * on top of that would be the same news twice, said worse.
     */
    public function claim(Request $request, string $quest): JsonResponse
    {
        $character = $this->character($request);

        return $this->respond($character, $this->game->claimQuest($character, $quest));
    }

    /**
     * §12.2 -- take the day's gold.
     *
     * Its own route rather than a flag on the one above, because they are two
     * ledgers: a quest pays once and never comes back, and this pays once a day.
     * Sharing an endpoint would mean one key space for two things that reset
     * differently, which is the sort of saving that costs a bug later.
     */
    public function claimDaily(Request $request, string $task): JsonResponse
    {
        $character = $this->character($request);

        return $this->respond($character, $this->game->claimDaily($character, $task));
    }
}
