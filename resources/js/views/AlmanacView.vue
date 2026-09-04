<script setup lang="ts">
/**
 * The almanac: every material and every item, and the road each one arrives by.
 *
 * The other screens answer "what can I do here". This one answers "where does
 * that come from", which is the question the rest of the game never has room
 * for -- the workshop hides what this bench cannot reach (§8.0), the trader
 * only stocks two rungs, and the bag only shows what you already hold. None of
 * them can tell you that ironwood is contested-ring only, or that a warbow
 * wants a reinforced frame you cannot make at a village.
 *
 * It talks to nothing, which is why it is its own page (/almanac) rather than a
 * panel over the map. Both catalogs are static data mirrored between PHP and
 * TS, so the whole screen is a pure read of `catalog.ts` and `sources.ts` -- no
 * store, no request, and correct with no character at all.
 *
 * The structural device is the provenance rail: every entry carries the same
 * two labeled lines, FROM and FEEDS, color-coded by which of the five roads
 * (§4/§6/§8/§9) applies. It is not decoration -- five roads in and one road out
 * is the actual shape of the economy, and an entry with nothing downstream is
 * saying something true about scrap.
 */
import { computed, ref, watch } from 'vue'
import {
  BIOME_SCRAP,
  MATERIALS,
  RARITY_LABEL,
  RARITY_RANK,
  RING_LABEL,
  SCOPE_ACTION,
  SKILL_BY_KEY,
  SLOT_LABEL,
  ITEMS,
  optionRollsFor,
  skillForSlot,
} from '@/game/catalog'
import {
  rawRole,
  SOURCE_COLOR,
  SOURCE_ICON,
  SOURCE_KINDS,
  SOURCE_LABEL,
  itemSources,
  materialSources,
  materialUses,
} from '@/game/sources'
import type { SourceLine } from '@/game/sources'
import { formatPercent, resaleValue } from '@/game/formulas'
import { EQUIPMENT, ECONOMY, PROCESSING, BAG } from '@/game/balance'
import { ACTION_PATHS } from '@/icons/actions'
import { CRITTER_BY_BIOME } from '@/game/critters'
import { MONSTERS, MONSTERS_BY_BIOME_RING } from '@/game/monsters'
import {
  ANIMALS,
  HUNT_GRADED_FROM,
  HUNT_GRADED_PART,
  HUNT_GRADES,
  HUNT_JUNK,
  HUNT_LEAVING,
  HUNT_PARTS,
} from '@/game/hunts'
import { TROPHY_BY_TIER } from '@/game/spoils'
import { BIOME_LABEL } from '@/theme/palette'
import {
  BIOME_VARIANTS,
  VARIANT_DESCRIPTION,
  VARIANT_RINGS,
  type VariantDef,
} from '@/game/variants'
import { itemIcon, materialIcon } from '@/icons/procedural'
import {
  animalSpecimen,
  monsterSpecimen,
  pocketSpecimen,
  variantSpecimen,
  waterSpecimen,
} from '@/map/props'
import { waterLabel } from '@/game/water'
import SvgIcon from '@/components/SvgIcon.vue'
import StatChips from '@/components/StatChips.vue'
import type {
  Biome,
  Ring,
  WaterKind,
  EquipSlot,
  ItemDef,
  Material,
  MaterialKey,
  MaterialTier,
  Rarity,
  VariantKey,
} from '@/game/types'

/**
 * §3.2 -- what the trader will do about a thing, in one line.
 *
 * The almanac already answered "where does this come from"; what it never
 * answered is "what is it worth", which is the question standing at a counter
 * actually asks. Both halves get the same line in the same place rather than a
 * gold chip on one and a sentence buried in a rail on the other.
 *
 * Equipment resale is quoted **at full condition**, because that is the only
 * figure that is a fact about the item rather than about the particular one in
 * your bag -- §8.2 scales it by what is left, and the trader panel quotes the
 * real number for the real piece.
 */
function traderLine(def: ItemDef): { buy: number; sell: number } {
  const buy = def.goldPrice ?? 0

  return { buy, sell: resaleValue(def, def.maxDurability ?? 0) }
}

/**
 * §8.0.1 -- what a bench might put on this piece, before anybody owns one.
 *
 * The almanac is the one screen that reads a piece you do not have, and until
 * now it read only the half that is fixed. A rolled line is the other half:
 * two of one recipe are never the same object, and *which* lines this
 * particular recipe can come out carrying is a fact about the recipe rather
 * than about the copy in somebody's bag.
 *
 * It says three things, because §8.0.1 says three things are random: how many
 * (a ceiling, never a quota), what band they are drawn from, and which of the
 * pair each one lands on -- an axe reaches only `attack`, a wand `attack`, and
 * everything else both.
 */
const OPTION_TIERS = Object.keys(
  EQUIPMENT.optionFlatValue,
) as (keyof typeof EQUIPMENT.optionFlatValue)[]

/** Everything at or below the piece's own rung -- a deeper bag, not a better one. */
function optionTiersFor(rarity: Rarity): typeof OPTION_TIERS {
  const out: typeof OPTION_TIERS = []
  for (const tier of OPTION_TIERS) {
    out.push(tier)
    if (tier === rarity) break
  }

  return out
}

interface RollBrief {
  ceiling: number
  lines: { label: string; value: string }[]
  unbreakable: string
  plainShelf: boolean
}

function rollBrief(def: ItemDef): RollBrief | null {
  if (!def.slot) return null

  const tiers = optionTiersFor(def.rarity)
  const first = tiers[0]!
  const last = tiers[tiers.length - 1]!
  const pct = (v: number) => `${Math.round(v * 100)}%`
  const pool = optionRollsFor(def)

  // §8.0.1 -- four kinds and four units, so the band is quoted per line rather
  // than once in the sentence: a point of the pair, a point of durability, a
  // whole round and a share of the work are not the same number.
  const band = (entry: (typeof pool)[number]): string => {
    if (entry.kind === 'durability') {
      const low = Math.max(1, Math.round((def.maxDurability ?? 0) * EQUIPMENT.optionDurabilityValue[first][0]))
      const high = Math.max(1, Math.round((def.maxDurability ?? 0) * EQUIPMENT.optionDurabilityValue[last][1]))

      return low === high ? `+${low}` : `+${low}–${high}`
    }
    if (entry.kind === 'cooldown') {
      const low = EQUIPMENT.optionCooldownValue[first]
      const high = EQUIPMENT.optionCooldownValue[last]

      return low === high ? `−${low}` : `−${low}–${high}`
    }
    if (entry.kind === 'gain') {
      // §8.0.1 -- gloves haul on a shorter ladder than everything else.
      const table =
        def.slot === 'gloves' && entry.stat === 'haul'
          ? EQUIPMENT.optionGainValueGloves
          : EQUIPMENT.optionGainValue
      const low = table[first]
      const high = table[last]

      return low === high ? `+${pct(low)}` : `+${pct(low)}–${pct(high)}`
    }

    const low = EQUIPMENT.optionFlatValue[first][0]
    const high = EQUIPMENT.optionFlatValue[last][1]

    return `+${low}–${high}`
  }

  const LABEL: Record<string, string> = {
    attack: 'atk',
    defense: 'def',
    durability: 'dur',
    // §9.5.8 -- on a weapon the same line is the fight's haul, and "haul" on a
    // sword would leave a reader asking haul of what.
    haul: def.slot === 'weapon' ? 'drops' : 'haul',
    cooldown: 'cd',
    travel: 'travel',
  }

  return {
    // §8.0.1 -- one line per stat, so the POOL is a ceiling too. Quoting the
    // rung alone would promise a line the piece cannot carry.
    ceiling: Math.min(EQUIPMENT.optionRolls[def.rarity] ?? 0, pool.length),
    lines: [
      ...pool.map((entry) => ({ label: LABEL[entry.stat] ?? entry.stat, value: band(entry) })),
      // §8.0.1 -- it belongs in this row, because this is the list a player
      // reads to find out what a bench can put on a thing and the rarest answer
      // is the one worth seeing. It carries NO value: every other pip's number
      // is what the line is worth, and this line is not worth an amount -- it
      // either happened or it did not. Its odds are prose, above.
      { label: 'unbreakable', value: '' },
    ],
    unbreakable: pct(EQUIPMENT.optionIndestructibleChance),
    plainShelf: def.goldPrice !== undefined,
  }
}

