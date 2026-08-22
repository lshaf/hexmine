<?php

declare(strict_types=1);

namespace App\Game;

/**
 * Static game data, §4 / §6 / §7.2 / §8.3. Port of
 * `frontend/src/game/catalog.ts`.
 *
 * This is deliberately code rather than seeded rows: it is definition, not
 * state. Nothing here ever changes at runtime, a migration to edit a drop table
 * would be absurd, and keeping it in PHP means a balance change is a diff, not a
 * data fix on production.
 */
final class Catalog
{
    public const BIOMES = ['forest', 'mountain', 'plains', 'badlands', 'grassland'];

    /**
     * The 20 economy materials of §4, plus the 5 scrap of §4.0.
     * tier 0 scrap (vendor trash) / 1 raw (biome-locked) / 2 refined /
     * 3 rare (capped) / 4 raid.
     *
     * Scrap sits outside the 20 on purpose: it feeds no recipe and reaches no
     * other tier, so it is not part of the economy the §11 sinks balance.
     */
    public static function materials(): array
    {
        static $materials = null;

        return $materials ??= [
            // Tier 0 -- Scrap, §4.0. What bare hands bring back when you have no
            // tool for the line. Sells for a copper, feeds no recipe, and exists
            // only to make the first tool obviously worth buying.
            'branch' => ['name' => 'Branch', 'tier' => 0, 'biome' => 'forest', 'palette' => 'wood', 'npcPrice' => 1, 'description' => 'Snapped off by hand. The trader gives you a copper and looks away.'],
            'ore_chips' => ['name' => 'Ore Chips', 'tier' => 0, 'biome' => 'mountain', 'palette' => 'iron', 'npcPrice' => 1, 'description' => 'Loose flakes off the seam face. Barely worth carrying down.'],
            'torn_hide' => ['name' => 'Torn Hide', 'tier' => 0, 'biome' => 'plains', 'palette' => 'pelt', 'npcPrice' => 1, 'description' => 'Scavenged, not hunted. Half of it is unusable.'],
            'gravel' => ['name' => 'Gravel', 'tier' => 0, 'biome' => 'badlands', 'palette' => 'stone', 'npcPrice' => 1, 'description' => 'Kicked loose from the scree. Nobody dresses this into anything.'],
            'chaff' => ['name' => 'Chaff', 'tier' => 0, 'biome' => 'grassland', 'palette' => 'fiber', 'npcPrice' => 1, 'description' => 'Pulled up by the root and mostly broken. The trader takes it by the sack.'],

            // Tier 1 -- Raw, biome-locked, decays over cap
            'wood' => ['name' => 'Wood', 'tier' => 1, 'biome' => 'forest', 'palette' => 'wood', 'npcPrice' => 2, 'description' => 'Green timber from the forest belt.'],
            'iron_ore' => ['name' => 'Iron Ore', 'tier' => 1, 'biome' => 'mountain', 'palette' => 'iron', 'npcPrice' => 3, 'description' => 'Raw ore hacked from mountain seams.'],
            'pelt' => ['name' => 'Pelt', 'tier' => 1, 'biome' => 'plains', 'palette' => 'pelt', 'npcPrice' => 3, 'description' => 'Rough hide taken from plains herds.'],
            'stone' => ['name' => 'Stone', 'tier' => 1, 'biome' => 'badlands', 'palette' => 'stone', 'npcPrice' => 2, 'description' => 'Blasted rubble from the badlands.'],
            'fiber' => ['name' => 'Fiber', 'tier' => 1, 'biome' => 'grassland', 'palette' => 'fiber', 'npcPrice' => 2, 'description' => 'Tough grassland stalks, retted for spinning.'],

            // Tier 2 -- Refined
            'planks' => ['name' => 'Planks', 'tier' => 2, 'palette' => 'wood', 'npcPrice' => 7, 'description' => 'Sawn and seasoned. The backbone of crafting.'],
            'ingots' => ['name' => 'Ingots', 'tier' => 2, 'palette' => 'iron', 'npcPrice' => 9, 'description' => 'Smelted iron, poured into bar moulds.'],
            'leather' => ['name' => 'Leather', 'tier' => 2, 'palette' => 'pelt', 'npcPrice' => 8, 'description' => 'Tanned hide, supple enough to work.'],
            'cut_stone' => ['name' => 'Cut Stone', 'tier' => 2, 'palette' => 'stone', 'npcPrice' => 7, 'description' => 'Dressed blocks, square and true.'],
            'cloth' => ['name' => 'Cloth', 'tier' => 2, 'palette' => 'fiber', 'npcPrice' => 6, 'description' => 'Spun and woven fiber bolts.'],
            'reinforced_frame' => ['name' => 'Reinforced Frame', 'tier' => 2, 'palette' => 'iron', 'npcPrice' => 26, 'description' => 'Planks banded with iron. A cross-line combo.'],

            // Tier 3 -- Rare, contested ring only, capped per wallet
            'ironwood' => ['name' => 'Ironwood', 'tier' => 3, 'biome' => 'forest', 'palette' => 'wood', 'npcPrice' => 0, 'walletCap' => Balance::RARE_WALLET_CAP, 'description' => 'Heartwood so dense it turns an axe. Contested ring only.'],
            'mythril_ore' => ['name' => 'Mythril Ore', 'tier' => 3, 'biome' => 'mountain', 'palette' => 'iron', 'npcPrice' => 0, 'walletCap' => Balance::RARE_WALLET_CAP, 'description' => 'A pale seam that hums under the pick.'],
            'beastfang_hide' => ['name' => 'Beastfang Hide', 'tier' => 3, 'biome' => 'plains', 'palette' => 'pelt', 'npcPrice' => 0, 'walletCap' => Balance::RARE_WALLET_CAP, 'description' => 'Taken off something that fought back.'],
            'obsidian_shard' => ['name' => 'Obsidian Shard', 'tier' => 3, 'biome' => 'badlands', 'palette' => 'stone', 'npcPrice' => 0, 'walletCap' => Balance::RARE_WALLET_CAP, 'description' => 'Volcanic glass, edged sharper than steel.'],
            'silkweave_fiber' => ['name' => 'Silkweave Fiber', 'tier' => 3, 'biome' => 'grassland', 'palette' => 'fiber', 'npcPrice' => 0, 'walletCap' => Balance::RARE_WALLET_CAP, 'description' => 'Spun by something in the tall grass. Nobody asks what.'],

            // Tier 4 -- Raid materials
            'essence' => ['name' => 'Essence', 'tier' => 4, 'palette' => 'raid', 'npcPrice' => 0, 'description' => 'Common residue. Drops from every monster tier.'],
            'shard_verdant' => ['name' => 'Verdant Shard', 'tier' => 4, 'palette' => 'raid', 'npcPrice' => 0, 'description' => 'Rootvault signature drop.'],
            'shard_ferrous' => ['name' => 'Ferrous Shard', 'tier' => 4, 'palette' => 'raid', 'npcPrice' => 0, 'description' => 'Deepshaft signature drop.'],
            'shard_sanguine' => ['name' => 'Sanguine Shard', 'tier' => 4, 'palette' => 'raid', 'npcPrice' => 0, 'description' => 'Beastwarren signature drop.'],
            'shard_cinder' => ['name' => 'Cinder Shard', 'tier' => 4, 'palette' => 'raid', 'npcPrice' => 0, 'description' => 'Ashpit signature drop.'],
            'shard_zephyr' => ['name' => 'Zephyr Shard', 'tier' => 4, 'palette' => 'raid', 'npcPrice' => 0, 'description' => 'Windhollow signature drop.'],
            'relic' => ['name' => 'Relic', 'tier' => 4, 'palette' => 'raid', 'npcPrice' => 0, 'description' => 'Deep-floor rarity. Pity-timer protected.'],
            'core' => ['name' => 'Core', 'tier' => 4, 'palette' => 'raid', 'npcPrice' => 0, 'description' => 'Boss-only. Gates the best equipment tier.'],
        ]
            // §4 -- the alchemist's raw stock, two per biome so a recipe can
            // want two different things off one kind of ground. Generated, not
            // typed: see scripts/gen_alchemy.py.
            + Alchemy::REAGENTS
            // §4 -- the smith's and the armorer's raw stock, on the same model:
            // two per biome, gathered off a hex, and wanted by the weapon and
            // armor benches the way a reagent is wanted by the consumable one.
            // Generated, not typed: see scripts/gen_components.py.
            + Components::CRAFT
            // §5.3 -- the grades above the base raw and what they refine into.
            // A biome is four kinds of ground, and each kind gives up its own
            // material. Generated, not typed: see scripts/gen_variants.py.
            + Variants::RAW
            + Variants::REFINED
            // §4.0 -- junk. Sells for a copper and reaches no tier, exactly as
            // the bare-hands scrap does; it is simply never what a hex gives up.
            + Alchemy::JUNK;
    }

