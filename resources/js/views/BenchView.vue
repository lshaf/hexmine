<script setup lang="ts">
/**
 * §6, §8.4 -- what is finished, and what is still cooking.
 *
 * Crafting and processing both leave something in a building, and both hand it
 * over only to somebody standing there. That turns "is it done yet" into a
 * question about the MAP rather than a progress bar: three finished things in
 * three settlements is a route, and this is the page that plans it.
 *
 * Finished first, because that is the news. Within each half the soonest is at
 * the top -- the clock is the thing you cannot change and the walk is the thing
 * you can.
 */
import { computed, ref } from 'vue'
import { useGame } from '@/stores/game'
import { ITEM_BY_KEY, MATERIALS, RARITY_LABEL, RECIPE_BY_KEY, stationForRarity } from '@/game/catalog'
import { formatDuration, placeLabel } from '@/game/formulas'
import { hexDistance } from '@/map/hexGeometry'
import { SLATE_CAP } from '@/game/balance'
import { itemIcon, materialIcon } from '@/icons/procedural'
import JobCard from '@/components/JobCard.vue'
import SlateMark from '@/components/SlateMark.vue'
import SvgIcon from '@/components/SvgIcon.vue'
import type { CraftJob, MaterialKey, ProcessingJob } from '@/game/types'

type BenchJob = CraftJob | ProcessingJob

const game = useGame()

/**
 * §8.4 -- two tabs, because this page holds two questions rather than one list.
 *
 * WORK is what is already in a building and has to be walked back to; SLATE is
 * what you have not started yet, and it plans a gather rather than a route.
 * They were stacked, which meant the slate was below however many jobs happened
 * to be out -- so the half of the page you read while standing in a field was
 * the half you had to scroll past a bench ledger to reach.
 *
 * Two, and no third. There is no state a bench job is in that this page cannot
 * say on the row itself.
 */
type Tab = 'work' | 'slate'

const tab = ref<Tab>('work')

const here = (job: BenchJob) =>
  job.col === game.character?.col && job.row === game.character?.row

const done = computed(() => game.benchJobs.filter((j) => j.endsAt <= game.now))
const working = computed(() => game.benchJobs.filter((j) => j.endsAt > game.now))

/** Whole hexes, the way travel is priced -- not a line drawn across the map. */
const distance = (job: BenchJob) => {
  if (job.col === null || job.row === null || !game.character) return null

  return hexDistance(game.character.col, game.character.row, job.col, job.row)
}

/**
 * §5.6 -- and what those hexes cost in hours, which is the actual decision.
 *
 * "Nine hexes away" is a fact about the map; "an hour and a half" is a fact
 * about your evening, and this page exists to plan a route rather than to
 * admire distances.
 */
const walk = (job: BenchJob) => {
  if (job.col === null || job.row === null) return null

  return formatDuration(game.travelEta(job.col, job.row))
}

/** §6 vs §8.4 -- two different buildings, and the row should say which. */
const kind = (job: BenchJob) => (job.kind === 'craft' ? 'Bench' : 'Processing line')

// -------------------------------------------------------------- the slate §8.4

/**
 * §8.4 -- what is on a bench, and what you MEANT to put on one.
 *
 * They belong on the same page because they are the same question asked a step
 * apart: this panel plans a route, and a recipe you cannot afford yet plans a
 * gather. It is also the one screen the slate has to be readable from, since
 * being four days from a bench is exactly when a shopping list is worth having.
 *
 * The shortfall is worked out here rather than stored (§8.4), because the bag
 * moves with every haul and a written-down answer would be stale by the time
 * it was read.
 */
interface SlateLine {
  key: string
  name: string
  icon: string
  /** What it makes, said the way the bench that makes it would say it. */
  makes: string
  inputs: Array<{ key: MaterialKey; need: number; have: number }>
  ready: boolean
}

