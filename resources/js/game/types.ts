/**
 * Core domain types. These mirror the design doc (CLAUDE.md) section by section
 * and are shared by the world generator, the API DTOs and the UI.
 */

// ---------------------------------------------------------------- world

export type Biome = 'forest' | 'mountain' | 'plains' | 'badlands' | 'grassland'

/** Concentric ring layout, §5.2. Drives generation, not just cosmetics. */
export type Ring = 'outer' | 'mid' | 'inner' | 'center'

/** Offset (odd-q) coordinates. Flat-top hexes, odd columns pushed down H/2. */
export interface Coord {
  col: number
  row: number
}

export type SettlementTier = 'village' | 'city' | 'capital'

// ---------------------------------------------------------------- materials

export type MaterialTier = 0 | 1 | 2 | 3 | 4

/**
 * §4.0 -- scrap. What bare hands bring back from a hex when you have no tool
 * for its line: sells for a copper, feeds no recipe, reaches no other tier.
 */
export type ScrapKey = 'branch' | 'ore_chips' | 'torn_hide' | 'gravel' | 'chaff'

export type RawKey = 'wood' | 'iron_ore' | 'pelt' | 'stone' | 'fiber'

/**
 * §5.3 Tier 1 -- the grades above the base raw, two per biome. Biome-locked
 * exactly as the base is; what changes is which variant of the ground drops it.
 */
export type GradeRawKey =
  | 'hardwood'
  | 'heartoak'
  | 'hematite'
  | 'meteoric_iron'
  | 'thick_pelt'
  | 'dire_pelt'
  | 'basalt'
  | 'granite'
  | 'flax'
  | 'hemp'

export type RefinedKey =
  | 'planks'
  | 'ingots'
  | 'leather'
  | 'cut_stone'
  | 'cloth'
  | 'reinforced_frame'

/** §5.3 Tier 2 -- what each grade refines into, on the same 3:1 as the base. */
export type GradeRefinedKey =
  | 'beams'
  | 'bentwood'
  | 'steel_ingots'
  | 'skysteel'
  | 'boiled_leather'
  | 'lacquered_hide'
  | 'dressed_basalt'
  | 'polished_granite'
  | 'linen'
  | 'canvas'

export type RareKey =
  | 'ironwood'
  | 'mythril_ore'
  | 'beastfang_hide'
  | 'obsidian_shard'
  | 'silkweave_fiber'

export type RaidKey =
  | 'essence'
  | 'shard_verdant'
  | 'shard_ferrous'
  | 'shard_sanguine'
  | 'shard_cinder'
  | 'shard_zephyr'
  | 'relic'
  | 'core'

/**
 * §4 Tier 1 -- the alchemist's raw stock, two per biome. Raw like wood and
 * iron: biome-locked, decays over cap, and worth more than scrap.
 */
export type ReagentKey =
  | 'toadstool'
  | 'birch_sap'
  | 'lichen'
  | 'stonewort'
  | 'bitterroot'
  | 'yarrow'
  | 'ashcap'
  | 'sagebrush'
  | 'blue_nettle'
  | 'clover'

/**
 * §4.0 Tier 0 -- junk. Sells for a copper, feeds no recipe, reaches no tier.
 * Kept apart from ScrapKey because scrap is what a hex gives up to bare hands
 * and this is not: it is simply the rubbish you carry out alongside.
 */
export type JunkKey =
  | 'deadfall'
  | 'slag'
  | 'bone_splinter'
  | 'cinder'
  | 'thistle'

/**
 * §4 Tier 1 -- the smith's and the armorer's raw stock, two per biome. Same
 * model as ReagentKey: biome-locked, gathered off a hex, worth more than scrap.
 */
export type ComponentKey =
  | 'heartknot'
  | 'pine_pitch'
  | 'flux_salt'
  | 'slate_scale'
  | 'horn'
  | 'sinew'
  | 'whetgrit'
  | 'tar_seep'
  | 'quench_reed'
  | 'beeswax'

/**
 * §5.3 -- a biome is four kinds of ground, one per equipment rung. The key is
 * the biome for the base grade and `biome_grade` above it, so the base tiles
 * keep reading as plain 'forest' wherever a variant was not the question.
 */
export type VariantKey =
  | 'forest'
  | 'forest_uncommon'
  | 'forest_rare'
  | 'forest_epic'
  | 'mountain'
  | 'mountain_uncommon'
  | 'mountain_rare'
  | 'mountain_epic'
  | 'plains'
  | 'plains_uncommon'
  | 'plains_rare'
  | 'plains_epic'
  | 'badlands'
  | 'badlands_uncommon'
  | 'badlands_rare'
  | 'badlands_epic'
  | 'grassland'
  | 'grassland_uncommon'
  | 'grassland_rare'
  | 'grassland_epic'

