<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Game\Quests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §12.1 -- quests.
 *
 * Split the same way the skill trees are (§7.4): the catalog is static and
 * identical for everyone, so it is served once from here, while where a
 * particular character stands rides in the player state with everything else
 * that moves.
 */
class QuestController extends GameController
{
    /** The catalog, cacheable and player-independent. */
    public function index(): JsonResponse
    {
        return response()->json(['quests' => Quests::DEFS]);
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
}
