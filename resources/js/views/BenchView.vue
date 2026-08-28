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
import { computed } from 'vue'
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

    <!-- ---------------------------------------------------------- the slate -->
    <!-- §8.4 -- outside the `v-else`, because what you mean to make is worth
         reading whether or not anything is cooking. It is the half of this page
         that plans a gather rather than a walk. -->
    <section v-if="slate.length" class="section">
      <div class="row" style="gap: 8px; margin-bottom: 3px">
        <h3 class="head">On the slate</h3>
        <span class="tally" :class="{ ready: slateReady > 0 }">
          {{ slateReady > 0 ? `${slateReady} affordable` : `${slate.length}/${SLATE_CAP}` }}
        </span>
      </div>
      <p class="tiny muted note">
        What you mean to make, and what is still missing for it. Nothing is
        reserved — this is a note to yourself, not a claim on the bag.
      </p>

      <div v-for="line in slate" :key="line.key" class="line inset" :class="{ ready: line.ready }">
        <SvgIcon :svg="line.icon" boxed :size="26" />
        <div class="grow">
          <div class="row-between">
            <strong class="tiny">{{ line.name }}</strong>
            <span class="tiny muted makes">{{ line.makes }}</span>
          </div>
          <div class="inputs">
            <span
              v-for="input in line.inputs"
              :key="input.key"
              class="input tiny mono"
              :class="{ short: input.have < input.need }"
            >
              {{ MATERIALS[input.key].name }} {{ input.have }}/{{ input.need }}
            </span>
          </div>
        </div>
        <SlateMark :recipe="line.key" />
      </div>
    </section>
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
  gap: 3px 8px;
  margin-top: 3px;
}

/* Ember on the shortfall alone, because that is the state to deal with. */
.input.short {
  color: var(--ember);
}

.input:not(.short) {
  color: var(--vellum-dim);
}
</style>