type Half = 'materials' | 'equipment' | 'tiles' | 'monsters'

const half = ref<Half>('materials')
const query = ref('')

/**
 * The two halves share one scroll box, so switching while deep in the materials
 * would drop you into the middle of the equipment. Reset to the top -- the
 * switch is a change of subject, not a change of view onto the same list.
 */
const body = ref<HTMLElement | null>(null)
watch(half, () => body.value?.scrollTo({ top: 0 }))

const needle = computed(() => query.value.trim().toLowerCase())

const matches = (haystack: string) =>
  needle.value === '' || haystack.toLowerCase().includes(needle.value)

// ------------------------------------------------------------------ materials

/**
 * A tier is not always one kind of thing, so the page bands rather than tiers.
 *
 * Tier 0 holds two things §4 is emphatic are different: scrap is what a hex
 * gives up to bare hands, junk is the rubbish carried out alongside, and the
 * argument about what a missing tool costs you only applies to the first.
 * Tier 1 holds three: the ground's own grades, and the two bench stocks. One
 * paragraph cannot be true of all of them, and thirty-five cards in a single
 * unlabeled run is a wall rather than a list.
 */
interface Band {
  key: string
  tier: MaterialTier
  name: string
  note: string
  holds: (mat: Material) => boolean
}

const SCRAP_KEYS = new Set<string>(Object.values(BIOME_SCRAP))

const BANDS: Band[] = [
  {
    key: 'scrap',
    tier: 0,
    name: 'Scrap',
    note: 'What a hex gives up to bare hands, on any ring: the same haul as the real thing, a fraction of the worth. It feeds no recipe and reaches no other tier, so it never enters the economy the sinks have to balance — it exists to make your first tool obviously worth buying.',
    holds: (mat) => SCRAP_KEYS.has(mat.key),
  },
  {
    key: 'junk',
    tier: 0,
    name: 'Junk',
    note: 'Sells for the same copper and feeds the same nothing, but it is not scrap: this is the rubbish carried out alongside a real haul, not what a missing tool costs you. Kept apart because that distinction is the whole of the argument above. A fight and a hunt leave their own — one saying what you took, one saying where you were standing.',
    holds: () => true,
  },
  {
    key: 'ground',
    tier: 1,
    name: 'The ground',
    note: `Four grades apiece, and what you are standing on decides which one you get — a variant of hex for the four countries, and for hunting the ANIMAL, which carries the same ladder off a creature instead of off ground. The base grade is everywhere; the better ones start at the middle ring and the best is contested. This is the bulk of what fills a bag — ${BAG.slots} straps is all a prospector carries, and a strap holds ${BAG.stackMaterial} of one material, so a big haul is several of them.`,
    holds: (mat) => rawRole(mat.key) === 'ground',
  },
  {
    key: 'reagent',
    tier: 1,
    name: "The alchemist's stock",
    note: 'Two apiece, and the consumable bench runs on these alone — no potion wants anything a smith would bid for. Eight grow on a kind of ground and two come off a hunt, which is the same split the whole hunting line has: four countries and a creature. Worth more than scrap, which §4 makes a rule rather than a tuning value.',
    holds: (mat) => rawRole(mat.key) === 'reagent',
  },
  {
    key: 'critter',
    tier: 1,
    name: "The alchemist's other stock",
    note: 'Five small animals — one on each of the four countries, and the hare off a hunt. The herbs say what grows on a kind of ground; these say what lives on it. Every one of them is taken with the line\'s tool and never picked up by hand, which is why the top rungs of the potion shelf wait on a tool you had to buy rather than on an afternoon with your hands.',
    holds: (mat) => rawRole(mat.key) === 'critter',
  },
  {
    key: 'spoil',
    tier: 1,
    name: 'Off a monster',
    note: 'Cut off a pack rather than out of a hex (§9.5). Two families of five graded by the tier of the thing carrying them — the plate line for the smith and the armorer, the ichor line for the consumable bench — so the best of them are only found in the center, where the worst things stand. Beside those, one stock per country, off that country\'s own five and nothing else: the one drop you cannot get by walking inward on ground you already know. Combat feeds combat; none of this reaches the mining economy.',
    holds: (mat) => rawRole(mat.key) === 'spoil',
  },
  {
    key: 'component',
    tier: 1,
    name: 'The smith and the armorer',
    note: 'The same idea again, for the other two benches: two apiece, one named for each. Eight come off a kind of ground and two — the horn and the sinew — come off a hunt, because that is where the hunting line\'s stock has always come from. Every crafted thing wants its line’s component, so these sit beside the raw and the refined in every recipe rather than replacing them.',
    holds: (mat) => rawRole(mat.key) === 'component',
  },
  {
    key: 'refined',
    tier: 2,
    name: 'Refined',
    note: `Made at settlements, never mined, and every grade refines on the same three-to-one the base line does — a better grade is a better material, never a better ratio. A village runs one line of five, a city two, a capital all of them, which is most of what makes a capital worth the walk. Every station has ${PROCESSING.publicSlots} public slots, first come first served, so a busy capital queues.`,
    holds: () => true,
  },
  {
    key: 'rare',
    tier: 3,
    name: 'Rare',
    note: `Contested ring only — four off ground that finally looks like itself, and the fifth off an animal nothing has taken down in living memory. Capped at ${ECONOMY.rareWalletCap} per wallet, every one of them, because this is the gate every mintable recipe stands behind: a thousand bot wallets get a thousand capped hauls, which is the point.`,
    holds: () => true,
  },
  {
    key: 'raid',
    tier: 4,
    name: 'Raid',
    note: 'Dungeon-sourced and not biome-locked. Shards are typed to their dungeon, so a top-tier tool always means crossing the map.',
    holds: () => true,
  },
]

interface MaterialEntry {
  mat: Material
  sources: SourceLine[]
  uses: ReturnType<typeof materialUses>
  hay: string
}

const materialEntries = computed<MaterialEntry[]>(() =>
  Object.values(MATERIALS).map((mat) => {
    const sources = materialSources(mat)
    const uses = materialUses(mat)
    return {
      mat,
      sources,
      uses,
      hay: [
        mat.name,
        mat.description,
        ...sources.map((s) => `${s.where} ${s.note ?? ''}`),
        ...uses.processedInto.map((m) => m.name),
        ...uses.craftedInto.map((i) => i.name),
      ].join(' '),
    }
  }),
)

/**
 * First band whose tier matches and whose predicate says yes, so the last band
 * of a tier is its catch-all and nothing can fall out of the list entirely.
 */
const materialsByBand = computed(() => {
  const groups: Record<string, MaterialEntry[]> = {}
  for (const band of BANDS) groups[band.key] = []

  for (const entry of materialEntries.value) {
    if (!matches(entry.hay)) continue
    const band = BANDS.find((b) => b.tier === entry.mat.tier && b.holds(entry.mat))
    if (band) groups[band.key]!.push(entry)
  }

  return groups
})

// ---------------------------------------------------------------------- tiles

/**
 * §5.3 -- a biome is four kinds of ground, and the variant decides what the hex
 * gives up.
 *
 * The other two halves answer "where does this come from" for a thing you can
 * hold. This one answers it for the ground itself, which is the only place the
 * four grades and their rings can be compared side by side -- the map shows you
 * one hex at a time, and by design shows you nothing at all about the ones you
 * have not scouted.
 */
