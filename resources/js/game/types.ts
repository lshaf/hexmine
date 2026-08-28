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

/**
 * §4 -- tier 0, and what a fight leaves that nobody wants. One per monster
 * tier, dropped every time, worth a gold and wanted by no recipe.
 *
 * Its own type rather than a sixth SpoilKey, because a spoil is a LADDER the
 * benches climb and these are not on it: filing them together would put a
 * chipped fang in the armorer's list of inputs.
 */
export type TrophyKey =
  | 'chipped_fang'
  | 'cracked_horn'
  | 'snapped_quill'
  | 'charred_sinew'

export type MaterialKey =
  | ScrapKey
  | JunkKey
  | SpoilKey
  | TrophyKey
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
  /** §9.5.8 -- 1..5, the monster tier that gives it up. Grade 5 is the center ring. */
  grade?: number
  /** Biome lock for tier 1 and tier 3. Raid + refined materials are unlocked. */
  biome?: Biome
  /** Accent color for the procedural icon system, §13.1. */
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
 * only counts on its own mines. `weapon` is raid combat and never gathers.
 */
export type GatherSlot = 'axe' | 'pickaxe' | 'bow' | 'hammer' | 'sickle'
export type EquipSlot = GatherSlot | 'armor' | 'boots' | 'gloves' | 'weapon'

/**
 * §8.1 -- the rarity ladder. Ordered weakest to strongest; rarity sets the power
 * ceiling, the color, and which station can make the thing.
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
  | 'travelSpeed'
  | 'processingSpeed'
  /** §7.4 -- the two battle stats. Dormant until raid combat exists, and
   *  clamped by the same STAT_CEILING as everything else when it does. */
  | 'power'
  | 'defense'

/**
 * §8.5 -- what a buff can be pointed at.
 *
 * The five gathering lines are the §7.2 skills, so a line-scoped buff lands on
 * exactly the mines that line already governs. `travel` and `processing` are
 * the two other things a character spends real time on, and `battle` (§9.5) is
 * the one that is not work at all -- the only place `power` and `defense` are
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
  /** Absent on a gathering tool, whose base is its solid `attack` instead. */
  stat?: StatKey
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
  /**
   * §8 -- the percentage the piece is FOR, and absent on a gathering tool.
   *
   * A tool's base is its solid `attack` (§7.3) and it has no percentage at all:
   * attack is how fast you work through a hex and yield is how big the haul is,
   * which are two questions and therefore two numbers.
   */
  value?: number
  /**
   * §9.5.4 -- which of the three battle jobs this weapon levels, and the shape
   * of its attack/defense pair. Weapons only: one slot holds all three
   * families, and the family you carry is your class.
   */
  family?: 'shield' | 'sword' | 'focus'
  /**
   * §9.5.4 -- FLAT combat numbers, and deliberately not the `power`/`defense`
   * StatKeys, which stay percentages under §8.1's +15% ceiling. A fight cannot
   * be decided by a swing that small, so it needs a base; these are it.
   */
  attack?: number
  defense?: number
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
  /**
   * §8.0.1 -- percentage or solid number, and absent means percentage so every
   * row already stored keeps its shape.
   *
   * `attack` and `defense` are FLAT (§9.5.4) and share their names with two
   * percentage stats, so without this "+2 defense" and "+2% defense" would be
   * the same row saying two different things. A flat line never carries a
   * scope: it has no gathering line to belong to, and on a tool the slot
   * already names one.
   */
  kind?: 'percent' | 'flat'
}

/**
 * §9.5.2 -- one of the eight. `attack` and `defense` are FLAT, and they are not
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
  defense: number
  /** §9.5.5 -- what it has to be worked through. Its half of the exchange. */
  hp: number
  /** §9.5.6 -- a swift one blunts a weapon harder than its numbers suggest. */
  wearBias: number
  gold: [number, number]
  plate: SpoilKey
  ichor: SpoilKey
  /** The grade above its own, rarely. Grade 5 exists only off the center ring. */
  rareSpoil?: SpoilKey
  description: string
}

