<?php

declare(strict_types=1);

namespace App\Game;

/**
 * What a mine actually brings back, §4 / §5.3 / §8.
 *
 * A hex used to pay out one material. It now pays out several: the same total
 * units the tile card promises, drawn from a weighted table instead of handed
 * over as a single stack. The haul is unchanged in size and changed in shape,
 * which is the point -- a mine is a small event with an outcome rather than a
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
     * mine. Past the cap the remaining units fall to the primary, so variety is
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
     * The weighted table for one mine. Keys are materials, values are weights;
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
     * A job records the material its mine resolved to when it started, and that
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
     * §9.5.8 -- what comes off a monster, beside the gold.
     *
     * Two families and nothing else: a plate/hide line the smith and the
     * armorer want, an ichor/organ line the consumable bench wants. Combat
     * feeds combat, and that containment is what makes a whole new faucet safe
     * under §2 -- nothing here touches the mining economy, and nothing here is
     * Tier 3, Tier 4 or mintable.
     *
     * The grade is the monster's tier, and the RARE roll is the grade above it,
     * which is §9.5.4's rule said the other way round: "rare for that rung" is
     * the spoil one grade up. It picks a line rather than always the plate,
     * because the top of the ichor ladder is wanted by eight recipes and has to
     * come off something.
     *
     * @return array<string,int>
     */
    public static function battleSpoils(array $monster, int $seed, ?string $biome = null): array
    {
        $grade = max(1, min(5, (int) $monster['tier']));
        $lines = Spoils::BY_GRADE[$grade];
        $out = [];

        // The plate line every time: it is what the ninety battle-gear pieces
        // are made of, and a ladder whose bottom rung is a coin flip is a
        // ladder nobody climbs.
        $out[$lines['plate']] = Hash::randInt(
            Hash::hash2($seed, 11, Balance::mapSeed() ^ 0x5901),
            self::PLATE_MIN,
            self::PLATE_MAX,
        );

        // The ichor line about half the time. Potions are drunk and gone, so
        // the faucet is smaller and steadier rather than absent.
        if (Hash::rand01(Hash::hash2($seed, 13, Balance::mapSeed() ^ 0x5902)) < self::ICHOR_CHANCE) {
            $out[$lines['ichor']] = Hash::randInt(
                Hash::hash2($seed, 17, Balance::mapSeed() ^ 0x5903),
                self::ICHOR_MIN,
                self::ICHOR_MAX,
            );
        }

        // §4 -- the tier-0 leaving, every time. Worth a gold, wanted by no
        // recipe, and generous precisely because of it: a drop nobody can build
        // with cannot inflate anything, so it can be the one part of a fight
        // that always pays something without touching §9.5.8's containment.
        $trophy = Spoils::TROPHY_BY_TIER[$grade] ?? null;
        if ($trophy !== null) {
            $out[$trophy] = Hash::randInt(
                Hash::hash2($seed, 29, Balance::mapSeed() ^ 0x5906),
                self::TROPHY_MIN,
                self::TROPHY_MAX,
            );
        }

        // §4 -- and what the ground gave up while the two of you were on it.
        //
        // The monster belongs to no ground -- it walked here -- but the FIGHT
        // happened somewhere, and what is trampled into the dirt is the hex's
        // own. It is the same junk a mine turns up, so it costs no new strap
        // kind, and it is junk: a gold, no recipe, nothing to inflate.
        //
        // A chance rather than every time, because the trophy above already
        // guarantees a tier-0 row. Two of them on every win would be clutter
        // dressed as variety.
        if ($biome !== null
            && Hash::rand01(Hash::hash2($seed, 31, Balance::mapSeed() ^ 0x5907)) < self::BATTLE_JUNK_CHANCE) {
            $key = self::junkOf($biome);
            $out[$key] = ($out[$key] ?? 0) + Hash::randInt(
                Hash::hash2($seed, 37, Balance::mapSeed() ^ 0x5908),
                self::TROPHY_MIN,
                self::TROPHY_MAX,
            );
        }

        $above = Spoils::BY_GRADE[$grade + 1] ?? null;
        if ($above !== null
            && Hash::rand01(Hash::hash2($seed, 19, Balance::mapSeed() ^ 0x5904)) < self::RARE_SPOIL_CHANCE) {
            $line = Hash::rand01(Hash::hash2($seed, 23, Balance::mapSeed() ^ 0x5905)) < 0.5
                ? 'plate'
                : 'ichor';

            $key = $above[$line];
            $out[$key] = ($out[$key] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * §4 -- what the tier-0 leaving pays. One or two, always.
     *
     * Bigger than the spoil lines on purpose and it costs the economy nothing:
     * junk feeds no recipe and fetches a gold, so the only thing it spends is a
     * strap (§7.6). Which is the interesting part -- it is the one drop in the
     * game that can be worth throwing away.
     */
    public const TROPHY_MIN = 1;

    public const TROPHY_MAX = 2;

    /**
     * §4 -- how often the ground itself turns up in the spoils.
     *
     * Two in five, which is often enough to be a thing players notice about
     * where they fight and rare enough that a win is not two rows of rubbish.
     */
    public const BATTLE_JUNK_CHANCE = 0.4;

    /** §9.5.8 -- the plate line drops every win; the ichor line about half. */
    public const PLATE_MIN = 1;

    public const PLATE_MAX = 3;

    public const ICHOR_CHANCE = 0.5;

    public const ICHOR_MIN = 1;

    public const ICHOR_MAX = 2;

    /**
     * §9.5.4 -- "rare for that rung" is the spoil one grade up.
     *
     * Split across the two lines by a coin flip, so the effective rate for any
     * ONE material is half this. The top of each ladder is deliberately a
     * project -- four Revenant Plate is about forty center kills -- but the
     * ichor line feeds potions, which are drunk and gone, so it cannot be so
     * thin that a legendary philtre is a season's work.
     */
    public const RARE_SPOIL_CHANCE = 0.2;

    /**
     * §9.5.8 -- the kit the monster was using, and the cap on it is a §2 rule.
     *
     * Epic is where gear becomes mintable (§8.0), so a monster that drops one
     * is precisely the grind->NFT faucet the threat model exists to close.
     * RARE IS THE CEILING, whatever the tier: a center-ring kill answers with
     * better OPTION ROLLS instead (§8.0.1), which is the same mechanism the
     * capital bazaar already uses.
     *
     * @return string|null the item key, or null when it was carrying nothing worth taking
     */
    public static function lootedGear(array $monster, int $seed): ?string
    {
        if (Hash::rand01(Hash::hash2($seed, 29, Balance::mapSeed() ^ 0x5906)) >= self::LOOT_CHANCE) {
            return null;
        }

        $tier = (int) $monster['tier'];
        $rungs = self::LOOT_RUNGS[$tier] ?? self::LOOT_RUNGS[1];
        $rung = $rungs[Hash::randInt(
            Hash::hash2($seed, 31, Balance::mapSeed() ^ 0x5907),
            0,
            count($rungs) - 1,
        )];

        $pool = self::lootPool()[$rung] ?? [];
        if ($pool === []) {
            return null;
        }

        return $pool[Hash::randInt(
            Hash::hash2($seed, 37, Balance::mapSeed() ^ 0x5908),
            0,
            count($pool) - 1,
        )];
    }

    public const LOOT_CHANCE = 0.18;

    /** Tier -> the rungs it may have been wearing. Never past rare (§2). */
    public const LOOT_RUNGS = [
        1 => ['common'],
        2 => ['common', 'uncommon'],
        3 => ['uncommon', 'rare'],
        4 => ['rare'],
    ];

    /**
     * Battle gear by rung, built once. Only battle gear: a monster is not
     * carrying a sickle, and a looted gathering tool would put combat on the
     * mining ladder, which §8 rule 5 keeps apart in the other direction.
     *
     * @return array<string,list<string>>
     */
    private static function lootPool(): array
    {
        static $pool = null;

        if ($pool !== null) {
            return $pool;
        }

        $pool = [];
        foreach (BattleGear::ITEMS as $key => $def) {
            $rarity = (string) $def['rarity'];
            if (! isset(self::LOOT_RUNGS[1]) || ! in_array($rarity, ['common', 'uncommon', 'rare'], true)) {
                continue;
            }

            $pool[$rarity][] = $key;
        }

        return $pool;
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
            $roll = Hash::rand01(Hash::hash2($seed, $i, Balance::mapSeed() ^ 0xD309)) * $total;

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
     * up, which is a fact about the place; how often is what the mine is for.
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
