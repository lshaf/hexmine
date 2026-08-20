<script setup lang="ts">
/**
 * Work running for you at a settlement, pinned under the instrument cluster.
 *
 * Processing only. A mining trip is something you are doing on the hex you are
 * standing on, so it belongs to the dock; this is the job an NPC keeps running
 * whether you stay to help or walk away (§6.2), which is exactly why it needs a
 * readout that follows you.
 */
import { computed } from 'vue'
import { useGame } from '@/stores/game'
import { MATERIALS } from '@/game/catalog'
import { formatDuration } from '@/game/formulas'
import { materialIcon } from '@/icons/procedural'
import { ACTION_PATHS } from '@/icons/actions'
import SvgIcon from '@/components/SvgIcon.vue'
import type { Job } from '@/game/types'

const game = useGame()

const ordered = computed(() =>
  game.jobs.filter((j) => j.kind === 'processing').sort((a, b) => a.endsAt - b.endsAt),
)

const output = (job: Job) => (job.kind === 'mining' ? job.material : job.output)

const ready = (job: Job) => job.endsAt <= game.now

/** Where a stop right now would leave you -- whole hexes only. */
const walked = computed(() => game.travelHexesWalked)

const heading = computed(() => {
  const journey = game.travel
  if (!journey) return ''

  return journey.destinationName ?? `${journey.toCol}, ${journey.toRow}`
})
</script>

<template>
  <div v-if="game.travel" class="stack road">
    <div class="trip plate">
      <div class="inner">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path :d="ACTION_PATHS.travel" />
        </svg>
        <span class="grow body">
          <span class="qty readout">To {{ heading }}</span>
          <span class="label when">
            {{ walked }}/{{ game.travel.hexes }} hexes · {{ formatDuration(game.travelRemainingMs) }}
          </span>
        </span>
        <button
          class="btn btn-sm btn-danger"
          type="button"
          :disabled="game.busy"
          title="Stops on the last hex you crossed — part of a hex counts for nothing"
          @click="game.cancelTravel()"
        >
          Stop
        </button>
      </div>
    </div>
  </div>

  <TransitionGroup v-if="ordered.length" name="rise" tag="div" class="stack">
    <div v-for="job in ordered" :key="job.id" class="trip plate" :class="{ ready: ready(job) }">
      <div class="inner">
        <SvgIcon :svg="materialIcon(MATERIALS[output(job)], 20)" />
        <span class="grow body">
          <span class="qty readout">{{ job.quantity }} {{ MATERIALS[output(job)].name }}</span>
          <span class="label when">
            {{ ready(job) ? 'Ready' : formatDuration(job.endsAt - game.now) }}
          </span>
        </span>
        <button
          v-if="ready(job)"
          class="btn btn-primary btn-sm"
          type="button"
          :disabled="game.busy"
          @click="game.collect(job.id)"
        >
          Collect
        </button>
        <button
          v-else
          class="btn btn-sm btn-danger"
          type="button"
          :disabled="game.busy"
          title="Abandoning forfeits the partial haul"
          @click="game.abandon(job.id)"
        >
          Drop
        </button>
      </div>
    </div>
  </TransitionGroup>
</template>

<style scoped>
.stack {
  display: flex;
  flex-direction: column;
  gap: 6px;
  width: 232px;
}

.road {
  margin-bottom: 6px;
  color: var(--copper);
}

.road .qty,
.road .when {
  color: var(--vellum);
}

.inner {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 7px 9px 7px 10px;
}

.body {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.qty {
  font-size: 12px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.when {
  font-size: 8.5px;
}

.ready .when {
  color: var(--gold);
}

@media (max-width: 560px) {
  .stack {
    width: 200px;
  }
}
</style>
