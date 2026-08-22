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

    /**
     * §5.1 -- the map is measured from the middle out, and (0,0) is the middle.
     *
     * One radius, because the map is square and always has been. A radius of
     * 200 means every column and every row from -200 to 200 inclusive, so the
     * grid is 401 a side. Signed coordinates are what make the ring maths honest:
     * a ring is a distance from the origin rather than from an arbitrary point
     * halfway along an unsigned axis, and the dead centre of the world is the
     * one coordinate you never have to look up.
     *
     * Read from config/game.php, so the size and the seed of the world are
     * deployment settings rather than a code edit. Methods and not constants
     * for exactly that reason -- a const cannot ask config() anything.
     *
     * Ship value: 2500, for the 5000x5000 of §5.
     */
    public static function mapRadius(): int
    {
        return self::$mapRadius ??= max(1, (int) config('game.map.radius', 200));
    }

    /** Tiles per axis, both ends included. Derived -- never configure this. */
    public static function mapSize(): int
    {
        return self::mapRadius() * 2 + 1;
    }

    /**
     * The world's seed, masked to 32 bits.
     *
     * Hash::hash2 is a bit-for-bit port of the JavaScript one and only agrees
     * with it inside that width, so a seed configured wider would generate a
     * world the client cannot reproduce. Hex and decimal are both accepted:
     * `intval` with base 0 reads the 0x prefix the way the source did.
     */
    public static function mapSeed(): int
    {
        if (self::$mapSeed !== null) {
            return self::$mapSeed;
        }

        $seed = config('game.map.seed', 0x5eed1a3f);

        return self::$mapSeed = (is_string($seed) ? intval($seed, 0) : (int) $seed) & 0xFFFFFFFF;
    }

    /**
     * Memoised because the seed is read once per hashed coordinate, and a tile
     * is several hashes. A container lookup in that loop costs whole seconds
     * over a map-wide walk.
     */
    private static ?int $mapRadius = null;

    private static ?int $mapSeed = null;

    /**
     * Drop the memoised map settings.
     *
     * Only a test changing config/game.php at runtime needs this, and it must
     * also clear WorldGen's caches -- a different seed is a different world, so
     * every biome and cell already remembered is wrong. WorldGen::forget() does
     * both, and is the one to call.
     */
    public static function forgetMapConfig(): void
    {
        self::$mapRadius = null;
        self::$mapSeed = null;
    }

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

    /** Chance a collected tile is worked out and enters regrowth. */
    public const DEPLETE_CHANCE = 0.34;

    /** Chance an inner-ring tile carries its rare variant, §5.2 / §4. */
    public const RARE_SPAWN_CHANCE = 0.18;

    // ---------------------------------------------------------------- water §5.3

    /**
     * Lakes and waterways. Neither can be worked, and both are derived like
     * everything else on the map -- a pure function of (col, row, seed), so no
     * table stores a single drop of it.
     *
     * Water is deliberately thin, around 3% of the map. It is there to break up
     * the biome blobs and give a walk something to go round, not to gate
     * anything: a hex you cannot work is a hex the §11 sinks never see, and too
     * many of them would quietly shrink the economy.
     */
    public const RIVERS = 4;

    /** Hexes between a waterway's control points. Longer is straighter. */
    public const RIVER_SEGMENT = 24;

    /** How far a waterway may wander off its line, as a fraction of the radius. */
    public const RIVER_AMPLITUDE = 0.09;

    /**
     * Half the channel, in hexes.
     *
     * Under 1 on purpose: the band is measured between one column's centre and
     * the next, so a steep reach widens on its own and a slack one stays a
     * single hex across. A fixed width would either break into stepping stones
     * on the bends or run four hexes wide on the straights.
     */
    public const RIVER_HALF_WIDTH = 0.6;

    /** One candidate lake per cell of this many hexes, as with settlements. */
    public const LAKE_CELL = 34;

    public const LAKE_CHANCE = 0.42;

    public const LAKE_MIN_RADIUS = 3;

    public const LAKE_MAX_RADIUS = 5;

    /** Per-hex jitter on the shoreline, so a lake is not a drawn circle. */
    public const LAKE_EDGE_WOBBLE = 0.7;

    // ------------------------------------------------------------- hunting §5.5

    public const HERD_LIFETIME_MS = 4 * self::HOUR;
    public const HERD_CHANCE = 0.06;

    /**
     * Mirrors HUNTING in resources/js/game/balance.ts.
     *
     * Flat, and deliberately outside Formulas::tripTime(): §7.3's clamp floors a
     * trip at 30 minutes, so routing a 25-minute hunt through it would round the
     * hunt UP and quietly delete the difference. §7.3 is a rule about working a
     * hex for its seam; a herd is not a seam.
     */
    public const HUNT_BASE_SECONDS = 25 * 60;

    /** Pelt haul before skill, gear and ring are applied. */
    public const HUNT_PELT_MIN = 2;

    public const HUNT_PELT_MAX = 5;

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
    /**
     * §7.4.1 -- 100 levels, and one skill point each. The cap is the point: 100
     * points buys three complete 30-node trees with 10 spare, deliberately just
     * short of a fourth.
     */
    public const MAX_LEVEL = 100;

    public const SKILL_POINTS_PER_LEVEL = 1;

    // ------------------------------------------------------------------ bag §7.6

    /**
     * §7.6 -- what a prospector can carry, in units. Everything in the bag
     * counts: materials, potions, and every piece of gear not being worn.
     *
     * Flat, and level does not move it. Carrying capacity used to be one of the
     * things a level bought, which made the bag a number that solved itself --
     * by the time it mattered you had outgrown it. A fixed floor makes "what do
     * I take" a decision that lasts the whole game, and the only thing that
     * widens it is the road (§7.5), which is the one reward that cannot be
     * bought.
     */
    public const BAG_UNITS = 120;

    /**
     * §7.6 -- how many distinct things, regardless of how many of each.
     *
     * The second limit is what makes a bag a bag rather than a bucket. A stack
     * is a row whether it holds one or a hundred; an unworn tool is a row of
     * its own, because two axes do not stack.
     *
     * Thirty is deliberately roomy against a catalogue of twenty-nine materials
     * and five draughts: the straps are not meant to be the thing that bites on
     * an ordinary trip. They are the ceiling on carrying *one of everything* --
     * a prospector who never chooses a line still runs out of places to put
     * things -- while `BAG_UNITS` is what actually decides when a haul has to be
     * dealt with. Two limits, and only one of them is felt every day.
     */
    public const BAG_ROWS = 30;

    // -------------------------------------------------------------- skills §7.2

    public const SKILL_MAX_LEVEL = 50;

    /** Cap total points so characters specialise, §7.2. */
    public const SKILL_TOTAL_POINT_CAP = 90;

    // ---------------------------------------------------------------- jobs §7.4

    /**
     * §7.4.1 -- a job level gates tree nodes and does nothing else. It grants no
     * stat, no yield, no speed. Levelling a job to 30 is worth exactly nothing
     * until a point is spent, which is what keeps points the scarce thing.
     */
    public const JOB_MAX_LEVEL = 30;

    /** §7.4 -- what one craft teaches, by what was made: common 10 ... epic 40. */
    public const JOB_XP_PER_RARITY_RANK = 10;

    /**
     * §6 -- what one unit off a processing bench teaches its line's job.
     *
     * Per unit of output rather than per run, so a three-batch smelt is worth
     * three times a single one and the number the player picks is the number
     * that pays. Twelve puts a committed line at job 30 around the same
     * six-month mark §7.4.4 sizes everything else to: a prospector who actually
     * runs a line clears roughly fifteen units a day, and 32,000 XP is about
     * 2,700 units.
     *
     * Never run through scaled(), like every other XP figure (§7.4.4): a fast
     * dev clock is a testing tool, not a progression cheat.
     */
    public const JOB_XP_PER_PROCESS_UNIT = 12;

    /**
     * §7.4.3 -- caps on the node effects that are NOT stats.
     *
     * Stat nodes need no cap of their own: they feed the same aggregate and the
     * same STAT_CEILING clamp as gear, options and potions, so a skill point can
     * never take a stat past +15%.
     *
     * These four can, though, and each one thins a §11 sink rather than a power
     * curve -- cheaper crafts and bigger batches drain the materials sink, and
     * tougher gear drains the repair sink. Uncapped, a maxed crafter would
     * quietly switch off the loss the whole economy is balanced around. The bag
     * and sight caps below are the same argument in counts rather than
     * percentages.
     */
    public const SKILL_OPTION_CHANCE_CAP = 0.35;

    public const SKILL_DURABILITY_CAP = 0.25;

    public const SKILL_COST_REDUCTION_CAP = 0.15;

    public const SKILL_BATCH_CAP = 2;

    /**
     * §7.6 -- what the Explorer tree (§7.5) may add to each limit: 120 -> 200
     * units and 30 -> 50 rows, arrived at ten and four at a time across five
     * rows of three, from job level 2 to 30.
     *
     * Bounded for the same reason every other skill cap is: the bag is the
     * pressure that turns hauls into decisions, and a tree that could switch it
     * off would switch off the selling, processing and dumping it drives (§11.1).
     * These are counts rather than percentages, so like `sight` they have
     * nothing to do with the stat ceiling -- which matters more here than
     * anywhere, because the Explorer's rungs are granted rather than bought
     * (§7.5) and capability is the only thing a free tree may ever hand out.
     */
    public const SKILL_BAG_UNITS_CAP = 80;

    public const SKILL_BAG_ROWS_CAP = 20;

    /**
     * §7.5 -- how many hexes of sight the Explorer tree can add, on top of the
     * base one.
     *
     * The last of them, and it guards the same kind of thing the rest do: not a
     * power curve but a cost. Sight is the radius of the one query the map makes
     * (§5.6), and its cost is the square of that radius -- one hex is seven
     * tiles, two is nineteen, three is thirty-seven, ten would be three hundred
     * and thirty-one. The cap is what lets sight be a reward at all without
     * handing a scanner to anyone patient enough to walk.
     */
    public const SKILL_SIGHT_CAP = 2;

    /**
     * §7.5 -- Explorer XP for one hex crossed.
     *
     * Flat, and never run through scaled(): §7.4.4 forbids XP tracking the game
     * clock, and a hex is a distance rather than a duration, so there is nothing
     * here for a fast dev clock to compress. Five a hex puts the first sight
     * node about sixty hexes out and the last a few thousand -- a number of
     * journeys, not a number of days.
     */
    public const EXPLORER_XP_PER_HEX = 5;

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
     * §8.0.1 -- a line pointed at ONE gathering line is worth more than a flat
     * one, because it is worth nothing on the other four.
     *
     * Without the gap a scoped roll would be strictly the worse outcome and the
     * whole pool would read as a bad-luck table. With it, the choice between
     * "+2% yield everywhere" and "+4% mining yield" is a real one for a
     * prospector who knows which line they actually work. It is still clamped
     * by STAT_CEILING like everything else -- narrower buys a steeper climb to
     * the same ceiling, never a higher one (§8.1 rule 1).
     */
    public const OPTION_SCOPED_MIN = 0.02;

    public const OPTION_SCOPED_MAX = 0.05;

    /**
     * The capital bazaar, §8.0.1. A capital stocks the same common and uncommon
     * goods as a city -- its edge is that some of that stock comes pre-rolled,
     * even at common, which is the one place a common item can carry a line.
     */
    public const CAPITAL_SHOP_OPTION_CHANCE = 0.5;

    // --------------------------------------------------------- consumables §8.5

    /**
     * How many of one potion a character may hold. Stops hoarding a stat.
     *
     * There is no buff duration to tune any more: a draught arms the action it
     * names and is spent by taking it (§8.5). Being *spent* is the sink -- a
     * consumable whose effect were permanent would only accumulate, which the
     * design's north star forbids outright.
     */
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
     *
     * It is also the *only* thing that costs: there is no reach limit. Any hex
     * on the map is walkable from any other, and the far ones are expensive in
     * the one currency an idle game cannot inflate -- hours. A gate on top of
     * that would be a second answer to a question distance already answers.
     */
    public const TRAVEL_MS_PER_HEX = 10 * self::MINUTE;

    // ------------------------------------------------------------------- sight

    /**
     * §5.6 -- how far a prospector can actually see. One hex, and that is the
     * whole of it.
     *
     * Sight used to be reach, which made it wide enough that the live-state
     * query behind it was a scan of a couple of hundred hexes on every move.
     * One is a disc of seven -- the hex underfoot and its six neighbours. The
     * map beyond it is not blank -- terrain is derived from the seed and
     * settlement glyphs are drawn everywhere (§13.2) -- it is merely
     * *unscouted*: no depletion, no miners, no haul figures. That is what makes
     * walking somewhere worth doing, and starting at one is what leaves the
     * Explorer tree (§7.5) something to actually give.
     */
    public const SIGHT_RADIUS = 1;

    /**
     * §5.6 -- sight on the road. You are between hexes, watching your feet.
     *
     * Zero is also what makes the whole journey free of queries: a moving
     * character asks the server nothing until it stops.
     */
    public const SIGHT_TRAVELLING = 0;

    /**
     * §5.4 + §12 -- how far a fresh spawn may be from the village whose
     * woodcutting line its tutorial needs.
     *
     * This is a *generation* constraint, not a rule the player ever meets. It
     * was level-1 reach back when reach existed; it stays a number of its own
     * so that shrinking sight cannot quietly strand every new character six
     * hexes from the only place that turns their wood into planks.
     */
    public const SPAWN_VILLAGE_RADIUS = 6;

    // ------------------------------------------------------------------ curves

    /**
     * §7.4.4 -- sized against measured income, not picked.
     *
     * A career averages ~1,080 character XP a day at game speed 1 (28 mining
     * trips a day unequipped, 48 on the 30-minute floor, plus the processing
     * those hauls feed). ~197,000 XP total against that rate puts level 100 at
     * roughly 182 days of unbroken play, which is the six-month target.
     *
     * The flat 40 is a floor so the first level costs about three mining trips
     * rather than half of one.
     */
    public static function xpForLevel(int $level): int
    {
        return (int) round(40 + 2.1 * $level ** 1.7);
    }

    /** §7.4.4 -- ~32,000 XP to job 30, about 1,600 crafts. */
    public static function jobXpForLevel(int $level): int
    {
        return (int) round(17 * $level ** 1.5);
    }

    /** §7.4.1 -- every level is one point, so this is just the level. */
    public static function skillPointsFor(int $level): int
    {
        return $level * self::SKILL_POINTS_PER_LEVEL;
    }

    public static function skillXpForLevel(int $level): int
    {
        return (int) round(45 * $level ** 1.4);
    }

    /**
     * §8.3 -- what boots are for, now that reach is not gated.
     *
     * `travelSpeed` used to buy hexes of range; with the range gone it buys the
     * thing the stat is named after. A speed bonus divides the clock rather
     * than subtracting from it, so +8% boots really are 8% faster over any
     * distance, and the §8.1 ceiling caps the saving at 15% like every other
     * stat.
     */
    public static function travelMsPerHex(float $travelSpeedBonus = 0.0): int
    {
        return (int) round(self::TRAVEL_MS_PER_HEX / (1 + max(0.0, $travelSpeedBonus)));
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

    /**
     * Scale a real duration in ms into whatever clock this environment runs.
     *
     * **Durations only. Never XP.** (§7.4.4) A fast clock is a testing tool, and
     * the moment XP goes through here a fast clock becomes a progression cheat
     * and the six-month pacing figure stops meaning anything. GameLoopTest pins
     * this.
     */
    public static function scaled(int $ms): int
    {
        return max(1000, (int) round($ms / self::timeScale()));
    }
}
