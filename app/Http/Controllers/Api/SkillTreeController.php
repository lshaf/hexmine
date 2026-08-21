<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Game\Balance;
use App\Game\Jobs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §7.4 -- the trees.
 *
 * The catalog is static and identical for everyone, so it is served once from
 * its own endpoint rather than shipped with every state refresh. That is also
 * why it is not mirrored into catalog.ts: 180 hand-copied rows would be 180
 * chances for client and server to disagree about what a node costs.
 */
class SkillTreeController extends GameController
{
    /** The whole tree, cacheable and player-independent. */
    public function index(): JsonResponse
    {
        return response()->json([
            'jobs' => Jobs::JOBS,
            'nodes' => Jobs::NODES,
            'tierJobLevel' => Jobs::TIER_JOB_LEVEL,
            'tierSize' => Jobs::TIER_SIZE,
            // §7.5 -- the jobs whose nodes are granted rather than bought. The
            // panel needs to know which trees have no price on them, and asking
            // the server beats mirroring the rule into the client, where it
            // would be a second opinion about what costs a point.
            'automatic' => array_values(array_filter(
                array_keys(Jobs::JOBS),
                fn (string $job) => Jobs::isAutomatic($job),
            )),
            'jobMaxLevel' => Balance::JOB_MAX_LEVEL,
        ]);
    }

    /**
     * Buy one node. Every gate lives in the service, not here: the client draws
     * the tree, the server decides what may be bought (§16).
     */
    public function store(Request $request): JsonResponse
    {
        $character = $this->character($request);

        $validated = $request->validate([
            'node' => ['required', 'string', 'max:120'],
        ]);

        $result = $this->game->buyNode($character, $validated['node']);
        $name = Jobs::node($validated['node'])['name'];

        return $this->respond($character, $result, "Learned {$name}.");
    }
}
