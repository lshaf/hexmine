/**
 * Static game data: the 20 materials (§4) plus the 5 scrap (§4.0), 5 skill lines
 * (§7.2), the processing recipes (§6) and the item catalog (§8.3). This is the
 * data a Laravel seeder will eventually own -- one file makes the port mechanical.
 */
import { ECONOMY, EQUIPMENT } from './balance'
import { CONSUMABLES, JUNK, REAGENTS } from './alchemy'
import { COMPONENTS } from './components'
import { TOP_TIER } from './toptier'
import { CRITTERS } from './critters'
import { SPOILS } from './spoils'
import { BATTLE_GEAR } from './battlegear'
import {
  BIOME_VARIANTS,
  VARIANT_PROCESSING,
  VARIANT_RAW,
  VARIANT_REFINED,
  VARIANT_SKILL,
} from './variants'
import type {
  Biome,
  EquipSlot,
  GatherSlot,
  BuffScope,
  ItemDef,
  JunkKey,
  Material,
  MaterialKey,
  RareKey,
  RawKey,
  ReagentKey,
  ComponentKey,
  CritterKey,
  GradeRawKey,
  GradeRefinedKey,
  Recipe,
  Rarity,
  Ring,
  ScrapKey,
  SettlementTier,
  SpoilKey,
  Skill,
  SkillKey,
  StatKey,
} from './types'

// ------------------------------------------------------------- materials §4

export const MATERIALS: Record<MaterialKey, Material> = {
  // §4 -- generated, see scripts/gen_alchemy.py and gen_components.py.
  // Reagents and craft components are Tier 1 raw, junk is Tier 0; none of them
  // is typed here so the catalogs cannot drift.
  ...Object.fromEntries(
    [
      ...REAGENTS,
      ...COMPONENTS,
      ...CRITTERS,
      ...SPOILS,
      ...JUNK,
      ...VARIANT_RAW,
      ...VARIANT_REFINED,
    ].map((m) => [m.key, m]),
  ) as Record<
    | ReagentKey
    | ComponentKey
    | CritterKey
    | SpoilKey
    | JunkKey
    | GradeRawKey
    | GradeRefinedKey,
    Material
  >,

  // Tier 0 -- Scrap, §4.0. What bare hands bring back when you have no tool for
  // the line. Sells for a copper, feeds no recipe, and exists only to make the
  // first tool obviously worth buying.
  branch: {
    key: 'branch', name: 'Branch', tier: 0, biome: 'forest', palette: 'wood',
    npcPrice: 1, description: 'Snapped off by hand. The trader gives you a copper and looks away.',
  },
  ore_chips: {
    key: 'ore_chips', name: 'Ore Chips', tier: 0, biome: 'mountain', palette: 'iron',
    npcPrice: 1, description: 'Loose flakes off the seam face. Barely worth carrying down.',
  },
  torn_hide: {
    key: 'torn_hide', name: 'Torn Hide', tier: 0, biome: 'plains', palette: 'pelt',
    npcPrice: 1, description: 'Scavenged, not hunted. Half of it is unusable.',
  },
  gravel: {
    key: 'gravel', name: 'Gravel', tier: 0, biome: 'badlands', palette: 'stone',
    npcPrice: 1, description: 'Kicked loose from the scree. Nobody dresses this into anything.',
  },
  chaff: {
    key: 'chaff', name: 'Chaff', tier: 0, biome: 'grassland', palette: 'fiber',
    npcPrice: 1, description: 'Pulled up by the root and mostly broken. The trader takes it by the sack.',
  },

  // Tier 1 -- Raw, biome-locked, and the bulk of what fills a bag (§7.6)
  wood: {
    key: 'wood', name: 'Wood', tier: 1, biome: 'forest', palette: 'wood',
    npcPrice: 2, description: 'Green timber from the forest belt.',
  },
  iron_ore: {
    key: 'iron_ore', name: 'Iron Ore', tier: 1, biome: 'mountain', palette: 'iron',
    npcPrice: 3, description: 'Raw ore hacked from mountain seams.',
  },
  pelt: {
    key: 'pelt', name: 'Pelt', tier: 1, biome: 'plains', palette: 'pelt',
    npcPrice: 3, description: 'Rough hide taken from plains herds.',
  },
  stone: {
    key: 'stone', name: 'Stone', tier: 1, biome: 'badlands', palette: 'stone',
    npcPrice: 2, description: 'Blasted rubble from the badlands.',
  },
  fiber: {
    key: 'fiber', name: 'Fiber', tier: 1, biome: 'grassland', palette: 'fiber',
    npcPrice: 2, description: 'Tough grassland stalks, retted for spinning.',
  },

  // Tier 2 -- Refined
  planks: {
    key: 'planks', name: 'Planks', tier: 2, palette: 'wood',
    npcPrice: 7, description: 'Sawn and seasoned. The backbone of crafting.',
  },
  ingots: {
    key: 'ingots', name: 'Ingots', tier: 2, palette: 'iron',
    npcPrice: 9, description: 'Smelted iron, poured into bar moulds.',
  },
  leather: {
    key: 'leather', name: 'Leather', tier: 2, palette: 'pelt',
    npcPrice: 8, description: 'Tanned hide, supple enough to work.',
  },
  cut_stone: {
    key: 'cut_stone', name: 'Cut Stone', tier: 2, palette: 'stone',
    npcPrice: 7, description: 'Dressed blocks, square and true.',
  },
  cloth: {
    key: 'cloth', name: 'Cloth', tier: 2, palette: 'fiber',
    npcPrice: 6, description: 'Spun and woven fiber bolts.',
  },
  reinforced_frame: {
    key: 'reinforced_frame', name: 'Reinforced Frame', tier: 2, palette: 'iron',
    npcPrice: 26, description: 'Planks banded with iron. A cross-line combo.',
  },

  // Tier 3 -- Rare, PvP-zone tiles only, capped per wallet
  ironwood: {
    key: 'ironwood', name: 'Ironwood', tier: 3, biome: 'forest', palette: 'wood',
    npcPrice: 0, walletCap: ECONOMY.rareWalletCap,
    description: 'Heartwood so dense it turns an axe. Contested ring only.',
  },
  mythril_ore: {
    key: 'mythril_ore', name: 'Mythril Ore', tier: 3, biome: 'mountain', palette: 'iron',
    npcPrice: 0, walletCap: ECONOMY.rareWalletCap,
    description: 'A pale seam that hums under the pick.',
  },
  beastfang_hide: {
    key: 'beastfang_hide', name: 'Beastfang Hide', tier: 3, biome: 'plains', palette: 'pelt',
    npcPrice: 0, walletCap: ECONOMY.rareWalletCap,
    description: 'Taken off something that fought back.',
  },
  obsidian_shard: {
    key: 'obsidian_shard', name: 'Obsidian Shard', tier: 3, biome: 'badlands', palette: 'stone',
    npcPrice: 0, walletCap: ECONOMY.rareWalletCap,
    description: 'Volcanic glass, edged sharper than steel.',
  },
  silkweave_fiber: {
    key: 'silkweave_fiber', name: 'Silkweave Fiber', tier: 3, biome: 'grassland', palette: 'fiber',
    npcPrice: 0, walletCap: ECONOMY.rareWalletCap,
    description: 'Spun by something in the tall grass. Nobody asks what.',
  },

  // Tier 4 -- Raid materials, dungeon-sourced
  essence: {
    key: 'essence', name: 'Essence', tier: 4, palette: 'raid',
    npcPrice: 0, description: 'Common residue. Drops from every monster tier.',
  },
  shard_verdant: {
    key: 'shard_verdant', name: 'Verdant Shard', tier: 4, palette: 'raid',
    npcPrice: 0, description: 'Rootvault signature drop.',
  },
  shard_ferrous: {
    key: 'shard_ferrous', name: 'Ferrous Shard', tier: 4, palette: 'raid',
    npcPrice: 0, description: 'Deepshaft signature drop.',
  },
  shard_sanguine: {
    key: 'shard_sanguine', name: 'Sanguine Shard', tier: 4, palette: 'raid',
    npcPrice: 0, description: 'Beastwarren signature drop.',
  },
  shard_cinder: {
    key: 'shard_cinder', name: 'Cinder Shard', tier: 4, palette: 'raid',
    npcPrice: 0, description: 'Ashpit signature drop.',
  },
  shard_zephyr: {
    key: 'shard_zephyr', name: 'Zephyr Shard', tier: 4, palette: 'raid',
    npcPrice: 0, description: 'Windhollow signature drop.',
  },
  relic: {
    key: 'relic', name: 'Relic', tier: 4, palette: 'raid',
    npcPrice: 0, description: 'Deep-floor rarity. Pity-timer protected.',
  },
  core: {
    key: 'core', name: 'Core', tier: 4, palette: 'raid',
    npcPrice: 0, description: 'Boss-only. Gates the best equipment tier.',
  },
}

