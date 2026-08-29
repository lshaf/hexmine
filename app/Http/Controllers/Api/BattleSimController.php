<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Game\Balance;
use App\Game\BattleSkills;
use App\Game\Catalog;
use App\Game\Formulas;
use App\Game\GameService;
use App\Game\Jobs;
use App\Game\Monsters;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * §9.5 -- the fight, run against a kit nobody owns.
 *
 * A bench for the design work: pick a kit, pick a tree, pick a monster, and
 * watch the exact exchange a real fight would run. §9.5.4's measured ladder and
 * §9.5.9's cooldowns are both tuning decisions that need measuring, and reading
 * them out of a test run is a worse loop than watching one.
 *
 * IT RUNS THE REAL CODE. Not a copy of the loop with the same shape -- the same
 * Formulas::resolveBattle() and the same GameService wear arithmetic, off the
 * same catalog. A simulator that reimplements what it simulates is a second
 * opinion that drifts, and the first thing it does when it drifts is lie
 * confidently.
 *
 * It takes no character and touches nothing. There is no session here, nothing
 * is spent, no pack is cleared and no durability moves: every input arrives in
 * the request and the answer is a pure function of it. That is why it sits
 * outside ResolveCharacter -- there is no character for it to resolve, and
 * requiring one would make the bench unusable for exactly the question it
 * exists to answer.
 */
class BattleSimController extends Controller
{
    /** Everything the bench needs to draw its pickers, and nothing player-shaped. */
    public function index(): JsonResponse
    {
        $gear = [];

        foreach (Catalog::items() as $key => $def) {
            $slot = $def['slot'] ?? null;
            if (! in_array($slot, Balance::COMBAT_SLOTS, true)) {
                continue;
            }

            $gear[] = [
                'key' => $key,
                'name' => $def['name'],
                'slot' => $slot,
                'family' => $def['family'] ?? null,
                'rarity' => $def['rarity'],
                'palette' => $def['palette'] ?? 'iron',
                'attack' => (int) ($def['attack'] ?? 0),
                'defense' => (int) ($def['defense'] ?? 0),
                'maxDurability' => (int) ($def['maxDurability'] ?? 0),
            ];
        }

        return response()->json([
            'gear' => $gear,
            // Keyed rows, because Monsters::ROSTER is keyed by the array key
            // alone and the client needs it ON the row to select by. The bench
            // silently had no selectable monster until this went in.
            'monsters' => array_map(
                static fn (string $key, array $m): array => ['key' => $key] + $m,
                array_keys(Monsters::ROSTER),
                Monsters::ROSTER,
            ),
            'skills' => BattleSkills::SKILLS,
            // §7.4 -- the three battle trees, exactly as /api/jobs-tree serves
            // them. The bench picks NODES rather than raw figures now, so what
            // it simulates is a character somebody could actually build.
            'jobs' => array_filter(
                Jobs::JOBS,
                static fn (array $job): bool => $job['kind'] === Jobs::BATTLE,
            ),
            'nodes' => array_filter(
                Jobs::NODES,
                static fn (array $node): bool => Jobs::JOBS[$node['job']]['kind'] === Jobs::BATTLE,
            ),
            'tierJobLevel' => Jobs::TIER_JOB_LEVEL,
            'families' => array_keys(Catalog::BATTLE_JOB_FOR_FAMILY),
            'caps' => [
                'skillPower' => Balance::SKILL_BATTLE_POWER_CAP,
                'skillCooldown' => Balance::SKILL_BATTLE_COOLDOWN_CAP,
                'skillStun' => Balance::SKILL_BATTLE_STUN_CAP,
                'pair' => Balance::SKILL_PAIR_CAP,
                'battleWear' => Balance::SKILL_BATTLE_WEAR_CAP,
                'weaponWear' => Balance::SKILL_WEAPON_WEAR_CAP,
            ],
            'constants' => [
                'roundMs' => Balance::BATTLE_ROUND_MS,
                'maxRounds' => Balance::BATTLE_MAX_ROUNDS,
                'swing' => Balance::BATTLE_SWING,
                'chipFraction' => Balance::BATTLE_CHIP_FRACTION,
                'wearRate' => Balance::BATTLE_WEAR_RATE,
                'wearMajor' => Balance::BATTLE_WEAR_MAJOR,
            ],
        ]);
    }

