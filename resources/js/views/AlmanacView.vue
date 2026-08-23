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
 * two labelled lines, FROM and FEEDS, colour-coded by which of the five roads
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
  SCOPE_ACTION,
  SKILL_BY_KEY,
  SLOT_LABEL,
  ITEMS,
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
import { formatPercent, itemStatLine, resaleValue } from '@/game/formulas'
import { EQUIPMENT, ECONOMY, PROCESSING, BAG } from '@/game/balance'
import { ACTION_PATHS } from '@/icons/actions'
import { BIOME_LABEL } from '@/theme/palette'
import {
  BIOME_VARIANTS,
  VARIANT_DESCRIPTION,
  VARIANT_RINGS,
  type VariantDef,
} from '@/game/variants'
import { itemIcon, materialIcon } from '@/icons/procedural'
import { variantSpecimen, waterSpecimen } from '@/map/props'
import { waterLabel } from '@/game/water'
import SvgIcon from '@/components/SvgIcon.vue'
import type {
  Biome,
  WaterKind,
  EquipSlot,
  ItemDef,
  Material,
  MaterialKey,
  MaterialTier,
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

type Half = 'materials' | 'equipment' | 'tiles'

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
 * unlabelled run is a wall rather than a list.
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
    note: 'Sells for the same copper and feeds the same nothing, but it is not scrap: this is the rubbish carried out alongside a real haul, not what a missing tool costs you. Kept apart because that distinction is the whole of the argument above.',
    holds: () => true,
  },
  {
    key: 'ground',
    tier: 1,
    name: 'The ground',
    note: `Four grades of every biome, and the variant of hex you are standing on decides which one you get. The base grade is everywhere; the better ones start at the middle ring and the best is contested. This is the bulk of what fills a bag — ${BAG.units} units and ${BAG.rows} kinds is all a prospector carries, and a haul over either keeps you on the hex until you sell, process or drop it.`,
    holds: (mat) => rawRole(mat.key) === 'ground',
  },
  {
    key: 'reagent',
    tier: 1,
    name: "The alchemist's stock",
    note: 'Two per biome, and the consumable bench runs on these alone — no potion wants anything a smith would bid for. Biome-locked like every other raw, and worth more than scrap, which §4 makes a rule rather than a tuning value.',
    holds: (mat) => rawRole(mat.key) === 'reagent',
  },
  {
    key: 'critter',
    tier: 1,
    name: "The alchemist's other stock",
    note: 'Five small animals, one per biome, and the only ingredient a hunt brings back rather than a gather. The herbs say what grows on a kind of ground; these say what lives on it — and because a critter needs a bow and a live herd, the top three rungs of the potion shelf wait on an animal turning up rather than on an afternoon with a sickle.',
    holds: (mat) => rawRole(mat.key) === 'critter',
  },
  {
    key: 'spoil',
    tier: 1,
    name: 'Off a monster',
    note: 'The only Tier 1 with no ground under it: two families of five, cut off a pack rather than out of a hex (§9.5). The plate line feeds the smith and the armorer, the ichor line feeds the consumable bench, and the grade you get is the tier of the thing that was carrying it — which is why the best of them are only found in the barren centre. Combat feeds combat; none of this reaches the mining economy.',
    holds: (mat) => rawRole(mat.key) === 'spoil',
  },
  {
    key: 'component',
    tier: 1,
    name: 'The smith and the armorer',
    note: 'The same idea again, for the other two benches: two per biome, one named for each. Every crafted thing wants its line’s component, so these sit beside the raw and the refined in every recipe rather than replacing them.',
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
    note: `Contested ring only, on ground that finally looks like itself, and capped at ${ECONOMY.rareWalletCap} per wallet. A thousand bot wallets get a thousand capped hauls, which is the point.`,
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

const tileCount = computed(
  () =>
    tileGroups.value.reduce<number>((n, g) => n + g.entries.length, 0) +
    waterEntries.value.length,
)

// ------------------------------------------------------------------ equipment

/**
 * Grouped by slot rather than by rarity, because the slot *is* the ladder: §8.0
 * gives every gathering line the same five rungs on purpose, and stacking them
 * in one column is the only view that shows a line is not quietly weaker than
 * its neighbours.
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
  'weapon',
]

const WORN_NOTE: Partial<Record<EquipSlot, string>> = {
  armor: 'Worn. Counts on every line, and on the road between them.',
  boots: 'Worn. Counts on every line, and on the road between them.',
  gloves: 'Worn. Counts on every line, and on the road between them.',
  weapon: 'Raid combat only, and it never gathers — combat gear must not be a shortcut around the mining ladder. Nothing is made for it yet.',
}

interface ItemEntry {
  item: ItemDef
  sources: SourceLine[]
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
    hay: [
      item.name,
      item.description,
      ...sources.map((s) => `${s.where} ${s.note ?? ''}`),
      ...sources.flatMap((s) => (s.cost ?? []).map((c) => MATERIALS[c.key].name)),
    ].join(' '),
  }
}

const byRung = (a: ItemEntry, b: ItemEntry) =>
  RARITY_RANK[a.item.rarity] - RARITY_RANK[b.item.rarity] || a.item.value - b.item.value

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
                <span class="chip tiny" :class="entry.item.tradeable ? 'chip-nft' : ''">
                  {{ itemStatLine(entry.item) }}
                </span>
              </div>

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
        <template v-else>
          A bench reaches exactly as far as its tier, whatever you carry to it, and
          gold reaches the bottom two rungs and stops at every settlement — what a
          capital shop adds is a rolled bonus line, never a better rung. Every rung
          climbs toward one ceiling of {{ formatPercent(EQUIPMENT.statCeiling) }} and
          nothing passes it: not a rarity, not a rolled option, not a potion.
          Legendary is guild-hall work and unique only ever drops, so no ladder here
          reaches its top two rungs yet.
        </template>
      </p>
    </div>
  </div>
</template>

<style scoped>
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
  clip-path: polygon(7px 0, 100% 0, 100% calc(100% - 7px), calc(100% - 7px) 100%, 0 100%, 0 7px);
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
  clip-path: polygon(7px 0, 100% 0, 100% calc(100% - 7px), calc(100% - 7px) 100%, 0 100%, 0 7px);
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
  clip-path: polygon(
    10px 0,
    100% 0,
    100% calc(100% - 10px),
    calc(100% - 10px) 100%,
    0 100%,
    0 10px
  );
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
 * The signature. A hairline in the road's colour, the road's name in caps, and
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

/* Buy-back is the lesser of the two numbers and reads as the lesser: same
   family, no colour. Two golds side by side would make them look equal. */
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

/* Left uncoloured on purpose: an item pip is tinted by its rarity, and a rule
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
     colour rail carry the grouping that the column alignment was doing. */
  .rail {
    grid-template-columns: 1fr;
    gap: 2px;
  }
}
</style>
