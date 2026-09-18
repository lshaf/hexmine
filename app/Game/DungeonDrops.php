<?php

declare(strict_types=1);

namespace App\Game;

/**
 * §9.6.8 -- what a guardian pays, and the three things holding §2 shut.
 *
 * An ordinary monster on a floor pays §9.5.8's spoils exactly as one on a road
 * does, through `Drops::battleSpoils()`. Nothing here touches that. **This is
 * the guardian's own table and nothing else's**, which is what keeps the two
 * faucets separable: a floor full of Moss Hounds pays what Moss Hounds pay
 * wherever they stand, and the thing on the stair is the only place a dungeon
 * pays a dungeon's rewards.
 *
 * **This is the first grind-to-external-value path the game has ever had.** §8.0
 * makes legendary mintable and §9.6 makes it drop, so the sentence §2 opens with
 * -- NFTs are never dropped by mining or raiding -- is false here and only
 * rate-capped. Three things hold it, all three are needed, and two of them live
 * in this file:
 *
 *   1. THE RATE RAMPS WITH DEPTH. `DUNGEON_LEGENDARY_CHANCE` is the FLOOR-TEN
 *      guardian's rate and floor one is a tenth of it. Flat across ten guardians
 *      a hard session makes about 2.4 legendaries; ramped it is roughly half
 *      that, and descending starts meaning something beyond another roll.
 *   2. A PARTY COSTS SIX SYBIL WALLETS. Six rolls need six characters, six mint
 *      fees and six seven-day balance holds, so the faucet scales linearly with
 *      the cost of opening it rather than freely with patience.
 *   3. SESSIONS ARE CAPPED PER WALLET PER WEEK, in `DungeonService`. §12.2's
 *      argument exactly: the cap is a RATE, not a total.
 *
 * **Unique needs none of them and may be generous.** It is soulbound (§8.0), so
 * it can never leave the game and is not a faucet in the §2 sense at all.
 */
final class DungeonDrops
{
    /**
     * §9.6.3 -- which slots a contract's top-tier table may roll.
     *
     * The category is the whole reason a dungeon is worth *choosing* rather than
     * enduring: sixteen top-tier pieces against a flat table is a lottery nobody
     * can aim at, and a weapons run and a tools run are two different reasons to
     * organise six people.
     */
    public const SLOTS = [
        'tools' => ['axe', 'pickaxe', 'bow', 'hammer', 'sickle'],
        'armor' => ['armor', 'boots', 'gloves'],
        'weapons' => ['weapon'],
    ];

    /**
     * Every top-tier piece a contract can hand over, at one rung.
     *
     * Both catalogs are read, because the top of the ladder is split across
     * them: `TopTier` carries one legendary and one unique for the eight slots
     * that are not `weapon`, and `BattleGear` carries the weapon's own ladder
     * three families wide. Asking only one of them would silently halve the
     * armor table and empty the weapons one.
     *
     * @return list<string>
     */
    public static function pool(string $category, string $rarity): array
    {
        $slots = self::SLOTS[$category] ?? [];
        if ($slots === []) {
            return [];
        }

        $keys = [];

        foreach (TopTier::ITEMS as $key => $def) {
            if ($def['rarity'] === $rarity && in_array($def['slot'], $slots, true)) {
                $keys[] = $key;
            }
        }

        foreach (BattleGear::ITEMS as $key => $def) {
            if ($def['rarity'] === $rarity && in_array($def['slot'], $slots, true)) {
                $keys[] = $key;
            }
        }

        sort($keys);

        return $keys;
    }

    /**
     * §9.6.8 -- the legendary rate for this floor.
     *
     * Linear in depth so that the headline number is quoted where it matters --
     * the deepest guardian -- rather than paid out ten times over.
     */
    public static function legendaryChance(string $difficulty, int $floor): float
    {
        return self::ramp(Balance::DUNGEON_LEGENDARY_CHANCE, $difficulty, $floor);
    }