/** §5.2 -- what each concentric ring is called in the UI. */
export const RING_LABEL: Record<Ring, string> = {
  outer: 'Outer rim',
  mid: 'Middle ring',
  inner: 'Contested ring',
  center: 'Capital ring',
}

// ---------------------------------------------------------------- skills §7.2

export const SKILL_LIST: Skill[] = [
  {
    key: 'woodcutting', name: 'Woodcutting', material: 'wood', rareMaterial: 'ironwood',
    scrapMaterial: 'branch',
    description: 'Faster mining and better yield in forest hexes.',
  },
  {
    key: 'mining', name: 'Mining', material: 'iron_ore', rareMaterial: 'mythril_ore',
    scrapMaterial: 'ore_chips',
    description: 'Faster mining and better yield in mountain hexes.',
  },
  {
    key: 'hunting', name: 'Hunting', material: 'pelt', rareMaterial: 'beastfang_hide',
    scrapMaterial: 'torn_hide',
    description: 'Faster mining and better yield on plains and tundra.',
  },
  {
    key: 'quarrying', name: 'Quarrying', material: 'stone', rareMaterial: 'obsidian_shard',
    scrapMaterial: 'gravel',
    description: 'Faster mining and better yield in the badlands.',
  },
  {
    key: 'harvesting', name: 'Harvesting', material: 'fiber', rareMaterial: 'silkweave_fiber',
    scrapMaterial: 'chaff',
    description: 'Faster mining and better yield in grassland hexes.',
  },
]