export interface OwnedItem {
  id: string
  key: string
  durability: number
  /**
   * §7.4.3 -- THIS piece's ceiling, which is not always the catalog's.
   *
   * `craftDurability` raises the max of what a Smith makes, so two copies of one
   * recipe can differ. Everything that measures wear, prices a resale or offers
   * a repair has to read it here; the catalog's figure is the shelf's, not the
   * object's.
   */
  maxDurability: number
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

export type JobKind = 'mining' | 'processing' | 'craft' | 'battle'
export type JobStatus = 'active' | 'ready'

/**
 * A mine out on a hex, §5. A dig and a bare-handed gather are the same job to
 * everything that reads one -- both pin the character to a hex until it is
 * claimed or dropped -- and they differ only in what the haul is drawn from
 * (§4).
 */
export interface FieldJob {
  id: string
  kind: 'mining'
  status: JobStatus
  col: number
  row: number
  slot: 0 | 1 | null
  material: MaterialKey
  quantity: number
  startedAt: number
  endsAt: number
  /** Skill the mine trains, so the client can show what it feeds. */
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

/**
 * §9.5.5 -- a fight under way.
 *
 * On a hex like a mine and pinning you the same way, and it carries the whole
 * exchange: the fight is settled the instant you close (§9.5.5), and what runs
 * on screen is a REPLAY of it rather than a countdown to it.
 *
 * Handing the client the outcome early is deliberate and costs nothing. A fight
 * cannot be abandoned (§9.5.3), so there is no decision left for foreknowledge
 * to spoil -- reading ahead buys a few seconds of knowing.
 */
export interface BattleRound {
  /** What you got through this round. */
  hit: number
  /** What it got back. Zero on the round that put it down. */
  back: number
  /** Your pool after the round, and its HP. */
  hp: number
  foe: number
  /**
   * §9.5.9 -- the skill that went off this round, if one did. At most one a
   * round, and never in the opening rounds: they all start on cooldown.
   */
  skill?: string
  /**
   * What it did. Only the field its skill actually sets is present, which is
   * what lets the modal say one true sentence instead of a list of zeroes.
   */
  stunned?: number
  burn?: number
  extra?: number
  riposte?: number
  sunder?: number
  kept?: number
  released?: number
  toll?: number
  /** Its answer never came, because it was still finding its feet. */
  held?: boolean
}

export interface BattleJob {
  id: string
  kind: 'battle'
  status: JobStatus
  col: number
  row: number
  slot: null
  quantity: number
  startedAt: number
  endsAt: number
  /** The battle job it will teach, or a stand-in when nothing is armed. */
  skill: string
  /** What is being fought, for the glyph and the name. */
  monster: string | null
  /** §9.5.5 -- the two pools the bars start full at. */
  pool: number
  monsterHp: number
  /** How long one round is drawn for. Real milliseconds, never scaled. */
  roundMs: number
  /** The exchange, in order. Empty only for a job stored before this existed. */
  log: BattleRound[]
  /**
   * §9.5.9 -- the three that took the fight, as they were armed when it began.
   *
   * Stored with the roll for the same reason the roll is: the replay has to
   * draw the cooldowns the exchange actually ran on, not the ones the character
   * happens to have when they open the tab.
   */
  skills: Array<{
    key: string
    name: string
    glyph: string
    cooldown: number
    description: string
    effect: string
    stats: Array<{ label: string; value: string }>
  }>
}

export type Job = FieldJob | ProcessingJob | CraftJob | BattleJob

// --------------------------------------------------------------- traveling

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
  /**
   * §9.5.3 -- where the road ACTUALLY ends, which is not always where it was
   * pointed. A pack ahead stops the journey on its hex.
   *
   * Server-computed and re-decided on every read (§16). The client counts down
   * to `stopAt` rather than `endsAt`, because counting to the destination meant
   * visibly arriving at the village and then snapping back down the road when
   * the correction landed.
   *
   * A prediction, not a promise: whoever clears that pack first moves the
   * answer further along. Being wrong is self-correcting -- the client asks
   * early, is told it is still walking, and is handed the new stop.
   */
  stopHex: number
  stopCol: number
  stopRow: number
  stopAt: number
  /** The monster standing in the way, or null when the road is clear through. */
  /**
   * §5.6 -- THAT the road is cut short, never by what. Sight on the road is
   * zero, so the name of whatever is waiting is not the walker's to have until
   * they are standing in front of it. Nothing draws this; it is here so the
   * shape of the payload is honest.
   */
  blocked: boolean
}

// ---------------------------------------------------------------- tiles

export interface Tile {
  col: number
  row: number
  biome: Biome
  /** §5.3 -- which of the biome's four grades this ground is. */
  variant: VariantKey
  ring: Ring
  /** Undefined wherever there is no seam: dead ground, water, a town, §5.2. */
  material?: MaterialKey
  /**
   * §5.2 -- dead ground: no seam, and never will have one.
   *
   * Told apart from the other reasons `material` is missing, because it is the
   * only one that looks like ordinary country. A lake and a town announce
   * themselves; this wears the biome's own colour on purpose, so that finding
   * workable ground is something you do by walking rather than by reading the
   * map from four days away.
   */
  dead: boolean
  /** §7.3 -- how much work this hex is. The world rolls HP and nothing else. */
  hp: number
  /** Units yielded per mine before bonuses. */
  baseYield: number
  /**
   * §5.1 -- how many hauls this hex holds before it is worked out.
   *
   * Derived from `baseYield`, inversely: a rich hex is emptied in six mines and
   * a poor one takes ten. Not a chance -- the count is knowable, which is what
   * makes "is this seam worth coming back to" a decision rather than a guess.
   */
  extractions: number
  /** Both slots full closes the tile to everyone else, §5.1. */
  slotsUsed: number
  /**
   * §5.1 -- how many people are at work on this hex, whatever they are doing:
   * mining, gathering or fighting.
   *
   * Never fewer than `slotsUsed` and often more, because only mining takes one
   * of the two seats. A hex with a fight on it is busy and still open.
   */
  workers: number
  /** §5.1 -- hauls already taken off this hex, by anybody. Shared. */
  taken: number
  /** Unix ms when a depleted tile regrows; 0 when live. §5.1 */
  regrowsAt: number
  settlement?: Settlement
  /** Dungeon entrance, §9.1. Exactly five exist, in the capital ring. */
  dungeon?: { key: string; name: string }
  /** §5.3 -- standing water. Never mined, and never gathered. */
  water?: WaterKind
  /**
   * §5.7 -- a pocket: this ground is briefly worth more to work than usual.
   *
   * Derived like a pack, so a pocket nobody has walked onto costs no storage.
   * It belongs to no line -- it pays into whatever the hex already trains,
   * which is why it can appear on any workable ground.
   */
  pocketUntil?: number
  /**
   * §9.5.1 -- the pack standing here this bucket, if any. Derived like the
   * pocket, so an unmet pack costs no storage; whether somebody has already
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
