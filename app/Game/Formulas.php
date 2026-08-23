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
     * Rule 1 -- the total is then clamped to the ceiling of the best *rarity*
     * present. Rarity climbs toward a single global ceiling (Balance::STAT_CEILING)
     * and nothing may pass it: not a future rarity, not a rolled option, not a
     * buff. That ceiling is what keeps a whale within reach of a grinder.
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
        $bestCap = Balance::STAT_CAP['common'];
        $found = false;

        foreach ($items as $item) {
            if (! $item['equipped'] || $item['durability'] <= 0) {
                continue;
            }
            // Not filtered on the base stat here: §8.0.1 lets an item reach a
            // stat it was never built for, through a rolled line.
            $def = Catalog::item($item['key']);
            if ($def === null) {
                continue;
            }
            $toolLine = Catalog::skillForSlot($def['slot'] ?? '');
            if ($toolLine !== null && $toolLine !== $line) {
                continue;
            }

            // §8.0.1 -- a rolled line is just another contributor. It goes into
            // the same falloff and under the same cap, so options can add
            // variety without ever becoming a second power ladder. Note they
            // inherit the line-lock from their item, which is what stops five
            // equipped tools stacking five copies of the same bonus.
            $contributions = [];
            if ($def['stat'] === $stat) {
                $contributions[] = $def['value'];
            }
            foreach ($item['options'] ?? [] as $option) {
                if (($option['stat'] ?? null) !== $stat) {
                    continue;
                }

                // §8.0.1 -- a scoped line pays in full on the line it names and
                // nothing anywhere else. No line being worked is one of those
                // elsewheres, which is what keeps "+4% mining yield" off the
                // road and off the bench.
                $scope = $option['scope'] ?? null;
                if ($scope !== null && $scope !== $line) {
                    continue;
                }

                $contributions[] = (float) $option['value'];
            }

            if ($contributions === []) {
                continue;
            }

            foreach ($contributions as $value) {
                $values[] = $value;
            }
            $found = true;
            $cap = Balance::STAT_CAP[$def['rarity']];
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

    /**
     * §8.0.1 -- roll an item's bonus lines.
     *
     * Seeded rather than random so an outcome can be reproduced from its inputs,
     * the same way §16 treats every other roll in the game. `$extra` is the
     * capital bazaar's bonus slot, which is the one way a common item ever
     * carries a line.
     *
     * A worn line may come out pointed at one gathering line -- "+4% mining
     * yield" -- and is worth more when it does, because it is worth nothing on
     * the other four (Balance::OPTION_SCOPED_MIN). `scope` is absent on a flat
     * line rather than null, so every row already stored keeps its shape.
     *
     * @return array<int,array{stat:string,value:float,scope?:string}>
     */
    public static function rollOptions(array $def, int $seed, int $extra = 0): array
    {
        $slots = (Balance::OPTION_ROLLS[$def['rarity']] ?? 0) + $extra;
        if ($slots <= 0) {
            return [];
        }

        $pool = Catalog::optionRollsFor($def['slot'] ?? '');
        $out = [];
        $used = [];

        for ($i = 0; $i < $slots; $i++) {
            // Uncommon may come up empty; everything above it always fills.
            if ($def['rarity'] === 'uncommon' && $i < Balance::OPTION_ROLLS['uncommon']) {
                if (Hash::rand01(Hash::hash2($seed, 900 + $i, Balance::mapSeed())) >= Balance::OPTION_CHANCE_UNCOMMON) {
                    continue;
                }
            }

            // One line per (stat, scope): two "+2% mining yield" rows on one
            // item reads as a bug, while mining yield beside hunting yield is
            // two things the same piece of armor is genuinely good at.
            $choices = array_values(array_filter(
                $pool,
                static fn (array $entry) => ! in_array(self::optionKey($entry), $used, true),
            ));
            if ($choices === []) {
                break;
            }

            $pick = $choices[Hash::randInt(Hash::hash2($seed, 910 + $i, Balance::mapSeed()), 0, count($choices) - 1)];
            $used[] = self::optionKey($pick);

            $scoped = $pick['scope'] !== null;
            $min = $scoped ? Balance::OPTION_SCOPED_MIN : Balance::OPTION_MIN;
            $max = $scoped ? Balance::OPTION_SCOPED_MAX : Balance::OPTION_MAX;

            $steps = (int) round(($max - $min) * 100);
            $roll = Hash::randInt(Hash::hash2($seed, 920 + $i, Balance::mapSeed()), 0, $steps);

            $line = [
                'stat' => $pick['stat'],
                'value' => round($min + $roll / 100, 2),
            ];
            if ($scoped) {
                $line['scope'] = $pick['scope'];
            }

            $out[] = $line;
        }

        return $out;
    }

    /** @param array{stat:string,scope:?string} $entry */
    private static function optionKey(array $entry): string
    {
        return $entry['stat'].'|'.($entry['scope'] ?? '');
    }

    // ------------------------------------------------------------ combat §9.5

    /**
     * §9.5.4 -- what a character brings to a fight.
     *
     * Flat numbers off the gear, because §8.1's ceiling is +15% and a fight
     * cannot be decided by a swing that small. The percentages are still here:
     * `power` and `defence` MULTIPLY the gear half, so everything that feeds the
     * ordinary aggregate -- rolled options, tree nodes, a battle draught --
     * lands somewhere real without a second ceiling being invented for it.
     *
     * The battle job is added flat afterwards, in both halves. It is the proof
     * you have fought, and it is worth the same whether you are swinging or
     * being swung at.
     *
     * @param  array<int,array{key:string,durability:int,equipped:bool}>  $items
     * @return array{attack:int,defence:int}
     */
    public static function combatPair(
        array $items,
        int $jobLevel = 0,
        float $power = 0.0,
        float $defence = 0.0,
    ): array {
        $gearAttack = 0;
        $gearDefence = 0;

        foreach ($items as $item) {
            if (! $item['equipped'] || $item['durability'] <= 0) {
                continue;
            }

            $def = Catalog::item($item['key']);
            if ($def === null) {
                continue;
            }

            $gearAttack += (int) ($def['attack'] ?? 0);
            $gearDefence += (int) ($def['defence'] ?? 0);
        }

        $might = intdiv($jobLevel, Balance::BATTLE_JOB_DIVISOR);

        return [
            'attack' => (int) round($gearAttack * (1 + $power)) + $might,
            'defence' => (int) round($gearDefence * (1 + $defence)) + $might,
        ];
    }

    /**
     * §9.5.5 -- how the fight leans before the die is thrown.
     *
     * Strike is what you get through, hold is what you keep out, and the fight
     * is the average of the two. That is what makes the profiles in §9.5.2
     * matter: a brute loses to armor, a carapace loses to reach, and the same
     * kit is not the answer to both.
     */
    public static function battleMargin(int $attack, int $defence, array $monster): float
    {
        $strike = $attack - (int) $monster['defence'];
        $hold = $defence - (int) $monster['attack'];

        return ($strike + $hold) / 2;
    }

    /** §9.5.5 -- the margin as a chance, and never certain in either direction. */
    public static function battleOdds(int $attack, int $defence, array $monster): float
    {
        $margin = self::battleMargin($attack, $defence, $monster);

        return max(
            Balance::BATTLE_ODDS_MIN,
            min(Balance::BATTLE_ODDS_MAX, 0.5 + $margin / (2 * Balance::BATTLE_BAND)),
        );
    }

    /**
     * §9.5.6 -- what the weapon pays, on the gap to their defence.
     *
     * Hitting a wall chips the blade, which is why bringing the wrong class is
     * expensive EVEN WHEN YOU WIN. A swift monster blunts harder than its
     * numbers suggest, and that is its whole `wearBias`.
     */
    public static function weaponWear(int $attack, array $monster, bool $won, int $maxDurability): int
    {
        $gap = max(0, (int) $monster['defence'] - $attack);

        $wear = (Balance::WEAR_BASE + $gap * Balance::WEAR_PER_GAP)
            * ($monster['wearBias'] ?? 1.0)
            * ($won ? 1.0 : Balance::WEAR_LOSS_MULTIPLIER);

        return self::cappedWear($wear, $maxDurability);
    }

    /** §9.5.6 -- what one worn piece pays, on the excess of their attack over its guard. */
    public static function armorWear(int $pieceDefence, array $monster, bool $won, int $maxDurability): int
    {
        $excess = max(0, (int) $monster['attack'] - $pieceDefence);

        $wear = (Balance::WEAR_BASE + $excess * Balance::WEAR_PER_EXCESS)
            * ($won ? 1.0 : Balance::WEAR_LOSS_MULTIPLIER);

        return self::cappedWear($wear, $maxDurability);
    }

    /** No fight takes more than §9.5.6's share of an item, now that zero is fatal. */
    private static function cappedWear(float $wear, int $maxDurability): int
    {
        $cap = max(1, (int) floor($maxDurability * Balance::WEAR_CAP_FRACTION));

        return min($cap, max(1, (int) ceil($wear)));
    }

    /** Salvage returned when an item is discarded, §8.2. */
    /**
     * §8.2 -- what a trader pays for a piece of shop gear.
     *
     * Half the shelf price, scaled by what is left of the item: a half-worn axe
     * fetches half of half. Wear is already the thing the player is losing to
     * (§8.1 rule 3), so the resale simply reports it back rather than inventing
     * a second schedule for it.
     *
     * Zero for anything the trader does not stock. Gold buys the bottom two
     * rungs and nothing else (§3.2), so there is no shelf price to halve for a
     * crafted or NFT piece -- and §8.2's salvage is that gear's exit, not this.
     * Zero is also what a broken piece is worth, which the caller refuses
     * rather than paying: an idle game must not take something for nothing.
     */
    public static function resaleValue(array $def, int $durability): int
    {
        $price = $def['goldPrice'] ?? 0;
        $max = $def['maxDurability'] ?? 0;

        if ($price <= 0 || $max <= 0 || $durability <= 0) {
            return 0;
        }

        return (int) floor($price * Balance::RESALE_RATE * (min($durability, $max) / $max));
    }

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

        $equipProgress = min(1.0, $equipTripReduction / Balance::STAT_CEILING);
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