export const SKILL_BY_KEY: Record<SkillKey, Skill> = Object.fromEntries(
  SKILL_LIST.map((s) => [s.key, s]),
) as Record<SkillKey, Skill>

/** Biome -> the raw material it yields, §4 tier 1. */
export const BIOME_MATERIAL: Record<Biome, RawKey> = {
  forest: 'wood',
  mountain: 'iron_ore',
  plains: 'pelt',
  badlands: 'stone',
  grassland: 'fiber',
}

/**
 * Biome -> scrap, §4.0. What the hex gives up to bare hands: worked without the
 * line's tool, a hex yields this instead of its real material. Same haul size, a
 * fraction of the worth, and no recipe will take it.
 */
export const BIOME_SCRAP: Record<Biome, ScrapKey> = {
  forest: 'branch',
  mountain: 'ore_chips',
  plains: 'torn_hide',
  badlands: 'gravel',
  grassland: 'chaff',
}

export const isScrap = (key: MaterialKey): boolean =>
  (Object.values(BIOME_SCRAP) as MaterialKey[]).includes(key)

/** Biome -> its rare variant, spawned in the contested inner ring, §5.3. */
export const BIOME_RARE: Record<Biome, RareKey> = {
  forest: 'ironwood',
  mountain: 'mythril_ore',
  plains: 'beastfang_hide',
  badlands: 'obsidian_shard',
  grassland: 'silkweave_fiber',
}

export const skillForMaterial = (key: MaterialKey): SkillKey => {
  // §5.3 -- a grade belongs to the same line its base raw does, or the fallback
  // below would credit a hematite haul to woodcutting.
  if (VARIANT_SKILL[key]) return VARIANT_SKILL[key]

  const found = SKILL_LIST.find(
    (s) => s.material === key || s.rareMaterial === key || s.scrapMaterial === key,
  )
  return found?.key ?? 'woodcutting'
}

// ------------------------------------------------------------- recipes §4/§6

export const RECIPES: Recipe[] = [
  {
    key: 'planks', name: 'Saw Planks', input: 'wood', inputQty: 3,
    output: 'planks', outputQty: 1, baseSeconds: 12 * 60, skill: 'woodcutting',
  },
  {
    key: 'ingots', name: 'Smelt Ingots', input: 'iron_ore', inputQty: 3,
    output: 'ingots', outputQty: 1, baseSeconds: 15 * 60, skill: 'mining',
  },
  {
    key: 'leather', name: 'Tan Leather', input: 'pelt', inputQty: 3,
    output: 'leather', outputQty: 1, baseSeconds: 13 * 60, skill: 'hunting',
  },
  {
    key: 'cut_stone', name: 'Dress Stone', input: 'stone', inputQty: 3,
    output: 'cut_stone', outputQty: 1, baseSeconds: 12 * 60, skill: 'quarrying',
  },
  {
    key: 'cloth', name: 'Weave Cloth', input: 'fiber', inputQty: 3,
    output: 'cloth', outputQty: 1, baseSeconds: 11 * 60, skill: 'harvesting',
  },
  {
    key: 'reinforced_frame', name: 'Band a Frame', input: 'planks', inputQty: 2,
    secondInput: 'ingots', secondInputQty: 2, output: 'reinforced_frame',
    outputQty: 1, baseSeconds: 26 * 60, skill: 'mining',
  },

  // §5.3 -- one line per grade, generated alongside the variants themselves so
  // the bench list and the ground it comes off cannot disagree.
  ...VARIANT_PROCESSING,
]

export const RECIPE_BY_KEY: Record<string, Recipe> = Object.fromEntries(
  RECIPES.map((r) => [r.key, r]),
)

/** Which recipes a settlement can run, given the lines it hosts, §6. */
export const recipesForLines = (lines: SkillKey[]): Recipe[] =>
  RECIPES.filter((r) => lines.includes(r.skill))

/** Human-readable names for the stats items modify. The raw keys are camelCase
 *  and must never reach the screen. */
/**
 * §8.5 -- what a buff's action is called on the shelf.
 *
 * A potion is bought for one thing you do, so the bag has to name that thing:
 * "+3% yield when woodcutting" is a different offer from "+3% yield", and the
 * player is choosing between twelve of them a rung.
 */
export const SCOPE_LABEL: Record<BuffScope | 'global', string> = {
  woodcutting: 'when woodcutting',
  mining: 'when mining',
  hunting: 'when hunting',
  quarrying: 'when quarrying',
  harvesting: 'when harvesting',
  travel: 'on the road',
  processing: 'at the bench',
  battle: 'in a fight',
  global: 'everywhere',
}

/**
 * §8.5 -- the same action named as the thing you do, rather than as a place a
 * bonus applies. A charge is spent by one of these, so anything counting them
 * ("one woodcutting trip", "one journey") needs the noun, not the clause.
 */
export const SCOPE_ACTION: Record<BuffScope | 'global', string> = {
  woodcutting: 'woodcutting trip',
  mining: 'mining trip',
  hunting: 'hunt',
  quarrying: 'quarrying trip',
  harvesting: 'harvesting trip',
  travel: 'journey',
  processing: 'bench run',
  battle: 'fight',
  global: 'action',
}