const slate = computed<SlateLine[]>(() =>
  game.slate.flatMap((key) => {
    const recipe = RECIPE_BY_KEY[key]
    const item = ITEM_BY_KEY[key]

    const need: Record<string, number> = recipe
      ? {
          [recipe.input]: recipe.inputQty,
          ...(recipe.secondInput ? { [recipe.secondInput]: recipe.secondInputQty ?? 1 } : {}),
        }
      : (item?.inputs ?? {})

    const inputs = Object.entries(need).map(([m, qty]) => ({
      key: m as MaterialKey,
      need: qty as number,
      have: game.held(m as MaterialKey),
    }))

    if (recipe) {
      return [{
        key,
        name: recipe.name,
        icon: materialIcon(MATERIALS[recipe.output], 26),
        makes: `${recipe.outputQty} ${MATERIALS[recipe.output].name} · processing line`,
        inputs,
        ready: inputs.every((i) => i.have >= i.need),
      }]
    }

    if (!item) return []

    return [{
      key,
      name: item.name,
      icon: itemIcon({ slot: item.slot, family: item.family, rarity: item.rarity, palette: item.palette, size: 26 }),
      makes: `${RARITY_LABEL[item.rarity]} · ${stationForRarity(item.rarity) ?? 'guild'} bench`,
      inputs,
      ready: inputs.every((i) => i.have >= i.need),
    }]
  }),
)

const slateReady = computed(() => slate.value.filter((l) => l.ready).length)
</script>

<template>
  <div class="page">
    <div class="tabs" role="tablist">
      <button
        type="button"
        role="tab"
        class="tab"
        :class="{ on: tab === 'work' }"
        :aria-selected="tab === 'work'"
        @click="tab = 'work'"
      >
        On a bench
        <span class="tally" :class="{ ready: done.length > 0 }">{{ game.benchJobs.length }}</span>
      </button>
      <button
        type="button"
        role="tab"
        class="tab"
        :class="{ on: tab === 'slate' }"
        :aria-selected="tab === 'slate'"
        @click="tab = 'slate'"
      >
        Slate
        <span class="tally" :class="{ ready: slateReady > 0 }">{{ slate.length }}/{{ SLATE_CAP }}</span>
      </button>
    </div>

    <template v-if="tab === 'work'">
    <p v-if="!game.benchJobs.length" class="nothing tiny muted">
      Nothing is on a bench. A craft or a processing run waits at the settlement you
      started it in, until you come back for it.
    </p>

    <template v-else>
      <!-- ------------------------------------------------------- finished -->
      <section v-if="done.length" class="section">
        <div class="row" style="gap: 8px; margin-bottom: 3px">
          <h3 class="head done">Finished</h3>
          <span class="tally ready">{{ done.length }}</span>
        </div>
        <p class="tiny muted note">
          Handed over at the bench that holds it, so anything on this list somewhere
          else is a walk rather than a tap.
        </p>

        <div v-for="job in done" :key="job.id" class="entry">
          <JobCard :job="job" />

          <div class="row-between foot">
            <span class="tiny" :class="here(job) ? 'done' : 'muted'">
              <template v-if="here(job)">{{ kind(job) }} · ready, and you are here</template>
              <template v-else>
                {{ kind(job) }} ·
                {{ placeLabel(job.settlementName, job.col, job.row) }} ·
                {{ distance(job) }} hexes, {{ walk(job) }} away
              </template>
            </span>

            <button
              v-if="here(job)"
              class="btn btn-sm btn-primary"
              type="button"
              :disabled="game.busy"
              @click="game.collect(job.id)"
            >
              Collect
            </button>
            <button
              v-else
              class="btn btn-sm"
              type="button"
              :disabled="game.busy"
              @click="game.travelTo(job.col!, job.row!)"
            >
              Walk there
            </button>
          </div>
        </div>
      </section>

      <!-- -------------------------------------------------- still cooking -->
      <section v-if="working.length" class="section">
        <div class="row" style="gap: 8px; margin-bottom: 3px">
          <h3 class="head">Still working</h3>
          <span class="tally">{{ working.length }}</span>
        </div>

        <div v-for="job in working" :key="job.id" class="entry">
          <JobCard :job="job" />

          <div class="row-between foot">
            <span class="tiny muted">
              {{ kind(job) }} ·
              {{ placeLabel(job.settlementName, job.col, job.row) }}
              <template v-if="!here(job)">
                · {{ distance(job) }} hexes, {{ walk(job) }} away
              </template>
            </span>
            <span class="tiny mono muted">{{ formatDuration(job.endsAt - game.now) }} left</span>
          </div>
        </div>
      </section>
    </template>

    </template>

    <!-- ---------------------------------------------------------- the slate -->
    <template v-else>
      <p v-if="!slate.length" class="nothing tiny muted">
        The slate is empty. Mark a recipe at a bench or a processing line and it
        is remembered here — with what you are still short of for it, wherever
        you happen to be standing.
      </p>

      <template v-else>
        <p class="tiny muted note">
          What you mean to make, and what is still missing for it. Nothing is
          reserved — this is a note to yourself, not a claim on the bag.
          <template v-if="slateReady">
            <strong class="done">{{ slateReady }} you can afford now.</strong>
          </template>
        </p>

        <div v-for="line in slate" :key="line.key" class="line inset" :class="{ ready: line.ready }">
          <SvgIcon :svg="line.icon" boxed :size="26" />
          <div class="grow">
            <div class="row-between">
              <strong class="tiny">{{ line.name }}</strong>
              <span class="tiny muted makes">{{ line.makes }}</span>
            </div>
            <!-- Every material is drawn as well as named. A shopping list you
                 read in a field is scanned rather than read, and the glyph is
                 what a player recognises in the bag they are comparing it to. -->
            <div class="inputs">
              <span
                v-for="input in line.inputs"
                :key="input.key"
                class="input tiny"
                :class="{ short: input.have < input.need }"
              >
                <SvgIcon :svg="materialIcon(MATERIALS[input.key], 15)" />
                <span class="mono">{{ input.have }}/{{ input.need }}</span>
                <span class="iname">{{ MATERIALS[input.key].name }}</span>
              </span>
            </div>
          </div>
          <SlateMark :recipe="line.key" />
        </div>
      </template>
    </template>
  </div>