const RING_REACH: Record<string, string> = {
  outer: 'Everywhere, including the safe outer rim',
  mid: 'Middle ring and inward',
  inner: 'Contested ring only',
}

const GRADE_LABEL: Record<string, string> = {
  common: 'Base',
  uncommon: 'Better',
  rare: 'Best',
  epic: 'Contested',
}

interface TileEntry {
  key: string
  name: string
  grade: string
  tint: string
  material: Material
  reach: string
  blurb: string
  hay: string
}

interface TileGroup {
  biome: Biome
  entries: TileEntry[]
}

const tileGroups = computed<TileGroup[]>(() =>
  (Object.keys(BIOME_VARIANTS) as Biome[]).map((biome) => ({
    biome,
    entries: BIOME_VARIANTS[biome]
      .map((variant: VariantDef) => {
        const material = MATERIALS[variant.material as MaterialKey]
        const rings: string[] = VARIANT_RINGS[variant.key] ?? []
        const entry: TileEntry = {
          key: variant.key,
          name: variant.name,
          grade: GRADE_LABEL[variant.grade] ?? variant.grade,
          tint: variant.tint,
          material,
          reach: RING_REACH[rings[0] ?? 'outer'] ?? '',
          blurb: VARIANT_DESCRIPTION[variant.key] ?? '',
          hay: '',
        }
        entry.hay = [entry.name, entry.blurb, material.name, entry.reach].join(' ')
        return entry
      })
      .filter((e: TileEntry) => matches(e.hay)),
  })).filter((g: TileGroup) => g.entries.length),
)

/**
 * §5.3 -- the two kinds of water, per biome.
 *
 * Kept out of the grade ladder above rather than tacked on as a fifth rung:
 * water is not a better or worse version of the ground, it is the one hex
 * neither verb answers to, and listing it beside Heartoak would imply it sat
 * somewhere on the same scale.
 */
interface WaterEntry {
  key: string
  biome: Biome
  kind: WaterKind
  name: string
  hay: string
}

const WATER_BLURB: Record<WaterKind, string> = {
  river: 'Four waterways cross the map end to end, and each takes the character of whatever it runs through.',
  lake: 'Standing water, scattered everywhere the ground allows. Never on a settlement or a dungeon mouth — those hold the hex.',
}

const waterEntries = computed<WaterEntry[]>(() =>
  (Object.keys(BIOME_VARIANTS) as Biome[])
    .flatMap((biome) =>
      (['river', 'lake'] as WaterKind[]).map((kind) => {
        const name = waterLabel(biome, kind)
        return {
          key: `${biome}.${kind}`,
          biome,
          kind,
          name,
          hay: [name, BIOME_LABEL[biome], WATER_BLURB[kind], 'water lake river'].join(' '),
        }
      }),
    )
    .filter((e) => matches(e.hay)),
)

/**
 * §5.7 -- rich ground, one entry per biome.
 *
 * The almanac is the one screen that answers "where does that come from", and
 * this is the only fact about a hex a player is otherwise expected to work out
 * by noticing that a haul came back bigger. The tell is an ANIMAL rather than a
 * symbol, so what belongs here is a field guide: five creatures, one per kind
 * of country, drawn exactly as the map draws them.
 */
const POCKET_BLURB =
  'Animals find the good ground before you do. Where one has settled, the hex '
  + 'pays half again on every haul — mined or gathered — for a few hours, and '
  + 'then it is ordinary ground again.'

const pocketEntries = computed(() =>
  (Object.keys(BIOME_VARIANTS) as Biome[])
    .map((biome) => {
      const critter = MATERIALS[CRITTER_BY_BIOME[biome] as MaterialKey]

      return {
        key: `pocket.${biome}`,
        biome,
        critter,
        hay: [
          critter.name,
          BIOME_LABEL[biome],
          'rich ground pocket haul yield bonus',
          POCKET_BLURB,
        ].join(' '),
      }
    })
    .filter((e) => matches(e.hay)),
)

// -------------------------------------------------------------- monsters §9.5

/**
 * §9.5.2 -- the bestiary.
 *
 * The one thing on the map that is not terrain and not a settlement, and the
 * only one you meet by being stopped rather than by going to it. The almanac
 * owes it the same two answers it owes everything else: where it comes from,
 * and what comes off it.
 *
 * Static like the rest of this screen. A monster's numbers are the same for
 * everybody and the client already mirrors them, so this is a pure read -- no
 * store, no request, correct with no character at all.
 */
const PROFILE_NOTE: Record<string, string> = {
  brute: 'Hits hard, guards badly',
  carapace: 'Guards hard, hits badly',
  swift: 'Middling at both, and blunts what it is hit with',
}

/** §9.5.1 -- density climbs every ring inward, so the last one it stands on is
 *  where you meet it most. A design rule rather than a figure: the odds
 *  themselves are the server's and this screen talks to nothing. */
const monsterEntries = computed(() =>
  Object.values(MONSTERS)
    .map((m) => {
      // §9.5.2 -- a monster stands on one country, so the rings it is out on
      // are that country's rings and no others.
      const byRing = MONSTERS_BY_BIOME_RING[m.biome] ?? {}
      const rings = (Object.keys(byRing) as Ring[]).filter((r) =>
        byRing[r]!.includes(m.key),
      )
      const trophy = TROPHY_BY_TIER[m.tier]
      const name = (key: string) => MATERIALS[key as MaterialKey]?.name ?? key

      return {
        ...m,
        rings,
        /* Where it is thickest: the innermost ring it stands on. */
        home: rings[rings.length - 1] ?? 'outer',
        // The MATERIAL rather than its name: every list in this almanac draws
        // the thing beside the word, and a bestiary that only spelled its drops
        // would be the one screen where you cannot recognise them on sight.
        drops: [
          MATERIALS[m.plate],
          MATERIALS[m.ichor],
          ...(m.rareSpoil ? [MATERIALS[m.rareSpoil]] : []),
          ...(trophy ? [MATERIALS[trophy as MaterialKey]] : []),
          // §9.5.8 -- the two that say where it lived, which is the half of a
          // bestiary entry biome-locking made worth reading.
          MATERIALS[m.biomeSpoil],
          MATERIALS[m.biomeLeaving as MaterialKey],
        ],
        hay: [
          m.name,
          m.profile,
          m.description,
          ...rings.map((r) => RING_LABEL[r]),
          name(m.plate),
          name(m.ichor),
          m.rareSpoil ? name(m.rareSpoil) : '',
          trophy ? name(trophy) : '',
          BIOME_LABEL[m.biome as keyof typeof BIOME_LABEL] ?? m.biome,
          name(m.biomeSpoil),
          name(m.biomeLeaving),
        ].join(' '),
      }
    })
    .filter((m) => matches(m.hay))
    .sort((a, b) => a.tier - b.tier || a.name.localeCompare(b.name)),
)

/**
 * §5.5 -- the eight animals, which is the other half of what stands on a hex.
 *
 * Filed with the monsters rather than with the ground, and that is a judgment
 * about what a reader is asking. "The animal is the ground" is true of how a
 * hunt WORKS -- it is a mine with a creature in the seam's place -- and it is
 * not true of how one is found: you find an animal by seeing a creature, the
 * same way you find a pack, and this is the screen you come to afterwards to
 * ask what it was.
 *
 * What it owes is the same two answers everything else here owes: where it
 * comes from, and what comes off it. The second is the one the game had
 * nowhere to say at all -- a hunt paid a rung of hide, two components, two
 * reagents and a critter, and none of that was written down anywhere a player
 * could read it before taking one.
 */
