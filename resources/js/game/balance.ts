/**
 * Tuning constants. Every value here is a starting point for tuning, not a
 * locked constant (CLAUDE.md preamble). Keep all magic numbers in this file so
 * a balance pass never means grepping the codebase.
 */

export const MINUTE = 60_000
export const HOUR = 60 * MINUTE

/**
 * NOTE ON TIME
 *
 * Durations below are the real, honest ones (§7.3). The server may compress them
 * for development, but that factor is NOT duplicated here -- it arrives in
 * PlayerState.timeScale so there is exactly one source of truth and no "these
 * two env vars must match" footgun. Use `useGame().timeScale` when converting a
 * predicted duration into a wall-clock countdown.
 */

export const MAP = {
  /** §5.1. Server-authoritative: the client is handed these by GET /api/world,
   *  so they are documentation, not the source of truth. The map is square and
   *  centerd on the origin -- a radius of 200 is every column and every row
   *  from -200 to 200, so `size` is `radius * 2 + 1`. */
  radius: 2500,
  size: 5001,
  seed: 0x5eed_1a3f,
  /** Biome lattice, §5.3. Cell size in tiles, and how many cells make up one
   *  coherent region. Patches must be small enough that a second biome is a
   *  short walk from a fresh spawn -- there is no reach limit (§5.6), but there
   *  is a clock, and a new character should not owe it a day for a second
   *  material. */
  biomeCell: 9,
  biomeRegionCells: 5,
  /** Normalised radius boundaries for the ring layout, §5.2. */
  rings: { center: 0.08, inner: 0.34, mid: 0.64 },
  /**
   * §5.6 -- documentation only, and pointedly so. Sight is published per
   * character in the state payload (`character.sight`) because it changes with
   * the Explorer tree and drops to zero on the road; reading these numbers
   * instead of that field is how the fog and the server end up disagreeing.
   */
  sightRadius: 1,
  sightTraveling: 0,
  /** §5 -- five minutes of ground per hex, before travelSpeed divides it. */
  travelMsPerHex: 5 * MINUTE,
} as const

export const MINING = {
  /** base_tile_time range, §7.3. */
  baseMinSeconds: 30 * 60,
  baseMaxSeconds: 60 * 60,
  /** clamp() floor and ceiling. The floor is mandatory from day one, §7.3. */
  floorSeconds: 15 * 60,
  ceilingSeconds: 60 * 60,
  /** §7.3 -- what bare hands take out of a hex per second. */
  baseAttack: 10,
  /** §7.3 -- what a maxed line skill adds to that rate. */
  skillAttack: 10,
  /** Exactly two mining slots per hex, §5.1. */
  slotsPerTile: 2,
  /** Depleted tiles regrow after ~9h, §5.1. */
  regrowMs: 12 * HOUR,
  /** Leaving a hex mid-progress forfeits partial yield, §11.1. */
  abandonRefund: 0,
} as const

export const HUNTING = {
  /** Herd markers decay after ~4h, §5.5. */
  markerLifetimeMs: 4 * HOUR,
  baseSeconds: 25 * 60,
  peltYield: [2, 5] as const,
} as const

export const PROCESSING = {
  /** Five open slots per feature, first-come-first-served, §6.1. */
  publicSlots: 5,
  /** Speed multiplier by settlement tier -- lower is faster, §6. */
  speed: { village: 1, city: 0.75, capital: 0.55 } as const,
  /** Presence bonus, §6.2. Presence alone produces nothing; it only
   *  accelerates an already-capped queue, so bot value is near zero. */
  presenceSpeedBonus: 0.2,
  presenceXpPerMinute: 4,
} as const

/**
 * §8.4 -- how long a bench holds a thing, by rung. Mirrors
 * Balance::CRAFT_BASE_SECONDS.
 *
 * Base seconds, before the settlement's tier, the presence bonus and the gloves
 * are applied -- and before the game clock is. The client quotes this to answer
 * "is it worth starting here"; the exact figure arrives with the job.
 */
export const CRAFT = {
  seconds: {
    common: 8 * 60,
    uncommon: 14 * 60,
    rare: 22 * 60,
    epic: 34 * 60,
    legendary: 50 * 60,
    unique: 50 * 60,
  } as const,
} as const

export const CHARACTER = {
  startingGold: 25,
  /**
   * §7.4.4 -- sized against measured income, not picked. ~197,000 XP to level
   * 100 against a career average of ~1,080 char XP a day is roughly 182 days of
   * unbroken play at game speed 1. The flat 40 is a floor so the first level
   * costs about three mining trips rather than half of one.
   *
   * XP is never scaled by the game clock: a trip pays the same at speed 1 and
   * speed 100, which is what keeps a fast clock a testing tool.
   */
  xpForLevel: (level: number) => Math.round(40 + 2.1 * Math.pow(level, 1.7)),
  /** §7.4.1 -- 100 levels, one skill point each. */
  maxLevel: 100,
  skillPointsPerLevel: 1,
} as const

