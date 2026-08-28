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
 * The box is 125 wide against 86.6 tall because that is what a REGULAR hexagon
 * measures: circumradius R gives a width of 2R and a height of R√3, so height
 * is 0.866 of width and never equal to it. `.icon-box` cuts its clip from
 * whatever box it is given, so a square one yields a hexagon stretched 15% tall
 * -- which is fine where nothing else is drawn against it, and wrong here,
 * because the icon inside draws its own regular frame (§13.1) and the two then
 * disagree about what shape a hexagon is.
 *
 * The element is 1.25 cells wide against 0.866 tall, matching the viewBox, so
 * the scale stays uniform and the stroke stays honest.
 */
const VIEW_W = 125
const VIEW_H = 86.6
/** How far off the face the gauge stands, in the same units. */
const CLEARANCE = 6
const EDGE = `M${75 + CLEARANCE} 0 L${100 + CLEARANCE} ${VIEW_H / 2} L${75 + CLEARANCE} ${VIEW_H}`

/**
 * Unique per instance, and it has to come from the framework.
 *
 * A module-looking `let counter = 0` at the top of `<script setup>` is not
 * module scope -- that block IS the component's setup function, so the counter
 * is reborn at 0 for every cell and all nine mint the same id. The gradient
 * then resolves to whichever element claimed it first, and so does the clip:
 * every gauge in the rack drew the FIRST one's durability, which is a bug that
 * looks exactly like a gauge that is not wired to anything.
 */
const uid = useId()

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
    <!-- §13.1 -- a filled cell wears NO outer clip. The icon already draws a
         hexagon, and its artwork deliberately spills a little past that frame;
         a second hexagon over the top shaves the spill and leaves a blade cut
         off flat. An empty cell has no icon to be the shape, so it keeps the
         dark clipped box and the glyph sits well inside it. -->
    <span class="art" :class="{ 'icon-box': !icon }">
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
  width: calc(var(--cell, 60px) * 1.25);
  height: calc(var(--cell, 60px) * 0.866);
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
  width: var(--cell, 60px);
  display: grid;
  place-items: center;
}

/* The wrapper has to be given the box before the art can be a share of it: an
   auto-sized grid track and a percentage child collapse each other to nothing. */
.art :deep(.svg-icon) {
  display: grid;
  place-items: center;
  width: 100%;
  height: 100%;
}

/* Filling the cell, not sitting in it. §13.1 gives every item icon its own hex
   frame, so a smaller one drew a hexagon inside a hexagon -- two rings saying
   one thing, with the rarity colour on the inner and quieter of the two. At
   full size the frame IS the cell, which is what makes rarity readable across
   a rack of nine.

   1.075 because the icon's viewBox is SQUARE and the regular hexagon it draws
   is inscribed at 93% of that width. Scaling by 1/0.93 puts the drawn frame
   exactly on the cell's own hexagon -- and its height lands at 0.866 of the
   width on its own, which is the same geometry the gauge is cut from. */
.art :deep(svg) {
  display: block;
  width: calc(var(--cell, 60px) * 1.075);
  height: calc(var(--cell, 60px) * 1.075);
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

/* The fallbacks are line glyphs rather than framed icons -- a skill mark and a
   dashed hexagon -- so they keep their margin. Sized off the cell rather than
   as a percentage, because the box is no longer square and 58% of two different
   edges is two different sizes. */
.glyph :deep(svg) {
  display: block;
  width: calc(var(--cell, 60px) * 0.5);
  height: calc(var(--cell, 60px) * 0.5);
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
  stroke-width: 5;
  stroke-linejoin: miter;
  stroke-linecap: butt;
  /* The viewBox scales with the cell, and a geometric stroke would scale with
     it -- so a phone would get a thinner gauge than a desktop for no reason. */
  vector-effect: non-scaling-stroke;
}

.track {
  stroke: #4d5c53;
}

/* A tint behind the art would paint a SQUARE now that a filled cell carries no
   clip, and an outline on the cell would box in the gauge as well. Brightening
   the drawing itself is the one lift that follows whatever shape is there. */
.cell:hover .art {
  filter: brightness(1.3);
}

/* Focus needs to be seen rather than felt, so it gets a real ring -- on the
   button, which is the thing being focused, and never on the art. */
.cell:focus-visible {
  outline: 2px solid var(--copper);
  outline-offset: 2px;
}
</style>