const animalEntries = computed(() =>
  Object.values(ANIMALS)
    .map((a) => {
      const name = (key: string) => MATERIALS[key as MaterialKey]?.name ?? key

      return {
        ...a,
        // The rings this grade is actually rolled on. A contested animal is
        // inner-and-in and nowhere else, which is §2 keeping Beastfang Hide
        // off the safe rim -- and it is the one fact here worth walking for.
        rings: (() => {
          const w = HUNT_GRADES.find((g) => g.grade === a.grade)?.weights ?? {}
          const out = (['outer', 'mid', 'inner'] as Ring[]).filter((r) => (w[r] ?? 0) > 0)

          // §5.2 -- the center rolls on the INNER ring's column, because it is
          // the contested ring with the towns taken out. The weight table has
          // three columns for that reason, and a bestiary that listed them
          // literally would say a Beastfang Sire does not stand at a dungeon
          // mouth, which is the one place it most certainly does.
          return out.includes('inner') ? [...out, 'center' as Ring] : out
        })(),
        // The MATERIAL rather than its name, like every other list here: a
        // screen that only spelled its drops would be the one you cannot
        // recognise anything on.
        drops: [
          MATERIALS[a.material],
          ...HUNT_PARTS.map((k) => MATERIALS[k as MaterialKey]),
          // §5.5 -- the one drop the RUNG pays for, so a common animal's list
          // is genuinely shorter than the rest and the eight entries differ by
          // something a player can act on.
          ...(carriesGradedPart(a.grade) ? [MATERIALS[HUNT_GRADED_PART as MaterialKey]] : []),
          MATERIALS[HUNT_JUNK as MaterialKey],
          MATERIALS[HUNT_LEAVING as MaterialKey],
        ].filter(Boolean),
        hay: [
          a.name,
          a.grade,
          a.description,
          BIOME_LABEL[a.biome as keyof typeof BIOME_LABEL] ?? a.biome,
          name(a.material),
          ...HUNT_PARTS.map(name),
          carriesGradedPart(a.grade) ? name(HUNT_GRADED_PART) : '',
          name(HUNT_JUNK),
          name(HUNT_LEAVING),
        ].join(' '),
      }
    })
    .filter((a) => matches(a.hay))
    .sort(
      (a, b) =>
        HUNT_GRADE_ORDER.indexOf(a.grade) - HUNT_GRADE_ORDER.indexOf(b.grade) ||
        a.biome.localeCompare(b.biome),
    ),
)

/** §5.3's own four, in the order the ladder climbs. */
const HUNT_GRADE_ORDER = HUNT_GRADES.map((g) => g.grade)

/**
 * §5.5 -- which rungs carry the graded part, read off the ladder rather than
 * off a list, exactly as the server reads it.
 */
const carriesGradedPart = (grade: string) =>
  HUNT_GRADE_ORDER.indexOf(grade) >= HUNT_GRADE_ORDER.indexOf(HUNT_GRADED_FROM)

/**
 * §5.5 -- what a hunt pays that is not the hide, said once above the eight.
 *
 * Every animal drops the same parts; only the hide rung differs. Repeating the
 * sentence on all eight entries would be eight copies of one fact.
 */
const HUNT_BLURB =
  'An animal is a mine with the creature in the seam\'s place: the same hex, the same ' +
  'arithmetic, worked at the bow\'s rate. Which one is standing there decides the rung of ' +
  'hide, and nothing else about the kill changes — the horn, the sinew and the two herbs ' +
  'come off every one of them. Without a bow the hide comes back as Torn Hide, which no ' +
  'recipe anywhere will take.'

/** By tier, which is the ring each one is NEW on -- the bestiary's own order. */
const monsterBands = computed(() =>
  [1, 2, 3, 4]
    .map((tier) => ({ tier, entries: monsterEntries.value.filter((m) => m.tier === tier) }))
    .filter((b) => b.entries.length > 0),
)

// §5.5 -- the half's own tally, and it counts the game as well: a search for
// "Roe Deer" that answered "nothing matches" over an entry drawn right below
// it would be the count disagreeing with the page.
const monsterCount = computed(() => monsterEntries.value.length + animalEntries.value.length)

const tileCount = computed(
  () =>
    tileGroups.value.reduce<number>((n, g) => n + g.entries.length, 0) +
    waterEntries.value.length +
    pocketEntries.value.length,
)

// ------------------------------------------------------------------ equipment

/**
 * Grouped by slot rather than by rarity, because the slot *is* the ladder: §8.0
 * gives every gathering line the same five rungs on purpose, and stacking them
 * in one column is the only view that shows a line is not quietly weaker than
 * its neighbors.
 */
const SLOT_ORDER: EquipSlot[] = [
  'axe',
  'pickaxe',
  'bow',
  'hammer',
  'sickle',
  'armor',
  'boots',
  'gloves',
]

const WORN = 'Worn, and one set with two axes rather than a second wardrobe: the work stat that counts on every line, and the flat pair that decides a fight. Combat-leaning pieces sit beside work-leaning ones at every rung, so the decision belongs at the bench and never in front of a pack.'

const WORN_NOTE: Partial<Record<EquipSlot, string>> = {
  armor: WORN,
  boots: WORN,
  gloves: WORN,
}

/**
 * §9.5.4 -- the weapon slot is three ladders, not one.
 *
 * Every other slot is one line and one climb, which is why the page groups by
 * slot at all. This one holds three families competing for the same hand, and
 * the family you carry is the battle job you level -- so stacking thirty
 * unrelated pieces under a single "Weapon" heading would hide the only choice
 * the slot actually asks you to make.
 */
const FAMILIES: Array<{ key: 'shield' | 'sword' | 'focus'; title: string; sub: string }> = [
  {
    key: 'shield',
    title: 'Shield',
    sub: 'Weapon slot · Shieldbearer. A third attack to two thirds guard, on a larger budget than the other two carry — a shieldbearer has no offense anywhere else in the kit. It is always the most expensive win: a slow kill is more rounds, and more rounds is more of both wear streams.',
  },
  {
    key: 'sword',
    title: 'Sword',
    sub: 'Weapon slot · Swordhand. An even split, and the reference the other two are read against. Balanced means the two numbers are the same, not a bit of both.',
  },
  {
    key: 'focus',
    title: 'Focus',
    sub: 'Weapon slot · Runecaster. Four fifths attack, and the guard that is left is small rather than nothing — a focus that stopped none of it would make the sword the balanced one twice over. The only kit in the game whose hardest fight is genuinely uncertain.',
  },
]

/** §9.5.4 -- which battle job the family in the slot levels. */
const FAMILY_JOB: Record<string, string> = {
  shield: 'Shieldbearer',
  sword: 'Swordhand',
  focus: 'Runecaster',
}

interface ItemEntry {
  item: ItemDef
  sources: SourceLine[]
  /** §8.0.1 -- computed once per entry rather than per read: the template asks
   *  about it half a dozen times, and it is the same answer every time. */
  rolls: RollBrief | null
  hay: string
}

interface Group {
  key: string
  title: string
  sub: string
  entries: ItemEntry[]
}

const describe = (item: ItemDef): ItemEntry => {
  const sources = itemSources(item)
  return {
    item,
    sources,
    rolls: rollBrief(item),
    hay: [
      item.name,
      item.description,
      ...sources.map((s) => `${s.where} ${s.note ?? ''}`),
      ...sources.flatMap((s) => (s.cost ?? []).map((c) => MATERIALS[c.key].name)),
    ].join(' '),
  }
}

const byRung = (a: ItemEntry, b: ItemEntry) =>
  RARITY_RANK[a.item.rarity] - RARITY_RANK[b.item.rarity] ||
  (a.item.value ?? 0) - (b.item.value ?? 0)

