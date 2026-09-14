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
    public const SECOND = 1_000;

    public const MINUTE = 60 * self::SECOND;

    public const HOUR = 60 * self::MINUTE;

    // ---------------------------------------------------------------- map §5

    /**
     * §5.1 -- the map is measured from the middle out, and (0,0) is the middle.
     *
     * One radius, because the map is square and always has been. A radius of
     * 5000 means every column and every row from -5000 to 5000 inclusive, so
     * the grid is 10001 a side -- 5000 of ground in each direction from the
     * origin, which is the quarter the world is measured in.
     *
     * Signed coordinates are what make the ring maths honest: a ring is a
     * distance from the origin rather than from an arbitrary point halfway
     * along an unsigned axis, and the dead center of the world is the one
     * coordinate you never have to look up.
     *
     * Read from config/game.php, so the size and the seed of the world are
     * deployment settings rather than a code edit. Methods and not constants
     * for exactly that reason -- a const cannot ask config() anything. What the
     * config defaults TO is a const, self::SHIP_MAP_RADIUS, so the shipping map
     * has a name the tests can hold something up against.
     */
    public static function mapRadius(): int
    {
        return self::$mapRadius ??= max(1, (int) config('game.map.radius', self::SHIP_MAP_RADIUS));
    }

    /**
     * The map the game actually ships on, and config/game.php's default.
     *
     * Written here rather than only in the config because two things have to be
     * able to name it: the deployment, and the handful of tests that check a
     * promise the design makes about the SHIPPING world rather than about
     * generation in the abstract -- the per-ring share of workable ground is
     * the one that matters (§5.2), since BARREN_THRESHOLD is calibrated against
     * this map and no other.
     */
    public const SHIP_MAP_RADIUS = 5000;

    /**
     * The radius the frozen world fixture and the test suite both run at.
     *
     * Generation is scale-RELATIVE: every ring boundary is a fraction of the
     * map radius and every lattice is an absolute hex count, so a small map
     * exercises the same code paths as the shipping one. What it does not
     * exercise is the clock -- a stride-2 sweep of the real map is 25 million
     * tiles, which is an hour and a half of a test that used to take seconds.
     *
     * So the suite runs here and the fixture is frozen here, and both read this
     * one number: tests/TestCase.php installs it, and the fixture command
     * forces it, so a fixture regenerated from a developer's own .env cannot
     * quietly describe a different world from the one the tests check it
     * against.
     */
    public const FIXTURE_MAP_RADIUS = 200;

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

    /**
     * Biome lattice, §5.3. Cell size in tiles, and cells per coherent region.
     *
     * It is ALSO the settlement lattice: WorldGen::LATTICE reads this for every
     * tier's cell, so one biome cell holds exactly one settlement site. That is
     * a structural fact rather than two numbers that happen to agree -- §6
     * wants a settlement per country, and a country is what this cell draws.
     */
    public const BIOME_CELL = 50;

    public const BIOME_REGION_CELLS = 5;

    /**
     * §6 -- what share of countries have anybody living in them.
     *
     * The settlement lattice is the biome lattice (BIOME_CELL), so a country
     * carries at most one settlement; this is whether it carries any. One share
     * for all three tiers, because which tier a site turns out to be is decided
     * by the ring it lands in and not by a lattice of its own.
     *
     * A HALF rather than all of them. With every cell filled, "is there a bench
     * in this country" had one answer everywhere and stopped being a question
     * worth asking -- and a map where the answer is always yes is a map with
     * nothing to find out. At a half, a country with a bench and a country
     * without are both ordinary, and which one you are standing in is a fact
     * about the place.
     *
     * It sets the density of the whole settled world, so it is what to move if
     * the map ever feels crowded or empty. The gap floors (§6.0) do not: with
     * one town to a country the count is the cell's and the share's.
     */
    public const SETTLED_COUNTRY_SHARE = 0.5;

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
     *
     * These are calibrated against the SHIPPING map, which is what the shares
     * are a promise about. The suite runs on a small one
     * (Balance::FIXTURE_MAP_RADIUS) and checks the same numbers there, which
     * works because the field is stationary noise and the ring boundaries are
     * fractions of the radius -- what the two maps disagree about is only how
     * much ground the water and the towns have taken, and that is under a point.
     */
    public const BARREN_THRESHOLD = [
        'outer' => 0.4940,
        'mid' => 0.4284,
        'inner' => 0.3656,
        'center' => 0.3312,
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
     * waste you can see from the far side of the map is a route to plan around,
     * not a trap to walk into.
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
     * Calibrated once, here, and then left alone: 1,800 is TEN minutes for
     * somebody holding the common rung (attack 3) with nothing learned yet, and
     * 3,600 is twenty. That is the whole of what the numbers mean and the only
     * reason they are these numbers -- there is a test pinning it.
     *
     * It was 2,700-5,400, which was fifteen to thirty. The band kept its shape
     * (the top is still twice the bottom) and every grade of ground still takes
     * its own rung exactly as long as base ground takes the common one -- what
     * moved is how long that is.
     */
    /**
     * Every solid number in the game is quoted at this scale.
     *
     * Attack, defense, hit points, durability, a hex's own pile of work, and
     * every rolled line that adds to one of them. Not gold, not XP, and not
     * anything already expressed as a percentage.
     *
     * **It buys granularity and nothing else.** The arithmetic is identical --
     * a hex takes as long, a fight goes the same way, a kit lasts as many
     * mines -- because every term in every ratio moved together. What changes
     * is that a rolled line now has somewhere to land: `+1 to 2 attack` was two
     * possible outcomes and read as a rounding error, where `+100 to 200` is a
     * hundred and one of them and reads as luck. §8.0.1 asks luck to be
     * legible, and two values cannot be.
     *
     * The whole reason it is written down rather than baked in is that a
     * number left behind at the old scale is invisible: it does not fail, it
     * quietly stops mattering. BATTLE_BAND was one -- a margin denominator,
     * which at 20 against margins a hundred times larger would have pinned
     * every fight to the odds clamp.
     */
    public const SOLID_SCALE = 100;

    public const TILE_HP_MIN = 180000;

    public const TILE_HP_MAX = 360000;

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
     * one -- ten minutes to twenty, all the way up.
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
        'common' => 300,
        'uncommon' => 600,
        'rare' => 1000,
        'epic' => 1400,
    ];

    /**
     * clamp() bounds, and the floor is a GUARD rather than a lever.
     *
     * It used to be fifteen minutes and it used to bind, which made the top of
     * the tool ladder wasted ground: past a certain rung every hex took exactly
     * as long as it had before. Ten minutes is where the common rung lands now,
     * not where the game stops.
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
     * directly, and at four it BEAT it: bare hands worked a hex in seven and a
     * half minutes against a Stone Axe's ten, which made §12's step 5 -- buy
     * the axe, work the same hex, see the payoff -- a hex that got slower.
     */
    public const BARE_HAND_ATTACK = 300;

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
    public const MINING_COMMON_ATTACK = 300;

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
    public const BATTLE_BAND = 2000;

    /**
     * §9.5.5 -- the smallest a strike can ever be. ONE, the smallest thing the
     * model can express.
     *
     * Never hopeless and never certain, which is the same instinct as §7.3's
     * floor. A wall you cannot scratch would be a locked hex, and §9.5.3 says
     * fighting is always one of the two ways out. That is the whole job, and
     * one unit does it.
     *
     * It was 100 -- one whole point at the pre-SOLID_SCALE scale, multiplied up
     * with everything else and left there. That was a number doing a second job
     * nobody asked it to do. A hundredth of an attack only overtakes a flat
     * 100 at 10,000 units -- a hundred whole points -- and the strongest kit in
     * the game carries about 41. So `max(chip, 1% of attack)` picked the chip
     * for EVERY attack in the game and BATTLE_CHIP_FRACTION below never once
     * governed a blow. The slope it describes -- a heavy hitter still hurts a
     * wall and a light one still cannot -- was documented, tested around, and
     * inert.
     *
     * At one unit the floor is what it says it is: the guard against a locked
     * hex, and nothing else. The fraction decides what gets through a wall,
     * which is what §9.5.5 always said it did.
     */
    public const BATTLE_CHIP = 1;

    /**
     * §9.5.5 -- and the floor scales with what is swinging, not just with the
     * subtraction.
     *
     * Straight subtraction makes armor an on/off switch: one point of defense
     * either side of an attack turns a fight from routine into impossible,
     * which is how every matchup ended up 0% or 100%. A striker always gets
     * this fraction of its attack through, so a wall is never a locked door --
     * the difference stays a slope instead of a cliff. It was a tenth, which
     * was most of a hit: 2,860 of guard against a 2,100 attack still took 210 a
     * round, so building defense past the crossover bought almost nothing. A
     * hundredth keeps the slope and lets a guard that clears the attack be felt
     * as one; BATTLE_CHIP above is the floor under it.
     */
    public const BATTLE_CHIP_FRACTION = 0.01;

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
     * SIX COUNTRIES. Half of them are settled (SETTLED_COUNTRY_SHARE), so the
     * nearest roof is a couple of countries off on average and occasionally
     * several -- and this has to cover the tail rather than the average, because
     * the failure is not a long walk, it is `wokeAt` coming back null and
     * "nowhere to wake up" is not one of the outcomes §9.5.7 describes.
     *
     * It was 24 flat, from when villages sat eight hexes apart. At three
     * countries it was already leaving about one hex in four hundred with no
     * roof at all; at six, a sweep of the shipping map finds none.
     *
     * It is a search bound rather than a rule: past it there is genuinely
     * nowhere to wake, and the walk back is the first bill either way.
     */
    public const DEATH_WAKE_RADIUS = self::BIOME_CELL * 6;

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

    // --------------------------------------------------------- guild land §10.6

    /**
     * §10.6 -- what a hex costs a guild, out of the treasury and never a purse.
     *
     * Five times a founding (§10.0), and the difference between the two is the
     * whole point: founding is what one patient prospector saves for, and land
     * is what a roster buys together. §3.2 makes gold the currency that may be
     * inflated precisely because it bridges to nothing external, which is what
     * lets it carry a sink this size.
     */
    public const GUILD_LAND_COST = 100000;

    /**
     * §10.6 -- and both facilities on it climb twenty levels.
     *
     * Twenty rather than §10.5's five because this is the long project a guild
     * has instead of a personal ladder: a maxed land runs to about 7.6 million
     * across the two, which is a few times what a Hall and a Bench come to and
     * still well under §10.4's capital bidding.
     */
    public const GUILD_LAND_MAX_LEVEL = 20;

    public const GUILD_LAND_LEVEL_BASE = 5000;

    public const GUILD_LAND_LEVEL_EXPONENT = 1.5;

    /**
     * §10.6 -- what the next level of a facility costs, at the level being
     * bought. Rounded to the nearest hundred, like §10.5's own curve, because a
     * figure a roster is saving toward should be a figure they can hold.
     */
    public static function guildLandLevelCost(int $level): int
    {
        if ($level < 1 || $level > self::GUILD_LAND_MAX_LEVEL) {
            return 0;
        }

        $raw = self::GUILD_LAND_LEVEL_BASE * ($level ** self::GUILD_LAND_LEVEL_EXPONENT);

        return (int) (round($raw / 100) * 100);
    }

    /**
     * §10.6 -- how many of the five lines a processing level runs.
     *
     * **Nothing at all until it is levelled once**, which is the rule that makes
     * a claim the beginning of the work rather than the end of it: a hex bought
     * and left alone is a hex with a flag on it. Then a line a level for five
     * levels, so a guild reaches what a capital offers (four, §6) at level 4 and
     * passes it at 5 -- and everything above that is spent on the clock rather
     * than on the count, because five is all there is.
     */
    public static function guildLandLines(int $level): int
    {
        return max(0, min(5, $level));
    }

    /**
     * §10.6 -- and what a craft level reaches, up to §8.0's own guild cap.
     *
     * The same shape: nothing until it is levelled, then a rung at a time with
     * real ground between them. Epic at fifteen is the last rung in the game a
     * player can make (§8.0 -- legendary and unique are dungeon drops), so it is
     * deliberately most of the way up the ladder.
     */
    public const GUILD_LAND_CRAFT_RUNG = [
        1 => 'common',
        5 => 'uncommon',
        10 => 'rare',
        15 => 'epic',
    ];

    public static function guildLandCraftCap(int $level): ?string
    {
        $reach = null;
        foreach (self::GUILD_LAND_CRAFT_RUNG as $at => $rarity) {
            if ($level >= $at) {
                $reach = $rarity;
            }
        }

        return $reach;
    }

    /**
     * §10.6 -- five glyphs, so how far a guild has got is legible off the map.
     *
     * The overall level is the two facilities added together, and it steps the
     * drawing every eighth of the way up. §13.2 tells settlement tiers apart by
     * shape category rather than by size for exactly this reason: at a 58x34 hex
     * there is usually nothing beside it to compare against.
     */
    public const GUILD_LAND_GLYPH_TIERS = 5;

    public static function guildLandGlyphTier(int $processing, int $craft): int
    {
        $span = self::GUILD_LAND_MAX_LEVEL * 2;
        $share = max(0, min($span, $processing + $craft)) / $span;

        return (int) min(self::GUILD_LAND_GLYPH_TIERS, 1 + floor($share * self::GUILD_LAND_GLYPH_TIERS));
    }

    /**
     * §10.6 -- what a member saves for working their own guild's ground.
     *
     * The one thing membership is worth at a bench, and it is deliberately a
     * discount on the FEE rather than on the materials: §6 makes the fee the
     * steady gold sink everybody who makes anything pays, and §3.2 keeps
     * materials out of gold's reach entirely.
     */
    public const GUILD_LAND_MEMBER_DISCOUNT = 0.25;

    /**
     * §10.6 -- and half of what is actually paid goes back into the treasury.
     *
     * Half rather than all, because a guild taking the whole fee would make its
     * own land free to its own members by the back door -- pay the fee, watch it
     * come home. Half is a real cut of a real sink, and it is the reason a busy
     * guild's land funds its own next level.
     *
     * It is taken from what was PAID, so a member's discount thins the guild's
     * cut as well as their own bill. That is the honest order: you cannot take
     * half of money nobody handed over.
     */
    public const GUILD_LAND_FEE_SHARE = 0.5;

    /**
     * §10.6 -- what a processing level past the fifth buys: the clock.
     *
     * The count of lines stops at five because five is all there is, so
     * everything above it goes here. A maxed land runs at GUILD_LAND_SPEED_FLOOR
     * against a capital's 0.55 -- meaningfully faster than the best thing the
     * map offers, which is what a hundred thousand gold and twenty levels
     * should feel like, and not so much faster that a guild-less player is
     * playing a different game.
     */
    public const GUILD_LAND_SPEED_PER_LEVEL = 0.008;

    public const GUILD_LAND_SPEED_FLOOR = 0.40;

    /**
     * §10.6 -- how long a level takes to BUILD, at the level being built.
     *
     * A level used to land the instant it was paid for, which made the most
     * expensive thing a guild can do the only thing in the game with no clock
     * on it. A saw pit takes twelve minutes (§6) and the cheapest craft eight
     * (§8.4); raising a hall on a waste cannot take none.
     *
     * Linear in the level rather than following the cost curve, and that is
     * deliberate: the GOLD is the gate (7.6 million across the two), and a
     * second exponential on top would make the last few levels a wall rather
     * than a wait. Half an hour for the first, ten hours for the twentieth,
     * about four and a half days to build one ladder out.
     *
     * Through `scaled()` like every other clock, so a fast development clock
     * shortens it -- §7.4.4 exempts XP alone.
     */
    public const GUILD_BUILD_BASE_MS = 30 * self::MINUTE;

    public static function guildLandBuildMs(int $level): int
    {
        return self::scaled(self::GUILD_BUILD_BASE_MS * max(1, $level));
    }

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

    /**
     * §5.5 -- how much of the hunting countries has an animal standing on it.
     *
     * A share rather than all of it. Every workable forest and grassland hex
     * carrying one made the hunt a property of the ground -- walk onto forest,
     * hunt -- which is the plains biome again under another name. A chance is
     * what makes finding one a thing you do rather than a thing that is true.
     *
     * Higher than a pack's, and it has to be: a pack is a hazard the map is
     * better for being sparse with, and this is a whole gathering line's
     * faucet. About a third means a short walk across the right country turns
     * one up, and the country is still mostly ground you can mine.
     *
     * The same for both, and flat across the rings. What a ring changes is the
     * animal's GRADE (§5.3's own weights), not whether there is one -- the
     * ladder is the reward for walking inward, and a rim with no game on it
     * would make the line unplayable exactly where a new prospector starts.
     */
    public const HUNT_CHANCE = 0.35;

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
     * meant a run left at a village half a map away closed every saw pit
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
     * §7.6 -- how many straps the bag has. The one limit on carrying.
     *
     * A strap is a *place*, and what goes on it is one stack of one kind: fifty
     * of a material, a hundred of a draft, one piece of gear. Sixty wood is
     * therefore two straps, not one -- which is the whole difference between
     * this and the pair of limits it replaced. A weight ceiling counted every
     * unit against one number and had nothing to say about *shape*; straps that
     * hold stacks say both at once, in one drawing, and there is nothing left
     * to subtract.
     *
     * Flat, and level does not move it (§7.1). The only thing that widens it is
     * the road (§7.5), which is the one reward that cannot be bought: fifty to
     * eighty, across the Explorer's thirteen pack skills.
     */
    public const BAG_SLOTS = 50;

    /**
     * §7.6 -- how deep one strap goes, per kind of thing.
     *
     * Three numbers rather than one, because the three things in a bag are not
     * the same kind of thing. A material is bulk and stacks deep; a draft is
     * small and stacks deeper; a piece of gear is an *object* with its own
     * durability and its own rolled lines, so two axes are two objects and can
     * never be one strap.
     *
     * A stack that outgrows its strap takes another one. Nothing is refused for
     * being *big* -- only for having nowhere to go.
     */
    public const BAG_STACK_MATERIAL = 50;

    public const BAG_STACK_POTION = 100;

    public const BAG_STACK_GEAR = 1;

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
     * §8.2 -- what a mend teaches, as a share of what MAKING the thing teaches.
     *
     * A mend is not a make. The bench is the same bench and the job is the same
     * job, so a repair is real craft work and §7.1's rule applies -- every verb
     * that finishes work pays -- but putting an edge back on a blade is not the
     * afternoon that forged it.
     *
     * Well under one, and it is a share rather than a flat figure so that both
     * halves of "depends on what you repaired" fall out of one number: the
     * RUNG, through the same rarity rank a craft is paid on, and HOW BADLY it
     * needed it, through the fraction of the bar restored. A barely-scratched
     * common teaches almost nothing; a legendary brought back from the edge
     * teaches most of a craft.
     *
     * It cannot be farmed, and the reason is the sink rather than a cooldown:
     * durability only comes off through work that already pays more XP than the
     * mend will, and the parts a mend costs scale with the same `missing` this
     * does -- so XP per material spent is flat however it is split up.
     */
    public const REPAIR_XP_SHARE = 0.4;

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
    public const SKILL_BITE_CAP = 500;

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
     * §7.6 -- what the Explorer tree (§7.5) may add to the bag: 50 -> 80 straps,
     * arrived at two and four at a time across thirteen of the road's fifteen
     * skills, from job level 2 to 30.
     *
     * One number rather than two, because there is one limit rather than two.
     * The tree used to hand out room *and* straps, which was a fork in a
     * granted tree where nothing is chosen -- both columns were taken, always,
     * in the order the levels came.
     *
     * Bounded for the same reason every other skill cap is: the bag is the
     * pressure that turns hauls into decisions, and a tree that could switch it
     * off would switch off the selling, processing and dumping it drives (§11.1).
     * A count rather than a percentage, so like `sight` it has nothing to do
     * with the stat ceiling -- which matters more here than anywhere, because
     * the Explorer's rungs are granted rather than bought (§7.5) and capability
     * is the only thing a free tree may ever hand out.
     */
    public const SKILL_BAG_SLOTS_CAP = 30;

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
    public const SKILL_PAIR_CAP = 1200;

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
     * §7.1/§8.0 -- the character level each WORN rung may be put on at.
     *
     * **Level unlocks access, not power**, which is what §7.1 has always said
     * it does -- this is the same rule pointed at the wardrobe. A rung is not
     * made weaker by being gated; it simply cannot be reached around.
     *
     * What it actually closes is a §3.3 hole. An epic is the first rung that
     * may be bought on the marketplace and withdrawn, and until now a wallet a
     * day old could buy one and wear it. Now it cannot: the gear is bought, the
     * levelling is not, and §2's sybil arithmetic gets worse for every wallet a
     * farm has to walk to level 38 rather than fund.
     *
     * It does not touch §8.1 rule 4 -- every rarity below unique is still
     * reachable by crafting without spending. It is later, not denied, and the
     * binding constraint on a rung was always the materials and the bench.
     *
     * **Armor, boots and gloves only.** A coat answers to no line and to no
     * weapon family, so there is no job standing behind it and the character's
     * own level is the honest gate. The five tools and the weapon do have one,
     * and they read EQUIP_JOB_LEVEL below instead.
     *
     * Measured against §7.4.4's curve at a career's real income, the gates fall
     * at about half a day, three days, two weeks, seven weeks and three and a
     * half months. Common is level 1 because §12's opening arc is worked in it.
     */
    public const EQUIP_LEVEL = [
        'common' => 1,
        'uncommon' => 8,
        'rare' => 20,
        'epic' => 38,
        'legendary' => 60,
        'unique' => 80,
    ];

    /**
     * §7.1/§8.0 -- the JOB level each rung of a tool or a weapon wants.
     *
     * The piece that does the work is gated on the job that does it: an axe on
     * Woodcutting, a sword on Swordhand (§9.5.4 makes the family in the slot
     * your class). That is the same sentence §8 rule 1 and §8 rule 5 already
     * make about where a piece pays out, said about where it may be carried --
     * a pickaxe is worth nothing in a fight, and a Swordhand's twenty levels
     * are worth nothing toward one.
     *
     * It is a **better** gate than the character's own, and for the reason §7.1
     * gives: a character level is one number covering nine slots, so grinding
     * any one thing unlocked all of them. A job level cannot be reached around
     * by work the piece has nothing to do with -- an epic pickaxe wants a miner
     * rather than somebody who has walked a long way, and §2's sybil arithmetic
     * gets worse again, because a farm now has to level five lines rather than
     * one character.
     *
     * The numbers are a different ladder because the scale is: a job stops at
     * `JOB_MAX_LEVEL` where a career runs to 100. Common is 1 for §12's sake,
     * and unique at 28 is the last thing a job has left to give.
     *
     * Uncommon is 4 rather than 3 for §9.5.2 rather than for anything here. A
     * monster's level is quoted on this ladder and placed inside its own tier's
     * band; half of a two-point band cannot separate three profiles, so the
     * rim's five would have come out as two numbers rather than three. There is
     * a test.
     */
    public const EQUIP_JOB_LEVEL = [
        'common' => 1,
        'uncommon' => 4,
        'rare' => 8,
        'epic' => 14,
        'legendary' => 22,
        'unique' => 28,
    ];

    /**
     * Derived from the rung and never stored on the item.
     *
     * A column on a hundred catalog rows is a hundred chances to disagree with
     * the ladder, and the ladder is the only thing this depends on. The client
     * mirrors the same function over the same table for the same reason.
     */
    public static function equipLevel(string $rarity): int
    {
        return self::EQUIP_LEVEL[$rarity] ?? 1;
    }

    /** The same, for the pieces that answer to a job rather than to a career. */
    public static function equipJobLevel(string $rarity): int
    {
        return self::EQUIP_JOB_LEVEL[$rarity] ?? 1;
    }

    /**
     * §8.0 -- how far up the ladder each workbench reaches. A village will never
     * make a rare no matter what materials you carry to it, which is most of
     * what makes a capital worth the walk.
     *
     * **A capital reaches rare and stops**, where it used to reach epic. Epic is
     * a guild's now (§10.6) -- it is made on land a roster bought, named and
     * levelled, and nowhere the map put there by itself. That is the whole
     * bargain of the section: the last rung a player can craft is one other
     * people had to help pay for.
     *
     * **Legendary and unique are not crafted at all**, which is why neither
     * appears here. They come off a dungeon (§9.2) and that is their only
     * source, so the recipes stand defined and unreachable exactly as they did
     * before §10.5 existed. §8.1 rule 4's "every rarity below unique is
     * reachable by crafting" is therefore true up to epic and no further, and
     * epic is where an F2P ladder now ends.
     *
     * `guild` is the cap a *maxed* land reaches; what a given land reaches this
     * afternoon is its craft level (§10.6), which climbs to this and no
     * further.
     */
    public const STATION_RARITY_CAP = [
        'village' => 'common',
        'city' => 'uncommon',
        'capital' => 'rare',
        'guild' => 'epic',
    ];

    /**
     * §3.2/§8.0 -- the highest rung a SHELF ever holds.
     *
     * Common, and nothing above it: anything better than the cheapest thing in
     * the game is made rather than bought. That is what puts the benches at the
     * centre of §8's ladder instead of beside it -- gold gets you started, and
     * every rung after the first is somewhere you carried materials to.
     *
     * It used to reach uncommon, which made the second rung of every line
     * purchasable and the crafted rung beside it optional. A shelf that sells
     * the upgrade is a bench nobody has to visit.
     */
    public const SHOP_STOCK_CAP = 'common';

    /**
     * §3.2 -- the highest rung gold changes hands over AT ALL, which is the
     * trader's counter rather than its shelf.
     *
     * Two different questions, and they were one constant. The shelf is about
     * where gear comes FROM, and above common the answer is a bench. The
     * counter is about a piece's EXIT: §8.2 gives a piece three of them --
     * repair keeps it, salvage returns a fraction of what went in, a sale
     * returns gold scaled by what is left -- and taking one away from every
     * uncommon buys the threat model nothing.
     *
     * The two sets were never the same anyway: the trader has always bought
     * back a craft-only common it does not stock. What this cap is for is §2 --
     * every epic and legendary draft wants a Tier 3 rare (§8.5) and those are
     * capped per wallet, so a gold price on one would turn a capped rare into
     * uncapped coin.
     */
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
     * §8.0.1 -- what a rolled line off each tier is worth.
     *
     * Every line is a solid number on the pair, because that is what `attack`
     * and `defense` are (§9.5.4) and because a percentage is not a thing a
     * player can feel: it climbs toward a ceiling nobody can see, so a lucky
     * roll and an unlucky one read the same on the plate. A line is luck, and
     * luck has to be legible.
     *
     * Every line rolls its OWN tier, drawn from the tiers at or below the
     * item's rarity, so a legendary can come out carrying a common-grade line
     * and often does. That is what makes a good roll a good roll: the ceiling
     * is higher up the ladder, not the floor.
     *
     * Sized against the pairs on the gear itself -- a common weapon is 7-12
     * attack and a legendary 22-34 -- so a rolled line is a real find at the
     * bottom of the ladder and a nice extra at the top.
     */
    public const OPTION_FLAT_VALUE = [
        'common' => [100, 200],
        'uncommon' => [100, 300],
        'rare' => [200, 400],
        'epic' => [300, 600],
        'legendary' => [400, 800],
    ];

    /**
     * §8.0.1 -- a DURABILITY line, as a share of the piece's own max.
     *
     * Rolled as a share and stored as POINTS, because points are the unit a
     * player reads durability in: a bar says 130/130, so "+9 durability" lands
     * instantly where "+7%" is arithmetic. The share is what keeps the line
     * worth the same on a 40-point stone axe and a 240-point coat -- a flat
     * band would be a fifth of the axe and noise on the coat.
     *
     * Bounded by §11.1 rather than by §8.1: the repair bill is the largest
     * continuous sink in the game, and a line that thinned it much further
     * would be switching that sink off by luck.
     */
    public const OPTION_DURABILITY_VALUE = [
        'common' => [0.03, 0.05],
        'uncommon' => [0.04, 0.07],
        'rare' => [0.05, 0.09],
        'epic' => [0.07, 0.12],
        'legendary' => [0.09, 0.15],
    ];

    /**
     * §8.0.1/§9.5.9 -- whole rounds off EVERY cooldown the weapon's family
     * carries. Weapon only: the family in the slot is what decides which three
     * skills you have at all, so it is the only piece with any business
     * shortening them.
     *
     * One value per tier rather than a range, because whole rounds are a short
     * ladder and a "1-1" band is a band pretending to be one. Sized against
     * SKILL_BATTLE_COOLDOWN_CAP (+2 for a whole maxed tree), so a lucky
     * legendary is worth about what the tree is and never more -- gear is the
     * ladder (§8) and a tree is a different road up it, not a shorter one.
     */
    public const OPTION_COOLDOWN_VALUE = [
        'common' => 1,
        'uncommon' => 1,
        'rare' => 1,
        'epic' => 2,
        'legendary' => 2,
    ];

    /**
     * §8.0.1 -- what a HAUL or a TRAVEL line is worth, per tier.
     *
     * 10% to 30% in fives, which is one value per option tier and no roll
     * inside it: the tier IS the step. That is what makes these two legible in
     * a way a 1-6% band never was -- a player reads "+20% haul" and knows
     * exactly which rung of luck they got.
     *
     * **These are NOT `StatKey` percentages and do not meet STAT_CEILING.**
     * They are their own kinds, read where the work is done rather than summed
     * into the gear aggregate, the same way §5.7's pocket multiplies the ground
     * beside the ring premium instead of joining the kit. §8.1 rule 1 still
     * governs every stat it names; what it does not govern is a number it has
     * never counted.
     *
     * They do keep §8.1 rule 2, because that rule is about stacking rather than
     * about the ceiling: a second haul line is worth less than the first, and
     * OPTION_GAIN_CAP is where the whole kit stops.
     */
    public const OPTION_GAIN_VALUE = [
        'common' => 0.10,
        'uncommon' => 0.15,
        'rare' => 0.20,
        'epic' => 0.25,
        'legendary' => 0.30,
    ];

    /**
     * §5.3 / §8.0.1 -- how much likelier a favoured grade is to come up.
     *
     * THREE values and not five, because three is what the line is: ten, twenty
     * and thirty per cent. The five rungs map onto them in pairs from the
     * bottom, so an uncommon axe and a rare one both favour a seam by a tenth
     * and only a legendary reaches the third of it.
     *
     * That is deliberately flatter than the haul ladder next to it. A haul line
     * makes every trip bigger; this one bends WHAT comes out of the ground, and
     * §5.3's tails are the shape the whole grade ladder is read through -- a
     * line that could double the top tail would make the ladder a suggestion.
     */
    public const OPTION_SEAM_VALUE = [
        'common' => 0.10,
        'uncommon' => 0.10,
        'rare' => 0.20,
        'epic' => 0.20,
        'legendary' => 0.30,
    ];

    /** The most a whole kit's haul or travel lines may come to together. */
    public const OPTION_GAIN_CAP = 0.30;

    /**
     * §8.0.1/§8.2 -- the chance a crafted piece comes out unbreakable.
     *
     * At zero durability it is not destroyed: it sits there, useless until it
     * is mended, which is the one exception §8.2 has. Low on purpose and rolled
     * apart from the lines, so it never competes with them for a slot and never
     * dilutes a pool -- a piece that survives forever is a story, not a bonus.
     *
     * Only on a rung that rolls at all, which keeps it off every shelf (gold
     * buys a plain item) and off every common.
     */
    public const OPTION_INDESTRUCTIBLE_CHANCE = 0.02;

    // --------------------------------------------------------- consumables §8.5

    /**
     * §8.5 -- how deep a shelf of one draft goes is `BAG_STACK_POTION`, and the
     * Alchemist's `stackCap` deepens it (§7.4.3).
     *
     * There is no ceiling on how many of one draft a character may hold beyond
     * the straps it takes to carry them, and there does not need to be: what
     * stops a cellar being a stat is that one charge per (stat, action) is
     * enforced by a unique index, so forty-five flasks are still four stats
     * across eight actions once drunk. Being *spent* is the sink -- a
     * consumable whose effect were permanent would only accumulate, which the
     * design's north star forbids outright.
     *
     * What a deeper shelf buys is therefore straps rather than hoard: the same
     * hundred drafts on fewer of them.
     */
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
     * §8.0.2 -- how far a copy may fall either side of its recipe.
     *
     * Every solid figure a piece carries -- its attack, its defense and its
     * durability ceiling -- moves together by one rolled offset, so a piece is
     * *a good one* or *a poor one* rather than a bag of unrelated luck. One
     * number a player can hold in their head; three independent rolls would be
     * noise wearing the word variety.
     *
     * A share rather than a count, so it scales up the ladder for free rather
     * than needing a table -- the same argument §6 makes about the bench fee.
     * At the common rung it comes to about ±20 points of a Stone Axe's 300,
     * which is where the figure came from.
     *
     * **It is not a `StatKey` and meets no ceiling** (§8.1 rule 1): these are
     * solid numbers, the same standing §8.0.1's rolled lines have. And it is
     * far under BATTLE_SWING's ±10% per strike, so it colours a piece without
     * deciding a fight §9.5.4 says the kit decides.
     */
    public const QUALITY_BAND = 0.07;

    /**
     * Rolled as the MEAN OF TWO, which is what makes a good one worth having.
     *
     * A flat roll makes every value equally likely, so "a fine axe" is a
     * sentence about nothing -- one copy in ten is near the top and one in ten
     * near the bottom, and the middle is no more ordinary than either end.
     * Averaging two rolls gives a triangle: most copies sit near the recipe,
     * the edges are rare, and finding one is a find.
     */
    public const QUALITY_ROLLS = 2;

    /**
     * §4.0 -- what a scrap haul is worth as XP, against the same haul of the
     * real material. Bare-handed work still teaches the line, just badly: at 1.0
     * a player could max a skill without ever buying a tool, which would make
     * the whole §8.0 ladder optional.
     */
    public const SCRAP_XP_RATE = 0.25;

    /**
     * §8.1 rule 3 -- what one mine takes off the line's tool, as a BAND.
     *
     * It was a flat 100 -- one whole point at the old scale, multiplied up and
     * left there. That made the finest thing in the game about a piece of gear
     * the one number that never used the granularity: every mine took exactly
     * the same bite, so a durability bar moved in identical steps forever and
     * the two digits SOLID_SCALE bought were always zeroes.
     *
     * A band instead, seeded per mine like every other outcome (§16). The mean
     * is 100, so a tool still lasts the forty-odd mines it always did and no
     * repair bill moves -- what changes is that no two mines cost the same and
     * a bar you are watching is worth watching.
     *
     * Not so wide that "how many mines has this got left" stops being
     * answerable: at ±20% the answer is still forty, give or take one.
     */
    public const DRAIN_PER_MINE = 100;

    public const DRAIN_PER_MINE_BAND = 0.20;

    /** The most one mine can ever take, which is what a warning has to use. */
    public static function maxDrainPerMine(): int
    {
        return (int) ceil(self::DRAIN_PER_MINE * (1 + self::DRAIN_PER_MINE_BAND));
    }

    /**
     * The same for a raid, and it is 400 rather than 4 because durability moved
     * to SOLID_SCALE and this did not (§7.3's rule: anything converting between
     * a solid number and something that did not move has to be moved with it).
     *
     * Nothing reads it yet -- §14 has dungeon combat undesigned -- which is
     * exactly how it came to be left behind. A dormant constant at the wrong
     * scale does not fail; it waits, and then it is a hundredfold error in a
     * system that arrives believing it.
     */
    public const DRAIN_PER_RAID = 400;

    public const SALVAGE_RATE = 0.25;

    public const REPAIR_COST_RATE = 0.6;

    /**
     * §8.2 -- the highest material TIER a counter will sell you the parts for.
     *
     * A mend may be paid in coin as far as the trader reaches, and no further.
     * A village keeps a rack of raw; a city and a capital have the refined
     * stock behind the counter as well. **Nothing above tier 2 is ever payable
     * in gold, at any settlement** -- and that is a §2 rule rather than a
     * tuning value. Tier 3 is capped per wallet and §5.3 says the trader will
     * not touch one, so a gold price on a mend that wanted ironwood would turn
     * a capped rare into uncapped coin, which is the sentence §8.2 already
     * writes about the resale counter.
     *
     * A capital is no better stocked than a city here, exactly as its shelf is
     * no better stocked than a village's (§8.0). What a capital is for is the
     * bench.
     */
    public const REPAIR_COIN_TIER = [
        'village' => 1,
        'city' => 2,
        'capital' => 2,
    ];

    /**
     * §8.3 -- and the counter's spread, which is the shelf's own markup.
     *
     * The parts at the NPC's own poor rate, marked up by half. That the markup
     * is above 1 is the load-bearing part rather than a tuning value: the NPC
     * pays 1x for a material and charges 1.5x for it, so gathering the parts is
     * always strictly better value than buying them, and there is no
     * gather-sell-mend loop that beats mending directly. There is a test.
     */
    public const REPAIR_COIN_MARKUP = 1.5;

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
     *
     * **Divided by SOLID_SCALE, because the durability it multiplies moved and
     * the PRICE did not.** A shelf tag is gold, and gold is not on that scale
     * (§3.2 — it is its own currency with its own ladder). Left alone, every
     * stocked piece would have cost a hundred times what it does.
     */
    public const STATION_GOLD_PER_DURABILITY = [
        'village' => 0.43 / self::SOLID_SCALE,
        'city' => 1.40 / self::SOLID_SCALE,
    ];

    public const SHOP_MATERIAL_MARKUP = 1.5;

    /**
     * §6/§8.4 -- what a bench charges to be used, as a share of what it handles.
     *
     * A settlement is shared infrastructure (§6) rather than your workshop, and
     * standing at somebody else's saw pit costs something. It joins §3.2's list
     * of gold sinks -- repair, the shelf, settlement upgrades, guild bidding --
     * as the steady one that touches everybody who makes anything.
     *
     * **A TENTH, and small is the rule rather than the tuning.** §3.2 severs
     * gold from everything above the cheapest rung: the shelf stops at common
     * and every rung after it is made. A large bench fee would quietly reattach
     * them -- pay enough gold and the epic appears -- so the fee has to stay
     * far under the point where gold, rather than the materials and the walk,
     * is what gates a craft. At a tenth of the parts an Ironwood Axe costs 23
     * gold in fees against 228 gold of materials that no amount of gold can
     * buy.
     *
     * It is charged on what the bench HANDLES, at the NPC's own poor rate: the
     * parts for a craft, the inputs for a run. One rule for both benches, and
     * it scales with the rung for free rather than needing a table.
     *
     * §8.3's shelf price has always included a term for the bench -- "plus the
     * bench time it takes" -- which nothing ever charged. It charges this now,
     * so that line of the price is a fact rather than a valuation.
     */
    public const BENCH_FEE_SHARE = 0.10;

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
    public const TRAVEL_MS_PER_HEX = 5 * self::SECOND;

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
     * §5.4 + §12 -- how far a fresh spawn may be from the village whose
     * woodcutting line its opening arc needs.
     *
     * This is a *generation* constraint, not a rule the player ever meets. It
     * was level-1 reach back when reach existed; it stays a number of its own
     * so that shrinking sight cannot quietly strand a new character from the
     * only place that turns their wood into planks.
     *
     * HALF A COUNTRY, rather than a hex count of its own. It was six, from when
     * villages stood eleven hexes apart -- with one settlement to a country
     * (§6) a woodcutting bench is several countries away on average, and six
     * hexes of slack around one meant most spawns quietly fell through to a
     * fallback that guaranteed nothing at all. Half a cell is about two minutes
     * of walking at TRAVEL_MS_PER_HEX, which is what "a short walk" is worth
     * now that a hex costs five seconds rather than five minutes.
     */
    public const SPAWN_VILLAGE_RADIUS = self::BIOME_CELL >> 1;

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
     * The clamp is a guard at 1 minute now, the HP band is ten minutes to
     * twenty at the common rung rather than fifteen to thirty, and a geared
     * prospector works a hex in 3-8 -- so the late-career mine rate is several
     * times what this was sized against. The curve has not been re-fitted:
     * doing so is a deliberate pacing decision, not a side effect of a mining
     * change.
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
    /**
     * §8.3 -- `travelSpeed` DIVIDES the clock, so +8% boots really are 8%
     * faster over any distance.
     *
     * §8.0.1's rolled `travel` line is a second divisor rather than part of the
     * first: it is not a `StatKey` and never meets STAT_CEILING, so summing the
     * two would quietly put it under a ceiling it is not subject to.
     */
    public static function travelMsPerHex(float $travelSpeedBonus = 0.0, float $rolledBonus = 0.0): int
    {
        return (int) round(
            self::TRAVEL_MS_PER_HEX
                / (1 + max(0.0, $travelSpeedBonus))
                / (1 + max(0.0, $rolledBonus))
        );
    }

    /**
     * Speed multiplier for a place's benches -- lower is faster.
     *
     * §10.6 -- guild land starts level with a capital and gets faster from
     * there, a little per processing level. That is what levels 6 to 20 buy:
     * the count stops at five because five is all there is, and everything
     * above it goes on the clock. A hundred thousand gold and a maxed ladder
     * should feel different from walking into somebody else's capital.
     *
     * Capital's speed as the FLOOR rather than village's, which is where the
     * fall-through used to put it -- the most expensive place in the game
     * running at the rate of the cheapest.
     */
    public static function settlementSpeed(string $tier, int $level = 0): float
    {
        return match ($tier) {
            'capital' => self::SPEED_CAPITAL,
            'city' => self::SPEED_CITY,
            'guild' => max(
                self::GUILD_LAND_SPEED_FLOOR,
                self::SPEED_CAPITAL - max(0, $level) * self::GUILD_LAND_SPEED_PER_LEVEL,
            ),
            default => self::SPEED_VILLAGE,
        };
    }

    // --------------------------------------------------------- §9.6 dungeons

    /**
     * §9.6.1 -- a session stands for twelve hours and then closes with everybody
     * outside it.
     *
     * Twelve rather than three because a clock should end a run without shaping
     * it: at three the session was the binding constraint on everything, and
     * what binds now is durability and straps (§9.6.5), which are sinks §11.1 is
     * balanced on. A clock is not. It also spans a night, which is the only
     * answer this design has to getting six people to one centre-ring mouth.
     */
    public const DUNGEON_SESSION_MS = 12 * self::HOUR;

    /** §9.6.1 -- two or more is the shape it is built for; one is a challenge. */
    public const DUNGEON_PARTY_MAX = 6;

    /** §9.6.2 -- a floor is fifty hexes a side. */
    public const DUNGEON_FLOOR_SIZE = 50;

    /** §9.6.2 -- ten of them, and the tenth guardian is the one that always pays a Core. */
    public const DUNGEON_FLOORS = 10;

    /**
     * §9.6.2 -- six of a floor's monsters have to fall before the stair opens,
     * summed across the roster, with the guardian as the sixth.
     *
     * Never per member: six is what the FLOOR costs, so six people paying one
     * apiece is the same bargain the rest of §9.6 strikes, where what a party
     * buys is that fewer of them have to do each thing.
     */
    public const DUNGEON_FLOOR_KILLS = 6;

    /**
     * §9.6.2 -- the share of a floor's hexes holding a monster.
     *
     * This is the constraint the kill gate puts on placement rather than a
     * flavour value: a counter on a thin floor sends somebody combing
     * twenty-five hundred hexes at sight one to three looking for something to
     * hit. Six have to be met on the way and never hunted, and
     * `DungeonsTest` pins that against the SEED rather than the average --
     * a density right on average and thin one time in fifty is a floor that
     * strands somebody.
     */
    public const DUNGEON_MONSTER_DENSITY = 0.12;

    /** §9.6.2 -- 128 bits of CSPRNG, folded into every placement draw, handed to nobody. */
    public const DUNGEON_SECRET_BYTES = 16;

    /** §9.6.1 -- what a player types to join. Shareable, so it carries no authority of its own. */
    public const DUNGEON_CODE_LENGTH = 6;

    /**
     * §9.6.8 -- the FLOOR-TEN guardian's rate, and floor one is a tenth of it.
     *
     * Ramped rather than flat, and that is one of the three things holding §2
     * shut. A dropped legendary is mintable, so this is the first
     * grind-to-external-value path the game has had: flat across ten guardians
     * a hard session makes about 2.4 of them. Ramped it is roughly half that,
     * and descending starts meaning something beyond another roll.
     */
    public const DUNGEON_LEGENDARY_CHANCE = 0.025;

    /**
     * §9.6.8 -- and unique, which is free of consequence.
     *
     * It is soulbound (§8.0), so it can never leave the game and it is not a
     * faucet in the §2 sense at all. This is the one number in the section that
     * may be generous.
     */
    public const DUNGEON_UNIQUE_CHANCE = 0.0025;

    /** §9.6.3 -- hard is the same dungeon with the top two rungs doubled. */
    public const DUNGEON_HARD_MULTIPLIER = 2;

    /**
     * §9.6.8 -- the second of the three guards, and §12.2's argument exactly:
     * the cap is a RATE, not a total.
     *
     * The faucet's lifetime yield is wallets x weeks, and §2 has already priced
     * both ends of that -- a one-time mint fee, and a balance held for seven
     * continuous days before a wallet can act at all.
     */
    public const DUNGEON_SESSIONS_PER_WEEK = 3;

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