    public static function material(string $key): ?array
    {
        return self::materials()[$key] ?? null;
    }

    public static function materialTier(string $key): int
    {
        return self::materials()[$key]['tier'] ?? 0;
    }

    public static function walletCap(string $key): ?int
    {
        return self::materials()[$key]['walletCap'] ?? null;
    }

    /** Biome -> raw material, §4 tier 1. */
    public const BIOME_MATERIAL = [
        'forest' => 'wood',
        'mountain' => 'iron_ore',
        'plains' => 'pelt',
        'badlands' => 'stone',
        'grassland' => 'fiber',
    ];

    /**
     * Biome -> scrap, §4.0. What the hex gives up to bare hands: worked without
     * the line's tool, a hex yields this instead of its real material. Same haul
     * size, a fraction of the worth, and no recipe will take it.
     */
    public const BIOME_SCRAP = [
        'forest' => 'branch',
        'mountain' => 'ore_chips',
        'plains' => 'torn_hide',
        'badlands' => 'gravel',
        'grassland' => 'chaff',
    ];

    /** Biome -> rare variant, spawned in the contested inner ring, §5.3. */
    public const BIOME_RARE = [
        'forest' => 'ironwood',
        'mountain' => 'mythril_ore',
        'plains' => 'beastfang_hide',
        'badlands' => 'obsidian_shard',
        'grassland' => 'silkweave_fiber',
    ];

