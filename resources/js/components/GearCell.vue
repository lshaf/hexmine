<script setup lang="ts">
/**
 * One slot of the kit: a hexagon, and a gauge that runs down its right face.
 *
 * The prospector sheet used to be nine tall rows, each spending most of its
 * width on a name the icon had already said (§13.1 puts the slot in the
 * silhouette and the rung in the colour). What a player opens this screen to
 * find out is **what is about to break**, and a name answers that about as well
 * as a filename answers what a photo is of. So the name is gone and the gauge
 * is the row. What each piece IS lives one tap deeper.
 *
 * **The gauge is not a bar beside the hexagon, it is the hexagon's own edge.**
 * §13 allows two shapes and no third: a hexagon and a chamfer. A straight rail
 * next to a hexagon is a third one — it sits in the gap and reads as a divider
 * between two cells rather than as a reading off one. The chevron runs parallel
 * to the right face at the same 1:2 slope the clip cuts, so it belongs to its
 * own cell and to no other.
 *
 * §13.3 -- the scale is sap at the top, gold in the middle, ember at the foot,
 * and it is FIXED: the palette already has a word for healthy, for getting on,
 * and for a state to deal with, and pinning them to positions is what makes
 * nine gauges comparable. What varies is how much of the scale is lit, filling
 * from the foot up — so the height and the colour of the tip say the same
 * thing twice, and a piece in trouble is short AND red.
 */
import { computed } from 'vue'
import SvgIcon from '@/components/SvgIcon.vue'
import type { ItemDef, OwnedItem } from '@/game/types'

const props = defineProps<{
  item: OwnedItem | null
  def: ItemDef | null
  /** Drawn when the slot is bare: the line's glyph, or a dashed hexagon. */
  fallback: string
  /** Said on hover and to a reader. Nothing on the rack is said on screen. */
  label: string
  /** The icon of what is in the slot, already sized. */
  icon: string | null
}>()

/*
 * The gauge is drawn in the CELL's own coordinates, not in a box of its own.
 *
 * §13's clip cuts the hexagon at 25% / 50%, so its right face runs (75,0) →
 * (100,50) → (75,100) in a 100-unit square. The gauge is that same path pushed
 * out by a constant, which is the only way two shapes can be parallel: a
 * chevron placed BESIDE the hexagon's bounding box touches it at the middle
 * and drifts a quarter of the width away at the top and the bottom, because
 * the box is square and the shape inside it is not.
 *
 * The viewBox is 125 wide against 100 tall and the element is 1.25 cells wide
 * against one tall, so the scale stays uniform and the stroke stays honest.
 */
const VIEW_W = 125
const VIEW_H = 100
/** How far off the face the gauge stands, in the same units. */
const CLEARANCE = 6
const EDGE = `M${75 + CLEARANCE} 0 L${100 + CLEARANCE} ${VIEW_H / 2} L${75 + CLEARANCE} ${VIEW_H}`

/** Unique per instance: two gradients sharing an id would share a fill. */
let counter = 0
const uid = `gauge${++counter}`

const ceiling = computed(() => props.item?.maxDurability || (props.def?.maxDurability ?? 1))

/** 0 when the slot is bare, so a missing tool and a dead one look different. */
const fraction = computed(() =>
  props.item ? Math.max(0, Math.min(1, props.item.durability / Math.max(1, ceiling.value))) : 0,
)

/** The window onto the scale: the bottom `fraction` of it, and nothing above. */
const litTop = computed(() => VIEW_H * (1 - fraction.value))
</script>

<template>
  <button
    class="cell"
    :class="{ bare: !item, gone: item && item.durability <= 0 }"
    type="button"
    :title="label"
    :aria-label="label"
  >
    <span class="icon-box art">
      <SvgIcon v-if="icon" :svg="icon" :size="38" />
      <span v-else class="glyph" v-html="fallback" />
    </span>

    <svg class="gauge" :viewBox="`0 0 ${VIEW_W} ${VIEW_H}`" aria-hidden="true">
      <defs>
        <linearGradient :id="`${uid}-ramp`" x1="0" y1="1" x2="0" y2="0">
          <stop offset="0" stop-color="#b8453f" />
          <stop offset="0.5" stop-color="#d8b34a" />
          <stop offset="1" stop-color="#8fbf7f" />
        </linearGradient>
        <clipPath :id="`${uid}-lit`">
          <rect x="0" :y="litTop" :width="VIEW_W" :height="VIEW_H - litTop" />
        </clipPath>
      </defs>

      <!-- The unlit track has to be visible. Unlit and invisible are different
           facts: without it the lit part floats, and nine gauges read as nine
           ragged marks instead of nine readings off one scale. -->
      <path :d="EDGE" class="track" />
      <path v-if="item" :d="EDGE" :stroke="`url(#${uid}-ramp)`" :clip-path="`url(#${uid}-lit)`" class="lit" />
    </svg>
  </button>
</template>

<style scoped>
.cell {
  position: relative;
  width: calc(var(--cell, 56px) * 1.25);
  height: var(--cell, 56px);
  flex: 0 0 auto;
  padding: 0;
  border: 0;
  background: none;
  cursor: pointer;
}

/* The cell scales from one variable so the rack can shrink to a phone without
   nine hexagons breaking onto three lines. The art follows it in CSS rather
   than through the size prop, which is a number and cannot answer a media
   query. */
.art {
  position: absolute;
  inset: 0 auto 0 0;
  width: var(--cell, 56px);
}

/* The wrapper has to be given the box before the art can be a share of it: an
   auto-sized grid track and a percentage child collapse each other to nothing. */
.art :deep(.svg-icon) {
  display: grid;
  place-items: center;
  width: 100%;
  height: 100%;
}

.art :deep(svg) {
  display: block;
  width: 68%;
  height: 68%;
}

.cell.bare .art {
  color: #5a685f;
}

/* §8.2 -- a piece at zero is paying nothing until it is mended, and the icon
   says so by going quiet. The gauge is already at the floor; dimming the art is
   what stops a dead tool reading as a live one at a glance. */
.cell.gone .art {
  opacity: 0.45;
}

.glyph {
  display: grid;
  place-items: center;
  width: 100%;
  height: 100%;
}

.glyph :deep(svg) {
  display: block;
  width: 58%;
  height: 58%;
}

/* Laid over the whole cell rather than placed after it, because the path is
   written in the hexagon's own coordinates. */
.gauge {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  pointer-events: none;
}

.gauge path {
  fill: none;
  stroke-width: 4;
  stroke-linejoin: miter;
  stroke-linecap: butt;
  /* The viewBox scales with the cell, and a geometric stroke would scale with
     it -- so a phone would get a thinner gauge than a desktop for no reason. */
  vector-effect: non-scaling-stroke;
}

.track {
  stroke: #2a352e;
}

/* The hexagon is clipped, so an outline would be cut away with the corners.
   The lift is the fill instead, which is the one thing a clip cannot eat. */
.cell:hover .art,
.cell:focus-visible .art {
  background: rgba(193, 121, 63, 0.24);
}
</style>
