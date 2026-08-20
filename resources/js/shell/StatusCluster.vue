<script setup lang="ts">
/**
 * Top-left instrument cluster: the three numbers that decide what you can do
 * next. Laid out as a honeycomb on the same lattice as the map itself, so the
 * HUD reads as part of the world rather than a panel on top of it.
 *
 * Name and wallet are deliberately absent -- they never change, so they live on
 * the hero sheet. What is on the map is what moves.
 */
import { computed } from 'vue'
import { useGame } from '@/stores/game'
import { formatDuration } from '@/game/formulas'
import HexGauge from './HexGauge.vue'

const game = useGame()
const char = computed(() => game.character)

const apProgress = computed(() => (char.value ? char.value.ap / char.value.apMax : 0))

const storageProgress = computed(() =>
  char.value ? Math.min(1, char.value.storageUsed / char.value.storageCap) : 0,
)

const overCap = computed(() =>
  char.value ? char.value.storageUsed > char.value.storageCap : false,
)

const xpProgress = computed(() => (char.value ? char.value.xp / char.value.xpToNext : 0))

/** Time until the next action point, against the server clock. */
const nextAp = computed(() => {
  if (!char.value || char.value.ap >= char.value.apMax) return null
  return formatDuration(char.value.apUpdatedAt + char.value.apRegenMs - game.now)
})
</script>

<template>
  <div v-if="char" class="wrap">
    <div class="cluster">
    <div class="cell ap">
      <HexGauge
        label="AP"
        :value="char.ap"
        :sub="`/${char.apMax}`"
        :progress="apProgress"
        accent="var(--copper)"
      />
    </div>

    <div class="cell store">
      <HexGauge
        label="Store"
        :value="char.storageUsed"
        :sub="`/${char.storageCap}`"
        :progress="storageProgress"
        :accent="overCap ? 'var(--ember)' : 'var(--violet)'"
        :alert="overCap"
      />
    </div>

    <div class="cell lvl">
      <HexGauge
        label="Level"
        :value="char.level"
        :sub="`${char.xp}/${char.xpToNext}`"
        :progress="xpProgress"
        accent="var(--gold)"
      />
    </div>

    </div>

    <div class="side">
      <span class="chip chip-gold">{{ char.gold }}g</span>
      <span v-if="nextAp" class="tick label">+1 AP {{ nextAp }}</span>
      <span v-if="overCap" class="tick over label">Over cap — raw is rotting</span>
    </div>
  </div>
</template>

<style scoped>
/*
 * Honeycomb offsets. Flat-top hexes tile at 0.75x width horizontally with odd
 * columns dropped half a height -- the same rule the map uses, so the cluster
 * reads as cut from the same grid rather than placed on top of it.
 *
 * Gauge box is 100 x 87, so the column step is 75 and the drop is 43.5. The
 * boxes tile exactly; the visible gap between cells comes from the padding
 * inside each gauge, not from spacing them apart.
 */
.wrap {
  display: flex;
  flex-direction: column;
}

.cluster {
  position: relative;
  width: 175px;
  height: 174px;
  pointer-events: none;
}

.cell {
  position: absolute;
  pointer-events: auto;
}

.ap {
  left: 0;
  top: 0;
}

.store {
  left: 75px;
  top: 43.5px;
}

.lvl {
  left: 0;
  top: 87px;
}

/*
 * Below the honeycomb, not inside it. Tucked into the indent the bottom hex
 * leaves so it still reads as part of the cluster.
 */
.side {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  margin: 4px 0 0 6px;
  max-width: 184px;
}

.tick {
  font-size: 8px;
  letter-spacing: 0.1em;
  white-space: nowrap;
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.9);
}

.over {
  color: #e58c86;
  max-width: 90px;
  white-space: normal;
  line-height: 1.4;
}

@media (max-width: 560px) {
  .wrap {
    transform: scale(0.82);
    transform-origin: top left;
  }
}
</style>