const groups = computed<Group[]>(() => {
  const built: Group[] = SLOT_ORDER.map((slot) => {
    const line = skillForSlot(slot)
    const skill = line ? SKILL_BY_KEY[line] : null
    const biome = skill ? MATERIALS[skill.material].biome : undefined

    return {
      key: slot,
      title: SLOT_LABEL[slot],
      sub: skill
        ? `${skill.name} · ${BIOME_LABEL[biome!]} hexes. Pays out on this line and no other.`
        : (WORN_NOTE[slot] ?? ''),
      entries: ITEMS.filter((i) => i.slot === slot)
        .map(describe)
        .filter((e) => matches(e.hay))
        .sort(byRung),
    }
  })

  for (const family of FAMILIES) {
    built.push({
      key: family.key,
      title: family.title,
      sub: family.sub,
      entries: ITEMS.filter((i) => i.family === family.key)
        .map(describe)
        .filter((e) => matches(e.hay))
        .sort(byRung),
    })
  }

  built.push({
    key: 'consumable',
    title: 'Consumables',
    sub: 'Drunk, never worn. One charge per stat per action, spent by taking that action — and being spent is the sink.',
    entries: ITEMS.filter((i) => i.consumable)
      .map(describe)
      .filter((e) => matches(e.hay))
      .sort(byRung),
  })

  return built
})

/** Empty groups stay when browsing -- an empty rung is information (§8.0 rule 5)
 *  -- but vanish while searching, where they would only be noise. */
const shownGroups = computed(() =>
  needle.value === '' ? groups.value : groups.value.filter((g) => g.entries.length > 0),
)

const materialCount = computed(() =>
  BANDS.reduce<number>((n, band) => n + materialsByBand.value[band.key]!.length, 0),
)

const itemCount = computed(() => groups.value.reduce((n, g) => n + g.entries.length, 0))

/** What the thing is, in one line: where it goes, what it serves, how long. */
function nature(item: ItemDef): string {
  if (item.consumable) {
    return `Drunk · one ${SCOPE_ACTION[item.scope ?? 'global']}, then gone`
  }
  if (item.family) {
    return `Weapon slot · ${FAMILY_JOB[item.family]} · ${item.maxDurability} durability`
  }
  const line = item.slot ? skillForSlot(item.slot) : null
  const scope = line ? SKILL_BY_KEY[line].name.toLowerCase() : 'every line'
  return `${SLOT_LABEL[item.slot!]} slot · ${scope} · ${item.maxDurability} durability`
}
</script>

