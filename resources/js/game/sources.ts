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
  isScrap,
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
import { VARIANT_BY_MATERIAL, VARIANT_LABEL, VARIANT_RINGS } from './variants'
import { REAGENTS } from './alchemy'
import { COMPONENTS } from './components'
import { CRITTERS } from './critters'
import { SPOILS } from './spoils'
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

/**
 * §4 -- what kind of raw a Tier 1 material is.
 *
 * Five things share the tier and arrive by different roads, so the almanac
 * cannot describe them in one sentence. `ground` is what a hex gives up and is
 * the only one with a tool and a variant behind it; the rest are bench stocks.
 * `spoil` is the odd one: it comes off a monster rather than off any ground at
 * all, which is why it has no biome (§9.5.8).
 */
export type RawRole = 'ground' | 'reagent' | 'critter' | 'component' | 'spoil'

const REAGENT_KEYS = new Set<string>(REAGENTS.map((m) => m.key))
const COMPONENT_KEYS = new Set<string>(COMPONENTS.map((m) => m.key))
const CRITTER_KEYS = new Set<string>(CRITTERS.map((m) => m.key))
const SPOIL_KEYS = new Set<string>(SPOILS.map((m) => m.key))

/** §9.5.2 -- the ring a grade's monster is new on. Grade 5 is the centre's rare drop. */
const SPOIL_RING: Record<number, string> = {
  1: 'outer ring and inward',
  2: 'middle ring and inward',
  3: 'contested ring and inward',
  4: 'the barren centre',
  5: 'the barren centre, rarely',
}

export function rawRole(key: MaterialKey): RawRole {
  if (REAGENT_KEYS.has(key)) return 'reagent'
  if (CRITTER_KEYS.has(key)) return 'critter'
  if (COMPONENT_KEYS.has(key)) return 'component'
  if (SPOIL_KEYS.has(key)) return 'spoil'
  return 'ground'
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
/**
 * §5.2 -- how far in you have to walk before this ground turns up. Silent for
 * the base grade, which is everywhere, and the phrase only earns its space when
 * it is telling you something.
 */
function ringNote(variant: string): string {
  const rings = VARIANT_RINGS[variant as keyof typeof VARIANT_RINGS] ?? []
  if (rings.length >= 3) return ''
  if (rings.length === 1) return ', contested ring only'
  return ', middle ring and inward'
}

export function materialSources(mat: Material): SourceLine[] {
  const lines: SourceLine[] = []

  switch (mat.tier) {
    case 0: {
      // §4.0 -- junk is NOT scrap, and the difference is the whole of the
      // argument this tier makes. Scrap is what a hex gives up to bare hands,
      // so it has a tool and a displaced material behind it. Junk is the
      // rubbish carried out alongside a real haul: no tool, nothing displaced,
      // and nothing on the map drops it yet either.
      if (!isScrap(mat.key)) {
        lines.push({
          kind: 'mine',
          where: `${BIOME_LABEL[mat.biome!]} hex, alongside the haul`,
          pending: true,
          note:
            'Not what a missing tool costs you — that is scrap. This is what ' +
            'comes up with everything else. No hex drops it yet.',
        })
        break
      }

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
      const role = rawRole(mat.key)

      // §9.5.8 -- the only Tier 1 with no ground under it. A spoil comes off a
      // thing that walked there, so the almanac names the ring it walked on
      // rather than a biome.
      if (role === 'spoil') {
        const ring = SPOIL_RING[mat.grade ?? 1] ?? 'the road'
        lines.push({
          kind: 'mine',
          where: `Monster pack · ${ring}`,
          note:
            `Cut off a tier ${mat.grade} monster and nothing else — no hex gives ` +
            `this up. Wanted by the ${mat.spoil === 'ichor' ? 'consumable' : 'smith and armorer'} bench.`,
        })
        break
      }

      // §4 -- the alchemist's second stock, and the only ingredient that needs
      // a bow. A herb is an errand; a critter waits on a herd, and §5.5 puts
      // herds on a four-hour clock.
      if (role === 'critter') {
        lines.push({
          kind: 'mine',
          where: `${BIOME_LABEL[mat.biome!]} herd · Hunting`,
          note:
            'Needs a bow and a live herd — the one activity bare hands cannot do ' +
            'at all. Hunted, never gathered.',
        })
        break
      }

      // §4 -- the herbs and the craft components come off the ground, but not
      // off the same verb: a herb turns up whether or not you brought a tool,
      // a component only if you did.
      if (role !== 'ground') {
        const bench = role === 'reagent' ? 'consumable' : (mat.bench ?? 'craft')
        const verb = role === 'reagent' ? 'Gathering or mining' : 'Mining';
        lines.push({
          kind: 'mine',
          where: `${BIOME_LABEL[mat.biome!]} hex · ${verb}`,
          note: `Stock for the ${bench} bench, alongside whatever the hex itself gives up.`,
        })
        break
      }

      const skill = SKILL_BY_KEY[skillForMaterial(mat.key)]
      const tool = slotForSkill(skill.key)
      // §5.3 -- a grade names the ground it comes off, not just the biome. Four
      // kinds of forest give four different things, and which one you are
      // standing on is the whole question.
      const variant = VARIANT_BY_MATERIAL[mat.key]
      lines.push({
        kind: 'mine',
        where: variant
          ? `${VARIANT_LABEL[variant]} · ${skill.name}${ringNote(variant)}`
          : `${BIOME_LABEL[mat.biome!]} hex · ${skill.name}`,
        note:
          `Needs ${article(tool)} ${tool}. Without one the hex gives ` +
          `${MATERIALS[BIOME_SCRAP[mat.biome!]].name.toLowerCase()} instead.`,
      })
      if (mat.key === 'pelt') {
        lines.push({
          kind: 'mine',
          where: 'Herd markers, any biome',
          pending: true,
          note:
            `Herds wander onto open hexes and move on after about ` +
            `${Math.round(HUNTING.markerLifetimeMs / 3_600_000)} hours. A bow is required, ` +
            'and they pay horn, sinew and bone alongside the pelt.',
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
        where: `${VARIANT_LABEL[VARIANT_BY_MATERIAL[mat.key]!]}, contested ring only`,
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
export const stocksAt = (item: ItemDef): SettlementTier[] => {
  // §8.0 -- a guild hall is not a settlement, and no settlement reaches past
  // it. Legendary work is stocked and craftable nowhere a player can stand.
  if (item.station === 'guild') return []

  const need = item.station ?? 'village'

  return (Object.keys(STATION_RANK) as SettlementTier[]).filter(
    (tier) => STATION_RANK[tier] >= STATION_RANK[need],
  )
}
