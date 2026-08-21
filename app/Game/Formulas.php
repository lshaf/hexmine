<?php

declare(strict_types=1);

namespace App\Game;

/**
 * Pure game maths, §7 / §8. Port of `resources/js/game/formulas.ts`.
 *
 * This side is the authority. The client has an identical copy purely so it can
 * *predict* and display numbers before the round trip; if the two ever disagree,
 * the client is wrong by definition.
 */
final class Formulas
{
    // ---------------------------------------------------------- equipment §8.1

    /**
     * Aggregate one stat across equipped items.
     *
     * Rule 2 -- diminishing returns on stacking: sorted strongest-first, the nth
     * contributor is worth value * falloff^(n-1). This is what stops a whale
     * buying three identical bundles for linear scaling.
     *
     * Rule 1 -- the total is then clamped to the hard ceiling of the best tier
     * present, so rarity buys durability and reliability, not power.
     *
     * §8 gathering tools are line-locked: a bow does nothing to a tree. A tool
     * counts only when `$line` is the skill line it serves, so passing null --
     * "no line is being worked" -- leaves every tool out and returns what the
     * player gets from gear that works anywhere.
     *
     * @param  array<int,array{key:string,durability:int,equipped:bool}>  $items
     */
    public static function aggregateStat(array $items, string $stat, ?string $line = null): float
    {
        $values = [];
        $bestCap = Balance::STAT_CAP['basic'];
        $found = false;

        foreach ($items as $item) {
            if (! $item['equipped'] || $item['durability'] <= 0) {
                continue;
            }
            $def = Catalog::item($item['key']);
            if ($def === null || $def['stat'] !== $stat) {
                continue;
            }
            $toolLine = Catalog::skillForSlot($def['slot']);
            if ($toolLine !== null && $toolLine !== $line) {
                continue;
            }

            $values[] = $def['value'];
            $found = true;
            $cap = Balance::STAT_CAP[$def['tier']];
            if ($cap > $bestCap) {
                $bestCap = $cap;
            }
        }

        if (! $found) {
            return 0.0;
        }

        rsort($values);

        $total = 0.0;
        foreach ($values as $index => $value) {
            $total += $value * Balance::STACK_FALLOFF ** $index;
        }

        return min($total, $bestCap);
    }

    /** Salvage returned when an item is discarded, §8.2. */
    public static function salvageYield(array $def): array
    {
        $out = [];
        foreach ($def['inputs'] ?? [] as $key => $qty) {
            $amount = (int) floor($qty * Balance::SALVAGE_RATE);
            if ($amount > 0) {
                $out[$key] = $amount;
            }
        }

        return $out;
    }

    /** Repair cost, §8.2: cheaper than crafting new, but not dramatically so. */
    public static function repairCost(array $def, int $missingDurability): array
    {
        $fraction = $missingDurability / $def['maxDurability'];
        $out = [];
        foreach ($def['inputs'] ?? [] as $key => $qty) {
            $amount = (int) ceil($qty * $fraction * Balance::REPAIR_COST_RATE);
            if ($amount > 0) {
                $out[$key] = $amount;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------- mining §7.3

    /**
     *   trip_time = clamp(base - skill_reduction - equipment_reduction, 30m, 60m)
     *
     * The floor clamp is mandatory and has been in the formula from day one:
     * without it any future buff or equipment tier creates a sub-30-minute or
     * zero-time exploit. Do not remove it, and do not apply bonuses after it.
     *
     * @return array{base:int,skillReduction:int,equipReduction:int,total:int,clamped:bool}
     */
    public static function tripTime(int $baseSeconds, int $skillLevel, float $equipTripReduction): array
    {
        $skillProgress = min(1.0, $skillLevel / Balance::SKILL_MAX_LEVEL);
        $skillReduction = (int) round(Balance::MINING_MAX_SKILL_REDUCTION * $skillProgress);

        $equipProgress = min(1.0, $equipTripReduction / Balance::STAT_CAP['nft']);
        $equipReduction = (int) round(Balance::MINING_MAX_EQUIP_REDUCTION * $equipProgress);

        $raw = $baseSeconds - $skillReduction - $equipReduction;
        $total = min(Balance::MINING_CEILING_SECONDS, max(Balance::MINING_FLOOR_SECONDS, $raw));

        return [
            'base' => $baseSeconds,
            'skillReduction' => $skillReduction,
            'equipReduction' => $equipReduction,
            'total' => $total,
            'clamped' => $total !== $raw,
        ];
    }

    /** Yield for one trip. Skill and gear add; ring adds the risk premium. */
    public static function tripYield(
        int $baseYield,
        int $skillLevel,
        float $equipYieldBonus,
        float $ringMultiplier,
    ): int {
        $skillBonus = 1 + ($skillLevel / Balance::SKILL_MAX_LEVEL) * 0.5;

        return max(1, (int) round($baseYield * $skillBonus * (1 + $equipYieldBonus) * $ringMultiplier));
    }

    // -------------------------------------------------------- processing §6

    public static function processingTime(
        int $baseSeconds,
        string $tier,
        bool $presence,
        float $equipProcessingBonus,
    ): int {
        $tierSpeed = Balance::settlementSpeed($tier);
        $presenceSpeed = $presence ? 1 - Balance::PRESENCE_SPEED_BONUS : 1;

        return max(30, (int) round($baseSeconds * $tierSpeed * $presenceSpeed * (1 - $equipProcessingBonus)));
    }

    // --------------------------------------------------------- character §7.1

    /**
     * Lazy AP regeneration. Stored as (ap, apUpdatedAt); the value at any moment
     * is derived, never ticked. That is what keeps the client from asserting time
     * and keeps regen correct across arbitrary offline gaps.
     *
     * @return array{ap:int,apUpdatedAt:int}
     */
    public static function regenerateAp(int $ap, int $apUpdatedAt, int $level, int $now, int $regenMs): array
    {
        $max = Balance::apMax($level);
        if ($ap >= $max) {
            return ['ap' => $ap, 'apUpdatedAt' => $now];
        }

        $gained = intdiv(max(0, $now - $apUpdatedAt), $regenMs);
        if ($gained <= 0) {
            return ['ap' => $ap, 'apUpdatedAt' => $apUpdatedAt];
        }

        $next = min($max, $ap + $gained);
        // Keep the remainder so partial progress toward the next point survives.
        $consumed = $next >= $max ? $now - $apUpdatedAt : $gained * $regenMs;

        return ['ap' => $next, 'apUpdatedAt' => $apUpdatedAt + $consumed];
    }

    /**
     * Apply XP, cascading level-ups if a large grant lands at once.
     *
     * @return array{level:int,xp:int,levelsGained:int}
     */
    public static function applyXp(int $level, int $xp, int $gain, int $maxLevel, callable $curve): array
    {
        $nextLevel = $level;
        $nextXp = $xp + $gain;
        $levelsGained = 0;

        while ($nextLevel < $maxLevel && $nextXp >= $curve($nextLevel)) {
            $nextXp -= $curve($nextLevel);
            $nextLevel++;
            $levelsGained++;
        }

        if ($nextLevel >= $maxLevel) {
            $nextXp = 0;
        }

        return ['level' => $nextLevel, 'xp' => $nextXp, 'levelsGained' => $levelsGained];
    }
}
