<script setup lang="ts">
/**
 * Crafting, §8.
 *
 * The tier ladder is the message: gold buys basic, materials buy crafted, and
 * only tier 3 + tier 4 reach the NFT tier. All three sit on the same capped
 * power curve (§8.1 rule 4) -- what changes is how fast you get there and what
 * it costs to keep running.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useGame } from '@/stores/game'
import {
  MATERIALS,
  RARITIES,
  RARITY_LABEL,
  RARITY_RANK,
  SCOPE_ACTION,
  SKILL_LIST,
  SLOT_LABEL,
  STATION_RANK,
  craftableItems,
  skillForSlot,
  slotForSkill,
  stationForRarity,
  stationReaches,
} from '@/game/catalog'
import { formatDuration, placeLabel } from '@/game/formulas'
import { CRAFT, PROCESSING } from '@/game/balance'
import { itemIcon, materialIcon } from '@/icons/procedural'
import SvgIcon from '@/components/SvgIcon.vue'
import StatChips from '@/components/StatChips.vue'
import QueueBar from '@/components/QueueBar.vue'
import type { BuffScope, EquipSlot, ItemDef, MaterialKey, Rarity } from '@/game/types'

const game = useGame()

/**
 * §8.4 -- what a player is shopping *for*, which is four things and not three.
 *
 * The bench count is still three: a tool and a sword are the same anvil and the
 * same Smith (§8.4). But "tools & weapons" is one tab holding two decisions that
 * have nothing to do with each other -- which of my five lines to upgrade, and
 * what to carry into a fight -- and the axe rung was buried in the middle of the
 * shield rungs. The tab strip is a filter, not a claim about buildings.
 */
type Tab = 'tool' | 'weapon' | 'armor' | 'consumable'

const TABS: Tab[] = ['tool', 'weapon', 'armor', 'consumable']

const TAB_LABEL: Record<Tab, string> = {
  tool: 'Tools',
  weapon: 'Weapons',
  armor: 'Armor',
  consumable: 'Drafts',
}

const tab = ref<Tab>('tool')

/** §9.5.4 -- the three families, and the job each one levels. */
const FAMILY_LABEL: Record<string, string> = {
  shield: 'Shield',
  sword: 'Sword',
  focus: 'Focus',
}

const FAMILY_NOTE: Record<string, string> = {
  shield: 'Levels Shieldbearer · two thirds guard',
  sword: 'Levels Swordhand · even split',
  focus: 'Levels Runecaster · four fifths arm',
}

/** What each worn slot is actually for, which is how they differ at the bench. */
const WORN_NOTE: Record<string, string> = {
  armor: 'Counts on all five lines',
  boots: 'Counts on the road',
  gloves: 'Counts at the bench',
}

const station = computed(() => game.currentSettlement)

/**
 * §8.4 -- the bench bank, which queues the way §6.1's lines do.
 *
 * Five slots, first-come-first-served, shared by everybody standing here, and
 * counted apart from the processing queue: a run of planks and a sword are two
 * different buildings, so a busy saw pit never closes the forge.
 */
const benchSlots = computed(() => game.bench)

const freeBenches = computed(() => benchSlots.value.filter((s) => s.owner === null).length)

const mineHere = computed(() => benchSlots.value.some((s) => s.owner === 'you'))

const benchFull = computed(() => benchSlots.value.length > 0 && freeBenches.value === 0)

onMounted(() => void game.loadBench())
watch(() => station.value?.id, () => void game.loadBench())

/**
 * Only what this workbench can actually make.
 *
 * A recipe you cannot reach here is hidden outright rather than grayed: a
 * village will never craft an epic, so listing it is a permanent row of noise on
 * the screen a player uses most. Missing *materials* is a different thing and
 * stays visible -- that is the shopping list, and hiding it would remove the only
 * reason to go and gather.
 */
const reachable = computed(() => craftableItems().filter(hasStation))

function tabFor(item: ItemDef): Tab {
  if (item.consumable) return 'consumable'
  if (item.slot && skillForSlot(item.slot) !== null) return 'tool'

  return item.slot === 'weapon' ? 'weapon' : 'armor'
}

