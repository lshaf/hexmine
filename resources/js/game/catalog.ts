/**
 * Static game data: the 20 materials (§4), 5 skill lines (§7.2), the processing
 * recipes (§6) and the item catalog (§8.3). This is the data a Laravel seeder
 * will eventually own -- keeping it in one file makes that port mechanical.
 */
import { ECONOMY } from './balance'
import type {
  Biome,
  ItemDef,
  Material,
  MaterialKey,
  RareKey,
  RawKey,
  Recipe,
  Ring,
  SettlementTier,
  Skill,
  SkillKey,
  StatKey,
} from './types'

// ------------------------------------------------------------- materials §4

export const MATERIALS: Record<MaterialKey, Material> = {
  // Tier 1 -- Raw, biome-locked, decays over cap
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
    description: 'Faster trips and better yield in forest hexes.',
  },
  {
    key: 'mining', name: 'Mining', material: 'iron_ore', rareMaterial: 'mythril_ore',
    description: 'Faster trips and better yield in mountain hexes.',
  },
  {
    key: 'hunting', name: 'Hunting', material: 'pelt', rareMaterial: 'beastfang_hide',
    description: 'Faster trips and better yield on plains and tundra.',
  },
  {
    key: 'quarrying', name: 'Quarrying', material: 'stone', rareMaterial: 'obsidian_shard',
    description: 'Faster trips and better yield in the badlands.',
  },
  {
    key: 'harvesting', name: 'Harvesting', material: 'fiber', rareMaterial: 'silkweave_fiber',
    description: 'Faster trips and better yield in grassland hexes.',
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

/** Biome -> its rare variant, spawned in the contested inner ring, §5.3. */
export const BIOME_RARE: Record<Biome, RareKey> = {
  forest: 'ironwood',
  mountain: 'mythril_ore',
  plains: 'beastfang_hide',
  badlands: 'obsidian_shard',
  grassland: 'silkweave_fiber',
}

export const skillForMaterial = (key: MaterialKey): SkillKey => {
  const found = SKILL_LIST.find((s) => s.material === key || s.rareMaterial === key)
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
]

export const RECIPE_BY_KEY: Record<string, Recipe> = Object.fromEntries(
  RECIPES.map((r) => [r.key, r]),
)

/** Which recipes a settlement can run, given the lines it hosts, §6. */
export const recipesForLines = (lines: SkillKey[]): Recipe[] =>
  RECIPES.filter((r) => lines.includes(r.skill))

/** Human-readable names for the stats items modify. The raw keys are camelCase
 *  and must never reach the screen. */
export const STAT_LABEL: Record<StatKey, string> = {
  yield: 'yield',
  tripReduction: 'trip time',
  travelSpeed: 'travel',
  processingSpeed: 'processing',
  power: 'power',
}

// ------------------------------------------------------------- items §8.3

export const ITEMS: ItemDef[] = [
  // -- Basic: gold shop, +3-5%, universal, §8.
  //    `station` is the smallest settlement that stocks the item -- villages
  //    carry the basics, better gear is a reason to walk to a city.
  {
    key: 'stone_axe', name: 'Stone Axe', slot: 'tool', tier: 'basic', stat: 'yield',
    value: 0.03, palette: 'stone', goldPrice: 20, maxDurability: 40, station: 'village',
    description: 'A chipped edge lashed to a handle. Better than bare hands.',
  },
  {
    key: 'travel_cloak', name: 'Travel Cloak', slot: 'armor', tier: 'basic',
    stat: 'tripReduction', value: 0.04, palette: 'fiber', goldPrice: 65, maxDurability: 60,
    station: 'village',
    description: 'Keeps the weather off. Shaves a little off every trip.',
  },
  {
    key: 'hide_shoes', name: 'Hide Shoes', slot: 'boots', tier: 'basic',
    stat: 'travelSpeed', value: 0.04, palette: 'pelt', goldPrice: 55, maxDurability: 50,
    station: 'city',
    description: 'Soft-soled and quiet. Not built for the badlands.',
  },
  {
    key: 'iron_hatchet', name: 'Iron Hatchet', slot: 'tool', tier: 'basic', stat: 'yield',
    value: 0.05, palette: 'iron', goldPrice: 90, maxDurability: 70, station: 'city',
    description: 'Shop-grade steel. Reliable, unremarkable.',
  },

  // -- Crafted: tier 1-2 materials, +6-8%, universal, §8.3
  {
    key: 'wood_pickaxe', name: 'Wood Pickaxe', slot: 'tool', tier: 'crafted', stat: 'yield',
    value: 0.04, palette: 'wood', station: 'village', maxDurability: 60,
    inputs: { planks: 4 },
    description: 'Your first real tool. It will not last, but it will teach.',
  },
  {
    key: 'iron_pickaxe', name: 'Iron Pickaxe', slot: 'tool', tier: 'crafted', stat: 'yield',
    value: 0.06, palette: 'iron', station: 'village', maxDurability: 120,
    inputs: { ingots: 5, planks: 3 },
    description: 'Balanced head, seasoned haft. The workhorse tool.',
  },
  {
    key: 'leather_armor', name: 'Leather Armor', slot: 'armor', tier: 'crafted',
    stat: 'tripReduction', value: 0.06, palette: 'pelt', station: 'village', maxDurability: 130,
    inputs: { leather: 6, cloth: 2 },
    description: 'Light enough to walk in all day.',
  },
  {
    key: 'reinforced_boots', name: 'Reinforced Boots', slot: 'boots', tier: 'crafted',
    stat: 'travelSpeed', value: 0.08, palette: 'stone', station: 'city', maxDurability: 140,
    inputs: { cut_stone: 4, leather: 3 },
    description: 'Stone-shod. Ugly, and you will stop caring by noon.',
  },
  {
    key: 'work_gloves', name: 'Work Gloves', slot: 'gloves', tier: 'crafted',
    stat: 'processingSpeed', value: 0.04, palette: 'fiber', station: 'village', maxDurability: 90,
    inputs: { cloth: 3, planks: 2 },
    description: 'Doubled at the palm. Speeds work on the settlement lines.',
  },

  // -- NFT: tier 3 + tier 4 materials, +12-15% hard cap, §8.3
  {
    key: 'mythril_pickaxe', name: 'Mythril Pickaxe', slot: 'tool', tier: 'nft', stat: 'yield',
    value: 0.12, palette: 'iron', station: 'capital', maxDurability: 200,
    inputs: { mythril_ore: 3, reinforced_frame: 2, essence: 1 },
    description: 'Rings like a bell on ore. Marketplace-tradeable.',
  },
  {
    key: 'ironwood_armor', name: 'Ironwood Armor', slot: 'armor', tier: 'nft',
    stat: 'tripReduction', value: 0.12, palette: 'wood', station: 'capital', maxDurability: 210,
    inputs: { ironwood: 3, silkweave_fiber: 2, shard_verdant: 1 },
    description: 'Grown, not forged. Marketplace-tradeable.',
  },
  {
    key: 'beastfang_boots', name: 'Beastfang Boots', slot: 'boots', tier: 'nft',
    stat: 'travelSpeed', value: 0.15, palette: 'pelt', station: 'capital', maxDurability: 190,
    inputs: { beastfang_hide: 2, obsidian_shard: 1, relic: 1 },
    description: 'Something fast died for these. Marketplace-tradeable.',
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
