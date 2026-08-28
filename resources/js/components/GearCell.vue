<script setup lang="ts">
/**
 * One slot of the kit: a hexagon, and a gauge that runs down its right face.
 *
 * What a player opens the prospector sheet to find out is **what is about to
 * break**, and a name answers that about as well as a filename answers what a
 * photo is of — §13.1 already puts the slot in the silhouette and the rung in
 * the colour. So the name is gone and the gauge is the row. What each piece IS
 * lives one tap deeper.
 *
 * **Everything here is measured off the drawn hexagon, never off a box.** That
 * distinction is the whole of what went wrong before. §13.1's icon draws a
 * REGULAR hexagon inscribed in a square viewBox: 0.93 of the width across the
 * points, 0.866 of that again down the flats. A box is none of those numbers,
 * so laying a clip on the box, centring the art in the box and hanging the
 * gauge off the box gave three shapes that agreed with each other nowhere.
 *
 * `--cell` is therefore the hexagon's own WIDTH — the thing you can see and
 * point at — and the art, the cell and the gauge are all derived from it.
 */
import { computed, useId } from 'vue'
import SvgIcon from '@/components/SvgIcon.vue'
import type { ItemDef, OwnedItem } from '@/game/types'

const props = defineProps<{
  item: OwnedItem | null
  def: ItemDef | null
  /** Drawn when the slot is bare: the line's glyph, or a dashed hexagon. */
  fallback: string
  /** Said on hover and to a reader. Nothing on the rack is said on screen. */
  label: string
  /** The icon of what is in the slot. */
  icon: string | null
}>()

/*
 * The gauge, in hexagon units: 100 across the points, 86.6 down the flats.
 *
 * The right face runs (75,0) → (100,43.3) → (75,86.6), so the gauge is that
 * path pushed out by a constant — the only way two shapes stay parallel. It
 * starts at the hexagon's top-right corner and ends at its bottom-right one,
 * which is what makes it read as belonging to this cell and no other.
 *
 * The viewBox is 112 wide so the pushed-out apex has room; the element is
 * 1.12 cells wide against 0.866 tall, so the scale stays uniform both ways.
 */
const VIEW_W = 112
const VIEW_H = 86.6
const CLEARANCE = 6
/*
 * How far past the hexagon's corners the chevron runs, in the same units.
 *
 * `stroke-linecap: butt` cuts each end square across the path, and the path
 * meets the corner at 60° — so the topmost pixel it paints sits a couple below
 * the corner while the hexagon's own stroke overhangs a little above it. Left
 * geometric, the gauge reads two pixels short at the top and one at the foot.
 * Running the path past the corner and letting the ends fall where they may is
 * what makes the two shapes start and stop together.
 */
const OVER = 2.0
/** The path's own slope, so an extension follows the face rather than dropping straight. */
const RUN = (OVER * 50) / VIEW_H
const EDGE =
  `M${75 + CLEARANCE - RUN} ${-OVER}` +
  ` L${100 + CLEARANCE} ${VIEW_H / 2}` +
  ` L${75 + CLEARANCE - RUN} ${VIEW_H + OVER}`
/** The hexagon itself, in the same units — the ground every slot stands on. */
const GROUND = `25,0 75,0 100,${VIEW_H / 2} 75,${VIEW_H} 25,${VIEW_H} 0,${VIEW_H / 2}`

/**
 * Unique per instance, and it has to come from the framework.
 *
 * A module-looking `let counter = 0` at the top of `<script setup>` is not
 * module scope — that block IS the setup function, so the counter is reborn at
 * 0 for every cell and all nine mint the same id. Every gauge in the rack then
 * draws the FIRST one's durability, which looks exactly like a gauge wired to
 * nothing.
 */
const uid = useId()

const ceiling = computed(() => props.item?.maxDurability || (props.def?.maxDurability ?? 1))

/** 0 when the slot is bare, so a missing tool and a dead one look different. */
const fraction = computed(() =>
  props.item ? Math.max(0, Math.min(1, props.item.durability / Math.max(1, ceiling.value))) : 0,
)

/**
 * The window onto the scale: the bottom `fraction` of it, and nothing above.
 *
 * It has to reach past both corners, or the overrun that makes the gauge line
 * up with the hexagon would be clipped straight back off -- at the foot always,
 * and at the head once the piece is full.
 */
const clip = computed(() => {
  const y = fraction.value >= 1 ? -OVER : VIEW_H * (1 - fraction.value)

  return { y, h: VIEW_H + OVER - y }
})
</script>