const byTab = computed(() => {
  const out = { tool: [], weapon: [], armor: [], consumable: [] } as Record<Tab, ItemDef[]>
  for (const item of reachable.value) out[tabFor(item)].push(item)
  return out
})

const rung = (a: ItemDef, b: ItemDef) =>
  RARITY_RANK[a.rarity] - RARITY_RANK[b.rarity] || a.name.localeCompare(b.name)

/**
 * §8 rule 4 -- every line gets the same ladder, so the axis inside a tab is
 * never rarity. It is the line for a tool, the family for a weapon, the slot for
 * worn gear and the action for a draft: the thing being chosen between.
 */
interface Group {
  key: string
  label: string
  note: string
  items: ItemDef[]
}

const groups = computed<Group[]>(() => {
  const items = byTab.value[tab.value]

  if (tab.value === 'tool') {
    return SKILL_LIST.map((skill) => {
      const slot = slotForSkill(skill.key)

      return {
        key: skill.key,
        label: skill.name,
        note: `${SLOT_LABEL[slot]} · ${MATERIALS[skill.material as MaterialKey]?.name ?? ''}`,
        items: items.filter((i) => i.slot === slot).sort(rung),
      }
    }).filter((g) => g.items.length > 0)
  }

  if (tab.value === 'weapon') {
    return Object.keys(FAMILY_LABEL)
      .map((family) => ({
        key: family,
        label: FAMILY_LABEL[family]!,
        note: FAMILY_NOTE[family]!,
        items: items.filter((i) => i.family === family).sort(rung),
      }))
      .filter((g) => g.items.length > 0)
  }

  if (tab.value === 'armor') {
    return (['armor', 'boots', 'gloves'] as EquipSlot[])
      .map((slot) => ({
        key: slot,
        label: SLOT_LABEL[slot],
        note: WORN_NOTE[slot] ?? '',
        items: items.filter((i) => i.slot === slot).sort(rung),
      }))
      .filter((g) => g.items.length > 0)
  }

  const scopes = [...new Set(items.map((i) => i.scope ?? 'global'))] as Array<BuffScope | 'global'>

  return scopes
    .sort((a, b) => order(a) - order(b))
    .map((scope) => ({
      key: scope,
      label: SCOPE_ACTION[scope],
      note: 'One charge, spent on the next one',
      items: items.filter((i) => (i.scope ?? 'global') === scope).sort(rung),
    }))
})

/** Lines first, then the road, the bench and the fight -- §8.5's own order. */
function order(scope: BuffScope | 'global'): number {
  const all = Object.keys(SCOPE_ACTION) as Array<BuffScope | 'global'>

  return all.indexOf(scope)
}

const nothingHere = computed(() => reachable.value.length === 0)

/**
 * §8.0 -- what this bench reaches, said as the rungs it can actually make.
 *
 * Only those. A row of six caps with four of them dark was a ladder mostly
 * about what you cannot do here, and the sentence under it talked about guild
 * halls to players with no guild and about unique gear nobody ever crafts.
 * What is worth a line is the rung above this one and where it is made.
 */
const reaches = (rarity: Rarity) =>
  station.value !== null && stationReaches(station.value.tier, rarity) && rarity !== 'unique'

/** Everything up to and including the top rung this bench makes. */
const rungs = computed(() => RARITIES.filter(reaches))

/** The next rung up, and the smallest place that makes it. Null at the top. */
const nextStep = computed(() => {
  if (!station.value) return null

  const next = RARITIES.find((r) => !reaches(r) && r !== 'unique' && r !== 'legendary')
  const where = next ? stationForRarity(next) : null

  return next && where ? `A ${where} bench reaches ${RARITY_LABEL[next].toLowerCase()}.` : null
})

/**
 * §10.5 -- and the rung above every settlement, which is a guild's business
 * and is said to nobody else. A prospector with no hall cannot act on it.
 */
const hallNote = computed(() => {
  const guild = game.guild
  if (!guild) return null

  return game.atGuildHall
    ? `${guild.name}'s bench reaches ${guild.benchReach}.`
    : `Legendary is ${guild.name}'s hall, once its bench is built that far.`
})