    /** The five skill lines, §7.2. Ordered -- settlement line picks rely on it. */
    public const SKILLS = ['woodcutting', 'mining', 'hunting', 'quarrying', 'harvesting'];

    public static function skills(): array
    {
        return [
            'woodcutting' => ['name' => 'Woodcutting', 'material' => 'wood', 'rare' => 'ironwood', 'scrap' => 'branch', 'description' => 'Faster mining and better yield in forest hexes.'],
            'mining' => ['name' => 'Mining', 'material' => 'iron_ore', 'rare' => 'mythril_ore', 'scrap' => 'ore_chips', 'description' => 'Faster mining and better yield in mountain hexes.'],
            'hunting' => ['name' => 'Hunting', 'material' => 'pelt', 'rare' => 'beastfang_hide', 'scrap' => 'torn_hide', 'description' => 'Faster mining and better yield on plains and tundra.'],
            'quarrying' => ['name' => 'Quarrying', 'material' => 'stone', 'rare' => 'obsidian_shard', 'scrap' => 'gravel', 'description' => 'Faster mining and better yield in the badlands.'],
            'harvesting' => ['name' => 'Harvesting', 'material' => 'fiber', 'rare' => 'silkweave_fiber', 'scrap' => 'chaff', 'description' => 'Faster mining and better yield in grassland hexes.'],
        ];
    }

    public static function skillForMaterial(string $materialKey): string
    {
        // §5.3 -- a grade belongs to the same line its base raw does. Without
        // this the fallback below would credit a hematite haul to woodcutting.
        if (isset(Variants::SKILL_FOR_MATERIAL[$materialKey])) {
            return Variants::SKILL_FOR_MATERIAL[$materialKey];
        }

        foreach (self::skills() as $key => $skill) {
            if (
                $skill['material'] === $materialKey
                || $skill['rare'] === $materialKey
                || $skill['scrap'] === $materialKey
            ) {
                return $key;
            }
        }

        return 'woodcutting';
    }

    /**
     * §8.4 -- the three craft benches.
     *
     * Derived from the slot rather than stored on each item: a thing's category
     * is already implied by where it is worn, and a second field would only be
     * somewhere for the two to disagree. Consumables have no slot at all, which
     * is exactly what makes them the third category.
     */
    public const CATEGORIES = ['weapon', 'armor', 'consumable'];

    public static function categoryForSlot(?string $slot): string
    {
        if ($slot === null) {
            return 'consumable';
        }

        return in_array($slot, ['armor', 'boots', 'gloves'], true) ? 'armor' : 'weapon';
    }

    public static function category(array $def): string
    {
        return self::categoryForSlot($def['slot'] ?? null);
    }

    /**
     * §8.0.1 -- what a rolled line may land on.
     *
     * A line-locked tool draws from trip stats only. Rolling `travelSpeed` onto
     * an axe would be worth nothing (walking is not woodcutting) *and* would
     * invite equipping all five tools to stack five travel lines -- the exact
     * hole the line-lock exists to close.
     */
    public const OPTION_STATS_TOOL = ['yield', 'tripReduction'];

    public const OPTION_STATS_WORN = ['yield', 'tripReduction', 'travelSpeed', 'processingSpeed'];

    /** @return array<int,string> */
    public static function optionStatsFor(string $slot): array
    {
        return self::skillForSlot($slot) !== null
            ? self::OPTION_STATS_TOOL
            : self::OPTION_STATS_WORN;
    }

    /** §4.0 -- scrap is what a hex gives up to bare hands. It feeds no recipe. */
    public static function isScrap(string $materialKey): bool
    {
        return in_array($materialKey, self::BIOME_SCRAP, true);
    }

    /** Processing recipes, §4 tier 2 / §6. */
    public static function recipes(): array
    {
        return [
            'planks' => ['name' => 'Saw Planks', 'input' => 'wood', 'inputQty' => 3, 'output' => 'planks', 'outputQty' => 1, 'baseSeconds' => 12 * 60, 'skill' => 'woodcutting'],
            'ingots' => ['name' => 'Smelt Ingots', 'input' => 'iron_ore', 'inputQty' => 3, 'output' => 'ingots', 'outputQty' => 1, 'baseSeconds' => 15 * 60, 'skill' => 'mining'],
            'leather' => ['name' => 'Tan Leather', 'input' => 'pelt', 'inputQty' => 3, 'output' => 'leather', 'outputQty' => 1, 'baseSeconds' => 13 * 60, 'skill' => 'hunting'],
            'cut_stone' => ['name' => 'Dress Stone', 'input' => 'stone', 'inputQty' => 3, 'output' => 'cut_stone', 'outputQty' => 1, 'baseSeconds' => 12 * 60, 'skill' => 'quarrying'],
            'cloth' => ['name' => 'Weave Cloth', 'input' => 'fiber', 'inputQty' => 3, 'output' => 'cloth', 'outputQty' => 1, 'baseSeconds' => 11 * 60, 'skill' => 'harvesting'],
            'reinforced_frame' => ['name' => 'Band a Frame', 'input' => 'planks', 'inputQty' => 2, 'secondInput' => 'ingots', 'secondInputQty' => 2, 'output' => 'reinforced_frame', 'outputQty' => 1, 'baseSeconds' => 26 * 60, 'skill' => 'mining'],
        ]
            // §5.3 -- one line per grade, on the same 3:1 the five base lines
            // run. A better grade is a better material, never a better ratio:
            // making the good ore also process cheaper would turn one ladder
            // into two.
            + Variants::PROCESSING;
    }

