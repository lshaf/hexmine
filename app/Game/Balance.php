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

    /** Hard cap per slot regardless of rarity, §8.1 rule 1. */
    public const STAT_CAP = ['basic' => 0.05, 'crafted' => 0.08, 'nft' => 0.15];

    /** Diminishing returns on stacking, §8.1 rule 2. */
    public const STACK_FALLOFF = 0.5;

    public const DRAIN_PER_MINE = 1;
    public const DRAIN_PER_RAID = 4;
    public const SALVAGE_RATE = 0.25;
    public const REPAIR_COST_RATE = 0.6;

    // -------------------------------------------------------------- economy §2

    /** Tier 3 materials are capped per wallet, §2. */
    public const RARE_WALLET_CAP = 40;

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