export type MaterialKey =
  | ScrapKey
  | JunkKey
  | RawKey
  | GradeRawKey
  | ReagentKey
  | ComponentKey
  | RefinedKey
  | GradeRefinedKey
  | RareKey
  | RaidKey

export interface Material {
  key: MaterialKey
  name: string
  tier: MaterialTier
  /** Which craft bench the component was named for. Flavour: nothing reads it. */
  bench?: 'weapon' | 'armor'
  /** Biome lock for tier 1 and tier 3. Raid + refined materials are unlocked. */
  biome?: Biome
  /** Accent colour for the procedural icon system, §13.1. */
  palette: 'wood' | 'iron' | 'pelt' | 'stone' | 'fiber' | 'raid'
  /** NPC buy-back price in gold. Deliberately poor, §3.2. */
  npcPrice: number
  /** Tier 3 materials are capped per wallet, §2. */
  walletCap?: number
  description: string
}

// ---------------------------------------------------------------- skills

export type SkillKey =
  | 'woodcutting'
  | 'mining'
  | 'hunting'
  | 'quarrying'
  | 'harvesting'

export interface Skill {
  key: SkillKey
  name: string
  material: RawKey
  rareMaterial: RareKey
  /** §4.0 -- what this line yields to bare hands. */
  scrapMaterial: ScrapKey
  description: string
}

// ---------------------------------------------------------------- equipment

/**
 * Slots, §8. The five gathering slots are one implement per skill line -- an axe
 * is no use on a seam and a bow is no use on a tree -- so a line-locked tool
 * only counts on its own trips. `weapon` is raid combat and never gathers.
 */
export type GatherSlot = 'axe' | 'pickaxe' | 'bow' | 'hammer' | 'sickle'
export type EquipSlot = GatherSlot | 'armor' | 'boots' | 'gloves' | 'weapon'

/**
 * §8.1 -- the rarity ladder. Ordered weakest to strongest; rarity sets the power
 * ceiling, the colour, and which station can make the thing.
 *
 * Rarity is NOT tradeability. `unique` is the strongest and is soulbound; `epic`
 * and `legendary` are the tradeable ones. Read `ItemDef.tradeable` for that, and
 * see §2 for why a drop must never be an NFT.
 */
export type Rarity =
  | 'common'
  | 'uncommon'
  | 'rare'
  | 'epic'
  | 'legendary'
  | 'unique'

/** What an item modifies. All are capped per slot, §8.1. */
export type StatKey =
  | 'yield'
  | 'tripReduction'
  | 'travelSpeed'
  | 'processingSpeed'
  /** §7.4 -- the two battle stats. Dormant until raid combat exists, and
   *  clamped by the same STAT_CEILING as everything else when it does. */
  | 'power'
  | 'defence'

/**
 * §8.5 -- what a buff can be pointed at.
 *
 * The five gathering lines are the §7.2 skills, so a line-scoped buff lands on
 * exactly the trips that line already governs. `travel` and `processing` are
 * the two other things a character spends real time on.
 */
export type BuffScope = SkillKey | 'travel' | 'processing'

/**
 * §8.0 -- the bench a recipe needs, which is not the same as a settlement tier.
 * Village, city and capital are places on the map; a guild hall is not one, and
 * legendary work is reachable only there. Mirrors Balance::stationForRarity().
 */
export type CraftStation = SettlementTier | 'guild'

export interface ItemDef {
  key: string
  name: string
  /**
   * §8.5 -- absent on consumables, which is what makes them the third craft
   * category: a potion is never worn, so it has nowhere to go.
   */
  slot?: EquipSlot
  /** True for potions and buffs. They are spent, not equipped. */
  consumable?: boolean
  rarity: Rarity
  /**
   * §3.3 -- whether this is an NFT, the only externally tradeable value. Kept
   * apart from rarity on purpose: `unique` is the strongest thing in the game
   * and is soulbound, because §2 forbids a grind→NFT faucet and a dungeon drop
   * would be exactly that.
   */
  tradeable: boolean
  stat: StatKey
  /**
   * §8.5 -- the action this buff applies to, on consumables only.
   *
   * A potion no longer boosts a stat everywhere; it boosts it on one thing you
   * do. That is what lets sixty of them exist without sixty of them stacking:
   * two potions are only rivals when they buff the same stat on the same
   * action, and the unique index on character_buffs says exactly that.
   */
  scope?: BuffScope
  /**
   * §8.0 -- the one fixed line a unique carries on top of its three rolled
   * options. Named, not costed: loot tables (§14.3) and combat (§14.2) are both
   * undesigned, so this states an intent the almanac shows as pending. Never a
   * percentage -- the ceiling is +15% and the rolled options already reach it.
   */
  perk?: string
  /** Fractional bonus, e.g. 0.06 = +6%. */
  value: number
  palette: Material['palette']
  /** Gold cost if sold by the NPC shop; absent for crafted/NFT items. */
  goldPrice?: number
  /** Crafting inputs; absent for shop items. */
  inputs?: Partial<Record<MaterialKey, number>>
  /** Station required to craft, §6. `guild` is unreachable -- §8.0. */
  station?: CraftStation
  /** Absent on consumables: nothing that is drunk wears out. */
  maxDurability?: number
  description: string
}