/**
 * §8.0 -- can this bench make it? Rarity decides, not the item's own `station`
 * field, so the screen and the server refuse for the same reason.
 */
function hasStation(item: ItemDef): boolean {
  const here = station.value
  if (!here) return false
  if (!stationReaches(here.tier, item.rarity)) return false
  // §8.0 -- the guild hall is a building a guild puts inside a settlement, so
  // nothing that needs one is craftable from a settlement alone.
  if (item.station === 'guild') return false

  return !item.station || STATION_RANK[here.tier] >= STATION_RANK[item.station]
}

function inputs(item: ItemDef): Array<{ key: MaterialKey; need: number; have: number }> {
  return Object.entries(item.inputs ?? {}).map(([key, need]) => ({
    key: key as MaterialKey,
    need: need as number,
    have: game.held(key as MaterialKey),
  }))
}

const stocked = (item: ItemDef) => inputs(item).every((i) => i.have >= i.need)

/** Stock, a free bench, and nothing of yours already on one here (§8.4). */
const ready = (item: ItemDef) => stocked(item) && !benchFull.value && !mineHere.value

/**
 * Name the shortfall. "Missing materials" is a state; this is a shopping list.
 *
 * Counts stay on the pills, which already carry them in ember. Repeating them
 * here produced "1 planks short", because a material name is not a countable
 * noun -- what the player needs from this line is *which* materials.
 */
function shortfall(item: ItemDef): string | null {
  if (mineHere.value) return 'Your bench here is busy'
  if (benchFull.value) return 'Every bench here is busy'

  const short = inputs(item)
    .filter((i) => i.have < i.need)
    .map((i) => MATERIALS[i.key].name.toLowerCase())

  if (!short.length) return null
  if (short.length === 1) return `Short on ${short[0]}`
  if (short.length === 2) return `Short on ${short[0]} and ${short[1]}`

  return `Short on ${short.length} materials`
}

/**
 * What the thing costs you over time. Gear wears out; a potion runs down. Both
 * are the §11.1 sink, so both belong in the same spot on the row.
 */
function lifespan(item: ItemDef): string {
  if (item.consumable) return `one ${SCOPE_ACTION[item.scope ?? 'global']}`

  return `${item.maxDurability} dur`
}

/**
 * §8.4 -- how long the bench keeps it, before the tier and the gloves are
 * applied. Quoted from the rung's base rather than the exact figure the server
 * will compute, because this is the number that decides whether to start it
 * here at all; the exact one arrives with the job.
 */
function benchTime(item: ItemDef): string {
  const tier = station.value?.tier ?? 'village'
  const seconds = CRAFT.seconds[item.rarity] * PROCESSING.speed[tier]

  return formatDuration((seconds / game.timeScale) * 1000)
}

/** The empty tab is worth a sentence about where the thing IS made. */
const emptyNote = computed(() => {
  const tier = station.value?.tier ?? 'village'

  if (tab.value === 'tool') return `No line's tools are made at a ${tier} bench.`
  if (tab.value === 'weapon') return `No weapon is made at a ${tier} bench.`
  if (tab.value === 'armor') return `No worn gear is made at a ${tier} bench.`

  return `No draft is brewed at a ${tier} bench.`
})
</script>

