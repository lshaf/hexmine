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
    public static function rollOptions(array $def, int $seed, int $extra = 0, float $upgrade = 0.0): array
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

            $index = Hash::randInt(
                Hash::hash2($seed, 930 + $i, Balance::mapSeed()),
                0,
                count($tiers) - 1,
            );

            // §8.0.1 -- the bag a line is drawn from still stops at the item's
            // own rarity. `$upgrade` is a bench's chance of reaching one band
            // deeper into it, never past the top of it, so a maker's tree makes
            // a good roll likelier and can never make a better item.
            if ($upgrade > 0 && $index < count($tiers) - 1 && Hash::rand01(
                Hash::hash2($seed, 950 + $i, Balance::mapSeed())
            ) < $upgrade) {
                $index++;
            }

            $tier = $tiers[$index];

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
        int $treeAttack = 0,
        int $treeDefense = 0,
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

        // §7.4 -- the tree's solid numbers join the gear before the percentage
        // multiplies, because they are the same kind of thing: what you are
        // carrying and what you know how to do with it. The job level is added
        // after, as the flat proof of having fought.
        return [
            'attack' => (int) round(($gearAttack + $treeAttack) * (1 + $power)) + $might,
            'defense' => (int) round(($gearDefense + $treeDefense) * (1 + $defense)) + $might,
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
     * The round-by-round `log` is what the screen draws (§9.5.5): the fight is
     * over the instant you close, and the modal is a replay of something the
     * server already settled rather than a negotiation with it.
     *
     * §9.5.9 -- and what the weapon knows how to do besides swing. Every skill
     * starts the fight ON COOLDOWN, so the earliest any of them lands is round
     * `cooldown + 1` and a rout never sees one. At most one fires a round, the
     * first one listed for the family that is ready (BattleSkills::armed).
     *
     * @param  list<array<string,mixed>>  $skills
     * @return array{won:bool,rounds:int,damageTaken:int,damageDealt:int,left:int,foeLeft:int,log:list<array<string,mixed>>}
     */
    public static function resolveBattle(
        int $attack,
        int $defense,
        int $pool,
        array $monster,
        int $seed,
        array $skills = [],
        bool $steady = false,
    ): array {
        $hp = max(0, $pool);
        $foe = max(1, (int) ($monster['hp'] ?? 1));
        $taken = 0;
        $dealt = 0;
        $round = 0;
        $log = [];

        // §9.5.9 -- the round each skill last went, and zero means "never".
        //
        // Held as the round rather than as a counter to tick down, because a
        // counter has to be decremented somewhere and the somewhere is easy to
        // leave out -- which is exactly what happened first time: the cooldowns
        // were set at the bell, never counted down, and not one skill fired in
        // the whole game. Read off the clock there is nothing to forget.
        //
        // Zero also gives the on-cooldown start for free: ready means
        // `round - last >= cooldown`, so with nothing used yet the earliest any
        // of them lands is round `cooldown`.
        $used = array_fill(0, count($skills), 0);

        $stunned = 0;
        $extra = 0;
        $riposte = 0;
        $sundered = 0;
        $burn = null;
        $stance = null;

        while ($hp > 0 && $foe > 0 && $round < Balance::BATTLE_MAX_ROUNDS) {
            $round++;
            $entry = ['hit' => 0, 'back' => 0, 'hp' => $hp, 'foe' => $foe];

            // What is already alight goes on burning whatever it is wearing.
            // Resolved before the swing, so a foe finished off by a burn is
            // finished without a blow being logged that never landed.
            if ($burn !== null) {
                $seared = max(1, (int) round($attack * $burn['tick']));
                $foe -= $seared;
                $dealt += $seared;
                $entry['burn'] = $seared;

                if (--$burn['left'] <= 0) {
                    $burn = null;
                }

                if ($foe <= 0) {
                    $entry['foe'] = 0;
                    $log[] = $entry;
                    break;
                }
            }

            // One a round at most, and the first listed that is ready. Picked
            // before the blow, because most of them ARE the blow.
            $fired = null;
            foreach ($skills as $i => $skill) {
                if ($round - $used[$i] < (int) $skill['cooldown']) {
                    continue;
                }

                $fired = $skill;
                $used[$i] = $round;
                break;
            }

            $multiplier = 1.0;
            $swung = max(0, $attack);
            $pierce = false;

            if ($fired !== null) {
                $entry['skill'] = $fired['key'];

                if (isset($fired['power'])) {
                    $multiplier = (float) $fired['power'];
                }

                // §9.5.9 -- the arc has been building since the first round,
                // so it reads the clock. Nothing else in the game gets better
                // for the fight having gone badly.
                if (isset($fired['ramp'])) {
                    $multiplier += (float) $fired['ramp'] * ($round - 1);
                }

                $pierce = (bool) ($fired['pierce'] ?? false);

                // The only blow in the game that reads your guard. A wall
                // landing a wall-sized hit is the shield's whole answer to
                // being unable to finish anything (§9.5.4).
                if (! empty($fired['toll'])) {
                    $swung += max(0, $defense);
                    $entry['toll'] = max(0, $defense);
                }

                if (isset($fired['stun'])) {
                    // Set rather than added, so two stuns running do not stack
                    // into a monster that never answers again.
                    $stunned = max($stunned, (int) $fired['stun']);
                    $entry['stunned'] = $stunned;
                }

                if (isset($fired['burn'])) {
                    // Refreshed, never stacked. A second application is the
                    // same fire burning longer.
                    $burn = ['left' => (int) $fired['burn'], 'tick' => (float) $fired['tick']];
                }

                if (isset($fired['strikes'])) {
                    $extra = max($extra, (int) $fired['strikes']);
                }

                if (isset($fired['riposte'])) {
                    $riposte = max($riposte, (int) $fired['riposte']);
                }

                // Permanent, and it stacks with itself. The one effect in the
                // fight that makes every round AFTER this one worth more, which
                // is what makes it the sword's.
                if (isset($fired['sunder'])) {
                    $sundered += (int) $fired['sunder'];
                    $entry['sunder'] = $sundered;
                }

                if (isset($fired['stance'])) {
                    $stance = [
                        'left' => (int) $fired['stance'],
                        'share' => (float) $fired['share'],
                        'stored' => 0,
                    ];
                }
            }

            $guard = $pierce ? 0 : max(0, (int) $monster['defense'] - $sundered);

            $hit = self::strike($swung, $guard, $seed, $round, 0, $multiplier, $steady);
            $foe -= $hit;
            $dealt += $hit;

            // A second swing inside its guard, at no multiplier: what Onslaught
            // buys is the extra blow, not a bigger one.
            if ($extra > 0 && $foe > 0) {
                $again = self::strike($swung, $guard, $seed, $round, 2, 1.0, $steady);
                $foe -= $again;
                $dealt += $again;
                $hit += $again;
                $entry['extra'] = $again;
                $extra--;
            }

            $entry['hit'] = $hit;
            $entry['foe'] = max(0, $foe);

            if ($foe <= 0) {
                $log[] = $entry;
                break;
            }

            // A stunned foe does not answer. It burns a round of the stun
            // whether or not it would have connected, so a stun landed on a
            // round it was going to miss is not a stun wasted.
            if ($stunned > 0) {
                $stunned--;
                $entry['held'] = true;
                $log[] = $entry;

                continue;
            }

            $back = self::strike((int) $monster['attack'], $defense, $seed, $round, 1, 1.0, $steady);

            // Set behind the boss: a share of what lands is kept rather than
            // suffered, and comes back as one blow when the stance breaks.
            if ($stance !== null) {
                $kept = (int) round($back * $stance['share']);
                $back -= $kept;
                $stance['stored'] += $kept;
                $entry['kept'] = $kept;
            }

            $hp -= $back;
            $taken += $back;

            $entry['back'] = $back;
            $entry['hp'] = max(0, $hp);

            // Everything it lands comes straight back, and it comes back
            // through no guard at all -- an answer is not a swing.
            if ($riposte > 0) {
                $foe -= $back;
                $dealt += $back;
                $entry['riposte'] = $back;
                $entry['foe'] = max(0, $foe);
                $riposte--;
            }

            if ($stance !== null && --$stance['left'] <= 0) {
                $foe -= $stance['stored'];
                $dealt += $stance['stored'];
                $entry['released'] = $stance['stored'];
                $entry['foe'] = max(0, $foe);
                $stance = null;
            }

            $log[] = $entry;
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
            'log' => $log,
        ];
    }

    /**
     * §9.5.5 -- how long the exchange takes on screen, in milliseconds.
     *
     * Derived from the fight rather than from the monster's tier: a rout is
     * short and a grind against a wall is long, which is the whole reason to
     * watch it. Real milliseconds, never `scaled()` -- see BATTLE_ROUND_MS.
     */
    public static function battleDurationMs(int $rounds): int
    {
        return max(1, $rounds) * Balance::BATTLE_ROUND_MS + Balance::BATTLE_TAIL_MS;
    }

    /**
     * §9.5.5 -- one blow, floored at a chip and wandering by the swing.
     *
     * THE FLOOR IS APPLIED LAST, AND THAT ORDER IS THE RULE. A blow always
     * lands for at least BATTLE_CHIP however far the attack is under the guard
     * -- bare hands against a Barrow Knight's 58 still take a point off it --
     * because a wall nobody can scratch is a locked hex, and §9.5.3 says
     * fighting is always one of the two ways out of a pin.
     *
     * The order also decouples the floor from BATTLE_SWING's tuning. Folding the
     * max() inside the round() happens to agree at the current +-10%, because
     * round(1 * 0.9) is still 1 -- but it makes the floor depend on the swing
     * never widening past 50%, where a floored blow of 1 would round to 0 and a
     * hopeless fight would silently become an unwinnable one. Applied last, the
     * floor is an invariant rather than a coincidence. There is a test pinning
     * the invariant.
     */
    private static function strike(
        int $attack,
        int $guard,
        int $seed,
        int $round,
        int $side,
        float $multiplier = 1.0,
        bool $steady = false,
    ): int {
        // §9.5.5 -- the preview is the same exchange with the swing taken out,
        // which is the only honest way to promise anything about a fight that
        // now has eight mechanics in it. A closed form could be written for a
        // plain trade of blows and cannot be for a stance that stores damage
        // and returns it later.
        $roll = Hash::rand01(Hash::hash2($seed, $round * 2 + $side, Balance::mapSeed()));
        $swing = $steady ? 1.0 : 1 + (($roll * 2) - 1) * Balance::BATTLE_SWING;

        // §9.5.9 -- the multiplier is on the BLOW, which is the gap the guard
        // has already been taken out of. Multiplying the attack instead would
        // make a strike skill worth nothing against a wall, and the wall is
        // exactly what a strike skill is for.
        //
        // The floor is still applied last (see above), so a multiplied chip is
        // at least a chip: the invariant does not care what happened before it.
        return max(
            self::strikeFloor($attack),
            (int) round(max(0, $attack - $guard) * $multiplier * $swing),
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
     * arithmetic says, and the fight then wanders by BATTLE_SWING either way.
     *
     * It RUNS the exchange rather than approximating it, which it did not use
     * to have to. A plain trade of blows has a closed form -- rounds to kill
     * against rounds to fall -- and §9.5.9's skills have none: a stance that
     * stores damage for three rounds and returns it as one blow cannot be
     * divided out of a total. Running the real loop with the swing pinned is
     * the only version of this that stays a promise.
     *
     * @param  list<array<string,mixed>>  $skills
     * @return array{won:bool,rounds:int,damageTaken:int,damageDealt:int,left:int,foeLeft:int}
     */
    public static function expectedBattle(
        int $attack,
        int $defense,
        int $pool,
        array $monster,
        array $skills = [],
    ): array {
        // Seed zero: nothing reads it once the swing is pinned, and passing the
        // fight's real seed would imply the preview knew which fight this was
        // going to be. It does not -- the seed is not struck until you close.
        $steady = self::resolveBattle($attack, $defense, $pool, $monster, 0, $skills, true);

        unset($steady['log']);

        return $steady;
    }

    /**
     * §9.5.6 -- the whole repair bill for one fight.
     *
     * A quarter of what the fight actually took out of you, and nothing else.
     * The health bar and the repair bill were always meant to be the same
     * number read twice (§9.5.5); this is the reading. A player who watched
     * their pool drop by eighty knows the bill is twenty before the plate says
     * so, which is the point.
     *
     * It replaced two streams -- the whole of the damage capped at half the
     * pool, plus a separate per-round blade bill -- that between them could
     * exceed the damage taken and could not be predicted from the bar.
     */
    public static function battleWearBill(int $damageTaken): int
    {
        return max(0, (int) round($damageTaken * Balance::BATTLE_WEAR_RATE));
    }

    /**
     * §9.5.6 -- which half of the kit pays most of that bill.
     *
     * The bill lands where the fight happened. A monster leaning on its attack
     * beat on the worn set, so armor and boots take the greater share; one
     * leaning on its guard was a wall you spent the fight hitting, so the
     * weapon and gloves do.
     *
     * `wearBias` shifts the RATIO rather than the total, which is what keeps a
     * swift monster's "it blunts whatever it is hit with" true without letting
     * it charge more than a quarter of the damage. A Ridge Wyrm sends half
     * again as much of the same bill to the blade.
     *
     * @param  array<string,mixed>  $monster
     * @return array{worn:float,hands:float}
     */
    public static function battleWearSplit(array $monster): array
    {
        $leansAttack = (int) $monster['attack'] > (int) $monster['defense'];

        $hands = $leansAttack
            ? 1 - Balance::BATTLE_WEAR_MAJOR
            : Balance::BATTLE_WEAR_MAJOR;

        // Capped short of the whole bill: every combat piece was in the fight,
        // so no split may leave one of the two halves paying nothing at all.
        $hands = min(
            Balance::BATTLE_WEAR_MAJOR,
            $hands * max(0.0, (float) ($monster['wearBias'] ?? 1.0)),
        );

        return ['worn' => 1 - $hands, 'hands' => $hands];
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
    /**
     * §8.2 -- what the trader gives for a piece of gear, as it stands.
     *
     * The basis is the shelf price where there is one and what the PARTS cost
     * where there is not, which is the same pair §8.3 prices the shelf from.
     * Then half of it, then scaled by what is left of the durability.
     *
     * The fallback is the fix for a real hole. §8.2 used to say a crafted piece
     * "has no shelf price to halve and salvage is its exit", which lumped a
     * common Hewn Axe in with an epic Mythril Pickaxe -- and the reason it gave
     * (gold buys the bottom two rungs and never the top) only ever argued for
     * excluding the top. The catalog was already inconsistent about it: a
     * Notched Sword is common, craftable AND stocked, so it sold, while a
     * Tempered Sword is common and craftable and was not stocked, so it did
     * not. Nothing a player can see distinguishes those two.
     */
    public static function resaleValue(array $def, int $durability): int
    {
        $max = $def['maxDurability'] ?? 0;
        if ($max <= 0 || $durability <= 0) {
            return 0;
        }

        $price = self::resaleBasis($def);
        if ($price <= 0) {
            return 0;
        }

        return (int) floor($price * Balance::RESALE_RATE * (min($durability, $max) / $max));
    }

    /**
     * What an undamaged piece is worth to the trader before wear comes off.
     *
     * Zero means the trader does not deal in it at all, which is a different
     * thing from a piece worn down to nothing -- the shop lists the second and
     * refuses it, so a player looking for their axe finds it and is told why.
     */
    public static function resaleBasis(array $def): int
    {
        // §3.2 -- gold reaches the bottom two rungs and stops. Checked on the
        // PRICE rather than at the till, so the shelf, the client's preview and
        // the sale itself cannot come to three different answers. It is what
        // keeps the make-cost fallback below from handing an epic a gold value
        // it was never supposed to have (§8.0: minting is that rung's exit).
        if (Balance::rarityRank($def['rarity'] ?? 'common') > Balance::rarityRank(Balance::SHOP_RARITY_CAP)) {
            return 0;
        }

        // §8.2 -- what a thing is MADE OF, whenever that is knowable. A shelf
        // price is make-cost marked up by half plus the bench time (§8.3), and
        // neither of those is yours to sell back: you did not pay the markup and
        // the trader is not buying your afternoon.
        //
        // This is the rule that keeps gather-craft-sell from being a slow gold
        // press. Six uncommon battle pieces were through the gap -- 41g of
        // materials made an Iron Broadsword that sold for 53g -- because their
        // shelf tag is set by the durability valuation rather than by their
        // parts, and half of it still cleared the parts. A shelf price is
        // ALWAYS above make cost by construction, so reading the parts first is
        // the whole fix.
        $parts = self::makeCost($def);
        if ($parts > 0) {
            return $parts;
        }

        // Nothing to make it from: it is shop stock and nothing else, so the
        // only price it has is the one on the shelf.
        return $def['goldPrice'] ?? 0;
    }

    /**
     * §8.3 -- what a thing's parts fetch at the NPC's own poor rate.
     *
     * The shelf marks this up by half and adds bench time; a sale back never
     * does either, which is what keeps the round trip a loss (§8.2) and keeps
     * the bench from being a gold press: half of what the materials were worth
     * is strictly less than selling the materials.
     */
    public static function makeCost(array $def): int
    {
        $worth = 0;
        foreach ($def['inputs'] ?? [] as $key => $qty) {
            $worth += ((Catalog::material($key)['npcPrice'] ?? 0) * $qty);
        }

        return $worth;
    }

    /**
     * §8.2 -- what the trader gives for a potion, per flask.
     *
     * A draft has no shelf price to halve: nothing stocks consumables, because
     * §8.5 makes them a thing you BREW. So the price comes from the other half
     * of §8.3's rule -- what the parts are worth at the NPC's own poor rate --
     * and the same RESALE_RATE the gear exit uses is applied to that. No wear
     * term, because a potion has no durability to have spent.
     *
     * Half is not a tuning value, it is the guard. Selling a brew must always
     * come to LESS than selling the reagents that went into it, or the
     * consumable bench is a gold press and §2's bot economics change: brew,
     * sell, repeat. Half leaves room even at the Alchemist's cap -- +35% extra
     * flasks (§7.4.3) still only reaches 0.675 of what the inputs fetched. A
     * test pins that, because the day it goes over 1 nothing else would notice.
     */
    public static function consumableResale(array $def): int
    {
        return (int) floor(self::makeCost($def) * Balance::RESALE_RATE);
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
     * §7.3 -- a hex is an amount of WORK, and a mine is how long you take over it.
     *
     *   rate      = (attack + skill_attack + skill_bite) * (1 + trip_reduction)
     *   trip_time = clamp(hp / rate, guard, ceiling)
     *
     * `$hp` is the tile's own, rolled by the world and stored nowhere else.
     *
     * `$attack` is the WHOLE base rate rather than a bonus on top of one. A
     * pick is what mines and a bow is what hunts -- neither verb has a
     * bare-handed mode to add to, because §8.0 rule 1 refuses it outright and
     * points at the gather button instead. Gathering passes
     * Balance::BARE_HAND_ATTACK here, because for that one verb your hands
     * ARE the tool.
     *
     * At zero attack the answer is not a very long mine, it is NO mine: nothing
     * in your hands and nothing learned means the ground does not move. `able`
     * is false, `total` is zero, and the caller says so rather than printing a
     * clock nobody can reach.
     *
     * The clamp survives as a GUARD rather than a lever -- see
     * Balance::MINING_FLOOR_SECONDS. Do not apply bonuses after it.
     *
     * `$skillBite` is what the LINE'S OWN TREE is worth per second (§7.4.3), in
     * whole points like every other term here. It is capped on the way in as
     * well as where it is aggregated, because a rate is not the place to find
     * out that a cap was missed.
     *
     * @return array{hp:int,toolAttack:int,skillAttack:int,skillBite:int,rate:float,total:int,clamped:bool,able:bool}
     */
    public static function mineTime(
        int $hp,
        int $skillLevel,
        float $equipTripReduction,
        int $toolAttack = 0,
        int $skillBite = 0,
    ): array {
        $skillAttack = self::skillAttack($skillLevel);
        $bite = max(0, min($skillBite, Balance::SKILL_BITE_CAP));
        $attack = max(0, $toolAttack) + $skillAttack + $bite;

        $rate = $attack * (1 + max(0.0, $equipTripReduction));

        $raw = $attack > 0 ? (int) round($hp / $rate) : 0;
        $total = $attack > 0
            ? min(Balance::MINING_CEILING_SECONDS, max(Balance::MINING_FLOOR_SECONDS, $raw))
            : 0;

        return [
            'hp' => $hp,
            'toolAttack' => $toolAttack,
            'skillAttack' => $skillAttack,
            'skillBite' => $bite,
            'rate' => round($rate, 2),
            'total' => $total,
            'clamped' => $attack > 0 && $total !== $raw,
            'able' => $attack > 0,
        ];
    }

    /**
     * §7.3 -- what the line skill is worth per second, on its own line.
     *
     * `floor(level / 10)`: whole points, stepped rather than smooth, so the
     * number on the panel is one a prospector can hold in their head and watch
     * go up. It is the one term every verb shares.
     *
     * Floor rather than ceil, because ceil handed the first level of a line a
     * free point -- a character who had never swung an axe read "+1" on a panel
     * describing what their skill was worth.
     */
    public static function skillAttack(int $skillLevel): int
    {
        $level = max(0, min($skillLevel, Balance::SKILL_MAX_LEVEL));

        return intdiv($level, Balance::MINING_SKILL_LEVELS_PER_ATTACK);
    }

    /**
     * §4.0 -- what a pair of hands manages per second, all in.
     *
     * This is GATHERING's rate and nothing else's. Mining and hunting never
     * reach it: they are refused without their tool rather than downgraded, so
     * there is no mine anywhere in the game that mixes hands and a tool.
     */
    public static function gatherAttack(int $skillLevel): int
    {
        return Balance::BARE_HAND_ATTACK + self::skillAttack($skillLevel);
    }

    /**
     * §8 -- what a gathering tool takes out of a hex each second.
     *
     * A tool's BASE stat, and the only one it has. It used to lead with a yield
     * percentage and have its attack derived from that, which conflated the two
     * halves of a mine: attack is how fast you work through a hex (§7.3) and
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

    /** Yield for one mine. Skill and gear add; ring adds the risk premium. */
    public static function mineYield(
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
        ?float $presenceBonus = null,
    ): int {
        $tierSpeed = Balance::settlementSpeed($tier);
        $presenceSpeed = $presence ? 1 - ($presenceBonus ?? Balance::PRESENCE_SPEED_BONUS) : 1;

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