/**
 * §7.4 -- the eleven bought jobs, plus Explorer (§7.5), which is bought by
 * nobody. A job level gates tree nodes and does nothing else: no stat, no
 * yield, no speed. Points are the scarce thing and are spent on breadth; job
 * levels are the slow thing and are earned on depth.
 */
export const JOBS = {
  maxLevel: 30,
  xpForLevel: (level: number) => Math.round(17 * Math.pow(level, 1.5)),
  xpPerRarityRank: 10,
  /**
   * §7.4.3 -- caps on the node effects that are not stats. Stat nodes need no
   * cap of their own; they feed the same aggregate and the same statCeiling
   * clamp as gear, options and potions, so a point can never pass +15%. These
   * four thin a §11 sink instead, which is why each is bounded.
   */
  optionChanceCap: 0.35,
  durabilityCap: 0.25,
  costReductionCap: 0.15,
  batchCap: 2,
  /**
   * §7.5 -- extra hexes of sight from the Explorer tree, on top of the base
   * one. A query budget rather than a balance one: cost goes as the square of
   * the radius -- one hex is seven tiles, three is thirty-seven.
   */
  sightCap: 2,
  /** §7.6 -- the bag caps are the same kind of thing, and live on BAG below. */
  /** §7.5 -- Explorer XP per hex crossed. Never scaled by the game clock. */
  explorerXpPerHex: 5,
} as const

export const SKILLS = {
  maxLevel: 50,
  /** Cap total points so characters specialise, §7.2. */
  totalPointCap: 90,
  xpForLevel: (level: number) => Math.round(45 * Math.pow(level, 1.4)),
} as const

/**
 * §7.6 -- the bag, and the two limits on it.
 *
 * Documentation only, like `sightRadius` above: what a character may carry is
 * published per character in the state payload (`character.bag`) because the
 * Explorer tree widens it. Reading these numbers instead of that field is how
 * the meters and the server end up disagreeing about whether you may leave.
 */
export const BAG = {
  units: 120,
  rows: 30,
  /**
   * §7.5 -- what the road adds, and the most it can ever add: 120 -> 200 units
   * and 30 -> 50 rows, ten and four at a time across the Explorer's fifteen
   * skills. Counts rather than percentages, so they have nothing to do with the
   * §8.1 stat ceiling -- which is the whole reason a tree that costs no skill
   * points is allowed to hand them out.
   */
  skillUnitsCap: 80,
  skillRowsCap: 20,
} as const

export const EQUIPMENT = {
  /**
   * The rarity ladder, §8.1 rule 1. Rarity walks up to a single global ceiling
   * rather than every tier sharing one: the best a stat can ever reach is
   * `unique`, and nothing -- no future rarity, no rolled option, no buff -- may
   * be allowed past it.
   */
  statCap: {
    common: 0.03,
    uncommon: 0.05,
    rare: 0.08,
    epic: 0.11,
    legendary: 0.14,
    unique: 0.15,
  } as const,
  /** The hard ceiling for the whole game. Read this, never `statCap.unique`. */
  statCeiling: 0.15,
  /**
   * §8.0 -- how far up the ladder each workbench reaches. A village will never
   * make an epic no matter what materials you carry to it, which is most of what
   * makes a capital worth the walk. `guild` is defined but unreachable until
   * guild halls exist (§10).
   */
  stationRarityCap: {
    village: 'common',
    city: 'uncommon',
    capital: 'epic',
    guild: 'legendary',
  } as const,
  /** Gold buys the bottom two rungs and nothing else, §3.2. */
  shopRarityCap: 'uncommon',
  /** Diminishing returns on stacking, §8.1 rule 2: the nth item of the same
   *  stat contributes value * falloff^(n-1). */
  stackFalloff: 0.5,
  /** Durability drain per use, §8.1 rule 3. Raiding drains faster than mining. */
  drainPerMine: 1,
  drainPerRaid: 4,
  /** Discard returns a small % salvage, §8.2. */
  salvageRate: 0.25,
  /** Repair must be cheaper than crafting new, but not dramatically, §8.2. */
  repairCostRate: 0.6,
  /** §8.2 -- what the trader gives back for shop gear, before wear is applied. */
  resaleRate: 0.5,
  /**
   * §3.2 -- the shelf: the higher of what a piece costs to MAKE (parts marked
   * up, plus bench time at §8.4's clock) and what it is WORTH (gold per point
   * of durability, per station).
   */
  stationGoldPerDurability: { village: 0.43, city: 1.4 },
  shopMaterialMarkup: 1.5,
  goldPerCraftMinute: 1,
} as const

export const ECONOMY = {
  /** NPC buy-back is deliberately a bad rate, §3.2 / §12 step 2. */
  npcSellMultiplier: 1,
  /** Tier 3 materials are capped per wallet, §2. */
  rareWalletCap: 40,
} as const
