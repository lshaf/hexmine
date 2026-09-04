<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Game\Balance;
use App\Game\BattleSkills;
use App\Game\Catalog;
use App\Game\Jobs;
use App\Game\Skills;
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
    /**
     * §7.4.3 -- the ceiling on each non-stat kind, keyed as the effects are.
     *
     * `stat` is deliberately absent: those do not have a cap of their own at
     * all. They join the very same aggregate and clamp as gear, options and
     * potions (§8.1 rule 1), so the number that bounds them is STAT_CEILING and
     * it is not this tree's to report.
     */
    private const CAPS = [
        'bite' => Balance::SKILL_BITE_CAP,
        'toolWear' => Balance::SKILL_TOOL_WEAR_CAP,
        'seamGrade' => Balance::SKILL_SEAM_GRADE_CAP,
        'presence' => Balance::SKILL_PRESENCE_CAP,
        'runSlot' => Balance::SKILL_RUN_SLOT_CAP,
        'costReduction' => Balance::SKILL_COST_REDUCTION_CAP,
        'batch' => Balance::SKILL_BATCH_CAP,
        'craftDurability' => Balance::SKILL_DURABILITY_CAP,
        'craftOption' => Balance::SKILL_OPTION_CHANCE_CAP,
        'optionTier' => Balance::SKILL_OPTION_TIER_CAP,
        'brewExtra' => Balance::SKILL_BREW_EXTRA_CAP,
        'stackCap' => Balance::SKILL_STACK_CAP,
        'pair' => Balance::SKILL_PAIR_CAP,
        'battleWear' => Balance::SKILL_BATTLE_WEAR_CAP,
        'weaponWear' => Balance::SKILL_WEAPON_WEAR_CAP,
        'goldFind' => Balance::SKILL_GOLD_FIND_CAP,
        'lootOption' => Balance::SKILL_LOOT_OPTION_CAP,
        'skillPower' => Balance::SKILL_BATTLE_POWER_CAP,
        'skillCooldown' => Balance::SKILL_BATTLE_COOLDOWN_CAP,
        'skillStun' => Balance::SKILL_BATTLE_STUN_CAP,
        'sight' => Balance::SKILL_SIGHT_CAP,
        'bagSlots' => Balance::SKILL_BAG_SLOTS_CAP,
    ];

    /** The whole tree, cacheable and player-independent. */
    public function index(): JsonResponse
    {
        return response()->json([
            'jobs' => Jobs::JOBS,
            // §7.4 -- the SKILLS, which is what the panel draws now. A job used
            // to be thirty nodes in five rows; it is a short list of levelled
            // skills, and each rank still names the node that carries its
            // effect so the balance is the same table it always was.
            'skills' => Skills::ALL,
            'nodes' => Jobs::NODES,
            // §7.5 -- the jobs whose nodes are granted rather than bought. The
            // panel needs to know which trees have no price on them, and asking
            // the server beats mirroring the rule into the client, where it
            // would be a second opinion about what costs a point.
            'automatic' => array_values(array_filter(
                array_keys(Jobs::JOBS),
                fn (string $job) => Jobs::isAutomatic($job),
            )),
            'jobMaxLevel' => Balance::JOB_MAX_LEVEL,
            // §7.4.3 -- what each kind stops at.
            //
            // Served rather than mirrored for the same reason the nodes are:
            // these caps are what protect the §11 sinks, and a second copy in
            // the client would be a second opinion about where a tree ends.
            //
            // The panel needs them for the one question it could not answer
            // before: how much of this kind you have already, and whether the
            // node you are looking at is a point you will actually feel. A cap
            // nobody is shown is a cap that reads as a bug the day it binds.
            'caps' => self::CAPS,
        ]);
    }

    /**
     * §9.5.9 -- the three a battle job knows, with this character's tree in them.
     *
     * These are NOT tree nodes: a skill is bought with a point like one, and
     * stored beside them, but it has no tier, no parent and no place in a
     * depth. The Jobs panel is where a player goes to ask what a Shieldbearer
     * knows, and answering with thirty stat nodes and no mention of Shield Bash
     * is the panel refusing the question.
     *
     * Its own route rather than part of index() because index() is
     * player-independent and cacheable, and every figure here is the player's:
     * `skillPower`, `skillCooldown` and `skillStun` move them (§7.4.3), which
     * is the whole reason the sentences are generated rather than typed.
     */
    public function skills(Request $request): JsonResponse
    {
        $character = $this->character($request);

        $out = [];

        $owned = $this->game->ownedNodes($character);
        $levels = $this->game->jobLevelsFor($character);
        $points = $this->game->skillPoints($character)['available'];

        foreach (Catalog::BATTLE_JOB_FOR_FAMILY as $family => $job) {
            $level = $levels[$job] ?? 1;

            // Every one of the three, known or not -- a skill you cannot use
            // yet is the reason to keep levelling, and hiding it would make the
            // job sheet say a Runecaster has nothing until it suddenly does.
            $out[$job] = array_values(array_map(
                function (array $skill) use ($owned, $level, $points): array {
                    $nodeKey = BattleSkills::nodeKey($skill['key']);
                    $known = in_array($nodeKey, $owned, true);

                    return [
                        'key' => $skill['key'],
                        'node' => $nodeKey,
                        'name' => $skill['name'],
                        'glyph' => $skill['glyph'],
                        'cooldown' => $skill['cooldown'],
                        'jobLevel' => $skill['jobLevel'],
                        'known' => $known,
                        'canLearn' => ! $known && $level >= $skill['jobLevel'] && $points >= 1,
                        ...BattleSkills::summary($skill),
                    ];
                },
                // Armed with nothing filtered, so the figures are this
                // character's tree applied to all three rather than only the
                // ones already learned.
                BattleSkills::armed($family, $this->game->battleTreeFor($character, $family)),
            ));
        }

        return response()->json($out);
    }

    /**
     * Buy one node. Every gate lives in the service, not here: the client draws
     * the tree, the server decides what may be bought (§16).
     */
    public function store(Request $request): JsonResponse
    {
        $character = $this->character($request);

        $validated = $request->validate([
            'skill' => ['required', 'string', 'max:120'],
        ]);

        $result = $this->game->buyRank($character, $validated['skill']);
        $skill = Skills::of($validated['skill']);

        // A rank rather than a name, because the name has not changed and the
        // rank is the thing that just did.
        return $this->respond(
            $character,
            $result,
            Skills::rankCount($validated['skill']) > 1
                ? "{$skill['name']} is now rank {$result['rank']}."
                : "Learned {$skill['name']}.",
        );
    }
}
