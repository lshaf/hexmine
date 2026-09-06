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
 * The whole road, as asked for.
 *
 * NOT the hex a pack ahead will stop you on. A clock that counted to the cut
 * would announce the ambush the instant the road started -- a whole journey
 * early, from any distance. The eye is open on the road now (§5.6), so a pack
 * IS discovered before you reach it: by coming within sight of it, a hex or
 * three out. That is a warning earned by getting close, which is the opposite
 * of a clock that knew all along.
 *
 * The marker still stops where the road really ends: travelProgress clamps to
 * `stopHex`, which is what keeps a walker from visibly arriving at the village
 * and snapping back when the correction lands. That is a fact about the
 * animation, and it is not drawn as a number anywhere.
 */
const legs = computed(() => game.travel?.hexes ?? 0)
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