    /**
     * Run one exchange and hand back everything the plate draws.
     *
     * The seed is an input rather than a roll, because the point of a bench is
     * running the SAME fight twice with one thing changed. Left out, it is
     * still a fixed number rather than a random one -- a bench that answered
     * differently every time you pressed it would be a slot machine.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'monster' => ['required', 'string', Rule::in(array_keys(Monsters::ROSTER))],
            'gear' => ['array'],
            'gear.*' => ['string'],
            'seed' => ['integer', 'min:0', 'max:2000000000'],
            'runs' => ['integer', 'min:1', 'max:500'],
            'jobLevel' => ['integer', 'min:0', 'max:'.Balance::JOB_MAX_LEVEL],
            'power' => ['numeric', 'min:0'],
            'guard' => ['numeric', 'min:0'],
            'skillPower' => ['numeric', 'min:0'],
            'skillCooldown' => ['integer', 'min:0'],
            'skillStun' => ['integer', 'min:0'],
            'treeAttack' => ['integer', 'min:0'],
            'treeDefense' => ['integer', 'min:0'],
            'wearSpared' => ['numeric', 'min:0'],
            'weaponSpared' => ['numeric', 'min:0'],
            'nodes' => ['array'],
            'nodes.*' => ['string'],
        ]);

        $monster = Monsters::ROSTER[$validated['monster']];

        // The kit, as itemRows() would have handed it over: one row a piece,
        // full durability, no rolled options. Options are §8.0.1's business and
        // a bench that rolled them would answer differently every press.
        $items = [];
        $id = 1;
        $family = null;

        foreach ($validated['gear'] ?? [] as $key) {
            $def = Catalog::item($key);
            if ($def === null || ! in_array($def['slot'] ?? '', Balance::COMBAT_SLOTS, true)) {
                continue;
            }

            if (($def['slot'] ?? '') === 'weapon') {
                $family = $def['family'] ?? null;
            }

            $items[] = [
                'id' => $id++,
                'key' => $key,
                'durability' => (int) ($def['maxDurability'] ?? 0),
                'equipped' => true,
                'options' => [],
            ];
        }

        // §7.4 -- the tree, aggregated by the very method the game aggregates
        // a character's own nodes with. The bench sends node KEYS and gets back
        // the same buckets, the same caps and the same family scoping, so a
        // simulated Swordhand is a Swordhand rather than a set of figures that
        // resemble one.
        //
        // Whatever the nodes come to is ADDED to the raw figures beside them,
        // so the bench can still answer "what would 25% feel like" for a shape
        // no tree can currently reach -- which is half of what tuning is.
        $tree = [
            'attack' => 0, 'defense' => 0, 'wear' => 0.0, 'weaponWear' => 0.0,
            'skillPower' => 0.0, 'skillCooldown' => 0, 'skillStun' => 0,
        ];

        if ($family !== null && ! empty($validated['nodes'])) {
            $effects = app(GameService::class)->effectsOf(array_values($validated['nodes']));
            $bucket = 'battle:'.$family;
            $job = Catalog::BATTLE_JOB_FOR_FAMILY[$family] ?? null;
            $byJob = $job === null ? [] : ($effects['byJob'][$job] ?? []);

            $tree = [
                'attack' => (int) ($effects['pair'][$bucket]['attack'] ?? 0),
                'defense' => (int) ($effects['pair'][$bucket]['defense'] ?? 0),
                'wear' => (float) ($effects['battleWear'][$bucket] ?? 0.0),
                'weaponWear' => (float) ($byJob['weaponWear'] ?? 0.0),
                'skillPower' => (float) ($byJob['skillPower'] ?? 0.0),
                'skillCooldown' => (int) ($byJob['skillCooldown'] ?? 0),
                'skillStun' => (int) ($byJob['skillStun'] ?? 0),
            ];
        }

        $pair = Formulas::combatPair(
            $items,
            (int) ($validated['jobLevel'] ?? 0),
            (float) ($validated['power'] ?? 0),
            (float) ($validated['guard'] ?? 0),
            (int) ($validated['treeAttack'] ?? 0) + $tree['attack'],
            (int) ($validated['treeDefense'] ?? 0) + $tree['defense'],
        );

        $pool = Formulas::battlePool($items);
        $armed = BattleSkills::armed($family, [
            'power' => (float) ($validated['skillPower'] ?? 0) + $tree['skillPower'],
            'cooldown' => (int) ($validated['skillCooldown'] ?? 0) + $tree['skillCooldown'],
            'stun' => (int) ($validated['skillStun'] ?? 0) + $tree['skillStun'],
        ]);

        $seed = (int) ($validated['seed'] ?? 1);
        $fight = Formulas::resolveBattle($pair['attack'], $pair['defense'], $pool, $monster, $seed, $armed);

        // §9.5.6 -- the same one bill a real fight charges, off the same method
        // rather than a copy of its arithmetic.
        $lost = app(GameService::class)->simulateWear(
            $items,
            $monster,
            $fight['damageTaken'],
            (float) ($validated['wearSpared'] ?? 0) + $tree['wear'],
            (float) ($validated['weaponSpared'] ?? 0) + $tree['weaponWear'],
        );

        $wear = [];
        foreach ($items as $item) {
            $def = Catalog::item($item['key']);
            $cost = $lost[$item['id']] ?? 0;

            $wear[] = [
                'key' => $item['key'],
                'name' => $def['name'] ?? $item['key'],
                'slot' => $def['slot'] ?? null,
                'lost' => $cost,
                'of' => $item['durability'],
                // §8.2 -- at zero the thing is GONE, and a bench that did not
                // say so would be hiding the most expensive thing a fight does.
                'destroyed' => $cost >= $item['durability'],
            ];
        }

        // Many runs of the SAME kit against the SAME monster, differing only by
        // seed. One fight tells you what happened; a hundred tell you whether
        // it was going to.
        $runs = max(1, (int) ($validated['runs'] ?? 1));
        $won = 0;
        $rounds = 0;
        $taken = 0;

        for ($i = 0; $i < $runs; $i++) {
            $sample = Formulas::resolveBattle(
                $pair['attack'],
                $pair['defense'],
                $pool,
                $monster,
                $seed + $i,
                $armed,
            );
            $won += $sample['won'] ? 1 : 0;
            $rounds += $sample['rounds'];
            $taken += $sample['damageTaken'];
        }

        return response()->json([
            'attack' => $pair['attack'],
            'defense' => $pair['defense'],
            'pool' => $pool,
            'family' => $family,
            // What the picked nodes actually came to, capped, so the bench can
            // show the tree's contribution rather than only its own inputs.
            'tree' => $tree,
            'monster' => $validated['monster'],
            'monsterHp' => (int) $monster['hp'],
            'roundMs' => Balance::BATTLE_ROUND_MS,
            'seed' => $seed,
            'won' => $fight['won'],
            'rounds' => $fight['rounds'],
            'damageTaken' => $fight['damageTaken'],
            'damageDealt' => $fight['damageDealt'],
            'log' => $fight['log'],
            'skills' => array_map(static fn (array $skill): array => [
                'key' => $skill['key'],
                'name' => $skill['name'],
                'glyph' => $skill['glyph'],
                'cooldown' => $skill['cooldown'],
                'description' => $skill['description'],
                ...BattleSkills::summary($skill),
            ], $armed),
            // §9.5.6 -- what it costs, piece by piece, so a kit can be weighed
            // by the repair bill and not only by whether it won.
            'wear' => $wear,
            'bill' => array_sum($lost),
            'over' => [
                'runs' => $runs,
                'won' => $won,
                'winRate' => round($won / $runs, 3),
                'meanRounds' => round($rounds / $runs, 1),
                'meanTaken' => (int) round($taken / $runs),
                'meanBill' => Formulas::battleWearBill((int) round($taken / $runs)),
            ],
        ]);
    }
}