    public static function uniqueChance(string $difficulty, int $floor): float
    {
        return self::ramp(Balance::DUNGEON_UNIQUE_CHANCE, $difficulty, $floor);
    }

    private static function ramp(float $base, string $difficulty, int $floor): float
    {
        $floor = max(1, min(Balance::DUNGEON_FLOORS, $floor));
        $hard = $difficulty === 'hard' ? Balance::DUNGEON_HARD_MULTIPLIER : 1;

        return $base * ($floor / Balance::DUNGEON_FLOORS) * $hard;
    }

    /**
     * §9.6.8 -- everything the thing on the stair gives up.
     *
     * Seeded like every other outcome (§16), and each roll takes its own salt so
     * that retuning one cannot shuffle another: a Core landing must not be
     * correlated with a legendary landing, or the rarest thing in the game
     * arrives in lockstep with the second rarest.
     *
     * @return array{materials: array<string,int>, item: string|null, rarity: string|null}
     */
    public static function guardian(
        string $dungeon,
        string $category,
        string $difficulty,
        int $floor,
        int $seed,
    ): array {
        $hard = $difficulty === 'hard';
        $materials = [];

        // Essence off every guardian: the common residue, and the one Tier 4 a
        // player meets on floor one.
        $materials['essence'] = Hash::randInt(Hash::hash2($seed, 0xE55E, 0x1111), 1, $hard ? 3 : 2);

        // The dungeon's own Shard. Locked to the site, which is what makes a
        // recipe wanting two Shard types a cross-map project (§4).
        if ($floor >= ($hard ? 2 : 4)) {
            foreach (Catalog::DUNGEONS as $site) {
                if ($site['key'] === $dungeon) {
                    $materials[$site['drop']] = Hash::randInt(Hash::hash2($seed, 0x5A2D, 0x2222), 1, 2);
                    break;
                }
            }
        }

        // Relic, deep only. §9.3 wants a pity timer on it and there is not one
        // yet -- see the note in DungeonService, which is where a counter would
        // have to live because it is a fact about a player rather than a floor.
        if ($floor >= ($hard ? 5 : 7)) {
            $materials['relic'] = 1;
        }

        // Core gates the best equipment tier (§4) and is the boss's alone. Floor
        // ten always, and a chance from eight on hard.
        if ($floor >= Balance::DUNGEON_FLOORS) {
            $materials['core'] = 1;
        } elseif ($hard && $floor >= 8 && Hash::rand01(Hash::hash2($seed, 0xC02E, 0x3333)) < 0.35) {
            $materials['core'] = 1;
        }

        // §9.6.8 -- unique is rolled FIRST and wins, because it is the rarer of
        // the two and a guardian hands over one piece. Rolled the other way the
        // rarest thing in the game would be swallowed whenever the commoner one
        // happened to land in the same breath.
        foreach (['unique', 'legendary'] as $rarity) {
            $chance = $rarity === 'unique'
                ? self::uniqueChance($difficulty, $floor)
                : self::legendaryChance($difficulty, $floor);

            $roll = Hash::rand01(Hash::hash2($seed, $rarity === 'unique' ? 0x0417 : 0x1E6D, 0x4444));

            if ($roll >= $chance) {
                continue;
            }

            $pool = self::pool($category, $rarity);
            if ($pool === []) {
                // A contract whose table is empty at this rung drops no piece
                // rather than substituting one from another rung. Substituting
                // would make a category that is merely unfinished look like a
                // category that pays differently, and the gap is worth seeing.
                continue;
            }

            $pick = $pool[Hash::randInt(Hash::hash2($seed, 0x9E3D, 0x5555), 0, count($pool) - 1)];

            return ['materials' => $materials, 'item' => $pick, 'rarity' => $rarity];
        }

        return ['materials' => $materials, 'item' => null, 'rarity' => null];
    }
}