    public static function recipe(string $key): ?array
    {
        return self::recipes()[$key] ?? null;
    }

    /**
     * Gathering tool slots, §8. One implement per skill line -- an axe is no use
     * on a seam and a bow is no use on a tree, so each line has its own slot and
     * its own ladder. A tool contributes its stat *only* on trips for its own
     * line, and only that tool takes durability for the trip.
     *
     * `weapon` is deliberately not in here: that slot is raid combat, and combat
     * gear must never be able to stand in for a gathering tool.
     */
    public const TOOL_SLOT_SKILL = [
        'axe' => 'woodcutting',
        'pickaxe' => 'mining',
        'bow' => 'hunting',
        'hammer' => 'quarrying',
        'sickle' => 'harvesting',
    ];

    /** The skill a gathering slot serves, or null for gear that works anywhere. */
    public static function skillForSlot(string $slot): ?string
    {
        return self::TOOL_SLOT_SKILL[$slot] ?? null;
    }

    /** The slot a skill line draws its tool from. */
    public static function slotForSkill(string $skill): ?string
    {
        $slot = array_search($skill, self::TOOL_SLOT_SKILL, true);

        return $slot === false ? null : $slot;
    }

    /**
     * Equipment, §8.3. `stat` values are the item's own contribution before the
     * §8.1 stacking falloff and per-tier cap are applied.
     *
     * Every gathering line carries the same five-step ladder -- village basic,
     * city basic, crafted starter, crafted, NFT -- so no line is quietly weaker
     * than another. The specialisation §7.2 asks for comes from the skill point
     * cap, never from one line having better tools available than the rest.
     */
    public static function items(): array
    {
        static $items = null;

        return $items ??= [
            // -------------------------------------------- Basic -- gold shop, +3-5%
            // `station` on a shop item is the smallest settlement that stocks it.
            // Villages carry the basics; the better gear is a reason to walk to a
            // city, which is the same tier pressure §6 puts on processing lines.
            'stone_axe' => ['name' => 'Stone Axe', 'slot' => 'axe', 'rarity' => 'common', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.02, 'palette' => 'stone', 'goldPrice' => 12, 'maxDurability' => 40, 'station' => 'village', 'description' => 'A chipped edge lashed to a handle. Better than bare hands.'],
            'chipped_pick' => ['name' => 'Chipped Pick', 'slot' => 'pickaxe', 'rarity' => 'common', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.02, 'palette' => 'stone', 'goldPrice' => 13, 'maxDurability' => 40, 'station' => 'village', 'description' => 'Second-hand, and shorter than it started. Still bites ore.'],
            'crude_bow' => ['name' => 'Crude Bow', 'slot' => 'bow', 'rarity' => 'common', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.02, 'palette' => 'wood', 'goldPrice' => 14, 'maxDurability' => 40, 'station' => 'village', 'description' => 'Green stave, gut string. Close range or nothing.'],
            'stone_mallet' => ['name' => 'Stone Mallet', 'slot' => 'hammer', 'rarity' => 'common', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.02, 'palette' => 'stone', 'goldPrice' => 12, 'maxDurability' => 40, 'station' => 'village', 'description' => 'A rock on a stick. It still splits badlands shale.'],
            'bent_sickle' => ['name' => 'Bent Sickle', 'slot' => 'sickle', 'rarity' => 'common', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.02, 'palette' => 'fiber', 'goldPrice' => 11, 'maxDurability' => 40, 'station' => 'village', 'description' => 'Someone straightened it once. It did not take.'],

            'iron_hatchet' => ['name' => 'Iron Hatchet', 'slot' => 'axe', 'rarity' => 'uncommon', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.05, 'palette' => 'iron', 'goldPrice' => 90, 'maxDurability' => 70, 'station' => 'city', 'description' => 'Shop-grade steel. Reliable, unremarkable.'],
            'miners_pick' => ['name' => "Miner's Pick", 'slot' => 'pickaxe', 'rarity' => 'uncommon', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.05, 'palette' => 'iron', 'goldPrice' => 95, 'maxDurability' => 70, 'station' => 'city', 'description' => 'Guild pattern, guild price. Every seam in the range has met one.'],
            'recurve_bow' => ['name' => 'Recurve Bow', 'slot' => 'bow', 'rarity' => 'uncommon', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.05, 'palette' => 'pelt', 'goldPrice' => 95, 'maxDurability' => 70, 'station' => 'city', 'description' => 'Backed and glued. Drops a plains buck without the chase.'],
            'iron_sledge' => ['name' => 'Iron Sledge', 'slot' => 'hammer', 'rarity' => 'uncommon', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.05, 'palette' => 'iron', 'goldPrice' => 90, 'maxDurability' => 70, 'station' => 'city', 'description' => 'Heavy enough that the stone does most of the arguing.'],
            'steel_sickle' => ['name' => 'Steel Sickle', 'slot' => 'sickle', 'rarity' => 'uncommon', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.05, 'palette' => 'iron', 'goldPrice' => 85, 'maxDurability' => 70, 'station' => 'city', 'description' => 'Holds an edge through a full field, then wants a stone.'],

            'travel_cloak' => ['name' => 'Travel Cloak', 'slot' => 'armor', 'rarity' => 'common', 'tradeable' => false, 'stat' => 'tripReduction', 'value' => 0.02, 'palette' => 'fiber', 'goldPrice' => 16, 'maxDurability' => 60, 'station' => 'village', 'description' => 'Keeps the weather off. Shaves a little off every trip.'],
            'hide_shoes' => ['name' => 'Hide Shoes', 'slot' => 'boots', 'rarity' => 'uncommon', 'tradeable' => false, 'stat' => 'travelSpeed', 'value' => 0.04, 'palette' => 'pelt', 'goldPrice' => 55, 'maxDurability' => 50, 'station' => 'city', 'description' => 'Soft-soled and quiet. Not built for the badlands.'],

            // ------------------------- Crafted starter -- raw + one refined, +4%
            // The first thing a player makes on a line. Cheap, short-lived, and
            // deliberately weaker than the city shop tool: it is what you can
            // build before you can afford to buy, §12 step 7.
            //
            // Two kinds, and the rung widens by one at every step above: two,
            // three, four, five. A recipe reaching further up the ladder reaches
            // wider across the map as well, so a top-tier craft is a project
            // rather than a purchase.
            //
            // Raw sits in every rung beside the refined. Wood is worth carrying
            // home as wood, not only as something to feed the saw, and the
            // gathering lines get a sink that does not run through a queue.
            'hewn_axe' => ['name' => 'Hewn Axe', 'slot' => 'axe', 'rarity' => 'common', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.03, 'palette' => 'wood', 'station' => 'village', 'maxDurability' => 60, 'inputs' => ['wood' => 6, 'planks' => 2, 'heartknot' => 2], 'description' => 'Your first real tool. It will not last, but it will teach.'],
            'wood_pickaxe' => ['name' => 'Wood Pickaxe', 'slot' => 'pickaxe', 'rarity' => 'common', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.03, 'palette' => 'wood', 'station' => 'village', 'maxDurability' => 60, 'inputs' => ['wood' => 6, 'planks' => 2, 'flux_salt' => 2], 'description' => 'Wood against rock. It lasts exactly as long as you would expect.'],
            'shortbow' => ['name' => 'Shortbow', 'slot' => 'bow', 'rarity' => 'common', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.03, 'palette' => 'wood', 'station' => 'village', 'maxDurability' => 60, 'inputs' => ['wood' => 6, 'cloth' => 2, 'horn' => 2], 'description' => 'Straight stave, woven string. Quiet, and quick to redraw.'],
            'stone_maul' => ['name' => 'Stone Maul', 'slot' => 'hammer', 'rarity' => 'common', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.03, 'palette' => 'stone', 'station' => 'village', 'maxDurability' => 60, 'inputs' => ['stone' => 6, 'cut_stone' => 2, 'whetgrit' => 2], 'description' => 'Dressed head, seated cold. Stone breaks stone.'],
            'reed_sickle' => ['name' => 'Reed Sickle', 'slot' => 'sickle', 'rarity' => 'common', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.03, 'palette' => 'fiber', 'station' => 'village', 'maxDurability' => 60, 'inputs' => ['fiber' => 6, 'cloth' => 2, 'quench_reed' => 2], 'description' => 'Bound at the grip so it stops turning in a wet hand.'],

            // -------------- Crafted -- the uncommon grade, +6-8%, city bench
            // §5.3 -- this rung wants hardwood rather than wood, hematite rather
            // than iron ore. That ground only turns up from the middle ring in,
            // so the city bench is not the only thing making a player walk.
            //
            // The partner is the other half of the thing: iron for an axe head,
            // wood for a pick haft, cloth for a bowstring.
            'ironbound_axe' => ['name' => 'Ironbound Axe', 'slot' => 'axe', 'rarity' => 'uncommon', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.05, 'palette' => 'iron', 'station' => 'city', 'maxDurability' => 120, 'inputs' => ['hardwood' => 6, 'beams' => 3, 'ingots' => 2, 'heartknot' => 3], 'description' => 'Wedged head, banded eye. Fells clean and comes back out.'],
            'iron_pickaxe' => ['name' => 'Iron Pickaxe', 'slot' => 'pickaxe', 'rarity' => 'uncommon', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.05, 'palette' => 'iron', 'station' => 'city', 'maxDurability' => 120, 'inputs' => ['hematite' => 6, 'steel_ingots' => 3, 'planks' => 2, 'flux_salt' => 3], 'description' => 'Balanced head, seasoned haft. The workhorse tool.'],
            'sinew_longbow' => ['name' => 'Sinew Longbow', 'slot' => 'bow', 'rarity' => 'uncommon', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.05, 'palette' => 'pelt', 'station' => 'city', 'maxDurability' => 120, 'inputs' => ['thick_pelt' => 6, 'boiled_leather' => 3, 'cloth' => 2, 'horn' => 3], 'description' => 'Sinew-backed and heavy to draw. The herd never hears it.'],
            'banded_sledge' => ['name' => 'Banded Sledge', 'slot' => 'hammer', 'rarity' => 'uncommon', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.05, 'palette' => 'iron', 'station' => 'city', 'maxDurability' => 120, 'inputs' => ['basalt' => 6, 'dressed_basalt' => 3, 'ingots' => 2, 'whetgrit' => 3], 'description' => 'Iron banding over a stone core. It takes the shock instead of you.'],
            'toothed_sickle' => ['name' => 'Toothed Sickle', 'slot' => 'sickle', 'rarity' => 'uncommon', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.05, 'palette' => 'iron', 'station' => 'city', 'maxDurability' => 120, 'inputs' => ['flax' => 6, 'linen' => 3, 'ingots' => 2, 'quench_reed' => 3], 'description' => 'Serrated inside the curve. It saws where a plain edge slides.'],

            'leather_armor' => ['name' => 'Leather Armor', 'slot' => 'armor', 'rarity' => 'uncommon', 'tradeable' => false, 'stat' => 'tripReduction', 'value' => 0.05, 'palette' => 'pelt', 'station' => 'city', 'maxDurability' => 130, 'inputs' => ['thick_pelt' => 8, 'boiled_leather' => 4, 'cloth' => 2, 'sinew' => 3], 'description' => 'Light enough to walk in all day.'],
            'reinforced_boots' => ['name' => 'Reinforced Boots', 'slot' => 'boots', 'rarity' => 'uncommon', 'tradeable' => false, 'stat' => 'travelSpeed', 'value' => 0.05, 'palette' => 'stone', 'station' => 'city', 'maxDurability' => 140, 'inputs' => ['basalt' => 8, 'dressed_basalt' => 3, 'leather' => 2, 'slate_scale' => 3], 'description' => 'Stone-shod. Ugly, and you will stop caring by noon.'],
            'work_gloves' => ['name' => 'Work Gloves', 'slot' => 'gloves', 'rarity' => 'common', 'tradeable' => false, 'stat' => 'processingSpeed', 'value' => 0.03, 'palette' => 'fiber', 'station' => 'village', 'maxDurability' => 90, 'inputs' => ['fiber' => 6, 'cloth' => 2, 'beeswax' => 2], 'description' => 'Doubled at the palm. Speeds work on the settlement lines.'],

            // ------------------ Rare -- the rare grade, +8%, capital bench
            // §5.3 -- heartoak, meteoric iron, dire pelt: contested ring only.
            // Reinforced Frame gates the rung on top of that, being the one
            // tier-2 that needs two processing lines, so rare gear implies both
            // a settled player and one willing to work the middle of the map.
            'broadaxe' => ['name' => 'Broadaxe', 'slot' => 'axe', 'rarity' => 'rare', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.08, 'palette' => 'iron', 'station' => 'capital', 'maxDurability' => 160, 'inputs' => ['heartoak' => 8, 'bentwood' => 4, 'ingots' => 3, 'heartknot' => 4, 'reinforced_frame' => 1], 'description' => 'Two hands, a long haul, and a tree down in three swings.'],
            'deep_pick' => ['name' => 'Deep Pick', 'slot' => 'pickaxe', 'rarity' => 'rare', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.08, 'palette' => 'iron', 'station' => 'capital', 'maxDurability' => 160, 'inputs' => ['meteoric_iron' => 8, 'skysteel' => 4, 'planks' => 3, 'flux_salt' => 4, 'reinforced_frame' => 1], 'description' => 'Long in the head, for seams that do not start at the surface.'],
            'warbow' => ['name' => 'Warbow', 'slot' => 'bow', 'rarity' => 'rare', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.08, 'palette' => 'pelt', 'station' => 'capital', 'maxDurability' => 160, 'inputs' => ['dire_pelt' => 8, 'lacquered_hide' => 4, 'canvas' => 3, 'horn' => 4, 'reinforced_frame' => 1], 'description' => 'A draw weight most people cannot hold. It does not need a second shot.'],
            'splitting_maul' => ['name' => 'Splitting Maul', 'slot' => 'hammer', 'rarity' => 'rare', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.08, 'palette' => 'stone', 'station' => 'capital', 'maxDurability' => 160, 'inputs' => ['granite' => 8, 'polished_granite' => 4, 'ingots' => 3, 'whetgrit' => 4, 'reinforced_frame' => 1], 'description' => 'Wedge-headed. It does not crush the rock, it opens it.'],
            'threshing_scythe' => ['name' => 'Threshing Scythe', 'slot' => 'sickle', 'rarity' => 'rare', 'tradeable' => false, 'stat' => 'yield', 'value' => 0.08, 'palette' => 'iron', 'station' => 'capital', 'maxDurability' => 160, 'inputs' => ['hemp' => 8, 'canvas' => 4, 'skysteel' => 3, 'quench_reed' => 4, 'reinforced_frame' => 1], 'description' => 'Long snath, long blade. A field goes down in rows, not handfuls.'],
            'banded_mail' => ['name' => 'Banded Mail', 'slot' => 'armor', 'rarity' => 'rare', 'tradeable' => false, 'stat' => 'tripReduction', 'value' => 0.08, 'palette' => 'iron', 'station' => 'capital', 'maxDurability' => 160, 'inputs' => ['dire_pelt' => 8, 'lacquered_hide' => 4, 'steel_ingots' => 3, 'sinew' => 4, 'reinforced_frame' => 1], 'description' => 'Iron bands over tanned hide. Heavy, and worth every pound of it.'],
            'marching_boots' => ['name' => 'Marching Boots', 'slot' => 'boots', 'rarity' => 'rare', 'tradeable' => false, 'stat' => 'travelSpeed', 'value' => 0.08, 'palette' => 'pelt', 'station' => 'capital', 'maxDurability' => 160, 'inputs' => ['dire_pelt' => 8, 'lacquered_hide' => 4, 'polished_granite' => 3, 'tar_seep' => 4, 'reinforced_frame' => 1], 'description' => 'Built for the road between rings, not the walk to the next hex.'],
            'tanners_gloves' => ['name' => "Tanner's Gloves", 'slot' => 'gloves', 'rarity' => 'rare', 'tradeable' => false, 'stat' => 'processingSpeed', 'value' => 0.08, 'palette' => 'pelt', 'station' => 'capital', 'maxDurability' => 160, 'inputs' => ['hemp' => 8, 'canvas' => 4, 'lacquered_hide' => 3, 'beeswax' => 4, 'reinforced_frame' => 1], 'description' => 'Cut for the settlement lines. The work goes faster and the hands last.'],

            // ------------ NFT -- six kinds across four tiers, +12-15% hard cap
            // Each line's top tool wants its own rare material and its own dungeon
            // shard, so kitting out a second line means crossing the map, §4. The
            // rare grade underneath them is what makes it a haul as well as a
            // raid: tier 1, 2, 3 and 4 in one recipe.
            'ironwood_axe' => ['name' => 'Ironwood Axe', 'slot' => 'axe', 'rarity' => 'epic', 'tradeable' => true, 'stat' => 'yield', 'value' => 0.11, 'palette' => 'wood', 'station' => 'capital', 'maxDurability' => 200, 'inputs' => ['ironwood' => 3, 'heartoak' => 8, 'bentwood' => 4, 'heartknot' => 4, 'reinforced_frame' => 2, 'shard_verdant' => 1], 'description' => 'Cut from the thing it is meant to cut. Marketplace-tradeable.'],
            'mythril_pickaxe' => ['name' => 'Mythril Pickaxe', 'slot' => 'pickaxe', 'rarity' => 'epic', 'tradeable' => true, 'stat' => 'yield', 'value' => 0.11, 'palette' => 'iron', 'station' => 'capital', 'maxDurability' => 200, 'inputs' => ['mythril_ore' => 3, 'meteoric_iron' => 8, 'skysteel' => 4, 'flux_salt' => 4, 'reinforced_frame' => 2, 'essence' => 1], 'description' => 'Rings like a bell on ore. Marketplace-tradeable.'],
            'beastfang_bow' => ['name' => 'Beastfang Bow', 'slot' => 'bow', 'rarity' => 'epic', 'tradeable' => true, 'stat' => 'yield', 'value' => 0.11, 'palette' => 'pelt', 'station' => 'capital', 'maxDurability' => 200, 'inputs' => ['beastfang_hide' => 3, 'dire_pelt' => 8, 'lacquered_hide' => 4, 'horn' => 4, 'reinforced_frame' => 2, 'shard_sanguine' => 1], 'description' => 'Strung with something that used to run. Marketplace-tradeable.'],
            'obsidian_sledge' => ['name' => 'Obsidian Sledge', 'slot' => 'hammer', 'rarity' => 'epic', 'tradeable' => true, 'stat' => 'yield', 'value' => 0.11, 'palette' => 'stone', 'station' => 'capital', 'maxDurability' => 200, 'inputs' => ['obsidian_shard' => 3, 'granite' => 8, 'polished_granite' => 4, 'whetgrit' => 4, 'reinforced_frame' => 2, 'shard_cinder' => 1], 'description' => 'Glass that lands like iron. Marketplace-tradeable.'],
            'silkweave_sickle' => ['name' => 'Silkweave Sickle', 'slot' => 'sickle', 'rarity' => 'epic', 'tradeable' => true, 'stat' => 'yield', 'value' => 0.11, 'palette' => 'fiber', 'station' => 'capital', 'maxDurability' => 200, 'inputs' => ['silkweave_fiber' => 3, 'hemp' => 8, 'canvas' => 4, 'quench_reed' => 4, 'reinforced_frame' => 2, 'shard_zephyr' => 1], 'description' => 'The grass parts before it arrives. Marketplace-tradeable.'],

            'ironwood_armor' => ['name' => 'Ironwood Armor', 'slot' => 'armor', 'rarity' => 'epic', 'tradeable' => true, 'stat' => 'tripReduction', 'value' => 0.11, 'palette' => 'wood', 'station' => 'capital', 'maxDurability' => 210, 'inputs' => ['ironwood' => 3, 'heartoak' => 8, 'bentwood' => 4, 'pine_pitch' => 4, 'reinforced_frame' => 2, 'shard_verdant' => 1], 'description' => 'Grown, not forged. Marketplace-tradeable.'],
            'beastfang_boots' => ['name' => 'Beastfang Boots', 'slot' => 'boots', 'rarity' => 'epic', 'tradeable' => true, 'stat' => 'travelSpeed', 'value' => 0.11, 'palette' => 'pelt', 'station' => 'capital', 'maxDurability' => 190, 'inputs' => ['beastfang_hide' => 3, 'dire_pelt' => 8, 'lacquered_hide' => 4, 'sinew' => 4, 'reinforced_frame' => 2, 'relic' => 1], 'description' => 'Something fast died for these. Marketplace-tradeable.'],
            'silkweave_gloves' => ['name' => 'Silkweave Gloves', 'slot' => 'gloves', 'rarity' => 'epic', 'tradeable' => true, 'stat' => 'processingSpeed', 'value' => 0.11, 'palette' => 'fiber', 'station' => 'capital', 'maxDurability' => 195, 'inputs' => ['silkweave_fiber' => 3, 'hemp' => 8, 'canvas' => 4, 'beeswax' => 4, 'reinforced_frame' => 2, 'shard_zephyr' => 1], 'description' => 'Spun so fine the work goes quicker for feeling less. Marketplace-tradeable.'],
        ]
            // §8.0 -- the two rungs above epic, so the ladder can be read whole.
            // Neither is reachable: legendary needs a guild hall and there are
            // none, unique has no bench at all and drops soulbound. Generated,
            // not typed: see scripts/gen_toptier.py.
            + TopTier::ITEMS
            // §8.5 -- sixty potions, twelve a rung. No slot and no durability:
            // a potion is spent, it starts a timed buff on ONE ACTION, and the
            // buff expiring is the sink (§11.1). Generated, not typed.
            + Alchemy::CONSUMABLES;
    }