<template>
  <div class="almanac">
    <!-- The bar carries the two halves, the search, and the legend that teaches
         the five-road vocabulary every entry below uses. -->
    <header class="masthead">
      <div class="controls">
        <div class="modes">
          <button type="button" :class="{ on: half === 'materials' }" @click="half = 'materials'">
            Materials <span class="tally">{{ materialCount }}</span>
          </button>
          <button type="button" :class="{ on: half === 'equipment' }" @click="half = 'equipment'">
            Equipment <span class="tally">{{ itemCount }}</span>
          </button>
          <button type="button" :class="{ on: half === 'tiles' }" @click="half = 'tiles'">
            Ground <span class="tally">{{ tileCount }}</span>
          </button>
          <button type="button" :class="{ on: half === 'monsters' }" @click="half = 'monsters'">
            Creatures <span class="tally">{{ monsterCount }}</span>
          </button>
        </div>

        <label class="search">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"
               stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
            <path d="M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14 M16.2 16.2 21 21" />
          </svg>
          <input v-model="query" type="search" placeholder="Search by name, source or ingredient" />
        </label>
      </div>

      <ul class="legend">
        <li class="label">Five roads in</li>
        <li v-for="kind in SOURCE_KINDS" :key="kind" :style="{ '--road': SOURCE_COLOR[kind] }">
          <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor"
               stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path :d="ACTION_PATHS[SOURCE_ICON[kind]]" />
          </svg>
          {{ SOURCE_LABEL[kind] }}
        </li>
      </ul>
    </header>

    <div ref="body" class="body scroll">
      <!-- ------------------------------------------------------- materials -->
      <template v-if="half === 'materials'">
        <p v-if="!materialCount" class="nothing tiny muted">
          Nothing matches “{{ query }}”.
        </p>

        <section v-for="band in BANDS" :key="band.key" v-show="materialsByBand[band.key]!.length">
          <div class="sect">
            <h3>Tier {{ band.tier }} <span class="dot">·</span> {{ band.name }}</h3>
            <span class="tally">{{ materialsByBand[band.key]!.length }}</span>
          </div>
          <p class="tiny muted sect-note">{{ band.note }}</p>

          <div class="entries">
            <article v-for="entry in materialsByBand[band.key]!" :key="entry.mat.key" class="entry">
              <div class="head">
                <SvgIcon :svg="materialIcon(entry.mat, 30)" boxed :size="30" />
                <div class="grow">
                  <span v-if="entry.mat.biome" class="label eyebrow">
                    {{ BIOME_LABEL[entry.mat.biome] }}
                  </span>
                  <strong class="name">{{ entry.mat.name }}</strong>
                </div>
              </div>

              <p class="tiny muted desc">{{ entry.mat.description }}</p>

              <dl class="rails">
                <div
                  v-for="(source, i) in entry.sources"
                  :key="i"
                  class="rail"
                  :style="{ '--road': SOURCE_COLOR[source.kind] }"
                >
                  <dt class="label">{{ SOURCE_LABEL[source.kind] }}</dt>
                  <dd>
                    <span class="where">{{ source.where }}</span>
                    <span v-if="source.pending" class="tag">not built yet</span>
                    <div v-if="source.cost" class="cost">
                      <span v-for="c in source.cost" :key="c.key" class="pip mat">
                        <SvgIcon :svg="materialIcon(MATERIALS[c.key], 15)" />
                        {{ c.qty }} {{ MATERIALS[c.key].name }}
                      </span>
                    </div>
                    <p v-if="source.note" class="tiny muted note">{{ source.note }}</p>
                  </dd>
                </div>

                <!-- Downstream. Neutral rail: this is the one line that is not a
                     road in, and scrap having nothing here is the whole point. -->
                <div class="rail out">
                  <dt class="label">Feeds</dt>
                  <dd>
                    <template
                      v-if="entry.uses.processedInto.length || entry.uses.craftedInto.length"
                    >
                      <div class="feeds">
                        <span
                          v-for="out in entry.uses.processedInto"
                          :key="out.key"
                          class="pip mat"
                        >
                          <SvgIcon :svg="materialIcon(out, 15)" />{{ out.name }}
                        </span>
                        <span
                          v-for="made in entry.uses.craftedInto"
                          :key="made.key"
                          class="pip"
                          :class="`rarity-${made.rarity}`"
                        >
                          {{ made.name }}
                        </span>
                      </div>
                    </template>
                    <p v-else class="tiny muted note flat">
                      <template v-if="entry.uses.sellsFor">
                        Nothing. Selling it is all it is for.
                      </template>
                      <template v-else>
                        Nothing yet, and the trader will not take it either.
                      </template>
                    </p>
                  </dd>
                </div>

                <!-- §3.2 -- what it is worth at a counter. Its own rail rather
                     than a sentence inside Feeds: a price is a fact about the
                     thing, not one of the roads it travels. -->
                <div class="rail coin">
                  <dt class="label">Trader</dt>
                  <dd>
                    <template v-if="entry.uses.sellsFor">
                      <span class="price">{{ entry.uses.sellsFor }}g</span>
                      <span class="tiny muted"> each</span>
                    </template>
                    <!-- §3.3 -- gold has no bridge to NFT value, so the trader
                         simply will not touch a rare or a raid material. -->
                    <span v-else class="tiny muted">Will not touch it.</span>
                  </dd>
                </div>
              </dl>
            </article>
          </div>
        </section>
      </template>

      <!-- ------------------------------------------------------- equipment -->
      <template v-else-if="half === 'equipment'">
        <p v-if="!itemCount" class="nothing tiny muted">Nothing matches “{{ query }}”.</p>

        <section v-for="group in shownGroups" :key="group.key">
          <div class="sect">
            <h3>{{ group.title }}</h3>
            <span class="tally">{{ group.entries.length }}</span>
          </div>
          <p class="tiny muted sect-note">{{ group.sub }}</p>

          <p v-if="!group.entries.length" class="tiny muted vacant">
            No rung on this ladder is filled.
          </p>

          <div v-else class="entries">
            <article v-for="entry in group.entries" :key="entry.item.key" class="entry">
              <div class="head">
                <SvgIcon
                  :svg="itemIcon({
                    slot: entry.item.slot,
                    family: entry.item.family,
                    rarity: entry.item.rarity,
                    palette: entry.item.palette,
                    size: 30,
                  })"
                  boxed
                  :size="30"
                />
                <div class="grow">
                  <span class="label eyebrow" :class="`rarity-${entry.item.rarity}`">
                    {{ RARITY_LABEL[entry.item.rarity] }}
                  </span>
                  <strong class="name" :class="`rarity-${entry.item.rarity}`">
                    {{ entry.item.name }}
                  </strong>
                </div>
                <span v-if="entry.item.tradeable" class="chip tiny chip-nft">NFT</span>
              </div>

              <!-- §9.5.4 -- the almanac is where a piece is read before it is
                   owned, so it has to say every half of what it is. -->
              <div class="row tiny stats"><StatChips :def="entry.item" /></div>

              <p class="tiny muted desc">{{ entry.item.description }}</p>
              <p class="tiny meta">{{ nature(entry.item) }}</p>

              <dl class="rails">
                <div
                  v-for="(source, i) in entry.sources"
                  :key="i"
                  class="rail"
                  :style="{ '--road': SOURCE_COLOR[source.kind] }"
                >
                  <dt class="label">{{ SOURCE_LABEL[source.kind] }}</dt>
                  <dd>
                    <!-- The price is NOT here. This rail says where a thing
                         comes from; the Trader rail below says what it costs,
                         both directions, and one number in two places is one
                         number too many. -->
                    <span class="where">{{ source.where }}</span>
                    <span v-if="source.pending" class="tag">not built yet</span>
                    <div v-if="source.cost" class="cost">
                      <span v-for="c in source.cost" :key="c.key" class="pip mat">
                        <SvgIcon :svg="materialIcon(MATERIALS[c.key], 15)" />
                        {{ c.qty }} {{ MATERIALS[c.key].name }}
                      </span>
                    </div>
                    <p v-if="source.note" class="tiny muted note">{{ source.note }}</p>
                  </dd>
                </div>

                <!-- §8.0.1 -- what a bench MIGHT put on it. Always a solid
                     number on the pair, and the rail is narrower on a tool and
                     on a wand than on a coat: which of the two a piece is
                     eligible for is decided by what the piece is FOR. -->
                <div v-if="entry.rolls" class="rail rolls">
                  <dt class="label">Rolls</dt>
                  <dd>
                    <template v-if="entry.rolls!.ceiling">
                      <span class="where">
                        Up to {{ entry.rolls!.ceiling }}
                        {{ entry.rolls!.ceiling === 1 ? 'line' : 'lines' }}, at most one
                        of each below. Unbreakable has no number and takes no slot: it
                        is rolled apart from the rest, {{ entry.rolls!.unbreakable }} of
                        the time, and the piece simply never goes — at zero it is not
                        lost, only useless until it is mended.
                      </span>
                      <div class="cost">
                        <span v-for="l in entry.rolls!.lines" :key="l.label" class="pip roll solid">
                          <span class="key">{{ l.label }}</span>
                          <span v-if="l.value" class="mono">{{ l.value }}</span>
                        </span>
                      </div>
                      <p v-if="entry.rolls!.plainShelf" class="tiny muted note">
                        Nothing off a shelf ever rolls. A bought one is plain.
                      </p>
                    </template>
                    <span v-else class="tiny muted">
                      Never. A common piece is exactly what its recipe says.
                    </span>
                  </dd>
                </div>

                <!-- §8.2 -- the counter, both directions. Resale is quoted at
                     full condition: wear scales it, and what a particular
                     battered piece fetches belongs on the trader screen. -->
                <div class="rail coin">
                  <dt class="label">Trader</dt>
                  <dd>
                    <template v-if="traderLine(entry.item).buy">
                      <span class="price">{{ traderLine(entry.item).buy }}g</span>
                      <span class="tiny muted"> new</span>
                      <span class="tiny muted sep">·</span>
                      <span class="price back">{{ traderLine(entry.item).sell }}g</span>
                      <span class="tiny muted"> back, unworn</span>
                    </template>
                    <!-- §3.2 -- gold buys the bottom two rungs and never the
                         top, so there is no shelf price to halve. Salvage is
                         this gear's exit instead (§8.2). -->
                    <span v-else class="tiny muted">
                      Not stocked, and not bought back. Scrap it for materials.
                    </span>
                  </dd>
                </div>
              </dl>
            </article>
          </div>
        </section>
      </template>

      <!-- ----------------------------------------------------------- tiles -->
      <template v-if="half === 'tiles'">
        <p v-if="!tileCount" class="nothing tiny muted">
          Nothing matches “{{ query }}”.
        </p>

        <section v-for="group in tileGroups" :key="group.biome">
          <div class="sect">
            <h3>{{ BIOME_LABEL[group.biome] }}</h3>
            <span class="tally">{{ group.entries.length }}</span>
          </div>

          <div class="entries">
            <article v-for="tile in group.entries" :key="tile.key" class="entry">
              <div class="head">
                <span class="specimen" v-html="variantSpecimen(tile.key as VariantKey, 76)" />
                <div class="grow">
                  <span class="label eyebrow">{{ tile.grade }}</span>
                  <strong class="name">{{ tile.name }}</strong>
                </div>
              </div>

              <p v-if="tile.blurb" class="tiny muted desc">{{ tile.blurb }}</p>

              <dl class="rails">
                <div class="rail" :style="{ '--road': SOURCE_COLOR.mine }">
                  <dt class="label">Gives up</dt>
                  <dd>
                    <span class="pip mat">
                      <SvgIcon :svg="materialIcon(tile.material, 15)" />{{ tile.material.name }}
                    </span>
                  </dd>
                </div>

                <!-- Neutral rail: how far in you have to walk is not a road the
                     material arrives by, it is a fact about the ground. -->
                <div class="rail out">
                  <dt class="label">Found</dt>
                  <dd>{{ tile.reach }}</dd>
                </div>
              </dl>
            </article>
          </div>
        </section>
      </template>

      <!-- §5.7 -- rich ground. It sits with the tiles because it IS a fact
           about a hex, and above the water for the same reason the seams are:
           this is ground you can work, and better than usual. -->
      <template v-if="half === 'tiles' && pocketEntries.length">
        <section>
          <div class="sect">
            <h3>Rich ground</h3>
            <span class="tally">{{ pocketEntries.length }}</span>
          </div>

          <p class="tiny muted lede">{{ POCKET_BLURB }}</p>

          <div class="entries">
            <article v-for="p in pocketEntries" :key="p.key" class="entry">
              <div class="head">
                <span class="specimen" v-html="pocketSpecimen(p.biome, 76)" />
                <div class="grow">
                  <span class="label eyebrow">{{ BIOME_LABEL[p.biome] }}</span>
                  <strong class="name">{{ p.critter.name }}</strong>
                </div>
              </div>

              <p class="tiny muted desc">{{ p.critter.description }}</p>

              <dl class="rails">
                <div class="rail" :style="{ '--road': SOURCE_COLOR.mine }">
                  <dt class="label">Worth</dt>
                  <dd>Half again on every haul off this hex</dd>
                </div>

                <div class="rail out">
                  <dt class="label">Lasts</dt>
                  <dd>About four hours, then the ground is ordinary again</dd>
                </div>
              </dl>
            </article>
          </div>
        </section>
      </template>

      <template v-if="half === 'tiles' && waterEntries.length">
        <section>
          <div class="sect">
            <h3>Water</h3>
            <span class="tally">{{ waterEntries.length }}</span>
          </div>

          <div class="entries">
            <article v-for="w in waterEntries" :key="w.key" class="entry">
              <div class="head">
                <span class="specimen" v-html="waterSpecimen(w.biome, w.kind, 76)" />
                <div class="grow">
                  <span class="label eyebrow">{{ BIOME_LABEL[w.biome] }}</span>
                  <strong class="name">{{ w.name }}</strong>
                </div>
              </div>

              <p class="tiny muted desc">{{ WATER_BLURB[w.kind] }}</p>

              <dl class="rails">
                <div class="rail out">
                  <dt class="label">Gives up</dt>
                  <dd>Nothing. Water is worked by neither verb.</dd>
                </div>
              </dl>
            </article>
          </div>
        </section>
      </template>

      <!-- ------------------------------------------------------- monsters -->
      <template v-if="half === 'monsters'">
        <p v-if="!monsterCount" class="nothing tiny muted">
          Nothing matches “{{ query }}”.
        </p>

        <!-- §9.5.2 -- by tier, which is the ring each one is NEW on. That is
             the bestiary's own order and it is also the walk inward: the band
             is the difficulty and the entries inside it are the three reads. -->
        <section v-for="band in monsterBands" :key="band.tier">
          <div class="sect">
            <h3>Tier {{ band.tier }}</h3>
            <span class="tally">{{ band.entries.length }}</span>
          </div>

          <div class="entries">
            <article v-for="m in band.entries" :key="m.key" class="entry">
              <div class="head">
                <!-- §9.5.2 -- on a hex rather than in a crest. A crest is how
                     it looks in a fight; this is how it looks while you are
                     still deciding whether to have one, which is what a
                     bestiary is read for -- and it is the same drawing the map
                     puts on the tile, halo and all. -->
                <span class="specimen" v-html="monsterSpecimen(m.key, 76)" />
                <div class="grow">
                  <span class="label eyebrow">{{ m.profile }}</span>
                  <strong class="name">{{ m.name }}</strong>
                  <!-- §9.5.4/§9.5.5 -- the three solid numbers a fight is
                       decided by. Flat, never percentages, so they are printed
                       as figures rather than as chips with signs on them. -->
                  <span class="figs tiny mono">
                    {{ m.attack }} atk · {{ m.defense }} def · {{ m.hp }} pool
                  </span>
                </div>
              </div>

              <p class="tiny muted desc">{{ m.description }}</p>

              <dl class="rails">
                <!-- §9.5.2 -- the profile is what a player reads instead of a
                     level, so it gets a line rather than an eyebrow: it is the
                     one fact here that says how to FIGHT the thing. -->
                <div class="rail out">
                  <dt class="label">Fights</dt>
                  <dd>{{ PROFILE_NOTE[m.profile] }}</dd>
                </div>

                <!-- Neutral rail: where a thing stands is a fact about the map
                     rather than a road anything arrives by. -->
                <div class="rail out">
                  <dt class="label">Found</dt>
                  <dd>
                    {{ m.rings.map((r) => RING_LABEL[r]).join(' · ') }}
                    <span class="muted"> — thickest on the {{ RING_LABEL[m.home].toLowerCase() }}</span>
                  </dd>
                </div>

                <div class="rail" :style="{ '--road': SOURCE_COLOR.dungeon }">
                  <dt class="label">Drops</dt>
                  <dd class="pips">
                    <span class="pip coin">{{ m.gold[0] }}–{{ m.gold[1] }} gold</span>
                    <span v-for="d in m.drops" :key="d.key" class="pip mat">
                      <SvgIcon :svg="materialIcon(d, 15)" />{{ d.name }}
                    </span>
                  </dd>
                </div>
              </dl>
            </article>
          </div>
        </section>

        <!-- §5.5 -- the other thing that stands on a hex, and the only one you
             are meant to walk toward. It sits under the monsters rather than
             above them because the bestiary's own order is the walk inward,
             and the hunt is a ladder crossing all of it. -->
        <section v-if="animalEntries.length">
          <div class="sect">
            <h3>Game</h3>
            <span class="tally">{{ animalEntries.length }}</span>
          </div>

          <p class="tiny muted lede">{{ HUNT_BLURB }}</p>

          <div class="entries">
            <article v-for="a in animalEntries" :key="a.key" class="entry">
              <div class="head">
                <span class="specimen" v-html="animalSpecimen(a.key, 76)" />
                <div class="grow">
                  <span class="label eyebrow">{{ BIOME_LABEL[a.biome as Biome] }}</span>
                  <strong class="name">{{ a.name }}</strong>
                  <span class="figs tiny mono">{{ a.grade }} rung</span>
                </div>
              </div>

              <p class="tiny muted desc">{{ a.description }}</p>

              <dl class="rails">
                <!-- Neutral rail: where a thing stands is a fact about the map
                     rather than a road anything arrives by. -->
                <div class="rail out">
                  <dt class="label">Found</dt>
                  <dd>{{ a.rings.map((r) => RING_LABEL[r]).join(' · ') }}</dd>
                </div>

                <div class="rail" :style="{ '--road': SOURCE_COLOR.mine }">
                  <dt class="label">Gives up</dt>
                  <dd class="pips">
                    <span v-for="d in a.drops" :key="d.key" class="pip mat">
                      <SvgIcon :svg="materialIcon(d, 15)" />{{ d.name }}
                    </span>
                  </dd>
                </div>
              </dl>
            </article>
          </div>
        </section>
      </template>

      <p class="tiny muted footnote">
        <template v-if="half === 'materials'">
          Resources never move between players — there is no trade, no gift, no
          mailbox. Everything above is either dug out of the ground yourself or
          made from something that was.
        </template>
        <template v-else-if="half === 'tiles'">
          Terrain is a pure function of where it is and the world seed, so the
          client draws the land itself at any distance — but a hex outside your
          sight keeps everything only the server knows: what is depleted, who is
          working it, and what it would pay. Four grades a biome, and the better
          ground is further in on purpose.
        </template>
        <template v-else-if="half === 'monsters'">
          A pack stands on a hex for two hours and stops whoever walks onto it.
          Five to a country, and a country's five stand on that country and
          nowhere else. Density climbs every ring inward and a ring fields its
          own tier and the one beyond it — so walking in you meet one you know
          how to fight and one you do not, and every ring runs all three reads. Clearing one removes it for everybody, win or lose, and there is
          no second roll: supply is capped by hexes and hours rather than by
          patience. Nothing here drops a rare material, a raid material, or
          anything that can be minted. Game stands on the same buckets and is
          the opposite errand — a hex you walk toward rather than around, and
          the hunting line's whole faucet.
        </template>

        <template v-else>
          A bench reaches exactly as far as its tier, whatever you carry to it, and
          gold reaches the bottom two rungs and stops at every settlement — a shelf
          sells a plain piece at every tier including a capital's, and a rolled bonus
          line is the one thing only a bench can put on a thing. Every rung climbs
          toward one ceiling of {{ formatPercent(EQUIPMENT.statCeiling) }} and nothing
          passes it: not a rarity, not a rolled option, not a potion. Legendary is
          guild-hall work, so it wants a guild that has built its bench that far;
          unique has no bench at all and only ever drops.
        </template>
      </p>
    </div>
  </div>
