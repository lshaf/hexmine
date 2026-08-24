<script setup lang="ts">
/**
 * The road, pinned under the instrument cluster.
 *
 * Travel and nothing else. A journey is the one piece of work with no place to
 * be claimed -- it ends wherever you are standing, and stopping it is a decision
 * you make while looking at the map. Everything a settlement is holding for you
 * (§6, §8.4) is claimed at the building that holds it, so it lives in the
 * Benches ledger rather than in a stack over the map.
 */
import { computed } from 'vue'
import { useGame } from '@/stores/game'
import { formatDuration } from '@/game/formulas'
import { ACTION_PATHS } from '@/icons/actions'

const game = useGame()

/** Where a stop right now would leave you -- whole hexes only. */
const walked = computed(() => game.travelHexesWalked)

const heading = computed(() => {
  const journey = game.travel
  if (!journey) return ''

  return journey.destinationName ?? `${journey.toCol}, ${journey.toRow}`
})

/**
 * §9.5.3 -- how far the road actually goes, which a pack ahead brings forward.
 *
 * Nothing is given away by saying so: the client generates packs itself from
 * the seed (§5.6), so the map is already drawing the one standing there. What
 * this fixes is the walker arriving at the village and snapping back down the
 * road when the server's correction landed a whole journey later.
 */
const legs = computed(() => game.travel?.stopHex ?? 0)
</script>

<template>
  <div v-if="game.travel" class="stack road">
    <div class="leg plate">
      <div class="inner">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path :d="ACTION_PATHS.travel" />
        </svg>
        <span class="grow body">
          <span class="qty readout">To {{ heading }}</span>
          <span class="label when">
            {{ walked }}/{{ legs }} hexes · {{ formatDuration(game.travelRemainingMs) }}
          </span>
          <!-- §13.3 -- ember, because it is a state to deal with rather than
               news worth crossing the screen for. The rest of the road is not
               walked (§9.5.3), so the destination on the line above is no
               longer where this journey is going. -->
          <span v-if="game.travelBlockedBy" class="label blocked">
            {{ game.travelBlockedBy }} on the road — the walk ends there
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

.road .blocked {
  color: var(--ember);
  font-size: 8.5px;
}

@media (max-width: 560px) {
  .stack {
    width: 186px;
    gap: 5px;
  }

  .inner {
    gap: 7px;
    padding: 6px 8px 6px 9px;
  }

  .qty {
    font-size: 11px;
  }
}
</style>
