<?php

declare(strict_types=1);

namespace App\Game;

/**
 * Tuning constants. Port of `frontend/src/game/balance.ts`.
 *
 * Every value here is a starting point for tuning, not a locked constant
 * (CLAUDE.md preamble). Keeping all of them in one class means a balance pass
 * never turns into a grep across the codebase.
 *
 * These MUST stay in step with the frontend copy: the client uses its own copy
 * to render predictions (trip times, queue durations) before the server answers.
 * The server is still the authority -- a drift shows up as a UI number that does
 * not match the result, not as an exploit.
 */
final class Balance
{
    public const MINUTE = 60_000;
    public const HOUR = 60 * self::MINUTE;

    // ---------------------------------------------------------------- map §5

    public const MAP_COLS = 5000;
    public const MAP_ROWS = 5000;
    public const MAP_SEED = 0x5eed1a3f;

    /** Biome lattice, §5.3. Cell size in tiles, and cells per coherent region. */
    public const BIOME_CELL = 9;
    public const BIOME_REGION_CELLS = 5;

    /** Normalised radius boundaries for the ring layout, §5.2. */
    public const RING_CENTER = 0.08;
    public const RING_INNER = 0.34;
    public const RING_MID = 0.64;

    // ------------------------------------------------------------- mining §7.3

    public const MINING_BASE_MIN_SECONDS = 30 * 60;
    public const MINING_BASE_MAX_SECONDS = 60 * 60;

    /** clamp() bounds. The floor is mandatory -- see Formulas::tripTime(). */
    public const MINING_FLOOR_SECONDS = 30 * 60;
    public const MINING_CEILING_SECONDS = 60 * 60;

    public const MINING_MAX_SKILL_REDUCTION = 20 * 60;
    public const MINING_MAX_EQUIP_REDUCTION = 10 * 60;

    /** Exactly two mining slots per hex, §5.1. */
    public const SLOTS_PER_TILE = 2;

    /** Depleted tiles regrow after ~9h, §5.1. */
    public const REGROW_MS = 9 * self::HOUR;

    public const MINING_AP_COST = 2;

    /** Chance a collected tile is worked out and enters regrowth. */
    public const DEPLETE_CHANCE = 0.34;

    /** Chance an inner-ring tile carries its rare variant, §5.2 / §4. */
    public const RARE_SPAWN_CHANCE = 0.18;

    // ------------------------------------------------------------- hunting §5.5

    public const HERD_LIFETIME_MS = 4 * self::HOUR;
    public const HERD_CHANCE = 0.06;

    // ---------------------------------------------------------- processing §6

    /** Five open slots per feature, first-come-first-served, §6.1. */
    public const PUBLIC_SLOTS = 5;

    /** Speed multiplier by settlement tier -- lower is faster. */
    public const SPEED_VILLAGE = 1.0;
    public const SPEED_CITY = 0.75;
    public const SPEED_CAPITAL = 0.55;

    /** Presence bonus, §6.2. */
    public const PRESENCE_SPEED_BONUS = 0.2;

    // ----------------------------------------------------------- character §7

    public const STARTING_GOLD = 25;
    public const STARTING_AP = 20;
    public const BASE_AP_MAX = 20;
    public const AP_PER_LEVEL = 4;
    public const AP_REGEN_MS = 4 * self::MINUTE;
    public const BASE_STORAGE = 120;
    public const STORAGE_PER_LEVEL = 40;
    public const MAX_LEVEL = 60;

    // -------------------------------------------------------------- skills §7.2

    public const SKILL_MAX_LEVEL = 50;

    /** Cap total points so characters specialise, §7.2. */
    public const SKILL_TOTAL_POINT_CAP = 90;

    // ------------------------------------------------------------- storage §11.1

    public const DECAY_INTERVAL_MS = 10 * self::MINUTE;
    public const DECAY_RATE = 0.05;

    // ----------------------------------------------------------- equipment §8.1

    /**
     * The rarity ladder, §8.1 rule 1. Rarity walks up to a single global ceiling
     * rather than every tier sharing one: the best a stat can ever reach is
     * `unique`, and nothing -- no future rarity, no rolled option, no buff -- may
     * be allowed past it.
     */
    public const STAT_CAP = [
        'common' => 0.03,
        'uncommon' => 0.05,
        'rare' => 0.08,
        'epic' => 0.11,
        'legendary' => 0.14,
        'unique' => 0.15,
    ];

    /** The hard ceiling for the whole game. Read this, never `STAT_CAP['unique']`. */
    public const STAT_CEILING = 0.15;

    /** Ordered weakest-first, so a rarity can be compared against a station's reach. */
    public const RARITIES = ['common', 'uncommon', 'rare', 'epic', 'legendary', 'unique'];

    public static function rarityRank(string $rarity): int
    {
        $rank = array_search($rarity, self::RARITIES, true);

        return $rank === false ? 0 : $rank;
    }

    /**
     * §8.0 -- how far up the ladder each workbench reaches. A village will never
     * make an epic no matter what materials you carry to it, which is most of
     * what makes a capital worth the walk.
     *
     * `guild` is defined but unreachable: guild halls do not exist yet (§10), and
     * naming the gate now is what stops legendary leaking out of a capital.
     */
    public const STATION_RARITY_CAP = [
        'village' => 'common',
        'city' => 'uncommon',
        'capital' => 'epic',
        'guild' => 'legendary',
    ];