</template>

<style scoped>
/*
 * Finished reads GREEN here too, for the reason §12's ledger gives: ember is
 * the color of something wrong, and gold is the currency itself. A thing
 * waiting on a bench is neither a problem nor a payout figure.
 */
.done {
  color: var(--sap);
}

.tally {
  font-family: var(--font-display);
  font-variant-numeric: tabular-nums;
  font-size: 11px;
  padding: 1px 6px;
  background: rgba(0, 0, 0, 0.4);
  color: var(--vellum-dim);
}

.tally.ready {
  background: var(--sap);
  color: var(--ink);
}

.page {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.note {
  margin: 0 0 4px;
}

.entry {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: 8px;
}

.foot {
  padding: 0 2px;
}

/* --------------------------------------------------------------------- tabs */

.tabs {
  display: flex;
  gap: 6px;
}

.tab {
  flex: 1 1 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  padding: 8px 10px;
  font-size: 12px;
  color: var(--vellum-dim);
  background: rgba(0, 0, 0, 0.28);
  border: 1px solid transparent;
  clip-path: polygon(8px 0, 100% 0, 100% calc(100% - 8px), calc(100% - 8px) 100%, 0 100%, 0 8px);
  cursor: pointer;
}

.tab.on {
  color: var(--vellum);
  border-color: var(--line);
  background: var(--ink-panel);
}

/* -------------------------------------------------------------- the slate */

.line {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 6px;
}

/* Everything the bag can already cover reads sap, the same as a finished job
   above it (§13.3): this list is scanned for what is ready, not for what is not. */
.line.ready strong {
  color: var(--sap);
}

.inputs {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 10px;
  margin-top: 4px;
}

.input {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  line-height: 1;
}

.iname {
  color: var(--vellum-dim);
}

/* Ember on the count AND the name of what is missing, because the pair is one
   fact: the shortfall is what a player is scanning this list for. */
.input.short .iname {
  color: inherit;
}

/* Ember on the shortfall alone, because that is the state to deal with. */
.input.short {
  color: var(--ember);
}

.input:not(.short) .mono {
  color: var(--vellum-dim);
}
</style>