export const STAT_LABEL: Record<StatKey, string> = {
  yield: 'yield',
  // The stat is stored as a reduction and said with a minus, so the words are
  // the plain thing it moves: "-3% trip time" rather than "+3% off mine time".
  // Every screen goes through statLine(), which owns that sign.
  tripReduction: 'trip time',
  travelSpeed: 'travel speed',
  processingSpeed: 'processing speed',
  power: 'power',
  defence: 'defence',
}

/**
 * The same stats said against one gathering line, §8.
 *
 * Shorter than the unscoped label rather than longer: a line name already says
 * that this is a trip, so "mining time" beats "mining trip time".
 */
export const LINE_STAT_LABEL: Partial<Record<StatKey, string>> = {
  yield: 'yield',
  tripReduction: 'time',
}

/**
 * Gathering tool slots, §8. One implement per skill line -- an axe is no use on
 * a seam and a bow is no use on a tree, so each line has its own slot and its
 * own ladder. A tool contributes its stat *only* on trips for its own line, and
 * only that tool takes durability for the trip.
 *
 * `weapon` is deliberately absent: that slot is raid combat, and combat gear
 * must never be able to stand in for a gathering tool.
 */
export const TOOL_SLOT_SKILL: Record<GatherSlot, SkillKey> = {
  axe: 'woodcutting',
  pickaxe: 'mining',
  bow: 'hunting',
  hammer: 'quarrying',
  sickle: 'harvesting',
}

/** The skill a gathering slot serves, or null for gear that works anywhere. */
export const skillForSlot = (slot: EquipSlot): SkillKey | null =>
  (TOOL_SLOT_SKILL as Partial<Record<EquipSlot, SkillKey>>)[slot] ?? null

/** The slot a skill line draws its tool from. */
export const slotForSkill = (skill: SkillKey): GatherSlot =>
  (Object.keys(TOOL_SLOT_SKILL) as GatherSlot[]).find(
    (slot) => TOOL_SLOT_SKILL[slot] === skill,
  )!

/**
 * §8.1 -- the rarity ladder, weakest first. Anything that sorts or gates by
 * rarity compares `RARITY_RANK`, never the string.
 */
/**
 * §9.5.4 -- which battle job a weapon family levels. Mirrors
 * Catalog::BATTLE_JOB_FOR_FAMILY.
 */
export const BATTLE_JOB_FOR_FAMILY: Record<string, string> = {
  shield: 'shieldbearer',
  sword: 'swordhand',
  focus: 'runecaster',
}

export const RARITIES: Rarity[] = [
  'common',
  'uncommon',
  'rare',
  'epic',
  'legendary',
  'unique',
]

export const RARITY_RANK: Record<Rarity, number> = Object.fromEntries(
  RARITIES.map((r, i) => [r, i]),
) as Record<Rarity, number>

/**
 * §8.4 -- the three craft benches. Derived from the slot, never stored: a
 * thing's category is implied by where it is worn, and a second field would only
 * be somewhere for the two to disagree. Consumables have no slot at all, which
 * is exactly what makes them the third category.
 */
export type Category = 'weapon' | 'armor' | 'consumable'

export const CATEGORIES: Category[] = ['weapon', 'armor', 'consumable']

export const CATEGORY_LABEL: Record<Category, string> = {
  weapon: 'Tools & weapons',
  armor: 'Armor',
  consumable: 'Consumables',
}

export const categoryForSlot = (slot?: EquipSlot): Category => {
  if (!slot) return 'consumable'
  return slot === 'armor' || slot === 'boots' || slot === 'gloves' ? 'armor' : 'weapon'
}

/** §8.0 -- can this workbench reach this rung? Mirrors Balance::stationReaches. */
export const stationReaches = (tier: SettlementTier, rarity: Rarity): boolean =>
  RARITY_RANK[rarity] <= RARITY_RANK[EQUIPMENT.stationRarityCap[tier] as Rarity]

/** The smallest station that can make this rarity, or null when none can. */
export const stationForRarity = (rarity: Rarity): string | null =>
  Object.entries(EQUIPMENT.stationRarityCap).find(
    ([, reach]) => RARITY_RANK[rarity] <= RARITY_RANK[reach as Rarity],
  )?.[0] ?? null

export const RARITY_LABEL: Record<Rarity, string> = {
  common: 'Common',
  uncommon: 'Uncommon',
  rare: 'Rare',
  epic: 'Epic',
  legendary: 'Legendary',
  unique: 'Unique',
}

/**
 * §5.3 -- the rung a material sits on, which the ground already decided.
 *
 * The variant ladder answers it for everything the map gives up: base ground is
 * common, the two better grades are uncommon and rare, and contested ground is
 * the Tier 3 epic. Refining carries that rung across rather than starting a
 * second ladder -- Bentwood is as rare as the Heartoak it was steamed out of --
 * and the raid tier sits above all of it. Everything left is common: scrap,
 * junk, the three bench stocks and the base refined line.
 *
 * Derived, never stored, for the reason §8.4 gives about craft category. A
 * field would only be somewhere for the catalog and the ground to disagree.
 */
const RARITY_BY_MATERIAL: Record<string, Rarity> = {}

for (const variants of Object.values(BIOME_VARIANTS)) {
  for (const variant of variants) RARITY_BY_MATERIAL[variant.material] = variant.grade
}

for (const recipe of RECIPES) {
  RARITY_BY_MATERIAL[recipe.output] = RARITY_BY_MATERIAL[recipe.input] ?? 'common'
}

