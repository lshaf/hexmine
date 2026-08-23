/**
 * Core domain types. These mirror the design doc (CLAUDE.md) section by section
 * and are shared by the world generator, the API DTOs and the UI.
 */

// ---------------------------------------------------------------- world

export type Biome = 'forest' | 'mountain' | 'plains' | 'badlands' | 'grassland'

/** Concentric ring layout, §5.2. Drives generation, not just cosmetics. */
export type Ring = 'outer' | 'mid' | 'inner' | 'center'

/**
 * §5.3 -- standing water, and the one kind of ground no verb answers to.
 *
 * A lake is a blob and a waterway is a line, which is the whole of the
 * difference: they carry the same rule and draw differently.
 */
export type WaterKind = 'lake' | 'river'

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

/**
 * §4 Tier 1 -- the alchemist's second stock. What LIVES on a kind of ground, as
 * against what grows on it: hunted with a bow, never gathered by hand.
 */
export type CritterKey =
  | 'glimmermoth'
  | 'rockmite'
  | 'dustleveret'
  | 'ashnewt'
  | 'fenlark'

/**
 * §9.5.8 Tier 1 -- what comes off a monster. Two families of five: a plate/hide
 * line for the smith and the armorer, an ichor/organ line for the consumable
 * bench. Biome-free, and dropped by nothing but a fight.
 */
export type SpoilKey =
  | 'cracked_carapace'
  | 'bone_plate'
  | 'scaled_hide'
  | 'warped_barb'
  | 'revenant_plate'
  | 'thin_ichor'
  | 'black_blood'
  | 'bile_sac'
  | 'ember_gland'
  | 'grave_heart'

export type MaterialKey =
  | ScrapKey
  | JunkKey
  | SpoilKey
  | RawKey
  | GradeRawKey
  | ReagentKey
  | CritterKey
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
  /** §4 -- alchemy stock that is an animal rather than a plant. */
  critter?: boolean
  /** §9.5.8 -- which half of a monster this is, and nothing else has it. */
  spoil?: 'plate' | 'ichor'
  /** §9.5.8 -- 1..5, the monster tier that gives it up. Grade 5 is the centre ring. */
  grade?: number
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
 * the two other things a character spends real time on, and `battle` (§9.5) is
 * the one that is not work at all -- the only place `power` and `defence` are
 * worth drinking for.
 */
export type BuffScope = SkillKey | 'travel' | 'processing' | 'battle'

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
   * §3.3 -- whether this rung may be **minted** and withdrawn from the game.
   * Not a property the item has while it is in a bag: everything in a bag is in
   * play, and everything in play can be destroyed (§8.2). Kept apart from
   * rarity on purpose -- `unique` is the strongest thing in the game and is
   * soulbound, because §2 forbids a grind→NFT faucet.
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
  /**
   * §9.5.4 -- which of the three battle jobs this weapon levels, and the shape
   * of its attack/defence pair. Weapons only: one slot holds all three
   * families, and the family you carry is your class.
   */
  family?: 'shield' | 'sword' | 'focus'
  /**
   * §9.5.4 -- FLAT combat numbers, and deliberately not the `power`/`defence`
   * StatKeys, which stay percentages under §8.1's +15% ceiling. A fight cannot
   * be decided by a swing that small, so it needs a base; these are it.
   */
  attack?: number
  defence?: number
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

/**
 * §8.5 -- a charge that has been drunk and is waiting on its action.
 *
 * There is no clock. It is armed until the action it names is taken, and taking
 * that action spends it -- so the only thing the client has to render is that it
 * is there, and what it is waiting for.
 */
export interface ActiveBuff {
  key: string
  stat: StatKey
  /**
   * §8.5 -- the action this charge applies to. `global` is the unscoped case,
   * which is what every buff was before potions were locked to one action.
   *
   * The HUD has to show it: two charges on the same stat are not a
   * contradiction, they are two different things you are better at.
   */
  scope: BuffScope | 'global'
  value: number
}

/**
 * §8.0.1 -- one rolled bonus line. Server-rolled and stored per instance, so two
 * of the same recipe are never the same object.
 */
