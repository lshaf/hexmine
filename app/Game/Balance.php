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
 * to render predictions (mine times, queue durations) before the server answers.
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
     * halfway along an unsigned axis, and the dead center of the world is the
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

        $seed = config('game.map.seed', 0x5EED1A3F);

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

    // ------------------------------------------------------- dead ground §5.2

    /**
     * How much of each ring carries a seam at all.
     *
     * The design intent, in the numbers it was decided in: half the outer rim
     * is workable, and the share climbs the whole way in. It runs the same
     * direction as the two things that already climb inward -- Tier 3 density
     * (§4) and the pack rate (§9.5.1) -- so the middle of the map is richer,
     * more dangerous and more contested by one gradient rather than three.
     *
     * Ground that misses out is DEAD, not depleted: it carries no material,
     * never regrows, and is drawn in the one colour §13.3 held back for it.
     *
     * This is a share of EVERY tile in the ring, so the lakes and the towns
     * count toward the part that is not workable. A test pins each ring to
     * within a point of its share.
     */
    public const MINEABLE_SHARE = [
        'outer' => 0.50,
        'mid' => 0.60,
        'inner' => 0.70,
        'center' => 0.75,
    ];

    /**
     * Where the dead-ground field is cut, per ring.
     *
     * The field is smooth noise in [0,1] (WorldGen::barrenField), so these are
     * quantiles rather than probabilities: a hex is dead when its field value
     * falls below its ring's cut. CALIBRATED, not chosen -- each one is the
     * quantile that lands the ring on MINEABLE_SHARE once the water, the towns
     * and the five dungeon mouths have taken their share of the same ground.
     *
     * Recalibrate with scripts/calibrate_barren.php if a share or the map seed
     * moves. The test is what actually holds the shares honest.
     */
    public const BARREN_THRESHOLD = [
        'outer' => 0.4896,
        'mid' => 0.4141,
        'inner' => 0.3659,
        'center' => 0.2733,
    ];

    /**
     * Hexes per lattice cell of the dead-ground field.
     *
     * Five, so dead ground arrives in REGIONS rather than as speckle. §5.3
     * makes the same argument about biomes: clustered, "not random noise --
     * players need a mentally navigable map", and half a ring of salt-and-
     * pepper barren hexes is exactly the noise that rules out.
     *
     * Large regions are safe here for a reason particular to this map: dead
     * ground is TERRAIN, so §5.6 draws it at any distance through the fog. A
     * waste you can see from four days away is a route to plan around, not a
     * trap to walk into.
     */
    public const BARREN_CELL = 5;

    // ------------------------------------------------------------- mining §7.3

    /**
     * §7.3 -- a hex's HP. What the world rolls, and the only thing it rolls.
     *
     * This used to be a range of SECONDS that a reference rate converted into
     * work, which meant a tile carried its answer rather than its question: the
     * same fact stored once as a duration and once as a pile, with a constant
     * in between them waiting to drift. HP is the fact. How long it takes you
     * is `hp / rate`, and it is nobody's business but the character's.
     *
     * Calibrated once, here, and then left alone: 2,700 is fifteen minutes for
     * somebody holding the common rung (attack 3) with nothing learned yet, and
     * 5,400 is thirty. That is the whole of what the numbers mean and the only
     * reason they are these numbers -- there is a test pinning it.
     */
    public const TILE_HP_MIN = 2700;

    public const TILE_HP_MAX = 5400;

    /**
     * §5.3 -- what a grade of ground costs, as the rung it is named for.
     *
     * A variant is one rung of the equipment ladder (Variants::GRADES), and
     * until now that was decoration: an Ironwood Grove was the same afternoon's
     * work as the plain forest beside it, so the only thing gating the best
     * material on the map was where it spawned.
     *
     * These are the attacks of the gathering tools each grade is named for, and
     * a hex's HP is scaled by its own over the common rung's. So every grade of
     * ground takes ITS rung exactly as long as base ground takes the common
     * one -- fifteen minutes to thirty, all the way up.
     *
     * Gold per hour comes out flat across the four, because the price ladder
     * (2-3g / 4-5g / 7-9g) and this one are the same ladder. That is the
     * intended shape: better ground pays in ACCESS -- it is the only source of
     * the refined stock the upper recipes want -- and never in coin. The walk
     * inward is the price, and the epic grade pays no gold at all.
     *
     * Scaled with integer arithmetic rather than a float multiplier so the PHP
     * and TypeScript generators cannot round apart (scripts/parity.ts).
     */
    public const TILE_HP_GRADE_ATTACK = [
        'common' => 3,
        'uncommon' => 6,
        'rare' => 10,
        'epic' => 14,
    ];

    /**
     * clamp() bounds, and the floor is a GUARD rather than a lever.
     *
     * It used to be fifteen minutes and it used to bind, which made the top of
     * the tool ladder wasted ground: past a certain rung every hex took exactly
     * as long as it had before. Fifteen minutes is where the common rung lands
     * now, not where the game stops.
     *
     * One minute rather than three, because the tool IS the rate: with no flat
     * base underneath it a Mythril Pickaxe works six times faster than a Stone
     * Axe rather than twice, and a three-minute guard bound at the top of the
     * ladder on the easiest hexes.
     */
    public const MINING_FLOOR_SECONDS = 60;

    public const MINING_CEILING_SECONDS = 60 * 60;

    /**
     * §4.0 -- what BARE HANDS take out of a hex per second, before the line
     * skill. Gathering's whole rate, and gathering's alone.
     *
     * Mining never reads this. A seam is worked with the line's tool and has no
     * bare-handed mode, because §8.0 rule 1 refuses the verb outright without
     * one and points at the gather button instead. What a tool does is *be* the
     * rate, not add to one.
     *
     * TWO, and it must stay under MINING_COMMON_ATTACK. It was four while this
     * number was the floor every verb stood on -- shared by hands and tool
     * alike, so it could sit above the common rung without meaning anything.
     * Now that it is gathering's whole rate it competes with the tool ladder
     * directly, and at four it BEAT it: bare hands worked a hex in twelve
     * minutes against a Stone Axe's fifteen, which made §12's step 5 -- buy the
     * axe, work the same hex, see the payoff -- a hex that got slower.
     */
    public const BARE_HAND_ATTACK = 3;

    /**
     * §7.3 -- how many levels of the line buy one more point a second.
     *
     * `floor(level / 10)`, so a character who has learned nothing adds nothing.
     * It was `ceil`, which handed the very first level of a line a free point
     * and printed "+1" on the panel of somebody who had never worked it.
     */
    public const MINING_SKILL_LEVELS_PER_ATTACK = 10;

    /**
     * §8.3 -- the common rung, and the yardstick TILE_HP_MIN and TILE_HP_MAX
     * were set by. WorldGen::tileHp() divides by it: base ground is measured at
     * this rung, so it is the denominator every other grade climbs above.
     *
     * It is above BARE_HAND_ATTACK, and that direction is a rule -- see there.
     */
    public const MINING_COMMON_ATTACK = 3;

    /** Exactly two mining slots per hex, §5.1. */
    public const SLOTS_PER_TILE = 2;

    /** Depleted tiles regrow after ~9h, §5.1. */
    public const REGROW_MS = 9 * self::HOUR;

    /**
     * §5.1 -- how many hauls a hex has in it before it is worked out.
     *
     * A COUNT, not a chance. It used to be a 34% roll at the end of every mine,
     * which made the one fact a prospector most wanted to know -- is this seam
     * worth coming back to -- unknowable in principle. A hex that says "three of
     * eight taken" is a decision; a hex with a hidden third of a coin behind it
     * is a slot machine.
     *
     * Inversely to the haul, and that is the whole shape of it: a rich hex is
     * emptied in six mines and a poor one takes ten, so what a hex is worth over
     * its life comes out roughly level and what differs is how many walks it
     * costs you to collect. The richest ground is not the ground you can sit on.
     *
     * The count is SHARED, like the two mining slots and like a cleared pack
     * (§9.5.1): everybody's mines come off the same seam. That is the anti-farm
     * rule -- you cannot re-roll a hex, and you cannot have one to yourself.
     */
    public const TILE_EXTRACTIONS_MIN = 6;

    public const TILE_EXTRACTIONS_MAX = 10;

    /**
     * §5.1 -- the haul band a hex rolls in, and the yardstick the count above
     * is read against. Both generators draw from it (WorldGen::generateTile),
     * so the band is stated once rather than spelled into each of them.
     */
    public const TILE_YIELD_MIN = 3;

    public const TILE_YIELD_MAX = 8;

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
     * Under 1 on purpose: the band is measured between one column's center and
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

    // ------------------------------------------------------- rich ground §5.7

    /**
     * §5.7 -- a pocket: ground that is briefly worth more than it usually is.
     *
     * A pack's machinery (§9.5.1) on any workable hex: a time bucket hashed
     * with the hex, derivable, costing no storage until somebody walks onto it.
     *
     * On ANY biome, because a pocket is not a line -- it is the hex being good
     * today, so it belongs to whichever line that hex already trains, and every
     * one of the five gets the same chance at one.
     */
    public const POCKET_LIFETIME_MS = 4 * self::HOUR;

    /**
     * About one hex in twenty-five.
     *
     * Sight is one hex (§5.6), so a pocket is met by walking onto it and never
     * by scanning for it: on a twenty-five hex journey you pass about one. Much
     * rarer and nobody would ever see the mechanic; much commoner and a rich
     * hex stops being a reason to stop.
     */
    public const POCKET_CHANCE = 0.04;

    /**
     * Half again on the haul, and it is a GROUND multiplier like the ring's.
     *
     * §7.3 keeps yield and mine time as two different questions: a pocket is
     * how big the haul is, never how fast it comes out. Half again is felt
     * without being a reason to abandon a plan -- the ring premium is already
     * ×1.35 in the mid ring and ×1.9 inside, so this is comfortably under the
     * gradient the map is built on.
     *
     * §2 -- and it is not a faucet, because it cannot be re-rolled or farmed.
     * A hex has two seats and depletes for nine hours after them (§5.1), and a
     * pocket lives four: it pays at most two hauls, to whoever is standing
     * there, and there is no second roll to wait for. Supply is capped by hexes
     * and hours, exactly as §9.5.1 caps packs.
     */
    public const POCKET_YIELD = 1.5;

    /**
     * §5.7 -- and rich ground is a little likelier to give up the grade above
     * what your tool reliably takes (§5.3).
     *
     * Rich means two things and this is the second: more of it, and better odds
     * on the thing you are not equipped for. Applied to the UPWARD tail only --
     * the long shot doubles from about one haul in twelve to one in six -- so
     * it is felt exactly where "the better grade is a long shot" is the sentence
     * on the card, and does nothing on ground your tool already tops out.
     *
     * It cannot reach past what the hex holds, because the tail it multiplies
     * stops at the tile's own grade: §5.3's contested rule is untouched.
     */
    public const POCKET_REACH = 2.0;

    /**
     * §8.4 -- how long a bench holds onto a thing, by what it is making.
     *
     * Crafting used to be instant, which made a capital's bench a vending
     * machine: carry the materials in, walk out with the item. A clock turns it
     * back into a place you have to come back to -- and since a claim now needs
     * you standing at the bench you left it on, the walk is part of the price.
     *
     * Read against §6's processing times: the cheapest craft is longer than the
     * longest processing run, because a run is a step and a craft is the thing
     * itself.
     */
    public const CRAFT_BASE_SECONDS = [
        'common' => 8 * 60,
        'uncommon' => 14 * 60,
        'rare' => 22 * 60,
        'epic' => 34 * 60,
        'legendary' => 50 * 60,
        'unique' => 50 * 60,
    ];

    // ---------------------------------------------------------- map combat §9.5

    /**
     * §9.5.1 -- how long a pack stands on its hex, and therefore how long the
     * pin it puts on a prospector can last.
     *
     * Scaled like every other clock, so a fast test clock shortens the wait as
     * well as the mine. Battle XP is NOT scaled and never will be (§7.4.4).
     */
    public const PACK_LIFETIME_MS = 2 * self::HOUR;

    /**
     * §9.5.1 -- the chance a hex is holding a pack this bucket, by ring.
     *
     * The outer ring is nearly safe on purpose: a new character has to be able
     * to walk to a village without a fight it cannot win. Inward the road stops
     * being a formality, and the barren center is the worst of it -- which is
     * what makes the last step toward a dungeon mouth a decision.
     */
    /**
     * §9.5.5 -- the band the roll swings through, and the clamp on either end.
     *
     * A straight comparison would decide every fight before it was tapped:
     * scout the number and you either always win or never engage, and there is
     * nothing left to choose. The band makes it a KNOWN RISK instead, and the
     * clamp means never certain and never hopeless -- the same instinct as
     * §7.3's floor on a mine.
     */
    /**
     * §9.5.5 -- how many points of margin span hopeless to certain.
     *
     * The knob that decides whether a fight is a decision or a lookup. At 10 it
     * was neither: monster stats are twenty to forty points apart, so every
     * matchup saturated at the 5% or 95% clamp and the whole band collapsed
     * into "you win" and "you don't". At 20 the ladder is legible instead --
     * a rung beats its own tier around 60-80%, is a real risk one tier up
     * around 30-50%, and is properly outmatched two tiers up.
     */
    public const BATTLE_BAND = 20;

    /**
     * §9.5.5 -- the smallest a strike can ever be.
     *
     * Never hopeless and never certain, which is the same instinct as §7.3's
     * floor. A wall you cannot scratch would be a locked hex, and §9.5.3 says
     * fighting is always one of the two ways out.
     */
    public const BATTLE_CHIP = 1;

    /**
     * §9.5.5 -- and the floor scales with what is swinging, not just with the
     * subtraction.
     *
     * Straight subtraction makes armor an on/off switch: one point of defense
     * either side of an attack turns a fight from routine into impossible,
     * which is how every matchup ended up 0% or 100%. A striker always gets
     * this fraction of its attack through, so a heavy hitter still hurts a wall
     * and a light one still cannot -- the difference stays a slope instead of a
     * cliff, and it is what separates a rare kit from an epic one against the
     * same Barrow Knight.
     */
    public const BATTLE_CHIP_FRACTION = 0.10;

    /**
     * §9.5.5 -- how far one strike wanders from its arithmetic.
     *
     * The exchange is otherwise deterministic, and a fight you can compute to
     * the point is a fight with nothing left to find out. Ten per cent is
     * enough that two runs at the same pack are not the same fight, and small
     * enough that the preview stays a promise rather than a guess.
     */
    public const BATTLE_SWING = 0.10;

    /**
     * §9.5.5 -- how long you get, and the bell is a LOSS.
     *
     * Not a technicality: the pools are far bigger than anything a pack is
     * carrying, so a long enough fight is always won by whoever brought more
     * durability, and a wall could be ground down by a kit with no business
     * touching it. Failing to put something down inside forty rounds is being
     * driven off, and §9.5.3's two exits are both still there.
     */
    public const BATTLE_MAX_ROUNDS = 60;

    /**
     * §9.5.9 -- the shortest a battle skill's cooldown may ever be tuned or
     * bought down to.
     *
     * Two, not one. At one a skill fires every round, which is the whole thing
     * a cooldown exists to prevent -- and with three skills armed at once the
     * exchange would stop being an exchange and become a rotation.
     */
    public const BATTLE_SKILL_MIN_COOLDOWN = 2;

    /**
     * §9.5.6 -- the share of a beating that comes off the kit.
     *
     * ONE bill, taken off what the fight actually took out of you. It used to
     * be two streams -- the whole of the damage capped at half the pool, plus a
     * separate blade bill for the rounds spent hitting armor -- which meant the
     * repair bill and the health bar were the same number only by accident.
     * A quarter of what you took is the bill, and nothing else is added to it.
     *
     * Anchored to damage TAKEN, which has a known consequence: a monster that
     * barely touches you barely costs you, however long it took to put down. A
     * seven-round grind against a Thornback runs to three points where it used
     * to run to forty. That is the deliberate trade for a bill a player can do
     * in their head off the bar they just watched drain.
     */
    public const BATTLE_WEAR_RATE = 0.25;

    /**
     * §9.5.6 -- and which half of the kit pays most of it.
     *
     * The bill lands where the fight actually happened. A monster that leans on
     * its attack beats on the worn set, so armor and boots take the greater
     * share; one that leans on its guard is a wall you spent the fight hitting,
     * so the weapon and gloves do. Seventy against thirty rather than all or
     * nothing, because every piece was in the fight and the split says which
     * part of it was the work.
     *
     * This is the surviving half of the old two-stream model: "what hit you is
     * on the armor, what you hit is on the blade" is now a ratio inside one
     * bill rather than a second bill of its own.
     */
    public const BATTLE_WEAR_MAJOR = 0.70;

    /**
     * §9.5.4 -- the slots that are in a fight at all.
     *
     * The five gathering tools are not. §8 rule 2 says only the tool that did
     * the work wears and the others idle, so counting an axe toward the pool
     * would make a full tool belt into armor -- and §8 rule 5 exists precisely
     * to keep the two ladders apart.
     */
    public const COMBAT_SLOTS = ['weapon', 'armor', 'boots', 'gloves'];

    public const BATTLE_ODDS_MIN = 0.05;

    public const BATTLE_ODDS_MAX = 0.95;

    /** §9.5.4 -- a battle job's level is worth this fraction of itself, in both halves. */
    public const BATTLE_JOB_DIVISOR = 3;

    /**
     * §9.5.6 -- wear is the combat system.
     *
     * There is no health, so the cost of a fight lands on the gear and scales
     * with how badly matched you were: a weapon wears on the gap to their
     * defense, one random worn piece wears on the excess of their attack over
     * its own. Matched, both cost `WEAR_BASE` and nothing more.
     */
    public const WEAR_BASE = 2;

    public const WEAR_PER_GAP = 0.4;

    public const WEAR_PER_EXCESS = 0.4;

    /** A loss costs half again. Being driven off is harder on the kit than winning. */
    public const WEAR_LOSS_MULTIPLIER = 1.5;

    /**
     * §9.5.8 -- looted gear comes off a thing that was using it.
     *
     * A wide band on purpose: half-worn is a real find and one-twentieth is
     * scrap with a name. Either way it walks straight into §11.1's repair bill,
     * which is what keeps a free weapon from being a free weapon.
     */
    public const LOOT_DURABILITY_MIN_PERCENT = 5;

    public const LOOT_DURABILITY_MAX_PERCENT = 50;

    /**
     * §9.5.7 -- how far a death looks for a roof.
     *
     * Villages sit on an 8-hex lattice (§6.0) and cities on an 11, so anything
     * short of the barren center finds one well inside this. It is a search
     * bound rather than a rule: past it there is genuinely nowhere to wake.
     */
    public const DEATH_WAKE_RADIUS = 24;

    /**
     * §9.5.7 -- how long a corpse stands with somebody's row on it.
     *
     * Twelve pack buckets, which is the point: the recovery is a journey you
     * plan rather than a sprint you are forced into. Through scaled() like
     * every other clock, so a fast test clock shortens the walk back too.
     */
    public const CARRIER_LIFETIME_MS = 24 * self::HOUR;

    // ------------------------------------------------------------- guilds §10

    /**
     * §10.0 -- what founding a guild costs its founder.
     *
     * The point rather than a price tag. §11.2 makes capital bidding the
     * largest gold sink in the game; this is the second, and unlike bidding it
     * is open to anybody with the patience to save. Gold has no bridge to NFT
     * value (§3.2), which is exactly why it needs sinks this size for the
     * number to keep meaning anything -- and it is the real gate on §8.0's top
     * rung, since the hall is the only bench that reaches legendary.
     */
    public const GUILD_FOUNDING_COST = 20000;

    /** §10.0.3 -- a flag is 32x32 and nothing else may be in the column. */
    public const GUILD_FLAG_SIZE = 32;

    /** Three raw bytes a dot, so the decoded flag is exactly this many bytes. */
    public const GUILD_FLAG_BYTES = self::GUILD_FLAG_SIZE * self::GUILD_FLAG_SIZE * 3;

    /**
     * §7 -- what a prospector may call themselves.
     *
     * Capped at 16 because the name is drawn on a shared map beside other
     * people's, where a long one crowds them out. The column is the same width,
     * so the schema and this agree.
     */
    public const CHARACTER_NAME_MIN = 4;

    public const CHARACTER_NAME_MAX = 16;

    public const GUILD_NAME_MIN = 3;

    public const GUILD_NAME_MAX = 32;

    public const GUILD_CODE_MIN = 2;

    public const GUILD_CODE_MAX = 5;

    public const GUILD_DESCRIPTION_MAX = 500;

    /**
     * §10.5 -- what one facility level costs, in gold.
     *
     * `round(BASE * level ** EXPONENT)`, so the first level costs more than the
     * hall itself did and the fifth costs an order more than that: 25k, 76k,
     * 145k, 230k, 328k. That shape is the point -- founding is what one patient
     * prospector can save for, and a facility is what a roster does together.
     * Gold is the one currency the game may inflate freely (§3.2), so it is the
     * one that can carry a sink this size.
     */
    public const GUILD_FACILITY_BASE_COST = 25000;

    public const GUILD_FACILITY_EXPONENT = 1.6;

    /** §10.5 -- seats a hall holds before a single Hall level is bought. */
    public const GUILD_ROSTER_BASE = 10;

    public const GUILD_ROSTER_PER_LEVEL = 10;

    public const GUILD_HALL_MAX_LEVEL = 5;

    /**
     * §10.5 -- gold a facility level costs, at the level being bought.
     *
     * Rounded to the nearest hundred, because a price with two significant
     * digits reads as a decision and one with six reads as a receipt.
     */
    public static function guildFacilityCost(int $level): int
    {
        $raw = self::GUILD_FACILITY_BASE_COST * ($level ** self::GUILD_FACILITY_EXPONENT);

        return (int) (round($raw / 100) * 100);
    }

    /** §10.5 -- how many members a hall at this level seats. */
    public static function guildRosterCap(int $hallLevel): int
    {
        return self::GUILD_ROSTER_BASE + $hallLevel * self::GUILD_ROSTER_PER_LEVEL;
    }

    /**
     * §9.5.5 -- how long a fight takes.
     *
     * It is a skirmish on a road, not a project: shorter than the shortest
     * bench run (§8.4) and far shorter than a mine, because the pin (§9.5.3)
     * already holds you in place while it runs and a long clock on top would
     * make one pack a lost afternoon.
     *
     * Scaled by tier, so the center's two cost more of the day than the
     * treeline's -- and through scaled() like every clock in the game.
     */
    public const BATTLE_BASE_SECONDS = 3 * self::MINUTE;

    public const BATTLE_SECONDS_PER_TIER = 2 * self::MINUTE;

    /**
     * §9.5.5 -- how long one round of the exchange takes ON SCREEN.
     *
     * The fight is settled the instant you close (§9.5.5), so this is not a
     * cooldown and it is not deciding anything: it is how fast the thing that
     * already happened is drawn.
     *
     * ONE SECOND A ROUND, so the exchange reads at the pace a person counts
     * rather than as a flicker. A rout is over in a couple of seconds; a grind
     * against a wall takes as long as the grind was, which is the whole reason
     * to watch one -- a fight that cost you a legendary should take longer to
     * watch than a fight that cost you nothing.
     *
     * Deliberately NOT through `scaled()`, and it is the one clock in the game
     * that is not. `GAME_TIME_SCALE` compresses the game's hours so a tester
     * does not wait them out; this is not an hour, it is an animation, and a
     * 60x clock would collapse it to nothing.
     */
    public const BATTLE_ROUND_MS = 1000;

    /** A beat at the end so the last blow is read rather than glimpsed. */
    public const BATTLE_TAIL_MS = 450;

    /**
     * §9.5.8 -- what a win teaches the battle job that fought it.
     *
     * Paid per monster tier, so the center's two are worth four times the
     * treeline's. On a WIN only: half XP for losing sounds generous and is a
     * trickle you can farm by dying on purpose (§9.5.3).
     */
    public const JOB_XP_PER_BATTLE_TIER = 25;

    /**
     * §7.1 -- and what it teaches the character, on the same tier ladder.
     *
     * Same gap as the craft bench had, and the same answer: a fight is finished
     * work with a real bill (§9.5.6), so it pays a character level like every
     * other verb that finishes something. On a WIN only, for the reason above.
     */
    public const CHARACTER_XP_PER_BATTLE_TIER = 6;

    /**
     * No single fight may take more than this share of an item's maximum.
     *
     * Not optional now that zero is fatal (§8.2): without it one hopeless fight
     * snaps a legendary outright, and the pre-fight warning would be the only
     * thing between a player and losing a week of work to a mistap.
     */
    public const WEAR_CAP_FRACTION = 0.15;

    /** §9.5.1 -- whether the roads hold anything at all. Off leaves them empty. */
    public static function packsEnabled(): bool
    {
        return (bool) config('game.packs', true);
    }

    /**
     * §9.5.1 -- what share of hexes hold a pack, per two-hour bucket.
     *
     * The gradient is the road inward: it climbs every ring, and there is a
     * test pinning that it climbs monotonically. What each rung is worth is a
     * tuning value; the ORDER is not.
     *
     * The outer ring runs at twice what it used to. At 0.02 a village-to-village
     * walk of twenty-five hexes was stopped about two times in five, which made
     * the pack a thing you heard about rather than the thing §9.4 says it is --
     * the one step where a player learns what attack, defense and durability
     * cost them, before a dungeon charges a crafted charge to teach the same
     * lesson. At 0.04 that same walk is stopped about two times in three, and
     * the outer ring is still by far the safest ground on the map.
     */
    public const PACK_CHANCE = [
        'outer' => 0.04,
        'mid' => 0.10,
        'inner' => 0.18,
        'center' => 0.22,
    ];

    // ---------------------------------------------------------- processing §6

    /** Five open slots per feature, first-come-first-served, §6.1. */
    public const PUBLIC_SLOTS = 5;

    /**
     * §8.4 -- and five at the benches, counted separately.
     *
     * The three craft benches are their own building. A queue of their own is
     * what makes a busy capital busy at the anvil as well as at the saw pit,
     * and keeping the two banks apart is what stops a run of planks closing the
     * forge -- which is what happened while both were counted off one number.
     */
    public const BENCH_SLOTS = 5;

    /**
     * §6.1 + §8.4 -- the most unclaimed work one character may have out at once,
     * counting processing runs and bench crafts together across the whole map.
     *
     * The per-settlement rules say how much you may leave in ONE building; this
     * says how much you may have scattered over all of them. It used to be
     * neither: a processing run was capped at one PER CHARACTER anywhere, which
     * meant a run left at a village four days' walk away closed every saw pit
     * on the map -- while §8.4 was arguing in the same breath that "the real
     * limit on how much you have going at once is still the walking". Two rules
     * about the same thing, disagreeing.
     *
     * Ten, so the walking is the limit right up until the bookkeeping would be.
     * A cap is still needed rather than none at all: §2 assumes thousands of
     * bots, and an unbounded queue of parked work is a wallet running two
     * hundred benches it never has to walk between. Ten is a route a person
     * plans; two hundred is a spreadsheet.
     */
    public const OUTSTANDING_WORK_CAP = 10;

    /**
     * §8.4 -- how many recipes fit on the slate.
     *
     * The same ten as the cap above, and for the same reason rather than by
     * coincidence: both count things a prospector is keeping in mind across a
     * map they have to walk. Ten is a route a person plans.
     *
     * It is deliberately not a soft limit that drops the oldest line. A slate
     * that quietly forgets is worse than one that says it is full -- §7.6 makes
     * the same argument about a bag, where the refusal is the decision.
     */
    public const SLATE_CAP = 10;

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
     * Thirty is deliberately roomy against a catalog of twenty-nine materials
     * and five drafts: the straps are not meant to be the thing that bites on
     * an ordinary mine. They are the ceiling on carrying *one of everything* --
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
     * §7.1 -- and what it teaches the CHARACTER, on the same rank ladder.
     *
     * A craft used to pay its bench's job XP and nothing else, alone among the
     * verbs: a mine, a gather, a hunt and a processing run all pay a character
     * level as well. There was no rule behind that, only the order things were
     * built in -- §7.5's road is the one thing that deliberately pays no
     * character XP, and the reason it gives is that idle time must not be a
     * faucet (§2). A craft is not idle: it costs materials, a bench slot, a
     * clock and the walk back for it.
     *
     * Sized under mining per minute, on purpose. A mine is the grind §7.4.4's
     * curve was fitted against; a craft is the thing you were grinding FOR, and
     * a rung that levelled you faster than the ground it was made from would
     * invert that.
     */
    public const CHARACTER_XP_PER_RARITY_RANK = 8;

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
     * These can, though, and each one thins a §11 sink rather than a power
     * curve -- cheaper crafts and bigger batches drain the materials sink,
     * tougher gear and a spared tool drain the repair sink, and a seam that
     * survives its mine drains the depletion clock. Uncapped, a maxed
     * specialist would quietly switch off the loss the whole economy is
     * balanced around. The bag and sight caps below are the same argument in
     * counts rather than percentages.
     */
    public const SKILL_OPTION_CHANCE_CAP = 0.35;

    public const SKILL_DURABILITY_CAP = 0.25;

    public const SKILL_COST_REDUCTION_CAP = 0.15;

    public const SKILL_BATCH_CAP = 2;

    public const SKILL_TOOL_WEAR_CAP = 0.25;

    /**
     * §7.3 + §7.4.3 -- whole points of MINING attack off a gathering tree.
     *
     * There is no mine timer to shave any more: a hex is HP and a tool is a
     * rate, so the only honest thing a gathering tree can sell is a faster
     * rate. It used to sell `tripReduction`, a percentage on that rate sharing
     * one clamp with gear, options and potions -- so a prospector in a decent
     * coat had already spent the ceiling and the ten nodes they bought did
     * nothing at all. That stat is gone from the game entirely now, for the
     * same reason it left the trees: a percentage on a number the tool already
     * sets is the tool's own ladder said twice.
     *
     * Five, and the number is the ladder rather than a feeling: the widest
     * single rung of §8.0's tool ladder is four (rare 10 to epic 14), and the
     * line skill itself is worth five at level fifty. So a maxed tree is worth
     * about a rung of gear and never a tier of it, which is the same argument
     * SKILL_PAIR_CAP makes on the combat side.
     *
     * A COUNT, so it has nothing to do with STAT_CEILING and cannot be clamped
     * away by a good coat. Being flat is what makes it felt at the bottom of
     * the ladder, where the percentage never was.
     */
    public const SKILL_BITE_CAP = 5;

    /**
     * §5.3 + §7.4.3 -- how often a gathering tree takes the better thing off
     * ground that carries it.
     *
     * A COUNT of grades, rolled: on a hit the mine reaches one grade past what
     * the tool can reliably take, and never past what the hex actually holds. So
     * it is capability rather than power -- nothing here feeds the stat ceiling,
     * which has no room left in it anyway.
     *
     * Twelve per cent, and low on purpose. §8.0 rule 4 makes the tool the ladder
     * and the skill point cap the specialisation; one mine in eight coming up a
     * grade better is knowing your ground, while a guaranteed grade would be a
     * free rung of tool and would make the ladder optional.
     */
    public const SKILL_SEAM_GRADE_CAP = 0.12;

    public const SKILL_PRESENCE_CAP = 0.20;

    public const SKILL_RUN_SLOT_CAP = 2;

    public const SKILL_OPTION_TIER_CAP = 0.25;

    public const SKILL_BREW_EXTRA_CAP = 0.35;

    public const SKILL_STACK_CAP = 10;

    public const SKILL_WEAPON_WEAR_CAP = 0.15;

    public const SKILL_GOLD_FIND_CAP = 0.25;

    public const SKILL_LOOT_OPTION_CAP = 0.25;

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
     * §7.4.3 -- how much of the SOLID pair one battle tree may grant.
     *
     * Solid numbers, because that is what attack and defense are (§9.5.4). A
     * battle node used to move `power` or `defense` by a percent, which was the
     * least legible thing in the game: "+1% power" moved a common sword's 5
     * attack to 5, and a whole tree of them was worth about three points at the
     * top of the ladder and nothing at all at the bottom.
     *
     * Twelve against a legendary kit's ~41 attack: a third of a hundred skill
     * points, behind job level 28, is worth roughly a rung of gear. It has no
     * business being worth more, because gear is the ladder §8 is built on and
     * the tree is meant to be a different road rather than a longer one.
     */
    public const SKILL_PAIR_CAP = 12;

    /**
     * §7.4.3 -- how much of a fight's bill a battle tree may spare the kit.
     *
     * §9.5.6 makes durability the whole combat system, so this is the one
     * effect a battle tree can have that is felt every time and understood
     * immediately: a fighter who knows the work takes less off their gear.
     *
     * Capped hard, and low, because that bill is the largest sink in the game
     * (§11.1) -- an uncapped version would switch off the loss the economy is
     * balanced around.
     */
    public const SKILL_BATTLE_WEAR_CAP = 0.15;

    /**
     * §9.5.9 + §7.4.3 -- what a battle tree may do to the three skills its
     * family carries.
     *
     * Three caps rather than one because they are three different things being
     * bought, and each of them breaks something different if it runs away.
     *
     * `skillPower` is a quarter MORE OF THE EXTRA, never a quarter of the whole
     * blow: a maxed tree moves a Lunge from x2.2 to x2.5, which is worth about
     * a rung of gear on the rounds it lands. That is the same bargain
     * SKILL_PAIR_CAP strikes, and §8.1 rule 4 is why -- the ladder is twelve
     * points wide and a tree must be a different road up it rather than a
     * longer one.
     *
     * `skillCooldown` is whole rounds, and two is most of a rotation: the
     * shortest cooldown in the set is four, so two rounds off it is half again
     * as many firings over a long fight. BATTLE_SKILL_MIN_COOLDOWN is what
     * stops it reaching every-round.
     *
     * `skillStun` is ONE round and will not be more. A stun is the only effect
     * that takes a turn away outright, so it compounds with itself: two extra
     * rounds on a Shield Bash is a monster that never gets to answer.
     */
    public const SKILL_BATTLE_POWER_CAP = 0.25;

    public const SKILL_BATTLE_COOLDOWN_CAP = 2;

    public const SKILL_BATTLE_STUN_CAP = 1;

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
     * §8.0.1 -- the MOST bonus lines a rung may roll. Not how many it will.
     *
     * A crafted piece rolls somewhere between nothing and this, so two of the
     * same recipe are never the same object and a lucky uncommon can carry what
     * an unlucky rare did not. An option is a bonus rather than part of the
     * item, which is exactly what makes rolling none of them acceptable.
     */
    public const OPTION_ROLLS = [
        'common' => 0,
        'uncommon' => 1,
        'rare' => 1,
        'epic' => 2,
        'legendary' => 3,
        'unique' => 3,
    ];

    /**
     * §8.0.1 -- what a line off each tier of the pool is worth.
     *
     * Every line rolls its OWN tier, drawn from the tiers at or below the
     * item's rarity, so a legendary can come out carrying a common-grade line
     * and often does. That is what makes a good roll a good roll: the ceiling
     * is higher up the ladder, not the floor.
     *
     * Small on purpose all the way up. Options are variety, not a second power
     * ladder, and they feed the same aggregate and the same STAT_CEILING as
     * everything else (§8.1 rule 1).
     */
    public const OPTION_VALUE = [
        'common' => [0.01, 0.02],
        'uncommon' => [0.01, 0.03],
        'rare' => [0.02, 0.04],
        'epic' => [0.03, 0.05],
        'legendary' => [0.04, 0.06],
    ];

    /**
     * §8.0.1 -- a line pointed at ONE gathering line is worth this much more
     * than a flat one, because it is worth nothing on the other four.
     *
     * Without the gap a scoped roll would be strictly the worse outcome and the
     * whole pool would read as a bad-luck table. With it, "+2% yield
     * everywhere" against "+4% mining yield" is a real choice for a prospector
     * who knows which line they actually work. Still clamped by STAT_CEILING:
     * narrower buys a steeper climb to the same ceiling, never a higher one.
     */
    public const OPTION_SCOPED_MULTIPLIER = 2.0;

    /**
     * §8.0.1 -- what a FLAT line off each tier is worth.
     *
     * Solid numbers, because that is what `attack` and `defense` are (§9.5.4).
     * Sized against the pairs on the gear itself -- a common weapon is 7-12
     * attack and a legendary 22-34 -- so a rolled line is a real find at the
     * bottom of the ladder and a nice extra at the top, which is the same shape
     * the percentage bands have.
     */
    public const OPTION_FLAT_VALUE = [
        'common' => [1, 2],
        'uncommon' => [1, 3],
        'rare' => [2, 4],
        'epic' => [3, 6],
        'legendary' => [4, 8],
    ];

    // --------------------------------------------------------- consumables §8.5

    /**
     * How many of one potion a character may hold. Stops hoarding a stat.
     *
     * There is no buff duration to tune any more: a draft arms the action it
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

    /**
     * §3.2 -- what the shop shelf charges: two ways of valuing one object, and
     * the price is the higher of them.
     *
     * **What it costs to make** — the parts at the NPC's own poor rate, marked
     * up, plus the bench time it takes (§8.4). **What it is worth** — gold per
     * point of durability, set per station.
     *
     * Neither alone is enough, and both failures are real ones this catalog has
     * already had. Worth alone priced the village combat rung at 22g against
     * 26-35g of materials, so the shop undercut its own recipe and crafting one
     * was a straight loss — a shelf that beats the bench inverts §8's whole
     * ladder. Make-cost alone would price a 40-durability axe and a
     * 60-durability cloak the same, because neither of them has a recipe at all.
     *
     * Materials are valued at what the NPC pays for them, which is deliberately
     * poor (§3.2) — so that side is conservative by construction: it charges
     * half again over the worst price the parts could fetch.
     *
     * Declared here and computed in scripts/gen_battlegear.py, which cannot read
     * PHP. A test asserts the catalog matches these numbers, so the two cannot
     * drift apart in silence.
     */
    public const STATION_GOLD_PER_DURABILITY = ['village' => 0.43, 'city' => 1.40];

    public const SHOP_MATERIAL_MARKUP = 1.5;

    /**
     * §8.4 -- a bench takes time, and time is worth something.
     *
     * One gold a minute, flat, against the rarity's own craft clock
     * (CRAFT_BASE_SECONDS above). It is the smallest term in the price and that
     * is correct: it is not there to set the number, it is there to be the
     * difference between two pieces made of the same parts at different
     * benches, which material cost alone cannot express.
     */
    public const GOLD_PER_CRAFT_MINUTE = 1.0;

    /**
     * §8.2 -- what the trader gives back for a piece of shop gear, before wear.
     *
     * Half, and then scaled by what is left of the item, so a worn axe fetches
     * a worn axe's price. Two things have to stay true of this number and both
     * are §3.2's, not §8's:
     *
     * Buy-and-sell must LOSE money. At anything near 1.0 a player could stand at
     * a trader turning gold into gold, which is a faucet with no work in it.
     * Half is far enough under that the round trip is plainly a mistake.
     *
     * And it must stay under the repair line. Selling a battered tool and buying
     * a fresh one has to cost more than repairing the one you have, or the
     * repair sink (§11.1) quietly switches itself off.
     */
    public const RESALE_RATE = 0.5;

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
    public const TRAVEL_MS_PER_HEX = 5 * self::MINUTE;

    // ------------------------------------------------------------------- sight

    /**
     * §5.6 -- how far a prospector can actually see. One hex, and that is the
     * whole of it.
     *
     * Sight used to be reach, which made it wide enough that the live-state
     * query behind it was a scan of a couple of hundred hexes on every move.
     * One is a disc of seven -- the hex underfoot and its six neighbors. The
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
    public const SIGHT_TRAVELING = 0;

    /**
     * §5.4 + §12 -- how far a fresh spawn may be from the village whose
     * woodcutting line its opening arc needs.
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
     * mines a day unequipped, 48 on the old 30-minute floor, plus the processing
     * those hauls feed). ~197,000 XP total against that rate puts level 100 at
     * roughly 182 days of unbroken play, which is the six-month target.
     *
     * OPEN: that income was measured when §7.3 clamped a mine at 30 minutes.
     * The clamp is a guard at 3 minutes now and a geared prospector works a hex
     * in 5-10, so the late-career mine rate is several times what this was
     * sized against. The curve has not been re-fitted -- doing so is a
     * deliberate pacing decision, not a side effect of the mining change.
     *
     * The flat 40 is a floor so the first level costs about three mines
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