/** §9.5.8 -- a spoil's grade is its rung: the tier of the thing that carried it. */
const SPOIL_RARITY: Rarity[] = ['common', 'uncommon', 'rare', 'epic', 'legendary']

export const materialRarity = (mat: Material): Rarity => {
  if (mat.spoil) return SPOIL_RARITY[(mat.grade ?? 1) - 1] ?? 'common'

  return mat.tier === 4 ? 'legendary' : (RARITY_BY_MATERIAL[mat.key] ?? 'common')
}

/** Slot names for the screen. The raw keys are lowercase and never reach it. */
export const SLOT_LABEL: Record<EquipSlot, string> = {
  axe: 'Axe',
  pickaxe: 'Pickaxe',
  bow: 'Bow',
  hammer: 'Hammer',
  sickle: 'Sickle',
  armor: 'Armor',
  boots: 'Boots',
  gloves: 'Gloves',
  weapon: 'Weapon',
}

// ------------------------------------------------------------- items §8.3

/**
 * Every gathering line carries the same five-step ladder -- village basic, city
 * basic, crafted starter, crafted, NFT -- so no line is quietly weaker than
 * another. The specialisation §7.2 asks for comes from the skill point cap,
 * never from one line having better tools available than the rest.
 */
export const ITEMS: ItemDef[] = [
  // §8.5 -- seventy action-scoped potions, generated alongside the PHP copy.
  ...CONSUMABLES,



  // §9.5.4 -- the six combat groups, three grades at every rung. Generated
  // alongside the PHP copy; see scripts/gen_battlegear.py.
  ...BATTLE_GEAR,
  // §8.0 -- the two rungs above epic, generated so the ladder reads whole.
  // Legendary needs a guild hall and there are none; unique has no bench at
  // all and drops soulbound. See scripts/gen_toptier.py.
  ...TOP_TIER,

  // -- Basic: gold shop, +3-5%, §8.
  //    `station` is the smallest settlement that stocks the item -- villages
  //    carry the basics, better gear is a reason to walk to a city.
  {
    key: 'stone_axe', name: 'Stone Axe', slot: 'axe', rarity: 'common', tradeable: false, stat: 'yield',
    value: 0.02, palette: 'stone', goldPrice: 20, maxDurability: 40, station: 'village',
    description: 'A chipped edge lashed to a handle. Better than bare hands.',
  },
  {
    key: 'chipped_pick', name: 'Chipped Pick', slot: 'pickaxe', rarity: 'common', tradeable: false, stat: 'yield',
    value: 0.02, palette: 'stone', goldPrice: 22, maxDurability: 40, station: 'village',
    description: 'Second-hand, and shorter than it started. Still bites ore.',
  },
  {
    key: 'crude_bow', name: 'Crude Bow', slot: 'bow', rarity: 'common', tradeable: false, stat: 'yield',
    value: 0.02, palette: 'wood', goldPrice: 24, maxDurability: 40, station: 'village',
    description: 'Green stave, gut string. Close range or nothing.',
  },
  {
    key: 'stone_mallet', name: 'Stone Mallet', slot: 'hammer', rarity: 'common', tradeable: false, stat: 'yield',
    value: 0.02, palette: 'stone', goldPrice: 20, maxDurability: 40, station: 'village',
    description: 'A rock on a stick. It still splits badlands shale.',
  },
  {
    key: 'bent_sickle', name: 'Bent Sickle', slot: 'sickle', rarity: 'common', tradeable: false, stat: 'yield',
    value: 0.02, palette: 'fiber', goldPrice: 18, maxDurability: 40, station: 'village',
    description: 'Someone straightened it once. It did not take.',
  },
  {
    key: 'iron_hatchet', name: 'Iron Hatchet', slot: 'axe', rarity: 'uncommon', tradeable: false, stat: 'yield',
    value: 0.05, palette: 'iron', goldPrice: 90, maxDurability: 70, station: 'city',
    description: 'Shop-grade steel. Reliable, unremarkable.',
  },
  {
    key: 'miners_pick', name: "Miner's Pick", slot: 'pickaxe', rarity: 'uncommon', tradeable: false, stat: 'yield',
    value: 0.05, palette: 'iron', goldPrice: 95, maxDurability: 70, station: 'city',
    description: 'Guild pattern, guild price. Every seam in the range has met one.',
  },
  {
    key: 'recurve_bow', name: 'Recurve Bow', slot: 'bow', rarity: 'uncommon', tradeable: false, stat: 'yield',
    value: 0.05, palette: 'pelt', goldPrice: 95, maxDurability: 70, station: 'city',
    description: 'Backed and glued. Drops a plains buck without the chase.',
  },
  {
    key: 'iron_sledge', name: 'Iron Sledge', slot: 'hammer', rarity: 'uncommon', tradeable: false, stat: 'yield',
    value: 0.05, palette: 'iron', goldPrice: 90, maxDurability: 70, station: 'city',
    description: 'Heavy enough that the stone does most of the arguing.',
  },
  {
    key: 'steel_sickle', name: 'Steel Sickle', slot: 'sickle', rarity: 'uncommon', tradeable: false, stat: 'yield',
    value: 0.05, palette: 'iron', goldPrice: 85, maxDurability: 70, station: 'city',
    description: 'Holds an edge through a full field, then wants a stone.',
  },
  {
    key: 'travel_cloak', name: 'Travel Cloak', slot: 'armor', rarity: 'common', tradeable: false,
    stat: 'tripReduction', value: 0.02, palette: 'fiber', goldPrice: 16, maxDurability: 60,
    station: 'village',
    description: 'Keeps the weather off, and a little off the clock on every hex.',
  },
  {
    key: 'hide_shoes', name: 'Hide Shoes', slot: 'boots', rarity: 'uncommon', tradeable: false,
    stat: 'travelSpeed', value: 0.04, palette: 'pelt', goldPrice: 55, maxDurability: 50,
    station: 'city',
    description: 'Soft-soled and quiet. Not built for the badlands.',
  },

  // -- Crafted starter: own raw + one refined, +4%, §12 step 7.
  //    Two kinds here, and one more at every rung above: 2, 3, 4, 5.
  //    The first thing a player makes on a line: cheap, short-lived, and
  //    deliberately weaker than the city shop tool. It is what you can build
  //    before you can afford to buy.
  {
    key: 'hewn_axe', name: 'Hewn Axe', slot: 'axe', rarity: 'common', tradeable: false, stat: 'yield',
    value: 0.03, palette: 'wood', station: 'village', maxDurability: 60,
    inputs: { wood: 6, planks: 2, heartknot: 2 },
    description: 'Your first real tool. It will not last, but it will teach.',
  },
  {
    key: 'wood_pickaxe', name: 'Wood Pickaxe', slot: 'pickaxe', rarity: 'common', tradeable: false, stat: 'yield',
    value: 0.03, palette: 'wood', station: 'village', maxDurability: 60,
    inputs: { wood: 6, planks: 2, flux_salt: 2 },
    description: 'Wood against rock. It lasts exactly as long as you would expect.',
  },
  {
    key: 'shortbow', name: 'Shortbow', slot: 'bow', rarity: 'common', tradeable: false, stat: 'yield',
    value: 0.03, palette: 'wood', station: 'village', maxDurability: 60,
    inputs: { wood: 6, cloth: 2, horn: 2 },
    description: 'Straight stave, woven string. Quiet, and quick to redraw.',
  },
  {
    key: 'stone_maul', name: 'Stone Maul', slot: 'hammer', rarity: 'common', tradeable: false, stat: 'yield',
    value: 0.03, palette: 'stone', station: 'village', maxDurability: 60,
    inputs: { stone: 6, cut_stone: 2, whetgrit: 2 },
    description: 'Dressed head, seated cold. Stone breaks stone.',
  },
  {
    key: 'reed_sickle', name: 'Reed Sickle', slot: 'sickle', rarity: 'common', tradeable: false, stat: 'yield',
    value: 0.03, palette: 'fiber', station: 'village', maxDurability: 60,
    inputs: { fiber: 6, cloth: 2, quench_reed: 2 },
    description: 'Bound at the grip so it stops turning in a wet hand.',
  },

  // -- Crafted: raw + own refined + a partner refined, +6-8%, §8.3
  {
    key: 'ironbound_axe', name: 'Ironbound Axe', slot: 'axe', rarity: 'uncommon', tradeable: false, stat: 'yield',
    value: 0.05, palette: 'iron', station: 'city', maxDurability: 120,
    inputs: { hardwood: 6, beams: 3, ingots: 2, heartknot: 3 },
    description: 'Wedged head, banded eye. Fells clean and comes back out.',
  },
  {
    key: 'iron_pickaxe', name: 'Iron Pickaxe', slot: 'pickaxe', rarity: 'uncommon', tradeable: false, stat: 'yield',
    value: 0.05, palette: 'iron', station: 'city', maxDurability: 120,
    inputs: { hematite: 6, steel_ingots: 3, planks: 2, flux_salt: 3 },
    description: 'Balanced head, seasoned haft. The workhorse tool.',
  },
  {
    key: 'sinew_longbow', name: 'Sinew Longbow', slot: 'bow', rarity: 'uncommon', tradeable: false, stat: 'yield',
    value: 0.05, palette: 'pelt', station: 'city', maxDurability: 120,
    inputs: { thick_pelt: 6, boiled_leather: 3, cloth: 2, horn: 3 },
    description: 'Sinew-backed and heavy to draw. The herd never hears it.',
  },
  {
    key: 'banded_sledge', name: 'Banded Sledge', slot: 'hammer', rarity: 'uncommon', tradeable: false, stat: 'yield',
    value: 0.05, palette: 'iron', station: 'city', maxDurability: 120,
    inputs: { basalt: 6, dressed_basalt: 3, ingots: 2, whetgrit: 3 },
    description: 'Iron banding over a stone core. It takes the shock instead of you.',
  },
  {
    key: 'toothed_sickle', name: 'Toothed Sickle', slot: 'sickle', rarity: 'uncommon', tradeable: false, stat: 'yield',
    value: 0.05, palette: 'iron', station: 'city', maxDurability: 120,
    inputs: { flax: 6, linen: 3, ingots: 2, quench_reed: 3 },
    description: 'Serrated inside the curve. It saws where a plain edge slides.',
  },
  {
    key: 'leather_armor', name: 'Leather Armor', slot: 'armor', rarity: 'uncommon', tradeable: false,
    stat: 'tripReduction', value: 0.05, palette: 'pelt', station: 'city', maxDurability: 130,
    inputs: { thick_pelt: 8, boiled_leather: 4, cloth: 2, sinew: 3 },
    description: 'Light enough to walk in all day.',
  },
  {
    key: 'reinforced_boots', name: 'Reinforced Boots', slot: 'boots', rarity: 'uncommon', tradeable: false,
    stat: 'travelSpeed', value: 0.05, palette: 'stone', station: 'city', maxDurability: 140,
    inputs: { basalt: 8, dressed_basalt: 3, leather: 2, slate_scale: 3 },
    description: 'Stone-shod. Ugly, and you will stop caring by noon.',
  },
  {
    key: 'work_gloves', name: 'Work Gloves', slot: 'gloves', rarity: 'common', tradeable: false,
    stat: 'processingSpeed', value: 0.03, palette: 'fiber', station: 'village', maxDurability: 90,
    inputs: { fiber: 6, cloth: 2, beeswax: 2 },
    description: 'Doubled at the palm. Speeds work on the settlement lines.',
  },

  // -- Rare: the crafted three, deeper, plus the frame. +8%, capital bench.
  //    Reinforced Frame gates the rung:
  //    it is the one tier-2 needing two processing lines, so rare gear already
  //    implies a settled player.
  {
    key: 'broadaxe', name: 'Broadaxe', slot: 'axe', rarity: 'rare', tradeable: false,
    stat: 'yield', value: 0.08, palette: 'iron', station: 'capital', maxDurability: 160,
    inputs: { heartoak: 8, bentwood: 4, ingots: 3, heartknot: 4, reinforced_frame: 1 },
    description: 'Two hands, a long haul, and a tree down in three swings.',
  },
  {
    key: 'deep_pick', name: 'Deep Pick', slot: 'pickaxe', rarity: 'rare', tradeable: false,
    stat: 'yield', value: 0.08, palette: 'iron', station: 'capital', maxDurability: 160,
    inputs: { meteoric_iron: 8, skysteel: 4, planks: 3, flux_salt: 4, reinforced_frame: 1 },
    description: 'Long in the head, for seams that do not start at the surface.',
  },
  {
    key: 'warbow', name: 'Warbow', slot: 'bow', rarity: 'rare', tradeable: false,
    stat: 'yield', value: 0.08, palette: 'pelt', station: 'capital', maxDurability: 160,
    inputs: { dire_pelt: 8, lacquered_hide: 4, canvas: 3, horn: 4, reinforced_frame: 1 },
    description: 'A draw weight most people cannot hold. It does not need a second shot.',
  },
  {
    key: 'splitting_maul', name: 'Splitting Maul', slot: 'hammer', rarity: 'rare', tradeable: false,
    stat: 'yield', value: 0.08, palette: 'stone', station: 'capital', maxDurability: 160,
    inputs: { granite: 8, polished_granite: 4, ingots: 3, whetgrit: 4, reinforced_frame: 1 },
    description: 'Wedge-headed. It does not crush the rock, it opens it.',
  },
  {
    key: 'threshing_scythe', name: 'Threshing Scythe', slot: 'sickle', rarity: 'rare', tradeable: false,
    stat: 'yield', value: 0.08, palette: 'iron', station: 'capital', maxDurability: 160,
    inputs: { hemp: 8, canvas: 4, skysteel: 3, quench_reed: 4, reinforced_frame: 1 },
    description: 'Long snath, long blade. A field goes down in rows, not handfuls.',
  },
  {
    key: 'banded_mail', name: 'Banded Mail', slot: 'armor', rarity: 'rare', tradeable: false,
    stat: 'tripReduction', value: 0.08, palette: 'iron', station: 'capital', maxDurability: 160,
    inputs: { dire_pelt: 8, lacquered_hide: 4, steel_ingots: 3, sinew: 4, reinforced_frame: 1 },
    description: 'Iron bands over tanned hide. Heavy, and worth every pound of it.',
  },
  {
    key: 'marching_boots', name: 'Marching Boots', slot: 'boots', rarity: 'rare', tradeable: false,
    stat: 'travelSpeed', value: 0.08, palette: 'pelt', station: 'capital', maxDurability: 160,
    inputs: { dire_pelt: 8, lacquered_hide: 4, polished_granite: 3, tar_seep: 4, reinforced_frame: 1 },
    description: 'Built for the road between rings, not the walk to the next hex.',
  },
  {
    key: 'tanners_gloves', name: "Tanner's Gloves", slot: 'gloves', rarity: 'rare', tradeable: false,
    stat: 'processingSpeed', value: 0.08, palette: 'pelt', station: 'capital', maxDurability: 160,
    inputs: { hemp: 8, canvas: 4, lacquered_hide: 3, beeswax: 4, reinforced_frame: 1 },
    description: 'Cut for the settlement lines. The work goes faster and the hands last.',
  },

  // -- NFT: five kinds across tiers 1-4, +12-15% hard cap, §8.3
  //    Each line's top tool wants its own rare material and its own dungeon
  //    shard, so kitting out a second line means crossing the map, §4.
  {
    key: 'ironwood_axe', name: 'Ironwood Axe', slot: 'axe', rarity: 'epic', tradeable: true, stat: 'yield',
    value: 0.11, palette: 'wood', station: 'capital', maxDurability: 200,
    inputs: { ironwood: 3, heartoak: 8, bentwood: 4, heartknot: 4, reinforced_frame: 2, shard_verdant: 1 },
    description: 'Cut from the thing it is meant to cut. Marketplace-tradeable.',
  },
  {
    key: 'mythril_pickaxe', name: 'Mythril Pickaxe', slot: 'pickaxe', rarity: 'epic', tradeable: true, stat: 'yield',
    value: 0.11, palette: 'iron', station: 'capital', maxDurability: 200,
    inputs: { mythril_ore: 3, meteoric_iron: 8, skysteel: 4, flux_salt: 4, reinforced_frame: 2, essence: 1 },
    description: 'Rings like a bell on ore. Marketplace-tradeable.',
  },
  {
    key: 'beastfang_bow', name: 'Beastfang Bow', slot: 'bow', rarity: 'epic', tradeable: true, stat: 'yield',
    value: 0.11, palette: 'pelt', station: 'capital', maxDurability: 200,
    inputs: { beastfang_hide: 3, dire_pelt: 8, lacquered_hide: 4, horn: 4, reinforced_frame: 2, shard_sanguine: 1 },
    description: 'Strung with something that used to run. Marketplace-tradeable.',
  },
  {
    key: 'obsidian_sledge', name: 'Obsidian Sledge', slot: 'hammer', rarity: 'epic', tradeable: true, stat: 'yield',
    value: 0.11, palette: 'stone', station: 'capital', maxDurability: 200,
    inputs: { obsidian_shard: 3, granite: 8, polished_granite: 4, whetgrit: 4, reinforced_frame: 2, shard_cinder: 1 },
    description: 'Glass that lands like iron. Marketplace-tradeable.',
  },
  {
    key: 'silkweave_sickle', name: 'Silkweave Sickle', slot: 'sickle', rarity: 'epic', tradeable: true, stat: 'yield',
    value: 0.11, palette: 'fiber', station: 'capital', maxDurability: 200,
    inputs: { silkweave_fiber: 3, hemp: 8, canvas: 4, quench_reed: 4, reinforced_frame: 2, shard_zephyr: 1 },
    description: 'The grass parts before it arrives. Marketplace-tradeable.',
  },
  // -- Consumables, §8.5. No slot and no durability: a potion is spent, it
  //    arms the action it names, and taking that action spends it (§11.1).

  {
    key: 'ironwood_armor', name: 'Ironwood Armor', slot: 'armor', rarity: 'epic', tradeable: true,
    stat: 'tripReduction', value: 0.11, palette: 'wood', station: 'capital', maxDurability: 210,
    inputs: { ironwood: 3, heartoak: 8, bentwood: 4, pine_pitch: 4, reinforced_frame: 2, shard_verdant: 1 },
    description: 'Grown, not forged. Marketplace-tradeable.',
  },
  {
    key: 'beastfang_boots', name: 'Beastfang Boots', slot: 'boots', rarity: 'epic', tradeable: true,
    stat: 'travelSpeed', value: 0.11, palette: 'pelt', station: 'capital', maxDurability: 190,
    inputs: { beastfang_hide: 3, dire_pelt: 8, lacquered_hide: 4, sinew: 4, reinforced_frame: 2, relic: 1 },
    description: 'Something fast died for these. Marketplace-tradeable.',
  },
  {
    key: 'silkweave_gloves', name: 'Silkweave Gloves', slot: 'gloves', rarity: 'epic', tradeable: true,
    stat: 'processingSpeed', value: 0.11, palette: 'fiber', station: 'capital', maxDurability: 195,
    inputs: { silkweave_fiber: 3, hemp: 8, canvas: 4, beeswax: 4, reinforced_frame: 2, shard_zephyr: 1 },
    description: 'Spun so fine the work goes quicker for feeling less. Marketplace-tradeable.',
  },
]

