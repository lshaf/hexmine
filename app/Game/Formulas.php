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
            // §8 -- a gathering tool has no percentage at all now; its base is
            // a solid attack. Absent rather than zero, so nothing has to know
            // which stat a tool would have had.
            if (($def['stat'] ?? null) === $stat) {
                $contributions[] = $def['value'];
            }
            foreach ($item['options'] ?? [] as $option) {
                if (($option['stat'] ?? null) !== $stat) {
                    continue;
                }

                // §8.0.1 -- a flat line is a solid number, not a percentage.
                // It is added by whoever reads the solid number; this is the
                // aggregate with the falloff and the ceiling on it, and putting
                // "+3 attack" through that would be nonsense twice over.
                if (($option['kind'] ?? 'percent') === 'flat') {
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
     * §8.0.1 -- roll a crafted item's bonus lines.
     *
     * Seeded rather than random so an outcome can be reproduced from its
     * inputs, the same way §16 treats every other roll in the game.
     *
     * Three things are random and that is the point: HOW MANY lines come out
     * (nothing up to the rung's ceiling), WHICH tier each line is drawn from
     * (any at or below the item's own rarity, so a legendary often carries a
     * common-grade line), and what it is worth inside that tier. Two of the
     * same recipe are never the same object.
     *
     * A worn line may come out pointed at one gathering line -- "+4% mining
     * yield" -- and is worth OPTION_SCOPED_MULTIPLIER more when it does,
     * because it is worth nothing on the other four. `scope` is absent on a
     * flat line rather than null, so every row already stored keeps its shape.
     *
     * `$extra` widens the ceiling: a Smith's tree node, or the extra slot a
     * hard pack's loot rolls (§9.5.8). Nothing BOUGHT ever comes here -- gold
     * buys a plain item and always has.
     *
     * @return array<int,array{stat:string,value:float,scope?:string}>
     */
    public static function rollOptions(array $def, int $seed, int $extra = 0): array
    {
        $ceiling = (Balance::OPTION_ROLLS[$def['rarity']] ?? 0) + $extra;
        if ($ceiling <= 0) {
            return [];
        }

        $pool = Catalog::optionRollsFor($def['slot'] ?? '');
        if ($pool === []) {
            return [];
        }

        // Nothing up to the ceiling. An item with no lines is a plain item, not
        // a broken one.
        $slots = Hash::randInt(Hash::hash2($seed, 890, Balance::mapSeed()), 0, $ceiling);

        $tiers = self::optionTiersFor($def['rarity']);
        $out = [];
        $used = [];

        for ($i = 0; $i < $slots; $i++) {
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

            $tier = $tiers[Hash::randInt(
                Hash::hash2($seed, 930 + $i, Balance::mapSeed()),
                0,
                count($tiers) - 1,
            )];

            $flat = ($pick['kind'] ?? 'percent') === 'flat';
            $valueSeed = Hash::hash2($seed, 920 + $i, Balance::mapSeed());

            if ($flat) {
                // §9.5.4 -- attack and defense are solid numbers, so the line
                // is one too. No scope: a flat pair has no gathering line to
                // belong to, and on a tool the slot already names it.
                [$min, $max] = Balance::OPTION_FLAT_VALUE[$tier];

                $out[] = [
                    'stat' => $pick['stat'],
                    'value' => Hash::randInt($valueSeed, (int) $min, (int) $max),
                    'kind' => 'flat',
                ];

                continue;
            }

            [$min, $max] = Balance::OPTION_VALUE[$tier];

            if ($pick['scope'] !== null) {
                $min *= Balance::OPTION_SCOPED_MULTIPLIER;
                $max *= Balance::OPTION_SCOPED_MULTIPLIER;
            }

            $steps = max(1, (int) round(($max - $min) * 100));
            $roll = Hash::randInt($valueSeed, 0, $steps);

            $line = [
                'stat' => $pick['stat'],
                'value' => round($min + $roll / 100, 2),
            ];
            if ($pick['scope'] !== null) {
                $line['scope'] = $pick['scope'];
            }

            $out[] = $line;
        }

        return $out;
    }

    /**
     * §8.0.1 -- the option tiers an item of this rarity may draw a line from.
     *
     * Everything at or below its own rung. A higher rarity does not roll a
     * better line every time; it rolls from a deeper bag, which is a different
     * and more interesting thing.
     *
     * @return list<string>
     */
    public static function optionTiersFor(string $rarity): array
    {
        $tiers = [];

        foreach (array_keys(Balance::OPTION_VALUE) as $tier) {
            $tiers[] = $tier;

            if ($tier === $rarity) {
                break;
            }
        }

        return $tiers;
    }

    /** @param array{stat:string,scope:?string,kind?:string} $entry */
    private static function optionKey(array $entry): string
    {
        // Kind is part of the identity: "+2 defense" and "+2% defense" are two
        // different lines that happen to share a name (§9.5.4).
        return ($entry['kind'] ?? 'percent').'|'.$entry['stat'].'|'.($entry['scope'] ?? '');
    }

    /**
     * §8.0.1 -- what an item's flat rolled lines add to one solid number.
     *
     * Percentage lines are aggregated somewhere else entirely (aggregateStat),
     * under the falloff and the ceiling; these are not percentages and neither
     * applies to them. They add.
     *
     * @param  array<int,array<string,mixed>>  $options
     */
    public static function flatOption(array $options, string $stat): int
    {
        $total = 0;

        foreach ($options as $option) {
            if (($option['kind'] ?? 'percent') !== 'flat') {
                continue;
            }
            if (($option['stat'] ?? null) !== $stat) {
                continue;
            }

            $total += (int) $option['value'];
        }

        return $total;
    }

    // ------------------------------------------------------------ combat §9.5

    /**
     * §9.5.4 -- what a character brings to a fight.
     *
     * Flat numbers off the gear, because §8.1's ceiling is +15% and a fight
     * cannot be decided by a swing that small. The percentages are still here:
     * `power` and `defense` MULTIPLY the gear half, so everything that feeds the
     * ordinary aggregate -- rolled options, tree nodes, a battle draft --
     * lands somewhere real without a second ceiling being invented for it.
     *
     * The battle job is added flat afterwards, in both halves. It is the proof
     * you have fought, and it is worth the same whether you are swinging or
     * being swung at.
     *
     * @param  array<int,array{key:string,durability:int,equipped:bool}>  $items
     * @return array{attack:int,defense:int}
     */
    public static function combatPair(
        array $items,
        int $jobLevel = 0,
        float $power = 0.0,
        float $defense = 0.0,
    ): array {
        $gearAttack = 0;
        $gearDefense = 0;

        foreach ($items as $item) {
            if (! $item['equipped'] || $item['durability'] <= 0) {
                continue;
            }

            $def = Catalog::item($item['key']);
            if ($def === null) {
                continue;
            }

            // §8 rule 5 -- combat slots only. A gathering tool's attack is
            // MINING attack (§7.3) and so is a flat line rolled onto one, so
            // neither reaches a fight.
            if (! in_array($def['slot'] ?? '', Balance::COMBAT_SLOTS, true)) {
                continue;
            }

            $gearAttack += (int) ($def['attack'] ?? 0)
                + self::flatOption($item['options'] ?? [], 'attack');
            $gearDefense += (int) ($def['defense'] ?? 0)
                + self::flatOption($item['options'] ?? [], 'defense');
        }

        $might = intdiv($jobLevel, Balance::BATTLE_JOB_DIVISOR);

        return [
            'attack' => (int) round($gearAttack * (1 + $power)) + $might,
            'defense' => (int) round($gearDefense * (1 + $defense)) + $might,
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
    public static function battleMargin(int $attack, int $defense, array $monster): float
    {
        $strike = $attack - (int) $monster['defense'];
        $hold = $defense - (int) $monster['attack'];

        return ($strike + $hold) / 2;
    }

    /** §9.5.5 -- the margin as a chance, kept for the preview's shorthand. */
    public static function battleOdds(int $attack, int $defense, array $monster): float
    {
        $margin = self::battleMargin($attack, $defense, $monster);

        return max(
            Balance::BATTLE_ODDS_MIN,
            min(Balance::BATTLE_ODDS_MAX, 0.5 + $margin / (2 * Balance::BATTLE_BAND)),
        );
    }

    /**
     * §9.5.5 -- what a character brings to a fight as HP.
     *
     * Durability IS the health bar. There is no second pool to invent and no
     * second thing to lose: the gear that is holding you up is the gear the
     * fight is spending, which is why a beating and a repair bill are the same
     * event rather than two.
     *
     * Combat slots only (§9.5.4). A tool belt is not armor.
     *
     * @param  array<int,array{key:string,durability:int,equipped:bool}>  $items
     */
    public static function battlePool(array $items): int
    {
        $pool = 0;

        foreach ($items as $item) {
            if (! $item['equipped'] || $item['durability'] <= 0) {
                continue;
            }

            $def = Catalog::item($item['key']);
            if ($def === null || ! in_array($def['slot'] ?? '', Balance::COMBAT_SLOTS, true)) {
                continue;
            }

            $pool += (int) $item['durability'];
        }

        return $pool;
    }

    /**
     * §9.5.5 -- the fight, as an exchange rather than a coin.
     *
     * Each round you strike first and it strikes back if it is still standing.
     * A strike is the gap between one side's attack and the other's defense,
     * never less than a chip, and it wanders by BATTLE_SWING so that two runs
     * at the same pack are not the same fight.
     *
     * You close the distance, so you swing first. It is a small edge and it is
     * the right one: engaging is a decision you made and being engaged is not.
     *
     * The bell (BATTLE_MAX_ROUNDS) exists for the chip-against-chip case, where
     * two walls would otherwise stand there all day. Whoever has more of their
     * pool left when it rings takes it, and a dead heat goes against the one
     * who picked the fight.
     *
     * @return array{won:bool,rounds:int,damageTaken:int,damageDealt:int,left:int,foeLeft:int}
     */
    public static function resolveBattle(
        int $attack,
        int $defense,
        int $pool,
        array $monster,
        int $seed,
    ): array {
        $hp = max(0, $pool);
        $foe = max(1, (int) ($monster['hp'] ?? 1));
        $taken = 0;
        $dealt = 0;
        $round = 0;

        while ($hp > 0 && $foe > 0 && $round < Balance::BATTLE_MAX_ROUNDS) {
            $round++;

            $hit = self::strike($attack, (int) $monster['defense'], $seed, $round, 0);
            $foe -= $hit;
            $dealt += $hit;

            if ($foe <= 0) {
                break;
            }

            $back = self::strike((int) $monster['attack'], $defense, $seed, $round, 1);
            $hp -= $back;
            $taken += $back;
        }

        // The bell is a loss. Anything else and a big enough pool grinds down
        // a wall it has no business touching.
        $won = $foe <= 0;

        return [
            'won' => $won,
            'rounds' => $round,
            'damageTaken' => min($taken, max(0, $pool)),
            'damageDealt' => $dealt,
            'left' => max(0, $hp),
            'foeLeft' => max(0, $foe),
        ];
    }

    /** §9.5.5 -- one blow, floored at a chip and wandering by the swing. */
    private static function strike(int $attack, int $guard, int $seed, int $round, int $side): int
    {
        $roll = Hash::rand01(Hash::hash2($seed, $round * 2 + $side, Balance::mapSeed()));
        $swing = 1 + (($roll * 2) - 1) * Balance::BATTLE_SWING;

        return max(
            self::strikeFloor($attack),
            (int) round(max(0, $attack - $guard) * $swing),
        );
    }

    /**
     * §9.5.5 -- what gets through however good the guard is.
     *
     * A fraction of the attack rather than a flat point, so a heavy hitter
     * still hurts a wall and a light one still cannot. That slope is what makes
     * the difference between a rare kit and an epic one against the same
     * carapace, where straight subtraction made both of them chip for one.
     */
    public static function strikeFloor(int $attack): int
    {
        return max(Balance::BATTLE_CHIP, (int) ceil($attack * Balance::BATTLE_CHIP_FRACTION));
    }

    /**
     * §9.5.5 -- the same exchange with the swing taken out, for the preview.
     *
     * A promise rather than a guess: the numbers on the plate are what the
     * arithmetic says, and the fight then wanders by ten per cent either way.
     *
     * @return array{won:bool,rounds:int,damageTaken:int,damageDealt:int,left:int,foeLeft:int}
     */
    public static function expectedBattle(int $attack, int $defense, int $pool, array $monster): array
    {
        $foe = max(1, (int) ($monster['hp'] ?? 1));

        $mine = max(self::strikeFloor($attack), $attack - (int) $monster['defense']);
        $theirs = max(
            self::strikeFloor((int) $monster['attack']),
            (int) $monster['attack'] - $defense,
        );

        $roundsToKill = (int) ceil($foe / $mine);
        $roundsToFall = (int) ceil(max(0, $pool) / $theirs);

        // The bell is a loss, so running out of rounds counts against you the
        // same way running out of pool does.
        $won = $roundsToKill <= $roundsToFall && $roundsToKill <= Balance::BATTLE_MAX_ROUNDS;
        $rounds = min($roundsToKill, $roundsToFall, Balance::BATTLE_MAX_ROUNDS);

        return [
            'won' => $won,
            'rounds' => $rounds,
            'damageTaken' => min($pool, ($won ? $rounds - 1 : $rounds) * $theirs),
            'damageDealt' => min($foe, $rounds * $mine),
            'left' => max(0, $pool - ($won ? $rounds - 1 : $rounds) * $theirs),
            'foeLeft' => max(0, $foe - $rounds * $mine),
        ];
    }

    /**
     * §9.5.6 -- what the blade pays, and it pays for what it was swung AT.
     *
     * Enemy armor is what blunts a weapon, so the bill is the monster's defense
     * spread over the rounds it took. Hitting a wall chips the edge, which is
     * why bringing the wrong class is expensive even when it wins -- and a
     * swift monster blunts harder than its numbers suggest, which is its whole
     * `wearBias`.
     */
    public static function weaponWear(array $monster, int $rounds, int $maxDurability): int
    {
        $perRound = max(
            1,
            (int) ceil((int) $monster['defense'] / Balance::WEAPON_WEAR_DIVISOR),
        );

        $wear = $perRound * max(1, $rounds) * ($monster['wearBias'] ?? 1.0);

        return min(max(1, $maxDurability), max(1, (int) round($wear)));
    }

    /**
     * §9.5.6 -- how much of a beating one fight may actually take off the kit.
     *
     * The exchange is settled on the full pool; this caps the bill. Without it
     * one hopeless swing in the center strips a legendary set in a single go,
     * and §8.2's warning would be the only thing between a player and a week of
     * work. The fight is still lost either way -- the cap is on the cost.
     */
    public static function cappedBattleWear(int $damageTaken, int $pool): int
    {
        $cap = (int) floor($pool * Balance::BATTLE_POOL_WEAR_CAP);

        return max(0, min($damageTaken, $cap));
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
     * §7.3 -- a hex is an amount of WORK, and a trip is how long you take over it.
     *
     *   durability = base_seconds * MINING_BASE_ATTACK
     *   rate       = (base + tool + skill) * (1 + trip_reduction)
     *   trip_time  = clamp(durability / rate, 15m, 60m)
     *
     * The old formula subtracted flat minutes for skill and for gear, which
     * made a good tool worth exactly as much on a rich hex as on a poor one. A
     * rate does the thing the ladder is for: a better tool takes a bigger bite
     * out of whatever is in front of it, so the hardest ground is where it pays
     * most.
     *
     * The floor clamp is mandatory and has been in the formula from day one:
     * without it any future buff or equipment tier creates a sub-floor or
     * zero-time exploit. Do not remove it, and do not apply bonuses after it.
     *
     * @return array{base:int,durability:int,toolAttack:int,skillAttack:int,rate:float,total:int,clamped:bool}
     */
    public static function tripTime(
        int $baseSeconds,
        int $skillLevel,
        float $equipTripReduction,
        int $toolAttack = 0,
    ): array {
        $durability = self::tileDurability($baseSeconds);

        $skillProgress = min(1.0, $skillLevel / Balance::SKILL_MAX_LEVEL);
        $skillAttack = Balance::MINING_SKILL_ATTACK * $skillProgress;

        $rate = (Balance::MINING_BASE_ATTACK + $toolAttack + $skillAttack)
            * (1 + max(0.0, $equipTripReduction));

        $raw = (int) round($durability / max(1.0, $rate));
        $total = min(Balance::MINING_CEILING_SECONDS, max(Balance::MINING_FLOOR_SECONDS, $raw));

        return [
            'base' => $baseSeconds,
            'durability' => $durability,
            'toolAttack' => $toolAttack,
            'skillAttack' => (int) round($skillAttack),
            'rate' => round($rate, 2),
            'total' => $total,
            'clamped' => $total !== $raw,
        ];
    }

    /**
     * §7.3 -- how much work a hex is, which is what a trip actually spends.
     *
     * Derived from the base seconds the world already rolls for the tile rather
     * than stored beside them: they are the same fact said twice, and at
     * MINING_BASE_ATTACK a bare-handed trip therefore takes exactly the seconds
     * the tile was rolled for.
     */
    public static function tileDurability(int $baseSeconds): int
    {
        return $baseSeconds * Balance::MINING_BASE_ATTACK;
    }

    /**
     * §8 -- what a gathering tool takes out of a hex each second.
     *
     * A tool's BASE stat, and the only one it has. It used to lead with a yield
     * percentage and have its attack derived from that, which conflated the two
     * halves of a trip: attack is how fast you work through a hex (§7.3) and
     * yield is how big the haul is. They are different questions, so they are
     * different numbers, and a tool answers the first one.
     *
     * Mining attack only. A tool is worth nothing in a fight (§8 rule 5), which
     * is why combatPair skips every non-combat slot rather than reading this.
     */
    public static function toolAttack(?array $def): int
    {
        return (int) ($def['attack'] ?? 0);
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