export interface ItemOption {
  stat: StatKey
  value: number
  /**
   * §8.0.1 -- the one gathering line this roll pays on, and absent when it pays
   * everywhere. Worn gear is where it matters: armor works all five lines at
   * once, so a line it names is narrower than a flat roll and worth more for
   * being so. A tool needs no scope -- its slot already locks it to one line.
   */
  scope?: SkillKey
}

/**
 * §9.5.2 -- one of the eight. `attack` and `defence` are FLAT, and they are not
 * the percentage stats of the same name: §8.1's ceiling is +15%, and a fight
 * cannot be decided by a swing that small.
 */
/** §9.5.1 -- a pack on a hex: which monster, which bucket, and when it leaves. */
export interface Pack {
  key: string
  /** The time bucket it belongs to. The clear flag is keyed by it (§9.5.1). */
  bucket: number
  /** Unix ms it wanders off, in the caller's time base. */
  until: number
}

export interface Monster {
  key: string
  name: string
  /** 1..4, and the ring it is new on. A ring fights its own tier and the one outside. */
  tier: number
  /** What a player reads instead of a level: brute, carapace, swift. */
  profile: 'brute' | 'carapace' | 'swift'
  attack: number
  defence: number
  /** §9.5.6 -- a swift one blunts a weapon harder than its numbers suggest. */
  wearBias: number
  gold: [number, number]
  plate: SpoilKey
  ichor: SpoilKey
  /** The grade above its own, rarely. Grade 5 exists only off the centre ring. */
  rareSpoil?: SpoilKey
  description: string
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

export type JobKind = 'mining' | 'hunting' | 'processing' | 'craft'
export type JobStatus = 'active' | 'ready'

/**
 * A trip out on a hex, §5. Mining and hunting are the same job to everything
 * that reads one -- both pin the character to a hex until it is claimed or
 * dropped -- and they differ only in what the haul is drawn from (§4).
 */
export interface FieldJob {
  id: string
  kind: 'mining' | 'hunting'
  status: JobStatus
  col: number
  row: number
  /** §5.5 -- a herd is not one of the hex's two seats, so a hunt takes none. */
  slot: 0 | 1 | null
  material: MaterialKey
  quantity: number
  startedAt: number
  endsAt: number
  /** Skill the trip trains, so the client can show what it feeds. */
  skill: SkillKey
}

/**
 * Work left in a building, §6 and §8.4.
 *
 * Both kinds carry where they are, because a claim now needs you standing at
 * the bench that holds it: "ready" on its own would be a cruel word for
 * something waiting four days' walk away.
 */
export interface BenchJob {
  id: string
  status: JobStatus
  settlementId: string
  /** The bench's name and hex, so the ledger can say where to walk. */
  settlementName: string | null
  col: number | null
  row: number | null
  quantity: number
  startedAt: number
  endsAt: number
  /** Presence bonus accrues only while the player is at the settlement, §6.2. */
  presence: boolean
}

export interface ProcessingJob extends BenchJob {
  kind: 'processing'
  recipeKey: string
  input: MaterialKey
  output: MaterialKey
  skill: SkillKey
}

/**
 * §8.4 -- a thing on a bench. The output is an ITEM key rather than a material,
 * and there is no input to name: the materials went in when the work started.
 */
export interface CraftJob extends BenchJob {
  kind: 'craft'
  /** The item key being made. */
  output: string
  /** The bench job it will teach when it comes off: smith, armorer, alchemist. */
  skill: string
}

export type Job = FieldJob | ProcessingJob | CraftJob

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
  /** §5.3 -- standing water. Never mined, never gathered, never grazed. */
  water?: WaterKind
  /** Temporary herd marker, §5.5. */
  herdUntil?: number
  /**
   * §9.5.1 -- the pack standing here this bucket, if any. Derived like the
   * herd, so an unmet pack costs no storage; whether somebody has already
   * fought it is the one thing the seed cannot say, and that arrives with the
   * live-state payload instead.
   */
  pack?: Pack
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