<template>
  <div class="page">
    <header class="bench inset">
      <div>
        <span class="label">Workbench</span>
        <div class="tiny place">
          <template v-if="station">
            {{ placeLabel(station.name, station.col, station.row) }}
            <span class="muted">· {{ station.tier }}</span>
          </template>
          <span v-else class="muted">You have left the settlement.</span>
        </div>
      </div>

      <div v-if="station" class="rungs">
        <span class="tiny muted">Makes</span>
        <span
          v-for="rarity in rungs"
          :key="rarity"
          class="chip tiny"
          :class="`rarity-${rarity}`"
        >{{ RARITY_LABEL[rarity] }}</span>
        <span v-if="!rungs.length" class="tiny muted">nothing at this tier</span>
      </div>

      <p v-if="nextStep || hallNote" class="tiny muted reach-note">
        {{ [nextStep, hallNote].filter(Boolean).join(' ') }}
      </p>

      <!-- §8.4 -- the bench bank, drawn by the same component §6.1's is. -->
      <QueueBar
        v-if="station"
        class="queue"
        label="Bench queue"
        :slots="benchSlots"
        full-note="Every bench here is busy. Congestion at a popular settlement is the point — try a quieter one, or wait for a slot."
      >
        <p v-if="mineHere && !benchFull" class="tiny muted queue-note">
          Something of yours is on a bench here. Collect it before starting
          another — one bench each, per settlement.
        </p>
      </QueueBar>
    </header>

    <nav v-if="!nothingHere" class="tabs">
      <button
        v-for="t in TABS"
        :key="t"
        type="button"
        :class="{ on: tab === t }"
        :aria-pressed="tab === t"
        @click="tab = t"
      >
        <span>{{ TAB_LABEL[t] }}</span>
        <span class="count mono">{{ byTab[t].length }}</span>
      </button>
    </nav>

    <!-- Nothing reachable here. Say where to go, not just that there is nothing. -->
    <div v-if="nothingHere" class="inset empty">
      <p class="tiny muted" style="margin: 0">
        <template v-if="station">
          A {{ station.tier }} workbench cannot make any of these. Bigger
          settlements carry deeper benches.
        </template>
        <template v-else>
          Crafting needs a workbench. Travel to a settlement and try again.
        </template>
      </p>
    </div>

    <div v-else-if="!groups.length" class="inset empty">
      <p class="tiny muted" style="margin: 0">{{ emptyNote }}</p>
    </div>

    <section v-for="group in groups" :key="group.key" class="group">
      <div class="eyebrow">
        <h3 class="g-label">{{ group.label }}</h3>
        <span class="hair" aria-hidden="true" />
        <span class="tiny muted g-note">{{ group.note }}</span>
      </div>

      <article
        v-for="item in group.items"
        :key="item.key"
        class="recipe"
        :class="{ ready: ready(item) }"
        :data-rarity="item.rarity"
      >
        <div class="head">
          <SvgIcon
            :svg="itemIcon({ slot: item.slot, family: item.family, rarity: item.rarity, palette: item.palette, size: 32 })"
            boxed
            :size="32"
          />
          <div class="grow">
            <div class="row-between">
              <strong class="name" :class="`rarity-${item.rarity}`">{{ item.name }}</strong>
              <span class="rung" :class="`rarity-${item.rarity}`">{{ RARITY_LABEL[item.rarity] }}</span>
            </div>
            <!-- §9.5.4 -- a bench is where a shield and a wand are actually
                 chosen between, and the chips are the choice. One component
                 draws them everywhere, so a piece reads the same on every screen. -->
            <div class="row tiny stats">
              <StatChips :def="item" />
              <span class="chip tiny">{{ lifespan(item) }}</span>
              <span v-if="item.tradeable" class="chip tiny chip-nft">NFT</span>
            </div>
          </div>
        </div>

        <p class="tiny muted blurb">{{ item.description }}</p>

        <div class="inputs">
          <span
            v-for="input in inputs(item)"
            :key="input.key"
            class="input"
            :class="{ short: input.have < input.need }"
          >
            <SvgIcon :svg="materialIcon(MATERIALS[input.key], 16)" />
            <span class="tiny mono">{{ input.have }}/{{ input.need }}</span>
            <span class="tiny muted iname">{{ MATERIALS[input.key].name }}</span>
          </span>
        </div>

        <div class="row-between foot">
          <span class="tiny" :class="ready(item) ? 'muted' : 'lack'">
            {{ shortfall(item) ?? `${benchTime(item)} on the bench` }}
          </span>
          <button
            class="btn btn-sm"
            :class="{ 'btn-primary': ready(item) }"
            type="button"
            :disabled="game.busy || !ready(item)"
            @click="game.craft(item.key)"
          >
            Craft
          </button>
        </div>
      </article>
    </section>

    <p class="tiny muted footnote">
      Rarity changes durability and reliability, not the power ceiling. A maxed
      crafted setup and a maxed NFT setup land on the same curve — which is what
      keeps free play viable.
    </p>
  </div>
</template>

<style scoped>
.page {
  /* Sizing and scrolling belong to PanelOverlay. */
  padding: 0;
}

