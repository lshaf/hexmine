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
     * The 20 materials, §4.
     * tier 1 raw (biome-locked) / 2 refined / 3 rare (capped) / 4 raid.
     */
    public static function materials(): array
    {
        static $materials = null;

        return $materials ??= [
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
        ];
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
            'woodcutting' => ['name' => 'Woodcutting', 'material' => 'wood', 'rare' => 'ironwood', 'description' => 'Faster trips and better yield in forest hexes.'],
            'mining' => ['name' => 'Mining', 'material' => 'iron_ore', 'rare' => 'mythril_ore', 'description' => 'Faster trips and better yield in mountain hexes.'],
            'hunting' => ['name' => 'Hunting', 'material' => 'pelt', 'rare' => 'beastfang_hide', 'description' => 'Faster trips and better yield on plains and tundra.'],
            'quarrying' => ['name' => 'Quarrying', 'material' => 'stone', 'rare' => 'obsidian_shard', 'description' => 'Faster trips and better yield in the badlands.'],
            'harvesting' => ['name' => 'Harvesting', 'material' => 'fiber', 'rare' => 'silkweave_fiber', 'description' => 'Faster trips and better yield in grassland hexes.'],
        ];
    }

    public static function skillForMaterial(string $materialKey): string
    {
        foreach (self::skills() as $key => $skill) {
            if ($skill['material'] === $materialKey || $skill['rare'] === $materialKey) {
                return $key;
            }
        }

        return 'woodcutting';
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
        ];
    }

    public static function recipe(string $key): ?array
    {
        return self::recipes()[$key] ?? null;
    }

    /**
     * Equipment, §8.3. `stat` values are the item's own contribution before the
     * §8.1 stacking falloff and per-tier cap are applied.
     */
    public static function items(): array
    {
        static $items = null;

        return $items ??= [
            // Basic -- gold shop, +3-5%
            // `station` on a shop item is the smallest settlement that stocks it.
            // Villages carry the basics; the better gear is a reason to walk to a
            // city, which is the same tier pressure §6 puts on processing lines.
            'stone_axe' => ['name' => 'Stone Axe', 'slot' => 'tool', 'tier' => 'basic', 'stat' => 'yield', 'value' => 0.03, 'palette' => 'stone', 'goldPrice' => 20, 'maxDurability' => 40, 'station' => 'village', 'description' => 'A chipped edge lashed to a handle. Better than bare hands.'],
            'travel_cloak' => ['name' => 'Travel Cloak', 'slot' => 'armor', 'tier' => 'basic', 'stat' => 'tripReduction', 'value' => 0.04, 'palette' => 'fiber', 'goldPrice' => 65, 'maxDurability' => 60, 'station' => 'village', 'description' => 'Keeps the weather off. Shaves a little off every trip.'],
            'hide_shoes' => ['name' => 'Hide Shoes', 'slot' => 'boots', 'tier' => 'basic', 'stat' => 'travelSpeed', 'value' => 0.04, 'palette' => 'pelt', 'goldPrice' => 55, 'maxDurability' => 50, 'station' => 'city', 'description' => 'Soft-soled and quiet. Not built for the badlands.'],
            'iron_hatchet' => ['name' => 'Iron Hatchet', 'slot' => 'tool', 'tier' => 'basic', 'stat' => 'yield', 'value' => 0.05, 'palette' => 'iron', 'goldPrice' => 90, 'maxDurability' => 70, 'station' => 'city', 'description' => 'Shop-grade steel. Reliable, unremarkable.'],

            // Crafted -- tier 1-2 materials, +6-8%
            'wood_pickaxe' => ['name' => 'Wood Pickaxe', 'slot' => 'tool', 'tier' => 'crafted', 'stat' => 'yield', 'value' => 0.04, 'palette' => 'wood', 'station' => 'village', 'maxDurability' => 60, 'inputs' => ['planks' => 4], 'description' => 'Your first real tool. It will not last, but it will teach.'],
            'iron_pickaxe' => ['name' => 'Iron Pickaxe', 'slot' => 'tool', 'tier' => 'crafted', 'stat' => 'yield', 'value' => 0.06, 'palette' => 'iron', 'station' => 'village', 'maxDurability' => 120, 'inputs' => ['ingots' => 5, 'planks' => 3], 'description' => 'Balanced head, seasoned haft. The workhorse tool.'],
            'leather_armor' => ['name' => 'Leather Armor', 'slot' => 'armor', 'tier' => 'crafted', 'stat' => 'tripReduction', 'value' => 0.06, 'palette' => 'pelt', 'station' => 'village', 'maxDurability' => 130, 'inputs' => ['leather' => 6, 'cloth' => 2], 'description' => 'Light enough to walk in all day.'],
            'reinforced_boots' => ['name' => 'Reinforced Boots', 'slot' => 'boots', 'tier' => 'crafted', 'stat' => 'travelSpeed', 'value' => 0.08, 'palette' => 'stone', 'station' => 'city', 'maxDurability' => 140, 'inputs' => ['cut_stone' => 4, 'leather' => 3], 'description' => 'Stone-shod. Ugly, and you will stop caring by noon.'],
            'work_gloves' => ['name' => 'Work Gloves', 'slot' => 'gloves', 'tier' => 'crafted', 'stat' => 'processingSpeed', 'value' => 0.04, 'palette' => 'fiber', 'station' => 'village', 'maxDurability' => 90, 'inputs' => ['cloth' => 3, 'planks' => 2], 'description' => 'Doubled at the palm. Speeds work on the settlement lines.'],

            // NFT -- tier 3 + tier 4 only, +12-15% hard cap
            'mythril_pickaxe' => ['name' => 'Mythril Pickaxe', 'slot' => 'tool', 'tier' => 'nft', 'stat' => 'yield', 'value' => 0.12, 'palette' => 'iron', 'station' => 'capital', 'maxDurability' => 200, 'inputs' => ['mythril_ore' => 3, 'reinforced_frame' => 2, 'essence' => 1], 'description' => 'Rings like a bell on ore. Marketplace-tradeable.'],
            'ironwood_armor' => ['name' => 'Ironwood Armor', 'slot' => 'armor', 'tier' => 'nft', 'stat' => 'tripReduction', 'value' => 0.12, 'palette' => 'wood', 'station' => 'capital', 'maxDurability' => 210, 'inputs' => ['ironwood' => 3, 'silkweave_fiber' => 2, 'shard_verdant' => 1], 'description' => 'Grown, not forged. Marketplace-tradeable.'],
            'beastfang_boots' => ['name' => 'Beastfang Boots', 'slot' => 'boots', 'tier' => 'nft', 'stat' => 'travelSpeed', 'value' => 0.15, 'palette' => 'pelt', 'station' => 'capital', 'maxDurability' => 190, 'inputs' => ['beastfang_hide' => 2, 'obsidian_shard' => 1, 'relic' => 1], 'description' => 'Something fast died for these. Marketplace-tradeable.'],
        ];
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