/** §8.5 -- a timed effect running right now. */
export interface ActiveBuff {
  key: string
  stat: StatKey
  /**
   * §8.5 -- the action this buff applies to. `global` is the unscoped case,
   * which is what every buff was before potions were locked to one action.
   *
   * The bag has to show it: two live buffs on the same stat are not a
   * contradiction, they are two different things you are better at.
   */
  scope: BuffScope | 'global'
  value: number
  /** Server-clock deadline. The client counts down against it, never ticks it. */
  expiresAt: number
}

/**
 * §8.0.1 -- one rolled bonus line. Server-rolled and stored per instance, so two
 * of the same recipe are never the same object.
 */
export interface ItemOption {
  stat: StatKey
  value: number
}

export interface OwnedItem {
  id: string
  key: string
  durability: number
  equipped: boolean
  /** Rolled lines. Empty for commons, unless a capital bazaar added one. */
  options: ItemOption[]
}

// ---------------------------------------------------------------- processing

/** One of the five processing lines a settlement can host, §6. */
export interface Recipe {
  key: string
  name: string
  input: MaterialKey
  inputQty: number
  output: MaterialKey
  outputQty: number
  /** Base seconds at a village; city and capital apply a speed multiplier. */
  baseSeconds: number
  skill: SkillKey
  /** Cross-combo recipes take a second input, §4 tier 2. */
  secondInput?: MaterialKey
  secondInputQty?: number
}

// ---------------------------------------------------------------- jobs

export type JobKind = 'mining' | 'processing'
export type JobStatus = 'active' | 'ready'

export interface MiningJob {
  id: string
  kind: 'mining'
  status: JobStatus
  col: number
  row: number
  slot: 0 | 1
  material: MaterialKey
  quantity: number
  startedAt: number
  endsAt: number
  /** Skill the trip trains, so the client can show what it feeds. */
  skill: SkillKey
}

export interface ProcessingJob {
  id: string
  kind: 'processing'
  status: JobStatus
  settlementId: string
  recipeKey: string
  input: MaterialKey
  output: MaterialKey
  quantity: number
  startedAt: number
  endsAt: number
  /** Presence bonus accrues only while the player is at the settlement, §6.2. */
  presence: boolean
  skill: SkillKey
}

export type Job = MiningJob | ProcessingJob

// --------------------------------------------------------------- travelling

/**
 * A journey in progress, §5. Ten minutes of ground per hex, and the hexes
 * between here and there are a straight hex line the server derives from the
 * same endpoints -- so the marker the client walks and the hex a stop lands on
 * are never two different opinions.
 */
export interface TravelState {
  toCol: number
  toRow: number
  startedAt: number
  endsAt: number
  /** Millis per hex crossed, already scaled to this environment's clock. */
  perHexMs: number
  /** Hexes between the two ends, so `path.length` is this plus one. */
  hexes: number
  /** Every hex crossed, from the departure tile to the destination. */
  path: Array<[number, number]>
  destinationName: string | null
}

// ---------------------------------------------------------------- tiles

export interface Tile {
  col: number
  row: number
  biome: Biome
  /** §5.3 -- which of the biome's four grades this ground is. */
  variant: VariantKey
  ring: Ring
  /** Undefined on barren capital-ring tiles, §5.2. */
  material?: MaterialKey
  /** Seconds, before skill and equipment reduction. §7.3 */
  baseSeconds: number
  /** Units yielded per trip before bonuses. */
  baseYield: number
  /** Both slots full closes the tile to everyone else, §5.1. */
  slotsUsed: number
  /** Unix ms when a depleted tile regrows; 0 when live. §5.1 */
  regrowsAt: number
  settlement?: Settlement
  /** Dungeon entrance, §9.1. Exactly five exist, in the capital ring. */
  dungeon?: { key: string; name: string }
  /** Temporary herd marker, §5.5. */
  herdUntil?: number
  /** Elevation prop seed so mountains/trees render deterministically. */
  propSeed: number
}

export interface Settlement {
  id: string
  name: string
  tier: SettlementTier
  col: number
  row: number
  /** Which of the five lines this settlement can run, §6. */
  lines: SkillKey[]
}
