/**
 * Provenance: where a thing comes from, and what it turns into.
 *
 * Every material and every item in this game arrives by exactly one of five
 * roads -- mined off a hex, processed at a settlement line, bought from a
 * trader, made at a bench, or dropped in a dungeon. That is not a UI
 * convenience; it is the shape of §4, §6, §8 and §9 read backwards, and it is
 * the whole vocabulary the almanac needs.
 *
 * Derived here rather than stored, for the same reason craft category is (§8.4):
 * a second field would only be somewhere for the catalog and the truth to
 * disagree. Everything below reads the catalog and the balance constants, so a
 * tuning pass moves this page without anyone editing it.
 */
import { HUNTING, PROCESSING } from './balance'
import {
  BIOME_SCRAP,
  DUNGEONS,
  ITEMS,
  MATERIALS,
  RECIPES,
  SKILL_BY_KEY,
  STATION_RANK,
  skillForMaterial,
  slotForSkill,
  stationForRarity,
} from './catalog'
import { BIOME_LABEL } from '@/theme/palette'
import type { ItemDef, Material, MaterialKey, SettlementTier } from './types'

/** The five roads. Nothing in the world arrives by a sixth. */
export type SourceKind = 'mine' | 'process' | 'trade' | 'craft' | 'dungeon'

export const SOURCE_KINDS: SourceKind[] = ['mine', 'process', 'trade', 'craft', 'dungeon']

/**
 * Past participles, deliberately: a source line answers "how did this get into
 * my bag", so every label completes the same sentence.
 */
export const SOURCE_LABEL: Record<SourceKind, string> = {
  mine: 'Mined',
  process: 'Processed',
  trade: 'Bought',
  craft: 'Crafted',
  dungeon: 'Dropped',
}

/**
 * One colour per road, from §13.3. Mining is the primary act so it takes the
 * primary accent; the two settlement roads sit in the same vellum family
 * because they are the same kind of act; gold and violet are the two ways value
 * arrives from outside the mining loop.
 */
export const SOURCE_COLOR: Record<SourceKind, string> = {
  mine: 'var(--copper)',
  process: 'var(--vellum-dim)',
  trade: 'var(--gold)',
  craft: 'var(--vellum)',
  dungeon: 'var(--violet)',
}

/** The glyph in icons/actions.ts that stands for each road. */
export const SOURCE_ICON: Record<SourceKind, string> = {
  mine: 'mine',
  process: 'process',
  trade: 'trade',
  craft: 'craft',
  dungeon: 'dungeon',
}

export interface SourceCost {
  key: MaterialKey
  qty: number
}

export interface SourceLine {
  kind: SourceKind
  /** Where, in one phrase. Never a sentence -- the note is for sentences. */
  where: string
  /** What it costs in materials, if anything. */
  cost?: SourceCost[]
  /** What it costs in gold, if anything. */
  gold?: number
  /** The qualifier: a cap, a gate, a timing. */
  note?: string
  /**
   * Designed but not built yet. Shown rather than hidden, because "this is
   * where tier 4 will come from" is the answer to the question the page is
   * being asked -- an omission would read as "nowhere".
   */
  pending?: boolean
}

const article = (word: string) => (/^[aeiou]/i.test(word) ? 'an' : 'a')

const title = (word: string) => word.charAt(0).toUpperCase() + word.slice(1)

const minutes = (seconds: number) => Math.round(seconds / 60)

// ------------------------------------------------------------------ upstream

/**
 * Where a material comes from. More than one line where more than one road
 * leads to it -- pelt is the clearest case, since a herd is a genuine
 * alternative to working a plains hex (§5.5).
 */
export function materialSources(mat: Material): SourceLine[] {
  const lines: SourceLine[] = []

  switch (mat.tier) {
    case 0: {
      const skill = SKILL_BY_KEY[skillForMaterial(mat.key)]
      const tool = slotForSkill(skill.key)
      // Whatever is true of all five scrap lives in the tier note, not here.
      // Five cards repeating the same paragraph is noise, and the only thing
      // that actually differs is the tool and what it displaces.
      lines.push({
        kind: 'mine',
        where: `${BIOME_LABEL[mat.biome!]} hex, bare-handed`,
        note:
          `Without ${article(tool)} ${tool} equipped, a ${mat.biome} hex gives this ` +
          `instead of ${MATERIALS[skill.material].name.toLowerCase()}.`,
      })
      break
    }

    case 1: {
      const skill = SKILL_BY_KEY[skillForMaterial(mat.key)]
      const tool = slotForSkill(skill.key)
      lines.push({
        kind: 'mine',
        where: `${BIOME_LABEL[mat.biome!]} hex · ${skill.name}`,
        note:
          `Needs ${article(tool)} ${tool}. Without one the hex gives ` +
          `${MATERIALS[BIOME_SCRAP[mat.biome!]].name.toLowerCase()} instead.`,
      })
      if (mat.key === 'pelt') {
        lines.push({
          kind: 'mine',
          where: 'Hunting grounds, plains and grassland',
          pending: true,
          note:
            `Herd markers wander onto open hexes and move on after about ` +
            `${Math.round(HUNTING.markerLifetimeMs / 3_600_000)} hours. They cost time and ` +
            'AP, no raid charge, and pay a little essence on top.',
        })
      }
      break
    }

    case 2: {
      const recipe = RECIPES.find((r) => r.output === mat.key)

      // Every other branch of this switch always produces a line, and a card
      // with an empty FROM rail would read as "comes from nowhere". A refined
      // material without a recipe is a catalog bug, so say the true general
      // thing rather than saying nothing.
      if (!recipe) {
        lines.push({ kind: 'process', where: 'A settlement processing line' })
        break
      }

      const cost: SourceCost[] = [{ key: recipe.input, qty: recipe.inputQty }]
      if (recipe.secondInput) {
        cost.push({ key: recipe.secondInput, qty: recipe.secondInputQty ?? 1 })
      }

      const timings = (Object.keys(PROCESSING.speed) as SettlementTier[])
        .map((tier) => `${minutes(recipe.baseSeconds * PROCESSING.speed[tier])} min at a ${tier}`)
        .join(' · ')

      lines.push({
        kind: 'process',
        where: `${SKILL_BY_KEY[recipe.skill].name} line, ${recipe.name.toLowerCase()}`,
        cost,
        note: timings,
      })
      break
    }

    case 3: {
      // The cap and the contest are true of all five rares, so the section note
      // carries them. `where` already says the only thing that differs.
      lines.push({
        kind: 'mine',
        where: `${BIOME_LABEL[mat.biome!]} hex, contested ring only`,
      })
      break
    }

    case 4: {
      lines.push(...raidSources(mat.key))
      break
    }
  }

  return lines
}

