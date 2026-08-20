<script setup lang="ts">
/**
 * A hexagon whose perimeter is the progress track.
 *
 * The map is hexes, so the instruments reading it are hexes too -- a bar would
 * be borrowed from a different interface. The fill travels the six edges
 * clockwise from the left vertex.
 *
 * Geometry matches the map's flat-top tiling (§13.2): width 100, height 86.6,
 * side length 50, so the perimeter is exactly 300 units and the dash maths needs
 * no measurement. Everything the gauge says sits inside the hex -- a label hung
 * underneath collides with the next cell in the honeycomb.
 *
 * The viewBox is padded rather than the hexagon shrunk, so the perimeter stays
 * 300. That padding is what keeps the cluster readable: cells in a honeycomb
 * share edges, and a stroke centred on a shared edge spills half of itself into
 * the neighbour, which then paints over it. Inset the drawing and every ring
 * stays inside its own cell, with the gap reading as the wall between them.
 */
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    label: string
    /** Big number in the middle. */
    value: string | number
    /** Small line under the value, e.g. "/120". */
    sub?: string
    /** 0–1. */
    progress: number
    accent: string
    /** Pulses the ring when something needs attention. */
    alert?: boolean
  }>(),
  { sub: '', alert: false },
)

const PERIMETER = 300

/** Half the stroke, plus the wall between neighbouring cells. */
const PAD = 6

/** Flat-top hexagon, starting at the left vertex so the fill sweeps over the top. */
const POINTS = '0,43.3 25,0 75,0 100,43.3 75,86.6 25,86.6'

const offset = computed(() => PERIMETER * (1 - Math.min(1, Math.max(0, props.progress))))
</script>

<template>
  <div class="gauge" :class="{ alert }" :style="{ '--accent': accent }">
    <svg
      :viewBox="`${-PAD} ${-PAD} ${100 + PAD * 2} ${86.6 + PAD * 2}`"
      preserveAspectRatio="none"
      aria-hidden="true"
    >
      <!-- Body first, so the ring sits on its edge rather than inside it. -->
      <polygon :points="POINTS" fill="var(--hud-solid)" />
      <polygon :points="POINTS" fill="none" stroke="var(--hud-line-soft)" stroke-width="7" />
      <polygon
        class="fill"
        :points="POINTS"
        fill="none"
        :stroke="accent"
        stroke-width="7"
        stroke-linecap="butt"
        :stroke-dasharray="PERIMETER"
        :stroke-dashoffset="offset"
      />
    </svg>

    <div class="readouts">
      <span class="readout value">{{ value }}</span>
      <span v-if="sub" class="sub">{{ sub }}</span>
      <span class="label cap">{{ label }}</span>
    </div>
  </div>
</template>

<style scoped>
.gauge {
  position: relative;
  width: 100px;
  height: 87px;
  display: grid;
  place-items: center;
}

svg {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
}

.fill {
  transition: stroke-dashoffset 0.5s cubic-bezier(0.32, 0.72, 0, 1);
}

.readouts {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  line-height: 1;
}

.value {
  font-size: 21px;
  color: var(--vellum);
}

.sub {
  font-size: 9px;
  color: var(--vellum-dim);
  font-variant-numeric: tabular-nums;
}

.cap {
  font-size: 7.5px;
  letter-spacing: 0.15em;
  color: var(--accent);
  margin-top: 1px;
}

.alert .fill {
  animation: throb 1.4s ease-in-out infinite;
}

@keyframes throb {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0.4;
  }
}
</style>
