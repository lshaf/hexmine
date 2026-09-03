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
 * TWO ACTIVITIES, and the tool is what separates them:
 *
 *   gathering  no tool, always available. Rubbish, herbs, and a windfall of
 *              the biome's common material about one time in twenty -- the
 *              lightning-felled log, the ore already loose on the scree. §4.0's
 *              argument survives intact: bare hands still mostly bring back
 *              scrap, so the first tool is still obviously worth buying.
 *   mining     the line's tool. The ground's own material, at the grade the
 *              tool can reach -- and on the plains that tool is a bow and that
 *              material is pelt -- which is all hunting ever was once §7.3 put
 *              it through the same arithmetic as a dig (§5.5).
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
    public static function table(string $activity, array $tile, int $toolGrade, bool $rich = false): array
    {
        $biome = $tile['biome'];
        $variants = Variants::BIOME_VARIANTS[$biome];
        $tileGrade = self::gradeOf($tile, $variants);
        $reach = min($toolGrade, $tileGrade);

        return $activity === self::MINING
            ? self::mining($biome, $variants, $tileGrade, $reach, $rich)
            : self::gathering($biome);
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
    public static function tableFor(string $activity, array $tile, string $primary, bool $rich = false): array
    {
        $biome = $tile['biome'];
        $variants = Variants::BIOME_VARIANTS[$biome];
        $tileGrade = self::gradeOf($tile, $variants);

        if ($activity === self::GATHERING) {
            return self::gathering($biome);
        }

        $reach = 0;
        foreach ($variants as $index => $variant) {
            if ($variant['material'] === $primary) {
                $reach = $index;
                break;
            }
        }

        return self::mining($biome, $variants, $tileGrade, $reach, $rich);
    }

    /**
     * Which activity a job was: the tool is what tells them apart, and the
     * material the job resolved to already records whether there was one.
     */
    public static function activityFor(string $kind, string $primary): string
    {
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
     * §5.3 -- how often a haul comes back a grade under the one being worked.
     *
     * Halved again for every further rung down, so the grade below is common
     * enough to notice and two below is a curiosity. Above the primary's 8.0
     * long shot on purpose: falling short of the grade you are cutting is more
     * ordinary than exceeding it.
     *
     * A tuning value rather than a rule. What is NOT tunable is that it exists:
     * §5.3's grades are what a hex mostly carries, never all it carries.
     */
    public const LOWER_GRADE_WEIGHT = 12.0;

    /**
     * The line's own tool on the line's own ground.
     *
     * @return array<string,float>
     */
    private static function mining(
        string $biome,
        array $variants,
        int $tileGrade,
        int $reach,
        bool $rich = false,
    ): array {
        $table = [$variants[$reach]['material'] => 60.0];

        // Every rung you are short of the ground halves the odds again -- and
        // §5.7's rich ground widens the whole tail, because "rich" means better
        // odds on what you are not equipped for as well as more of what you are.
        $above = 8.0 * ($rich ? Balance::POCKET_REACH : 1.0);
        for ($grade = $reach + 1; $grade <= $tileGrade; $grade++) {
            $table[$variants[$grade]['material']] = $above / (2 ** ($grade - $reach - 1));
        }

        // §5.3 -- and the same tail going DOWN, because a seam is not uniform.
        // An Ironwood Grove is a grove of ironwood with ordinary trees standing
        // in it; a Mythril Seam runs through rock that is mostly iron. Without
        // this, reaching a grade meant taking it on every single swing, which
        // made a hex a switch rather than a place.
        //
        // Commoner than the long shots above, and deliberately: falling short
        // of the grade you are cutting is more ordinary than exceeding it.
        for ($grade = $reach - 1; $grade >= 0; $grade--) {
            $table[$variants[$grade]['material']] = self::LOWER_GRADE_WEIGHT / (2 ** ($reach - $grade - 1));
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

        // §4 -- and what LIVES on that ground. It used to come off a herd and
        // nothing else, so folding hunting back into mining (§5.5) left the
        // five critters with no faucet at all -- and the consumable bench wants
        // every one of them.
        $table[Critters::BY_BIOME[$biome]] = 4.0;

        $table[self::junkOf($biome)] = 9.0;

        return $table;
    }

    /**
     * §9.5.8 -- what comes off a monster, beside the gold.
     *
     * Four lines. A plate/hide line the smith and the armorer want and an
     * ichor/organ line the consumable bench wants, both graded by tier; the
     * COUNTRY's own stock, which only that biome's five give up (§9.5.2); and
     * tier 0, which is the trophy for what you fought and the leaving for where.
     *
     * Combat feeds combat, and that containment is what makes a whole new
     * faucet safe under §2 -- nothing here touches the mining economy, and
     * nothing here is Tier 3, Tier 4 or mintable.
     *
     * The grade is the monster's tier, and the RARE roll is the grade above it,
     * which is §9.5.4's rule said the other way round: "rare for that rung" is
     * the spoil one grade up. It picks a line rather than always the plate,
     * because the top of the ichor ladder is wanted by eight recipes and has to
     * come off something.
     *
     * **The biome is not a parameter.** It used to be, back when the ground was
     * the only thing in a fight that knew where it was; the monster carries its
     * own country now, so passing one in would be a second opinion about which
     * ground the fight was on -- and the caller's would be the one that wins.
     *
     * @return array<string,int>
     */
    public static function battleSpoils(
        array $monster,
        int $seed,
        float $haul = 0.0,
    ): array {
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

        // §9.5.8 -- the country's own stock, which is the whole point of a
        // roster that stands on one kind of ground (§9.5.2).
        //
        // It is the one drop a prospector cannot get by walking inward on
        // ground they already know, so kitting out of a biome's line is a
        // reason to go and fight in that biome rather than the nearest one.
        // About as often as the ichor, because it is stock a bench wants
        // rather than rubbish.
        $biomeSpoil = $monster['biomeSpoil'] ?? null;
        if ($biomeSpoil !== null
            && Hash::rand01(Hash::hash2($seed, 41, Balance::mapSeed() ^ 0x5909)) < self::ICHOR_CHANCE) {
            $out[$biomeSpoil] = Hash::randInt(
                Hash::hash2($seed, 43, Balance::mapSeed() ^ 0x590A),
                self::ICHOR_MIN,
                self::ICHOR_MAX,
            );
        }

        // §9.5.8 -- and what the ground gave up while the two of you were on it.
        //
        // The trophy above says WHAT you fought; this says WHERE. It is the
        // fight's own leaving, one per biome, worth a gold and wanted by no
        // recipe -- so it can be generous without touching the containment
        // §9.5.8 keeps combat inside.
        //
        // A chance rather than every time, because the trophy already
        // guarantees a tier-0 row. Two of them on every win would be clutter
        // dressed as variety.
        //
        // It used to be §4's own mining junk, borrowed, on the argument that it
        // cost no new kind of strap. That was only ever true while straps were
        // scarce; §7.6 made them roomy, so the fight has its own rubbish now
        // and the mine keeps its.
        $leaving = $monster['biomeLeaving'] ?? null;
        if ($leaving !== null
            && Hash::rand01(Hash::hash2($seed, 31, Balance::mapSeed() ^ 0x5907)) < self::BATTLE_JUNK_CHANCE) {
            $out[$leaving] = ($out[$leaving] ?? 0) + Hash::randInt(
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

        // §8.0.1 -- a `haul` line on the weapon or the worn set, which is the
        // fight's own haul the way a tool's is its line's. It scales what came
        // off the body and nothing else: not the gold (§3.2's faucet is its
        // own thing and `goldFind` already owns it), and not the looted gear,
        // because §2 stops loot at rare whatever anybody is wearing.
        if ($haul > 0) {
            foreach ($out as $key => $quantity) {
                $out[$key] = self::scaleSpoil($quantity, $haul, Hash::hash2($seed, crc32($key), Balance::mapSeed() ^ 0x5910));
            }
        }

        return $out;
    }

    /**
     * §5.5 -- what comes off a hunted animal.
     *
     * The hunting line's whole haul, which is what mining a plains hex used to
     * pay. The animal's GRADE decides the rung of pelt, exactly as the hex's
     * variant used to (§5.3), so the ladder did not change hands -- only what
     * carries it.
     *
     * **A bow or nothing.** §8.0 rule 1 refuses a mine outright without the
     * line's tool and §4.0 pays scrap for the bare-handed version; a hunt is the
     * same bargain. Without a bow the kill still happens and what comes home is
     * Torn Hide, a gold apiece and wanted by no recipe — the gap that is the
     * whole argument for buying the first bow.
     *
     * Everything the plains biome used to give up comes off here now: its two
     * components, its two reagents, its critter and its junk. A hunt happens out
     * in the field, so a plant pulled up beside the carcass is not a stretch —
     * and the alternative was five materials with nowhere to come from.
     *
     * @return array<string,int>
     */
    public static function huntSpoils(array $animal, int $seed, bool $armed, float $haul = 0.0): array
    {
        $out = [];

        // §4.0 -- the tool is the whole difference. Same haul size, a fraction
        // of the worth, and no recipe anywhere will take it.
        $out[$armed ? $animal['material'] : Catalog::HUNT_SCRAP] = Hash::randInt(
            Hash::hash2($seed, 53, Balance::mapSeed() ^ 0x5920),
            self::PLATE_MIN,
            self::PLATE_MAX,
        );

        // The animal parts, and only to somebody who brought a bow: a carcass
        // torn at by hand gives up hide and nothing worth a bench.
        if ($armed) {
            foreach (self::HUNT_PARTS as $i => $key) {
                if (Hash::rand01(Hash::hash2($seed, 59 + $i, Balance::mapSeed() ^ 0x5921)) < self::ICHOR_CHANCE) {
                    $out[$key] = ($out[$key] ?? 0) + Hash::randInt(
                        Hash::hash2($seed, 67 + $i, Balance::mapSeed() ^ 0x5922),
                        self::ICHOR_MIN,
                        self::ICHOR_MAX,
                    );
                }
            }
        }

        // §4 -- and the rubbish, every time, worth a gold and feeding nothing.
        $out[self::HUNT_JUNK] = ($out[self::HUNT_JUNK] ?? 0) + Hash::randInt(
            Hash::hash2($seed, 71, Balance::mapSeed() ^ 0x5923),
            self::TROPHY_MIN,
            self::TROPHY_MAX,
        );

        if ($haul > 0) {
            foreach ($out as $key => $quantity) {
                $out[$key] = self::scaleSpoil($quantity, $haul, Hash::hash2($seed, crc32($key), Balance::mapSeed() ^ 0x5924));
            }
        }

        return $out;
    }

    /**
     * §8.0.1 -- a share applied to a small count, without losing the share.
     *
     * Spoil rows are one to three units, so rounding a 30% bonus would pay
     * nothing at all on the commonest row and a whole unit on the next -- a
     * bonus that is invisible two thirds of the time is a bonus nobody
     * believes. The fraction is a seeded chance instead, exactly as an extra
     * craft roll is (§8.0.1), so 1 unit at +30% really is 1.3 units on average.
     */
    private static function scaleSpoil(int $quantity, float $haul, int $seed): int
    {
        $exact = $quantity * (1 + $haul);
        $whole = (int) floor($exact);

        return $whole + (Hash::rand01($seed) < $exact - $whole ? 1 : 0);
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
    public static function kinds(string $activity, array $tile, int $toolGrade, bool $rich = false): array
    {
        $table = self::table($activity, $tile, $toolGrade, $rich);
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

    /**
     * §5.5 -- what a hunt gives up beside the pelt.
     *
     * The two components, the two reagents and the critter that used to come
     * off plains ground. They are rolled one at a time rather than as a pool,
     * so a hunt that comes home with two of them is a good one rather than an
     * exception the code had to allow for.
     */
    private const HUNT_PARTS = ['horn', 'sinew', 'bitterroot', 'yarrow', 'dustleveret'];

    /** §4 -- the tier-0 rubbish, every time. */
    private const HUNT_JUNK = 'bone_splinter';

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
