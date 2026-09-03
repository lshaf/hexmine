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
import { PROCESSING } from './balance'
import {
  BIOME_SCRAP,
  HUNT_SCRAP,
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
import { VARIANT_BY_MATERIAL, VARIANT_LABEL, VARIANT_LEAKS, VARIANT_RINGS } from './variants'
import { REAGENTS } from './alchemy'
import { COMPONENTS } from './components'
import { CRITTERS } from './critters'
import { SPOILS } from './spoils'
import {
  ANIMALS,
  HUNT_BIOMES,
  HUNT_GRADED_FROM,
  HUNT_GRADED_PART,
  HUNT_GRADES,
  HUNT_JUNK,
  HUNT_LEAVING,
} from './hunts'
import type { Biome, ItemDef, Material, MaterialKey, SettlementTier } from './types'

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
 * One color per road, from §13.3. Mining is the primary act so it takes the
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
/**
 * §5.5 -- the hunt's graded part files with the components it stands beside.
 *
 * It is an animal part like the horn and the sinew, not a rung of the hide
 * ladder, so leaving it to fall through to `ground` put it under a heading
 * that says a variant of hex decides which one you get. It comes off a
 * creature, and off no ladder at all.
 */
const COMPONENT_KEYS = new Set<string>([
  ...COMPONENTS.map((m) => m.key),
  HUNT_GRADED_PART,
])
const CRITTER_KEYS = new Set<string>(CRITTERS.map((m) => m.key))
const SPOIL_KEYS = new Set<string>(SPOILS.map((m) => m.key))

/** §9.5.2 -- the ring a grade's monster is new on. Grade 5 is the center's rare drop. */
const SPOIL_RING: Record<number, string> = {
  1: 'outer ring and inward',
  2: 'middle ring and inward',
  3: 'contested ring and inward',
  4: 'the barren center',
  5: 'the barren center, rarely',
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
 * leads to it.
 *
 * §5.5 -- it used to say pelt was the clearest case, "since a herd is a genuine
 * alternative to working a plains hex". Both halves of that are gone: there is
 * no plains hex and no herd, and pelt has exactly one road now -- an animal,
 * worked with a bow.
 */
/**
 * §5.2 -- how far in you have to walk before this ground turns up. Silent for
 * the base grade, which is everywhere, and the phrase only earns its space when
 * it is telling you something.
 */
function ringNote(variant: string): string {
  const rings = VARIANT_RINGS[variant as keyof typeof VARIANT_RINGS] ?? []

  // §5.2 -- the two middle grades leak onto the rings outside their own at a few
  // per cent, so "only" would be a lie and saying nothing would bury the one
  // fact a rim prospector wants: that it is worth keeping an eye out. Said as a
  // long shot, because that is what it is -- roughly one hex in fifty for an
  // uncommon and one in two hundred for a rare.
  const luck = VARIANT_LEAKS[variant as keyof typeof VARIANT_LEAKS]
    ? ', and rarely further out'
    : ''

  // Asked of the rings themselves rather than of how many there are. It used to
  // count -- three or more meant everywhere, one meant contested -- and that
  // broke the moment §5.2's center joined the list it belongs to: every
  // inner-bearing grade gained a ring and slid one rung down the phrase, so
  // uncommon ground quietly stopped saying where it was.
  if (rings.includes('outer')) return ''
  if (rings.includes('mid')) return `, middle ring and inward${luck}`

  return `, contested ring${luck || ' only'}`
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
        // §9.5.8 -- a monster's trophy has no ground at all: it says WHAT you
        // fought, one per tier, and every win drops it. The junk beside it is
        // the biome's, and that one says where.
        if (mat.biome === undefined && mat.source === undefined) {
          lines.push({
            kind: 'mine',
            where: `Monster pack · tier ${mat.grade ?? 1}`,
            note:
              'Dropped by every win against a monster of its tier. Worth a gold, ' +
              'wanted by no recipe — what a fight leaves that nobody wants.',
          })
          break
        }

        // §5.5 -- the hunt's own two, and they are not pending: a kill really
        // does pay them, one every time and one about twice in five. The
        // generic line below says "no hex drops it yet", which was true of the
        // mine's junk and was never true of these.
        if (mat.key === HUNT_JUNK || mat.key === HUNT_LEAVING) {
          lines.push({
            kind: 'mine',
            where: 'A hunt',
            note:
              mat.key === HUNT_JUNK
                ? 'Comes home from every kill, bow or no bow. Worth a gold, wanted ' +
                  'by no recipe — the rubbish carried out alongside the hide.'
                : 'Torn up under the animal on about two kills in five. It says ' +
                  'where the kill happened rather than what it was, which is the ' +
                  'same pair a fight pays in.',
          })
          break
        }

        lines.push({
          kind: 'mine',
          where: mat.source === 'hunt'
            ? 'A hunt, alongside the haul'
            : `${BIOME_LABEL[mat.biome!]} hex, alongside the haul`,
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
      //
      // §5.5 -- the hunting line's scrap comes off an ANIMAL rather than off a
      // country, so there is no biome to name. Four of the five say a hex and
      // the fifth says the hunt.
      lines.push({
        kind: 'mine',
        where: mat.source === 'hunt' ? 'A hunt, bare-handed' : `${BIOME_LABEL[mat.biome!]} hex, bare-handed`,
        note:
          `Without ${article(tool)} ${tool} equipped, ` +
          (mat.source === 'hunt' ? 'a hunt gives this ' : `a ${mat.biome} hex gives this `) +
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
        // §9.5.8 -- a country's own stock says WHERE it lived rather than how
        // hard it was, so it carries a biome and no grade. The graded ladders
        // are the other way round.
        if (mat.spoil === 'biome') {
          lines.push({
            kind: 'mine',
            where: `Monster pack · ${BIOME_LABEL[mat.biome!]}`,
            note:
              `Off ${BIOME_LABEL[mat.biome!].toLowerCase()} monsters and nothing else — ` +
              'the one drop you cannot get by walking inward on ground you know.',
          })
          break
        }

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

      // §4 -- the alchemist's second stock: what LIVES on a kind of ground, as
      // against what grows on it. Taken with the line's tool, never picked up
      // by hand.
      if (role === 'critter') {
        lines.push({
          kind: 'mine',
          where: mat.source === 'hunt'
            ? 'A hunt · Hunting'
            : `${BIOME_LABEL[mat.biome!]} hex · ${SKILL_BY_KEY[skillForMaterial(mat.key)].name}`,
          note: 'Taken with the line\'s tool. Hunted, never gathered.',
        })
        break
      }

      // §5.5 -- the one part the grade alone pays for. It files with the
      // components because it is an animal part like the horn and the sinew,
      // but it feeds no bench, so it has to answer before the line below
      // promises one.
      if (mat.key === HUNT_GRADED_PART) {
        const rungs = HUNT_GRADES.map((g) => g.grade)
        const carrying = rungs.slice(rungs.indexOf(HUNT_GRADED_FROM))

        lines.push({
          kind: 'mine',
          where: `A hunt · ${carrying.join(', ')}`,
          note:
            'Never off a common animal, and a bow is what brings it home. The rung ' +
            'pays in a kind of drop as well as in a rung of hide.',
        })
        break
      }

      // §4 -- the herbs and the craft components come off the ground, but not
      // off the same verb: a herb turns up whether or not you brought a tool,
      // a component only if you did.
      if (role !== 'ground') {
        const bench = role === 'reagent' ? 'consumable' : (mat.bench ?? 'craft')
        // §5.5 -- and the verb is the HUNT's when the thing comes off an
        // animal. Both of the other words name a hex: "gathering or mining" is
        // the two ways a country is worked, and neither of them is what you do
        // to a deer.
        const verb =
          mat.source === 'hunt'
            ? 'Hunting'
            : role === 'reagent'
              ? 'Gathering or mining'
              : 'Mining'
        lines.push({
          kind: 'mine',
          where: mat.source === 'hunt'
            ? `A hunt · ${verb}`
            : `${BIOME_LABEL[mat.biome!]} hex · ${verb}`,
          note: mat.source === 'hunt'
            ? `Stock for the ${bench} bench, alongside whatever the animal gives up.`
            : `Stock for the ${bench} bench, alongside whatever the hex itself gives up.`,
        })
        break
      }

      const skill = SKILL_BY_KEY[skillForMaterial(mat.key)]
      const tool = slotForSkill(skill.key)
      // §5.3 -- a grade names the ground it comes off, not just the biome. Four
      // kinds of forest give four different things, and which one you are
      // standing on is the whole question.
      // §5.5 -- the hunting line has no ground under it. Its four rungs come
      // off the animal's own grade, so the card names the creature's country
      // and the rung rather than a kind of hex.
      if (mat.source === 'hunt') {
        const animal = Object.values(ANIMALS).find((a) => a.material === mat.key)

        lines.push({
          kind: 'mine',
          where: animal
            ? `${animal.name} · ${HUNT_BIOMES.map((b) => BIOME_LABEL[b as Biome]).join(' and ')}`
            : 'A hunt',
          note:
            `Needs ${article(tool)} ${tool}. Without one a hunt gives ` +
            `${MATERIALS[HUNT_SCRAP].name.toLowerCase()} instead.`,
        })
        break
      }

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
      // §5.5 -- four of the five come off a variant of hex and the fifth comes
      // off an animal, so there is no variant to name. Both animals that carry
      // it are named instead, which is the same answer in the units this rung
      // is actually found in.
      if (mat.source === 'hunt') {
        const carriers = Object.values(ANIMALS)
          .filter((a) => a.material === mat.key)
          .map((a) => a.name)

        lines.push({
          kind: 'mine',
          where: `${carriers.join(' · ')}, contested ring only`,
          note:
            'Needs a bow, like every rung of the hide ladder. Capped per wallet ' +
            'like the other four, because this is the gate every mintable recipe ' +
            'in the line stands behind.',
        })
        break
      }

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
        // §9.1 -- Beastwarren belongs to no country: it is where the things
        // you hunt den, which is the whole of its name. Four dungeons take
        // their country's name and the fifth has none to take, so it says what
        // it is rather than printing the absence.
        where: dungeon.biome
          ? `${dungeon.name}, the ${dungeon.biome} dungeon`
          : `${dungeon.name}, the beast dungeon`,
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
      note:
        bench === 'guild'
          ? "Members only, at their own guild's hall, and only once the treasury has built its bench that far."
          : undefined,
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
  // §8.0 -- a guild hall is not a settlement and stocks nothing. Legendary work
  // is made there and sold nowhere.
  if (item.station === 'guild') return []

  const need = item.station ?? 'village'

  return (Object.keys(STATION_RANK) as SettlementTier[]).filter(
    (tier) => STATION_RANK[tier] >= STATION_RANK[need],
  )
}