<template>
  <button
    class="cell"
    :class="{ bare: !icon, gone: item && item.durability <= 0 }"
    type="button"
    :title="label"
    :aria-label="label"
  >
    <!-- Drawn BEFORE the art, because it carries the ground the art stands on.
         The chevron never crosses the hexagon, so nothing here can cover the
         icon. -->
      <!-- The viewBox stays the cell's own box so the scale never shifts; the
         chevron's overrun paints outside it, which `overflow: visible` allows
         and which opening the viewBox would not -- that would letterbox the
         whole drawing to fit a taller aspect. -->
    <svg class="gauge" :viewBox="`0 0 ${VIEW_W} ${VIEW_H}`" aria-hidden="true">
      <defs>
        <linearGradient :id="`${uid}-ramp`" x1="0" y1="1" x2="0" y2="0">
          <stop offset="0" stop-color="#b8453f" />
          <stop offset="0.5" stop-color="#d8b34a" />
          <stop offset="1" stop-color="#8fbf7f" />
        </linearGradient>
        <clipPath :id="`${uid}-lit`">
          <rect x="0" :y="clip.y" :width="VIEW_W" :height="clip.h" />
        </clipPath>
      </defs>

      <!-- The same ground under every slot, full or empty. It used to be a CSS
           clip on the art and only a BARE cell had one, which put the heaviest
           mark in the rack on the slots holding nothing — an empty hand reading
           louder than a full one. Drawn here it is exact hexagon geometry, it
           sits behind the icon rather than cutting it, and there is one
           definition of the shape instead of two. -->
      <polygon :points="GROUND" class="ground" />

      <!-- The unlit track carries half the reading. What separates a piece at
           84% from one at 73% is a few pixels of lit length, which nothing can
           see, or the same few pixels said as an absence above it, which
           anyone can. It needs real contrast against the panel for that. -->
      <path :d="EDGE" class="track" />
      <path v-if="item" :d="EDGE" :stroke="`url(#${uid}-ramp)`" :clip-path="`url(#${uid}-lit)`" />
    </svg>

    <span class="art">
      <SvgIcon v-if="icon" :svg="icon" />
      <span v-else class="glyph" v-html="fallback" />
    </span>
  </button>
</template>

<style scoped>
/*
 * Three derived numbers, all off the hexagon's width:
 *   height  = 0.866 × width   the flats of a regular hexagon
 *   cell    = 1.12  × width   the hexagon plus the room the gauge stands in
 *   art     = 1.075 × width   the square viewBox whose inscribed hexagon is
 *                             exactly `width` across (1 / 0.93)
 */
.cell {
  position: relative;
  width: calc(var(--cell, 58px) * 1.12);
  height: calc(var(--cell, 58px) * 0.866);
  flex: 0 0 auto;
  padding: 0;
  border: 0;
  background: none;
  cursor: pointer;
}

/* The hexagon itself: the left part of the cell, and the thing every other
   number here is measured against. */
.art {
  position: absolute;
  left: 0;
  top: 0;
  width: var(--cell, 58px);
  height: calc(var(--cell, 58px) * 0.866);
}

/*
 * Centred by transform rather than by alignment. `place-items: center` does not
 * centre an item LARGER than its track — it start-aligns it — so the art sat in
 * the cell's top-left corner and hung over the gauge and the row beneath. A
 * translate cannot have that opinion.
 */
.art :deep(.svg-icon) {
  position: absolute;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
  display: block;
  width: calc(var(--cell, 58px) * 1.075);
  height: calc(var(--cell, 58px) * 1.075);
}

.art :deep(.svg-icon svg) {
  display: block;
  width: 100%;
  height: 100%;
}

/* A bare slot's glyph is smaller than its box, so alignment centres it fine.
   The ICON is not — see the transform above. */
.cell.bare .art {
  display: grid;
  place-items: center;
  color: #5a685f;
}

.glyph {
  display: block;
  line-height: 0;
}

.glyph :deep(svg) {
  display: block;
  width: calc(var(--cell, 58px) * 0.46);
  height: calc(var(--cell, 58px) * 0.46);
}

/* §8.2 — a piece at zero is paying nothing until it is mended, and the icon
   says so by going quiet. The gauge is already at the floor; dimming the art
   is what stops a dead tool reading as a live one at a glance. */
.cell.gone .art {
  opacity: 0.45;
}

.gauge {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  pointer-events: none;
  /* The chevron runs a little past the cell at both ends so it lines up with
     the hexagon's painted edge; an SVG root crops to its viewport by default. */
  overflow: visible;
}

.ground {
  fill: rgba(0, 0, 0, 0.32);
}

.gauge path {
  fill: none;
  stroke-width: 5;
  stroke-linejoin: miter;
  stroke-linecap: butt;
  /* The viewBox scales with the cell, and a geometric stroke would scale with
     it — so a phone would get a thinner gauge than a desktop for no reason. */
  vector-effect: non-scaling-stroke;
}

.track {
  stroke: #4d5c53;
}

/* A tint behind the art would paint a square on a cell that carries no clip,
   and an outline on the cell would box in the gauge as well. Brightening the
   drawing is the one lift that follows whatever shape is actually there. */
.cell:hover .art {
  filter: brightness(1.3);
}

.cell:focus-visible {
  outline: 2px solid var(--copper);
  outline-offset: 2px;
}
</style>