.bench {
  margin-bottom: 14px;
}

.place {
  margin-top: 3px;
}

/* What this bench makes, in the rung's own color. Nothing about the rungs it
   does not: a chip you cannot act on is a chip in the way. */
.rungs {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 5px;
  margin: 10px 0 7px;
}

.reach-note {
  margin: 0;
  line-height: 1.45;
}

.queue {
  margin-top: 11px;
}

.queue-note {
  margin: 7px 0 0;
  line-height: 1.45;
}

/* Four filters, two by two: four across is 78px a label on a phone, which is
   where "Consumables" started truncating into a different word. */
.tabs {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 6px;
  margin-bottom: 16px;
}

@media (min-width: 420px) {
  .tabs {
    grid-template-columns: repeat(4, 1fr);
  }
}

.tabs button {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 9px 6px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--line);
  background: var(--ink-panel);
  color: var(--vellum-dim);
  font-weight: 700;
  font-size: 11px;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  cursor: pointer;
}

.tabs button.on {
  background: var(--ink-raised);
  border-color: var(--copper);
  color: var(--vellum);
}

.tabs .count {
  font-size: 10px;
  color: #7b8580;
}

.tabs button.on .count {
  color: var(--copper);
}

.empty {
  padding: 20px 16px;
  text-align: center;
  line-height: 1.5;
}

.group + .group {
  margin-top: 22px;
}

.eyebrow {
  display: flex;
  align-items: baseline;
  gap: 8px;
  margin-bottom: 8px;
}

.g-label {
  margin: 0;
  font-family: var(--font-display);
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  color: var(--vellum);
  white-space: nowrap;
}

.hair {
  flex: 1 1 auto;
  height: 1px;
  background: var(--line);
}

.g-note {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 52%;
}

/* One card a recipe, with the rung drawn down its left edge: the rarity is the
   thing players scan for, and a spine is readable at a glance where a word in
   a header is not. */
.recipe {
  position: relative;
  padding: 10px 12px 10px 14px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--line);
  background: var(--ink-panel);
  overflow: hidden;
}

.recipe::before {
  content: '';
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 3px;
  background: var(--rung, var(--line));
}

.recipe[data-rarity='common'] {
  --rung: var(--rarity-common);
}
.recipe[data-rarity='uncommon'] {
  --rung: var(--rarity-uncommon);
}
.recipe[data-rarity='rare'] {
  --rung: var(--rarity-rare);
}
.recipe[data-rarity='epic'] {
  --rung: var(--rarity-epic);
}
.recipe[data-rarity='legendary'] {
  --rung: var(--rarity-legendary);
}
.recipe[data-rarity='unique'] {
  --rung: var(--rarity-unique);
}

/* §13.3 -- sap is for a state worth crossing the screen for, and "you can make
   this right now" is the only one this screen has. */
.recipe.ready {
  border-color: #47563f;
}

.recipe.ready::before {
  background: linear-gradient(var(--sap) 22px, var(--rung) 22px);
}

.recipe + .recipe {
  margin-top: 8px;
}

.head {
  display: flex;
  align-items: flex-start;
  gap: 9px;
}

.name {
  font-family: var(--font-display);
  font-size: 13px;
  line-height: 1.2;
}

.rung {
  font-size: 8.5px;
  font-weight: 700;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  opacity: 0.85;
  white-space: nowrap;
}

.stats {
  gap: 4px;
  margin-top: 4px;
  flex-wrap: wrap;
}

.blurb {
  margin: 7px 0 0;
  line-height: 1.45;
}

.inputs {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin: 9px 0;
}

.input {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 8px 4px 5px;
  border-radius: var(--radius-sm);
  background: var(--ink);
  border: 1px solid var(--line);
}

.input.short {
  border-color: #6d3330;
}

.input.short .mono {
  color: #e58c86;
}

.input .iname {
  max-width: 92px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.foot {
  padding-top: 8px;
  border-top: 1px solid var(--line);
}

.lack {
  color: #e58c86;
}

.footnote {
  margin-top: 22px;
  padding-top: 12px;
  border-top: 1px solid var(--line);
  line-height: 1.5;
}
</style>