</template>

<style scoped>
.stats {
  gap: 4px;
  margin-top: 4px;
}

/* The page hands over the height left under its title strip, and nothing else:
   scrolling and padding belong here, because the bar has to stay put while the
   entries move under it. */
.almanac {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}

/* ---------------------------------------------------------------- masthead */

/* Not `.bar` -- app.css already owns that name for progress meters, and the
   global rule's height won this box down to its own padding. */
.masthead {
  flex: 0 0 auto;
  padding: 12px 16px 10px;
  border-bottom: 1px solid var(--hud-line-soft);
  background: var(--ink);
}

.controls {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.modes {
  display: flex;
  gap: 4px;
}

.modes button {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 6px 14px;
  border: 1px solid var(--line);
  background: var(--ink-panel);
  color: var(--vellum-dim);
  font-family: inherit;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  clip-path: var(--plate-clip);
}

.modes button:hover {
  color: var(--vellum);
}

.modes button.on {
  background: var(--ink-raised);
  border-color: var(--copper);
  color: var(--vellum);
}

.tally {
  font-size: 10px;
  font-weight: 700;
  color: #7b8580;
}

.modes button.on .tally {
  color: var(--copper);
}

.search {
  display: flex;
  align-items: center;
  gap: 7px;
  /* Capped: on a wide screen an uncapped field runs the width of the page and
     reads as a form, when what the bar is is an instrument. */
  flex: 1 1 200px;
  min-width: 160px;
  max-width: 420px;
  padding: 0 10px;
  border: 1px solid var(--line);
  background: var(--ink-panel);
  color: #7b8580;
  clip-path: var(--plate-clip);
}

.search:focus-within {
  border-color: var(--copper);
  color: var(--copper);
}

.search input {
  flex: 1 1 auto;
  min-width: 0;
  padding: 7px 0;
  border: 0;
  background: none;
  color: var(--vellum);
  font-family: inherit;
  font-size: 12px;
}

.search input:focus {
  outline: none;
}

.search input::placeholder {
  color: #6d7770;
}

/* The vocabulary, stated once. Every rail below is one of these five. */
.legend {
  display: flex;
  align-items: center;
  gap: 4px 14px;
  flex-wrap: wrap;
  margin: 10px 0 0;
  padding: 0;
  list-style: none;
}

.legend li {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 9.5px;
  font-weight: 700;
  letter-spacing: 0.13em;
  text-transform: uppercase;
  color: var(--road, #6d7770);
}

.legend li.label {
  color: #6d7770;
  padding-right: 4px;
  border-right: 1px solid var(--line);
}

/* -------------------------------------------------------------------- body */

.body {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  padding: 16px;
}

/* §5.7 -- one sentence for the whole group, because the five entries below say
   the same thing about five kinds of country and repeating it five times would
   be a field guide that explains itself over and over. */
.lede {
  margin: -2px 0 10px;
  max-width: 62ch;
  line-height: 1.55;
}

section + section {
  margin-top: 22px;
}

.sect {
  display: flex;
  align-items: baseline;
  gap: 9px;
}

.sect h3 {
  font-size: 15px;
}

.sect .dot {
  color: #5f6b64;
}

.sect .tally {
  font-size: 10.5px;
}

.sect-note {
  margin: 4px 0 10px;
  line-height: 1.5;
  max-width: 78ch;
}

.vacant {
  margin: 0;
  padding: 10px 12px;
  border: 1px dashed var(--line);
  color: #6d7770;
}

.nothing {
  padding: 24px 0;
  text-align: center;
}

.entries {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
  /* Cards size to their own content. Stretching them to match the tallest in
     the row leaves a shop item -- one rail, no note -- as a mostly empty box
     beside a crafted one. */
  align-items: start;
  gap: 10px;
}

/* ------------------------------------------------------------------ entry */

.entry {
  display: flex;
  flex-direction: column;
  padding: 11px 12px 10px;
  background: var(--ink-panel);
  border: 1px solid var(--line);
  clip-path: var(--plate-clip);
}

.head {
  display: flex;
  align-items: center;
  gap: 9px;
}

/* §13.2 -- a real tile, drawn by the same renderer the map uses, props and all.
   Tint alone would show half the difference: the treatment is the other half,
   and four greens are far easier to tell apart with trees standing on them.
   Line-height zero because the SVG is taller than its own tile and would
   otherwise push the name off the baseline. */
.specimen {
  flex: 0 0 auto;
  display: block;
  line-height: 0;
  margin: -18px 0 -14px;
}

.eyebrow {
  display: block;
  color: #6d7770;
  margin-bottom: 1px;
}

.name {
  font-size: 13px;
  line-height: 1.25;
}

.desc {
  margin: 8px 0 0;
  line-height: 1.5;
}

.meta {
  margin: 4px 0 0;
  color: #8a938c;
}

/* ------------------------------------------------------------------ rails */

.rails {
  margin: 10px 0 0;
  display: flex;
  flex-direction: column;
  gap: 7px;
}

/*
 * The signature. A hairline in the road's color, the road's name in caps, and
 * the destination beside it -- the same two-part shape whether the thing was
 * dug up, smelted, bought, forged or dropped.
 */
.rail {
  display: grid;
  /* Wide enough for PROCESSED, the longest of the five road names, at the
     tracking `.label` sets. Anything narrower runs the label into the entry. */
  grid-template-columns: 78px 1fr;
  gap: 8px;
  padding-left: 8px;
  border-left: 2px solid var(--road, var(--line));
}

.rail dt {
  padding-top: 1px;
  color: var(--road, #6d7770);
}

.rail.out {
  --road: var(--line);
}

.rail.out dt {
  color: #6d7770;
}

/* §3.2 -- the counter. Gold, because that is the one thing it deals in, and it
   is the only gold on an entry so the eye finds the price without hunting. */
.rail.coin {
  --road: var(--gold);
}

.price {
  font-family: var(--font-display);
  font-variant-numeric: tabular-nums;
  color: var(--gold);
}

/* §8.0.1 -- a maybe, not a fact, so it takes the dimmest road on the entry.
   Everything else in the rails happened or will happen; this one is the bench's
   luck, and it must not out-shout the recipe above it. */
.rail.rolls {
  --road: #4c5a51;
}

.pip.roll {
  padding: 1px 6px;
  /* §13 -- nothing is round, and this is smaller than the standard cut. */
  background: #1c2519;
  color: #b7d6a4;
}

/* The solid pair is told apart by its label, never by its color (§9.5.4). */
.pip.roll.solid .key {
  font-size: 8.5px;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--vellum-dim);
}

/* Buy-back is the lesser of the two numbers and reads as the lesser: same
   family, no color. Two golds side by side would make them look equal. */
.price.back {
  color: var(--vellum);
}

.sep {
  margin: 0 5px;
}

.rail dd {
  margin: 0;
  min-width: 0;
}

.where {
  font-size: 11.5px;
  line-height: 1.35;
  margin-right: 6px;
}

.tag {
  display: inline-block;
  padding: 1px 6px;
  border: 1px dashed #4c5a51;
  color: #7b8580;
  font-size: 9px;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  vertical-align: 1px;
}

.cost,
.feeds {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 8px;
  margin-top: 5px;
}

/* Left uncolored on purpose: an item pip is tinted by its rarity, and a rule
   here would outrank the global `.rarity-*` classes and flatten the ladder. */
.pip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
}

.pip.mat {
  color: var(--vellum-dim);
}

/* §9.5 -- the three solid numbers, under the name rather than beside it. Flat
   numbers have no roof (§9.5.4), so they get figures and never a meter. */
.figs {
  display: block;
  margin-top: 3px;
  color: var(--vellum-dim);
}

/* A dd holding pips is a row of them, not a sentence: without this the icons
   and the names run into one another as one long string. */
.rail dd.pips {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 3px 9px;
}

.pip.coin {
  color: var(--gold);
}

.note {
  margin: 4px 0 0;
  line-height: 1.45;
}

.note.flat {
  margin-top: 0;
}

.footnote {
  margin: 22px 0 0;
  padding-top: 12px;
  border-top: 1px solid var(--line);
  line-height: 1.55;
  max-width: 78ch;
}

@media (max-width: 560px) {
  .masthead {
    padding: 10px 12px 9px;
  }

  .body {
    padding: 12px;
  }

  .entries {
    grid-template-columns: 1fr;
  }

  /* 62px of gutter is a third of a phone's width. Stack instead, and let the
     color rail carry the grouping that the column alignment was doing. */
  .rail {
    grid-template-columns: 1fr;
    gap: 2px;
  }
}
</style>
