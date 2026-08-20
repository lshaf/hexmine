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

export type MaterialTier = 1 | 2 | 3 | 4

export type RawKey = 'wood' | 'iron_ore' | 'pelt' | 'stone' | 'fiber'

export type RefinedKey =
  | 'planks'
  | 'ingots'
  | 'leather'
  | 'cut_stone'
  | 'cloth'
  | 'reinforced_frame'

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

export type MaterialKey = RawKey | RefinedKey | RareKey | RaidKey

export interface Material {
  key: MaterialKey
  name: string
  tier: MaterialTier
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
  description: string
}

// ---------------------------------------------------------------- equipment

export type EquipSlot = 'tool' | 'armor' | 'boots' | 'gloves' | 'weapon'
export type EquipTier = 'basic' | 'crafted' | 'nft'

/** What an item modifies. All are capped per slot, §8.1. */
export type StatKey =
  | 'yield'
  | 'tripReduction'
  | 'travelSpeed'
  | 'processingSpeed'
  | 'power'

export interface ItemDef {
  key: string
  name: string
  slot: EquipSlot
  tier: EquipTier
  stat: StatKey
  /** Fractional bonus, e.g. 0.06 = +6%. */
  value: number
  palette: Material['palette']
  /** Gold cost if sold by the NPC shop; absent for crafted/NFT items. */
  goldPrice?: number
  /** Crafting inputs; absent for shop items. */
  inputs?: Partial<Record<MaterialKey, number>>
  /** Station required to craft, §6. */
  station?: SettlementTier
  maxDurability: number
  description: string
}

export interface OwnedItem {
  id: string
  key: string
  durability: number
  equipped: boolean
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

// ---------------------------------------------------------------- tiles

export interface Tile {
  col: number
  row: number
  biome: Biome
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
