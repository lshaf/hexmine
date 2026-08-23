<?php

declare(strict_types=1);

namespace App\Game;

/**
 * What a trip actually brings back, §4 / §5.3 / §8.
 *
 * A hex used to pay out one material. It now pays out several: the same total
 * units the tile card promises, drawn from a weighted table instead of handed
 * over as a single stack. The haul is unchanged in size and changed in shape,
 * which is the point -- a trip is a small event with an outcome rather than a
 * withdrawal of a known quantity.
 *
 * THREE ACTIVITIES, and the tool is what separates them:
 *
 *   gathering  no tool, always available. Rubbish, herbs, and a windfall of
 *              the biome's common material about one time in twenty -- the
 *              lightning-felled log, the ore already loose on the scree. §4.0's
 *              argument survives intact: bare hands still mostly bring back
 *              scrap, so the first tool is still obviously worth buying.
 *   mining     the line's tool. The ground's own material, at the grade the
 *              tool can reach.
 *   hunting    a bow, and a live herd (§5.5). Pelt, horn, sinew, and the
 *              biome's critter.
 *
 * THE TOOL SETS THE GRADE, THE GROUND SETS THE CEILING. A common axe on a
 * Hardwood Stand brings back Wood nearly every time and Hardwood occasionally:
 * the better ground is there, you are simply not equipped to take it. Each rung
 * you are short halves the odds again, so an epic seam worked with a village
 * pick is a lottery rather than a shortcut.
 *
 * Every weight here is a starting value for tuning. What is NOT tuning is that
 * the primary always dominates: a table where the incidental drops outweigh the
 * thing you came for turns a decision into a slot machine.
 */
final class Drops
{
    public const GATHERING = 'gathering';

    public const MINING = 'mining';

    public const HUNTING = 'hunting';

    /**
     * How many distinct materials one haul may split into.
     *
     * §7.6 -- rows are the tighter bag limit in practice, and a haul that came
     * back as seven different things would eat a quarter of the straps in one
     * trip. Past the cap the remaining units fall to the primary, so variety is
     * bounded and the bag stays a decision rather than an ambush.
     */
    public const MAX_KINDS = 4;

    /** Rarity -> the grade of material a tool of that rung can reliably take. */
    private const TOOL_GRADE = [
        'common' => 0,
        'uncommon' => 1,
        'rare' => 2,
        'epic' => 3,
        'legendary' => 3,
        'unique' => 3,
    ];

    public static function toolGrade(?string $rarity): int
    {
        return self::TOOL_GRADE[$rarity ?? 'common'] ?? 0;
    }

    /**
     * The weighted table for one trip. Keys are materials, values are weights;
     * nothing normalises them, because only their ratio is ever read.
     *
     * @param  array<string,mixed>  $tile
     * @return array<string,float>
     */
    public static function table(string $activity, array $tile, int $toolGrade): array
    {
        $biome = $tile['biome'];
        $variants = Variants::BIOME_VARIANTS[$biome];
        $tileGrade = self::gradeOf($tile, $variants);
        $reach = min($toolGrade, $tileGrade);

        return match ($activity) {
            self::HUNTING => self::hunting($biome, $tileGrade, $reach),
            self::MINING => self::mining($biome, $variants, $tileGrade, $reach),
            default => self::gathering($biome),
        };
    }

    /**
     * The same table, rebuilt from a haul already in flight.
     *
     * A job records the material its trip resolved to when it started, and that
     * key IS the grade the tool could reach -- so the table can be rebuilt an
     * hour later without asking what is on the character's belt now. That is
     * the point: the tool that did the work decides the haul, and swapping to a
     * better one while the timer runs buys nothing.
     *
     * @param  array<string,mixed>  $tile
     * @return array<string,float>
     */
    public static function tableFor(string $activity, array $tile, string $primary): array
    {
        $biome = $tile['biome'];
        $variants = Variants::BIOME_VARIANTS[$biome];
        $tileGrade = self::gradeOf($tile, $variants);

        if ($activity === self::GATHERING) {
            return self::gathering($biome);
        }

        $ladder = $activity === self::HUNTING ? Variants::BIOME_VARIANTS['plains'] : $variants;
        $reach = 0;
        foreach ($ladder as $index => $variant) {
            if ($variant['material'] === $primary) {
                $reach = $index;
                break;
            }
        }

        return $activity === self::HUNTING
            ? self::hunting($biome, $tileGrade, $reach)
            : self::mining($biome, $variants, $tileGrade, $reach);
    }

    /**
     * Which activity a job was: the tool is what tells them apart, and the
     * material the job resolved to already records whether there was one.
     */
    public static function activityFor(string $kind, string $primary): string
    {
        if ($kind === 'hunting') {
            return self::HUNTING;
        }

        return Catalog::isScrap($primary) ? self::GATHERING : self::MINING;
    }