    public static function item(string $key): ?array
    {
        return self::items()[$key] ?? null;
    }

    /** §9.1 -- five dungeons, one per biome. */
    public const DUNGEONS = [
        ['key' => 'rootvault', 'name' => 'Rootvault', 'biome' => 'forest', 'drop' => 'shard_verdant'],
        ['key' => 'deepshaft', 'name' => 'Deepshaft', 'biome' => 'mountain', 'drop' => 'shard_ferrous'],
        ['key' => 'beastwarren', 'name' => 'Beastwarren', 'biome' => 'plains', 'drop' => 'shard_sanguine'],
        ['key' => 'ashpit', 'name' => 'Ashpit', 'biome' => 'badlands', 'drop' => 'shard_cinder'],
        ['key' => 'windhollow', 'name' => 'Windhollow', 'biome' => 'grassland', 'drop' => 'shard_zephyr'],
    ];

    /**
     * Settlement names are built from a prefix and a suffix rather than picked
     * from a flat list. §5.3 wants a map players can navigate by memory, and a
     * flat list of 18 put two identically-named villages on screen at once --
     * "meet me at Millgate" stops meaning anything. This gives 22 x 16 = 352.
     */
    public const NAME_PREFIXES = [
        'Ash', 'Kel', 'Thorn', 'Dun', 'Red', 'Stone', 'Var', 'Mill', 'Black',
        'High', 'Ember', 'Grey', 'Oak', 'Iron', 'Cold', 'Sable', 'Wren', 'Marrow',
        'Elder', 'Fern', 'Hollow', 'Brack',
    ];

    public const NAME_SUFFIXES = [
        'ford', 'grave', 'well', 'moor', 'hollow', 'brook', 'row', 'gate',
        'fen', 'cross', 'ton', 'march', 'hurst', 'vale', 'water', 'ridge',
    ];

    public const STATION_RANK = ['village' => 1, 'city' => 2, 'capital' => 3];
}