export const ITEM_BY_KEY: Record<string, ItemDef> = Object.fromEntries(
  ITEMS.map((i) => [i.key, i]),
)

/** Settlement tiers, ordered. A station satisfies anything at or below its rank. */
export const STATION_RANK: Record<SettlementTier, number> = {
  village: 1,
  city: 2,
  capital: 3,
}

export const shopItems = (): ItemDef[] => ITEMS.filter((i) => i.goldPrice !== undefined)
export const craftableItems = (): ItemDef[] => ITEMS.filter((i) => i.inputs !== undefined)

// ------------------------------------------------------------- dungeons §9.1

export const DUNGEONS = [
  { key: 'rootvault', name: 'Rootvault', biome: 'forest', drop: 'shard_verdant' },
  { key: 'deepshaft', name: 'Deepshaft', biome: 'mountain', drop: 'shard_ferrous' },
  { key: 'beastwarren', name: 'Beastwarren', biome: 'plains', drop: 'shard_sanguine' },
  { key: 'ashpit', name: 'Ashpit', biome: 'badlands', drop: 'shard_cinder' },
  { key: 'windhollow', name: 'Windhollow', biome: 'grassland', drop: 'shard_zephyr' },
] as const satisfies ReadonlyArray<{
  key: string
  name: string
  biome: Biome
  drop: MaterialKey
}>