    /** Which rung of its biome's four this hex is, or 0 if it has no material. */
    private static function gradeOf(array $tile, array $variants): int
    {
        foreach ($variants as $index => $variant) {
            if ($variant['key'] === ($tile['variant'] ?? null)) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * §4.0 -- bare hands, and mostly rubbish.
     *
     * The windfall is deliberately thin and deliberately capped at the COMMON
     * grade: a gatherer on contested ground gets ordinary wood out of an
     * ironwood grove, never ironwood. Otherwise the tool ladder would have a
     * free rung at the top of it.
     *
     * @return array<string,float>
     */
    private static function gathering(string $biome): array
    {
        $table = [
            Catalog::BIOME_SCRAP[$biome] => 48.0,
            self::junkOf($biome) => 30.0,
            Variants::BIOME_VARIANTS[$biome][0]['material'] => 5.0,
        ];

        foreach (self::herbsOf($biome) as $herb) {
            $table[$herb] = 8.5;
        }

        return $table;
    }

    /**
     * The line's own tool on the line's own ground.
     *
     * @return array<string,float>
     */
    private static function mining(string $biome, array $variants, int $tileGrade, int $reach): array
    {
        $table = [$variants[$reach]['material'] => 60.0];

        // Every rung you are short of the ground halves the odds again.
        for ($grade = $reach + 1; $grade <= $tileGrade; $grade++) {
            $table[$variants[$grade]['material']] = 8.0 / (2 ** ($grade - $reach - 1));
        }

        // What else is lying about on that kind of ground. The bench stocks
        // are here and nowhere else -- this is the faucet the herbs and the
        // craft components never had.
        foreach (self::herbsOf($biome) as $herb) {
            $table[$herb] = 5.0;
        }
        foreach (self::componentsOf($biome) as $component) {
            $table[$component] = 6.0;
        }

        $table[self::junkOf($biome)] = 9.0;

        return $table;
    }

    /**
     * §5.5 -- a bow, and something to point it at.
     *
     * The pelt grade follows the ground the herd is standing on, capped by the
     * bow, exactly as mining follows the seam. Herbs and the biome's own
     * material still turn up, because an animal is found somewhere: hunt a
     * forest and you come back with a little wood whether you meant to or not.
     *
     * NO TIER 4 HERE. Essence is raid loot and nothing else: a herd on a
     * four-hour clock that anyone can shoot would be a faucet for the one
     * material tier the dungeons are supposed to gate, and §9.4's ladder ends
     * at a boss rather than at a deer.
     *
     * @return array<string,float>
     */
    private static function hunting(string $biome, int $tileGrade, int $reach): array
    {
        $plains = Variants::BIOME_VARIANTS['plains'];

        $table = [
            $plains[$reach]['material'] => 45.0,
            // Horn and sinew are the plains components, and they are the two
            // things in the catalog that plainly come off an animal.
            'horn' => 13.0,
            'sinew' => 13.0,
            Critters::BY_BIOME[$biome] => 12.0,
            'bone_splinter' => 8.0,
        ];

        for ($grade = $reach + 1; $grade <= $tileGrade; $grade++) {
            $table[$plains[$grade]['material']] = 6.0 / (2 ** ($grade - $reach - 1));
        }

        foreach (self::herbsOf($biome) as $herb) {
            $table[$herb] = 2.5;
        }

        // Whatever the ground itself is, in the small amounts a hunter notices
        // -- and never at the cost of the primary. On the plains the ground IS
        // the primary (§5.5), so a plain assignment here would quietly demote
        // pelt from 45 to 5 and hand the table to horn.
        $table[Variants::BIOME_VARIANTS[$biome][0]['material']] ??= 5.0;

        return $table;
    }

    /**
     * Split a haul across its table.
     *
     * One draw per unit, so the total is exactly what the tile card promised
     * and the primary wins most of them by weight alone -- no separate "bonus
     * roll" rule, and no way for the shape of the haul to disagree with its
     * size. Deterministic from the seed like every other outcome (§16).
     *
     * MAX_KINDS bounds the straps a single haul can eat (§7.6). Past it the
     * draw narrows to what is already in the haul, keeping the same relative
     * weights among those -- so a long haul deepens the stacks it already has
     * instead of opening new ones.
     *
     * @param  array<string,float>  $table
     * @return array<string,int>
     */
    public static function roll(array $table, int $units, int $seed): array
    {
        if ($units <= 0 || $table === []) {
            return [];
        }

        $primary = (string) array_key_first($table);
        $out = [];

        for ($i = 0; $i < $units; $i++) {
            // Once the strap budget is spent the draw narrows to what the haul
            // is ALREADY carrying, rather than falling back to the primary.
            // Falling back would quietly reshape the table towards the thing
            // you came for every time a haul ran long, and a cap is meant to
            // bound variety, not to pay a bonus for hitting it.
            $pool = count($out) >= self::MAX_KINDS
                ? array_intersect_key($table, $out)
                : $table;

            $total = array_sum($pool);
            $roll = Hash::rand01(Hash::hash2($seed, $i, Balance::mapSeed() ^ 0xd309)) * $total;

            $picked = (string) array_key_first($pool);
            $seen = 0.0;
            foreach ($pool as $key => $weight) {
                $seen += $weight;
                if ($roll < $seen) {
                    $picked = (string) $key;
                    break;
                }
            }

            $out[$picked] = ($out[$picked] ?? 0) + 1;
        }

        arsort($out);

        return $out;
    }

    /**
     * What the tile card lists: the kinds, most likely first, and NOT the odds.
     *
     * Naming the weights would turn a hex into a spreadsheet and the decision
     * into arithmetic. What a prospector is owed is what this ground can give
     * up, which is a fact about the place; how often is what the trip is for.
     *
     * @return list<string>
     */
    public static function kinds(string $activity, array $tile, int $toolGrade): array
    {
        $table = self::table($activity, $tile, $toolGrade);
        arsort($table);

        return array_keys($table);
    }

    /** @return list<string> */
    private static function herbsOf(string $biome): array
    {
        $out = [];
        foreach (Alchemy::REAGENTS as $key => $def) {
            if ($def['biome'] === $biome) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function componentsOf(string $biome): array
    {
        $out = [];
        foreach (Components::CRAFT as $key => $def) {
            if ($def['biome'] === $biome) {
                $out[] = $key;
            }
        }

        return $out;
    }

    private static function junkOf(string $biome): string
    {
        foreach (Alchemy::JUNK as $key => $def) {
            if ($def['biome'] === $biome) {
                return $key;
            }
        }

        return 'deadfall';
    }
}