    /** Gold buys the bottom two rungs and nothing else, §3.2. */
    public const SHOP_RARITY_CAP = 'uncommon';

    // -------------------------------------------------------------- options §8.0.1

    /**
     * How many bonus lines each rung rolls. Uncommon is the only one that may
     * roll nothing, which is what makes an uncommon with a line feel found
     * rather than issued.
     */
    public const OPTION_ROLLS = [
        'common' => 0,
        'uncommon' => 1,
        'rare' => 1,
        'epic' => 2,
        'legendary' => 3,
        'unique' => 3,
    ];

    /** Chance each of an uncommon's slots actually fills. Higher rungs always do. */
    public const OPTION_CHANCE_UNCOMMON = 0.5;

    /**
     * A rolled line is worth 1-3%. Small on purpose: options are variety, not a
     * second power ladder, and they are clamped by STAT_CEILING like everything
     * else (§8.1 rule 1).
     */
    public const OPTION_MIN = 0.01;

    public const OPTION_MAX = 0.03;

    /**
     * The capital bazaar, §8.0.1. A capital stocks the same common and uncommon
     * goods as a city -- its edge is that some of that stock comes pre-rolled,
     * even at common, which is the one place a common item can carry a line.
     */
    public const CAPITAL_SHOP_OPTION_CHANCE = 0.5;

    // --------------------------------------------------------- consumables §8.5

    /**
     * Buffs expire, and that is the sink (§11.1). A consumable with a permanent
     * effect would only accumulate, which the design's north star forbids
     * outright -- if a potion is ever made permanent, it stops being a sink and
     * becomes another power ladder.
     */
    public const BUFF_MS = 30 * self::MINUTE;

    /** How many of one potion a character may hold. Stops hoarding a stat. */
    public const CONSUMABLE_STACK_CAP = 20;

    public static function stationReaches(string $stationTier, string $rarity): bool
    {
        $reach = self::STATION_RARITY_CAP[$stationTier] ?? 'common';

        return self::rarityRank($rarity) <= self::rarityRank($reach);
    }

    /** The smallest station that can make this rarity, or null if none can. */
    public static function stationForRarity(string $rarity): ?string
    {
        foreach (self::STATION_RARITY_CAP as $tier => $reach) {
            if (self::rarityRank($rarity) <= self::rarityRank($reach)) {
                return $tier;
            }
        }

        return null;
    }

    /** Diminishing returns on stacking, §8.1 rule 2. */
    public const STACK_FALLOFF = 0.5;

    /**
     * §4.0 -- what a scrap haul is worth as XP, against the same haul of the
     * real material. Bare-handed work still teaches the line, just badly: at 1.0
     * a player could max a skill without ever buying a tool, which would make
     * the whole §8.0 ladder optional.
     */
    public const SCRAP_XP_RATE = 0.25;

    public const DRAIN_PER_MINE = 1;
    public const DRAIN_PER_RAID = 4;
    public const SALVAGE_RATE = 0.25;
    public const REPAIR_COST_RATE = 0.6;

    // -------------------------------------------------------------- economy §2

    /** Tier 3 materials are capped per wallet, §2. */
    public const RARE_WALLET_CAP = 40;

    // ------------------------------------------------------------------ travel

    /**
     * §5 -- one hex of ground, on foot. Distance is what makes a destination a
     * decision rather than a click, so the cost is paid per hex crossed.
     */
    public const TRAVEL_MS_PER_HEX = 10 * self::MINUTE;

    // ------------------------------------------------------------------ curves

    public static function xpForLevel(int $level): int
    {
        return (int) round(80 * $level ** 1.55);
    }

    public static function skillXpForLevel(int $level): int
    {
        return (int) round(45 * $level ** 1.4);
    }

    public static function apMax(int $level): int
    {
        return self::BASE_AP_MAX + ($level - 1) * self::AP_PER_LEVEL;
    }

    public static function storageCap(int $level): int
    {
        return self::BASE_STORAGE + ($level - 1) * self::STORAGE_PER_LEVEL;
    }

    /** §7.1 -- level unlocks travel range. Capacity, never power. */
    public static function travelRange(int $level, float $travelSpeedBonus = 0.0): int
    {
        return 6 + (int) floor($level * 0.8) + (int) round($travelSpeedBonus * 20);
    }

    public static function settlementSpeed(string $tier): float
    {
        return match ($tier) {
            'capital' => self::SPEED_CAPITAL,
            'city' => self::SPEED_CITY,
            default => self::SPEED_VILLAGE,
        };
    }

    /**
     * Development clock compression. Real timers are 30-60 minutes (§7.3), which
     * makes the game untestable by hand. Applied at the persistence boundary
     * only, so every formula stays honest; 1 means production timings.
     */
    public static function timeScale(): int
    {
        return max(1, (int) config('game.time_scale', 1));
    }

    /** Scale a real duration in ms into whatever clock this environment runs. */
    public static function scaled(int $ms): int
    {
        return max(1000, (int) round($ms / self::timeScale()));
    }
}