/** §9 -- the four raid materials, each gated differently on purpose. */
function raidSources(key: MaterialKey): SourceLine[] {
  const dungeon = DUNGEONS.find((d) => d.drop === key)

  if (dungeon) {
    return [
      {
        kind: 'dungeon',
        where: `${dungeon.name}, the ${dungeon.biome} dungeon`,
        pending: true,
        note: 'Reliable from floor 4 down, occasional above.',
      },
    ]
  }

  if (key === 'essence') {
    return [
      {
        kind: 'dungeon',
        where: 'Any dungeon, any floor',
        pending: true,
        note: 'The common residue. Every monster tier drops it.',
      },
      {
        kind: 'mine',
        where: 'Hunting grounds, plains and grassland',
        pending: true,
        note:
          `A small amount on top of the pelts, at a ${Math.round(HUNTING.essenceChance * 100)}% ` +
          'chance. The only activity that bridges the mining and raid tracks.',
      },
    ]
  }

  if (key === 'relic') {
    return [
      {
        kind: 'dungeon',
        where: 'Any dungeon, floors 4–10',
        pending: true,
        note:
          'Pity-timer protected: a dry streak cannot run forever, so nothing here ' +
          'rewards raid-spamming.',
      },
    ]
  }

  return [
    {
      kind: 'dungeon',
      where: 'The floor 10 boss',
      pending: true,
      note: 'Party required, and the only source. It gates the top equipment rung.',
    },
  ]
}

/** Workbench tiers, ordered. Guild halls sit above capitals and do not exist yet. */
const BENCH_RANK: Record<string, number> = { village: 1, city: 2, capital: 3, guild: 4 }

/**
 * §8.0 -- the smallest bench that can make a thing. Two gates apply and the
 * higher one wins: the rarity ceiling of the bench, and the item's own station.
 */
export function benchFor(item: ItemDef): string {
  const byRarity = stationForRarity(item.rarity) ?? 'guild'
  const byStation = item.station ?? 'village'
  return BENCH_RANK[byRarity]! >= BENCH_RANK[byStation]! ? byRarity : byStation
}

/** How an item is come by. Gold, a bench, a drop, the marketplace — or several. */
export function itemSources(item: ItemDef): SourceLine[] {
  const lines: SourceLine[] = []

  if (item.goldPrice !== undefined) {
    const tier = item.station ?? 'village'
    lines.push({
      kind: 'trade',
      where: tier === 'village' ? 'Any trader' : `Trader at a ${tier} or larger`,
      gold: item.goldPrice,
    })
  }

  if (item.inputs) {
    const bench = benchFor(item)
    lines.push({
      kind: 'craft',
      where: bench === 'guild' ? 'Guild hall bench' : `${title(bench)} bench`,
      cost: Object.entries(item.inputs).map(([key, qty]) => ({
        key: key as MaterialKey,
        qty: qty as number,
      })),
      pending: bench === 'guild',
      note: bench === 'guild' ? 'Guild halls are not built yet.' : undefined,
    })
  }

  if (item.tradeable) {
    lines.push({
      kind: 'trade',
      where: 'Marketplace, wallet to wallet',
      pending: true,
      note: 'The one externally tradeable thing in the game, and never a grind reward.',
    })
  }

  if (lines.length === 0) {
    lines.push({
      kind: 'dungeon',
      where: 'Dungeon drop only',
      pending: true,
      note: 'Soulbound the moment it lands, so it never reaches the marketplace.',
    })
  }

  return lines
}

// ---------------------------------------------------------------- downstream

export interface MaterialUses {
  /** Refined outputs this material feeds. */
  processedInto: Material[]
  /** Everything craftable that lists it. */
  craftedInto: ItemDef[]
  /** NPC buy-back, 0 when the trader will not take it. */
  sellsFor: number
}

/**
 * What a material is *for*. The reverse index nothing else in the app has, and
 * the second half of the question a compendium exists to answer: a bag full of
 * cut stone is only useful if you know what takes cut stone.
 */
export function materialUses(mat: Material): MaterialUses {
  return {
    processedInto: RECIPES.filter(
      (r) => r.input === mat.key || r.secondInput === mat.key,
    ).map((r) => MATERIALS[r.output]),
    craftedInto: ITEMS.filter((i) => i.inputs && mat.key in i.inputs),
    sellsFor: mat.npcPrice,
  }
}

/** Shop tiers that stock an item, for the trader line. */
export const stocksAt = (item: ItemDef): SettlementTier[] =>
  (Object.keys(STATION_RANK) as SettlementTier[]).filter(
    (tier) => STATION_RANK[tier] >= STATION_RANK[item.station ?? 'village'],
  )
