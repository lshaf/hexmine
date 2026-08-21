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
  MATERIALS,
  RARITY_LABEL,
  RARITY_RANK,
  SKILL_BY_KEY,
  SLOT_LABEL,
  STAT_LABEL,
  ITEMS,
  skillForSlot,
} from '@/game/catalog'
import {
  SOURCE_COLOR,
  SOURCE_ICON,
  SOURCE_KINDS,
  SOURCE_LABEL,
  itemSources,
  materialSources,
  materialUses,
} from '@/game/sources'
import type { SourceLine } from '@/game/sources'
import { formatPercent } from '@/game/formulas'
import { EQUIPMENT, ECONOMY, PROCESSING, BAG } from '@/game/balance'
import { ACTION_PATHS } from '@/icons/actions'
import { BIOME_LABEL } from '@/theme/palette'
import { itemIcon, materialIcon } from '@/icons/procedural'
import SvgIcon from '@/components/SvgIcon.vue'
import type { EquipSlot, ItemDef, Material, MaterialTier } from '@/game/types'

type Half = 'materials' | 'equipment'

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

const TIER_NAME: Record<MaterialTier, string> = {
  0: 'Scrap',
  1: 'Raw',
  2: 'Refined',
  3: 'Rare',
  4: 'Raid',
}

/** One structural fact per tier -- the thing that is true of all five of them. */
const TIER_NOTE: Record<MaterialTier, string> = {
  0: 'What a hex gives up to bare hands, on any ring: the same haul as the real thing, a fraction of the worth. Outside the twenty on purpose — it feeds no recipe and reaches no other tier, so it never enters the economy the sinks have to balance.',
  1: `Biome-locked, on the outer and middle rings, and the bulk of what fills a bag: ${BAG.units} units and ${BAG.rows} kinds is all a prospector carries, and a haul that puts you over either one keeps you on the hex until you sell, process or drop it.`,
  2: `Made at settlements, never mined. A village runs one line of five, a city two, a capital all of them — which is most of what makes a capital worth the walk. Every station has ${PROCESSING.publicSlots} public slots, first come first served, so a busy capital queues.`,
  3: `Contested ring only, and capped at ${ECONOMY.rareWalletCap} per wallet. A thousand bot wallets get a thousand capped hauls, which is the point.`,
  4: 'Dungeon-sourced and not biome-locked. Shards are typed to their dungeon, so a top-tier tool always means crossing the map.',
}

const TIERS: MaterialTier[] = [0, 1, 2, 3, 4]

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

const materialsByTier = computed(() => {
  const groups = {} as Record<MaterialTier, MaterialEntry[]>
  for (const tier of TIERS) groups[tier] = []
  for (const entry of materialEntries.value) {
    if (matches(entry.hay)) groups[entry.mat.tier].push(entry)
  }
  return groups
})

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
    sub: `Drunk, never worn. One buff per stat, ${Math.round(
      EQUIPMENT.buffMs / 60000,
    )} minutes each, and the expiry is the sink.`,
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
  TIERS.reduce<number>((n, tier) => n + materialsByTier.value[tier].length, 0),
)

const itemCount = computed(() => groups.value.reduce((n, g) => n + g.entries.length, 0))

/** What the thing is, in one line: where it goes, what it serves, how long. */
function nature(item: ItemDef): string {
  if (item.consumable) {
    return `Drunk · ${Math.round(EQUIPMENT.buffMs / 60000)} minutes, then gone`
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

        <section v-for="tier in TIERS" :key="tier" v-show="materialsByTier[tier].length">
          <div class="sect">
            <h3>Tier {{ tier }} <span class="dot">·</span> {{ TIER_NAME[tier] }}</h3>
            <span class="tally">{{ materialsByTier[tier].length }}</span>
          </div>
          <p class="tiny muted sect-note">{{ TIER_NOTE[tier] }}</p>

          <div class="entries">
            <article v-for="entry in materialsByTier[tier]" :key="entry.mat.key" class="entry">
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
                      <p v-if="entry.uses.sellsFor" class="tiny muted note">
                        Or sells to the trader for {{ entry.uses.sellsFor }}g each.
                      </p>
                    </template>
                    <p v-else class="tiny muted note flat">
                      <template v-if="entry.uses.sellsFor">
                        Nothing. It sells for {{ entry.uses.sellsFor }}g and that is all it
                        is for.
                      </template>
                      <template v-else>
                        Nothing yet, and the trader will not take it either.
                      </template>
                    </p>
                  </dd>
                </div>
              </dl>
            </article>
          </div>
        </section>
      </template>

      <!-- ------------------------------------------------------- equipment -->
      <template v-else>
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
                  {{ formatPercent(entry.item.value) }} {{ STAT_LABEL[entry.item.stat] }}
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
                    <span class="where">{{ source.where }}</span>
                    <span v-if="source.gold" class="chip chip-gold tiny">{{ source.gold }}g</span>
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
