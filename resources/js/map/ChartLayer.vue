<script setup lang="ts">
/**
 * The far end of the zoom: the world as a chart rather than a board.
 *
 * §13.2 -- the map is one <g> per hex, so its cost goes as the square of how
 * far out the camera is. Past `MAP_PX_CHART` that stops being affordable and
 * this takes over: the same world, drawn from the same seed, as sampled colour
 * with the settlements marked on it.
 *
 * This WAS the atlas, and the atlas was a separate screen behind a button with
 * its own pan, its own four named zoom steps and its own readout for whatever
 * you tapped. Everything it knew is here; what it stopped being is a different
 * place. The camera is the map's camera, the zoom is the map's zoom, and a tap
 * selects a hex so the tile card answers it -- which is what the rest of the
 * map already does, and one fewer thing to learn.
 *
 * It talks to nothing: terrain is a pure function of (col, row, seed) (§5) and
 * settlements sit on a lattice, so a whole continent is charted without a
 * request, a database or a tile store.
 *
 * Deliberately a CHART. Biomes are painted as a coarse sample raster with
 * visible cells, the way a printed survey sheet reads -- which both tells it
 * apart from the board and keeps a redraw honest at about 35,000 samples
 * whatever the scale.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { COL_STEP, ROW_STEP } from './hexGeometry'
import {
  biomeAt,
  coarseBiomeAt,
  isWorldConfigured,
  settlementMarksIn,
  worldParams,
} from '@/game/worldgen'
import type { SettlementMark } from '@/game/worldgen'
import { BIOME_COLOR } from '@/theme/palette'
import type { MapTier } from '@/game/types'

const props = defineProps<{
  centerCol: number
  centerRow: number
  /** Pixels per hex column -- the one unit both renderers share. */
  px: number
  width: number
  height: number
  characterCol: number
  characterRow: number
  selected: { col: number; row: number } | null
}>()

const emit = defineEmits<{
  (e: 'select', col: number, row: number): void
  (e: 'recenter', col: number, row: number): void
}>()

/** Rows are shorter than columns are wide, so the world keeps its proportions. */
const ROW_RATIO = ROW_STEP / COL_STEP

const pxPerCol = computed(() => props.px)
const pxPerRow = computed(() => props.px * ROW_RATIO)

/** Target size of one sampled block, in screen pixels. */
const SAMPLE_PX = 4

/**
 * Below this, one biome cell is too small on screen to read as a region and
 * point-sampling it renders as static, so the chart generalises to the coarse
 * layer instead. Same data, coarser level of detail.
 */
const DETAIL_CELL_PX = 10

/** How far past the canvas the raster reaches, so short pans need no rebuild. */
const RASTER_MARGIN = 160

const canvas = ref<HTMLCanvasElement | null>(null)

const detailed = computed(
  () => isWorldConfigured() && pxPerCol.value * worldParams().biomeCell >= DETAIL_CELL_PX,
)

/**
 * Which tiers are worth drawing at this scale.
 *
 * Villages outnumber cities and cities outnumber capitals (§6), so drawing
 * everything at every scale turns the sheet into confetti and hides the
 * structure the chart exists to show. Each step out drops the smallest tier,
 * the way a road atlas stops printing hamlets.
 */
const tiersShown = computed<MapTier[]>(() => {
  const px = pxPerCol.value
  if (px >= 4) return ['village', 'city', 'capital']
  if (px >= 1.2) return ['city', 'capital']
  return ['capital']
})

/** Villages are never labeled -- there are too many. Tap one to name it. */
const labeledTiers = computed<Set<MapTier>>(() => {
  const set = new Set<MapTier>(['capital'])
  if (pxPerCol.value >= 1.2) set.add('city')
  return set
})

// ------------------------------------------------------------------- raster

const raster = document.createElement('canvas')
let rasterCenter = { col: 0, row: 0 }
let rasterPx = -1
let rasterMarks: SettlementMark[] = []

/** Rebuild the cached bitmap if the view has left it, or the scale changed. */
function ensureRaster(): void {
  const w = props.width
  const h = props.height
  if (!w || !h) return

  const rw = w + RASTER_MARGIN * 2
  const rh = h + RASTER_MARGIN * 2

  const movedX = Math.abs(props.centerCol - rasterCenter.col) * pxPerCol.value
  const movedY = Math.abs(props.centerRow - rasterCenter.row) * pxPerRow.value
  const fits =
    rasterPx === props.px &&
    raster.width === rw &&
    raster.height === rh &&
    movedX < RASTER_MARGIN &&
    movedY < RASTER_MARGIN

  if (fits) return

  raster.width = rw
  raster.height = rh
  rasterCenter = { col: props.centerCol, row: props.centerRow }
  rasterPx = props.px

  const ctx = raster.getContext('2d')
  if (!ctx) return

  const cfg = worldParams()
  const px = pxPerCol.value
  const py = pxPerRow.value
  const sample = detailed.value ? biomeAt : coarseBiomeAt

  // Sample steps in hexes, chosen so a block is never smaller than SAMPLE_PX.
  const stepCol = Math.max(1, Math.ceil(SAMPLE_PX / px))
  const stepRow = Math.max(1, Math.ceil(SAMPLE_PX / py))
  const blockW = Math.ceil(stepCol * px) + 1
  const blockH = Math.ceil(stepRow * py) + 1

  const leftCol = rasterCenter.col - rw / 2 / px
  const topRow = rasterCenter.row - rh / 2 / py
  const rightCol = leftCol + rw / px
  const bottomRow = topRow + rh / py

  // Align samples to the step grid so blocks do not shimmer between rebuilds.
  const firstCol = Math.floor(leftCol / stepCol) * stepCol
  const firstRow = Math.floor(topRow / stepRow) * stepRow

  ctx.fillStyle = '#0b0f0d'
  ctx.fillRect(0, 0, rw, rh)

  for (let col = firstCol; col <= rightCol; col += stepCol) {
    if (Math.abs(col) > cfg.radius) continue
    const x = (col - leftCol) * px

    for (let row = firstRow; row <= bottomRow; row += stepRow) {
      if (Math.abs(row) > cfg.radius) continue
      ctx.fillStyle = BIOME_COLOR[sample(col, row)]
      ctx.fillRect(x, (row - topRow) * py, blockW, blockH)
    }
  }

  rasterMarks = settlementMarksIn(
    Math.floor(leftCol),
    Math.ceil(rightCol),
    Math.floor(topRow),
    Math.ceil(bottomRow),
    tiersShown.value,
  )
}

// -------------------------------------------------------------------- paint

const DOT: Record<MapTier, { r: number; fill: string; rank: number }> = {
  village: { r: 2.2, fill: '#ece3cd', rank: 0 },
  city: { r: 3.4, fill: '#c1793f', rank: 1 },
  capital: { r: 5, fill: '#d8b34a', rank: 2 },
}

/**
 * A dot has to be smaller than the gap between dots, or a field of them is a
 * field.
 *
 * Settlements stand about one to a country (§6), so their spacing on screen is
 * roughly `biomeCell * px` -- and at the far end of the zoom that is a couple
 * of pixels, where a five-pixel capital overlaps its neighbours in every
 * direction. The whole inner ring came out as one gold mass, which says
 * "something is here" and nothing else; §5.2's actual shape, a RING of them in
 * the contested band, only appears once the dots stop touching.
 *
 * Never below one pixel: a mark too small to see is the same as no mark, and
 * the point of drawing them at all is that the band is visible.
 */
function dotRadius(tier: MapTier, px: number): number {
  const spacing = worldParams().biomeCell * px

  return Math.max(1, Math.min(DOT[tier].r, spacing / 2))
}

/**
 * How many places the chart is willing to name.
 *
 * The overlap test alone is not enough. It stops two labels sitting on each
 * other, and on a world with a few capitals that was the whole problem -- but a
 * shipping map has them in the thousands, and a few dozen non-overlapping names
 * strewn across the middle is still a wall of text with no landmark in it. A
 * road atlas at country scale names a handful of cities; it does not name every
 * city that happens to fit.
 *
 * Biggest first, so what survives the budget is the biggest thing on screen.
 */
const LABEL_BUDGET = 12

/**
 * How far apart two names have to be, beyond simply not touching.
 *
 * The overlap test alone put every label in one column down the left of the
 * capital band: the marks arrive in lattice order, so the first that fits is
 * followed by its neighbour, which is followed by ITS neighbour, and twelve
 * names came out as a list beside a blob rather than as labels on places.
 *
 * Separation is what turns a budget into a spread. Tested against a padded box
 * and drawn at the real one, so the names stay where their dots are.
 */
const LABEL_SPACING_X = 44

const LABEL_SPACING_Y = 18

interface Box {
  x0: number
  y0: number
  x1: number
  y1: number
}

const overlaps = (a: Box, b: Box) =>
  !(a.x1 < b.x0 || a.x0 > b.x1 || a.y1 < b.y0 || a.y0 > b.y1)

function toCanvas(col: number, row: number): { x: number; y: number } {
  return {
    x: props.width / 2 + (col - props.centerCol) * pxPerCol.value,
    y: props.height / 2 + (row - props.centerRow) * pxPerRow.value,
  }
}

function draw(): void {
  const el = canvas.value
  const ctx = el?.getContext('2d')
  if (!el || !ctx || !isWorldConfigured()) return

  ensureRaster()

  const w = props.width
  const h = props.height
  const dpr = window.devicePixelRatio || 1
  if (el.width !== Math.round(w * dpr) || el.height !== Math.round(h * dpr)) {
    el.width = Math.round(w * dpr)
    el.height = Math.round(h * dpr)
  }
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
  ctx.clearRect(0, 0, w, h)

  // The cached terrain, shifted by however far the view has drifted from it.
  const offsetX = w / 2 - (props.centerCol - rasterCenter.col) * pxPerCol.value - raster.width / 2
  const offsetY = h / 2 - (props.centerRow - rasterCenter.row) * pxPerRow.value - raster.height / 2
  ctx.imageSmoothingEnabled = false
  ctx.drawImage(raster, offsetX, offsetY)

  const cfg = worldParams()
  const maxRadius = cfg.radius
  const mid = toCanvas(0, 0)

  // §5.2 -- the rings are the map's real structure, so the chart states them.
  ctx.save()
  ctx.setLineDash([5, 6])
  ctx.lineWidth = 1
  ctx.strokeStyle = 'rgba(236, 227, 205, 0.34)'
  for (const r of [cfg.rings.center, cfg.rings.inner, cfg.rings.mid]) {
    ctx.beginPath()
    ctx.ellipse(
      mid.x,
      mid.y,
      r * maxRadius * pxPerCol.value,
      r * maxRadius * pxPerRow.value,
      0,
      0,
      Math.PI * 2,
    )
    ctx.stroke()
  }
  ctx.restore()

  // Dungeons: five fixed sites in the barren center, §9.1.
  for (const site of cfg.dungeonSites) {
    const { x, y } = toCanvas(site.col, site.row)
    ctx.fillStyle = '#7d5fa8'
    ctx.strokeStyle = '#141b18'
    ctx.lineWidth = 1.4
    ctx.beginPath()
    ctx.moveTo(x, y - 6)
    ctx.lineTo(x + 5.5, y)
    ctx.lineTo(x, y + 6)
    ctx.lineTo(x - 5.5, y)
    ctx.closePath()
    ctx.fill()
    ctx.stroke()
  }

  ctx.font = '600 10px Archivo, sans-serif'
  ctx.textAlign = 'center'

  // Dots first, so no label is ever hidden behind a later settlement.
  const onScreen: Array<{ mark: SettlementMark; x: number; y: number }> = []
  for (const mark of rasterMarks) {
    const { x, y } = toCanvas(mark.col, mark.row)
    if (x < -40 || y < -40 || x > w + 40 || y > h + 40) continue
    onScreen.push({ mark, x, y })

    const r = dotRadius(mark.tier, pxPerCol.value)
    ctx.beginPath()
    ctx.arc(x, y, r, 0, Math.PI * 2)
    ctx.fillStyle = DOT[mark.tier].fill
    ctx.fill()
    // The outline is what separates one dot from the next, so it goes when
    // there is no longer room between them for a line.
    if (r >= 2) {
      ctx.lineWidth = 1.2
      ctx.strokeStyle = '#141b18'
      ctx.stroke()
    }
  }

  /*
   * Labels, decluttered. Capitals cluster in the middle rings by design (§5.2),
   * so at any scale wide enough to see them all their names land on top of each
   * other -- a solid blob of text is worse than no text. Bigger settlements
   * claim their space first and anything that would collide goes unnamed;
   * tapping still names it.
   */
  const placed: Box[] = []
  const byRank = onScreen
    .filter((s) => labeledTiers.value.has(s.mark.tier))
    .sort((a, b) => DOT[b.mark.tier].rank - DOT[a.mark.tier].rank)

  for (const { mark, x, y } of byRank) {
    if (placed.length >= LABEL_BUDGET) break

    const top = y - dotRadius(mark.tier, pxPerCol.value) - 4
    const half = ctx.measureText(mark.name).width / 2 + 3
    const box: Box = {
      x0: x - half - LABEL_SPACING_X,
      y0: top - 10 - LABEL_SPACING_Y,
      x1: x + half + LABEL_SPACING_X,
      y1: top + 2 + LABEL_SPACING_Y,
    }
    if (placed.some((b) => overlaps(box, b))) continue
    placed.push(box)

    ctx.lineWidth = 3
    ctx.strokeStyle = '#141b18'
    ctx.strokeText(mark.name, x, top)
    ctx.fillStyle = '#ece3cd'
    ctx.fillText(mark.name, x, top)
  }

  // §5.6 -- the selection, so a tap out here reads as one. A ring rather than
  // a filled mark: what is under it is the answer, and the tile card is where
  // the answer is written.
  if (props.selected) {
    const { x, y } = toCanvas(props.selected.col, props.selected.row)
    ctx.beginPath()
    ctx.arc(x, y, 7, 0, Math.PI * 2)
    ctx.lineWidth = 2
    ctx.strokeStyle = '#ece3cd'
    ctx.stroke()
  }

  /*
   * You. Just you.
   *
   * There is no reach ring, because there is no reach (§5.6): every hex on this
   * map is walkable and what it costs is the clock rather than permission.
   * Sight is the only radius left and it is one hex, three at the end of the
   * Explorer tree -- out here that is a fraction of a pixel, and a ring drawn
   * at its minimum legible size would be claiming a reach nobody has.
   *
   * The WALKER's hex rather than the character's, which sits on the departure
   * hex until the road ends (§5.6).
   */
  const me = toCanvas(props.characterCol, props.characterRow)
  ctx.beginPath()
  ctx.arc(me.x, me.y, 4, 0, Math.PI * 2)
  ctx.fillStyle = '#ece3cd'
  ctx.fill()
  ctx.lineWidth = 1.6
  ctx.strokeStyle = '#141b18'
  ctx.stroke()
}

let frame = 0
function schedule(): void {
  if (frame) return
  frame = requestAnimationFrame(() => {
    frame = 0
    draw()
  })
}

watch(
  () => [props.centerCol, props.centerRow, props.px, props.width, props.height, props.selected],
  schedule,
  { deep: true },
)

// --------------------------------------------------------------- interaction

let dragging = false
let moved = 0
let last = { x: 0, y: 0 }
let dragCenter = { col: 0, row: 0 }

function onPointerDown(event: PointerEvent) {
  dragging = true
  moved = 0
  last = { x: event.clientX, y: event.clientY }
  dragCenter = { col: props.centerCol, row: props.centerRow }
  ;(event.currentTarget as Element).setPointerCapture(event.pointerId)
}

function onPointerMove(event: PointerEvent) {
  if (!dragging) return
  const dx = event.clientX - last.x
  const dy = event.clientY - last.y
  last = { x: event.clientX, y: event.clientY }
  moved += Math.hypot(dx, dy)

  dragCenter = {
    col: dragCenter.col - dx / pxPerCol.value,
    row: dragCenter.row - dy / pxPerRow.value,
  }
  emit('recenter', Math.round(dragCenter.col), Math.round(dragCenter.row))
}

function onPointerUp(event: PointerEvent) {
  if (!dragging) return
  dragging = false
  ;(event.currentTarget as Element).releasePointerCapture?.(event.pointerId)
  if (moved > 6) return

  // A tap selects the hex under it, and the tile card answers -- the same
  // grammar the board uses. The atlas had a readout of its own here, naming
  // whatever settlement happened to be nearest; two screens answering "what am
  // I pointing at" in two different ways is one too many.
  const rect = (event.currentTarget as Element).getBoundingClientRect()
  const x = event.clientX - rect.left - props.width / 2
  const y = event.clientY - rect.top - props.height / 2

  emit(
    'select',
    Math.round(props.centerCol + x / pxPerCol.value),
    Math.round(props.centerRow + y / pxPerRow.value),
  )
}

onMounted(draw)
onBeforeUnmount(() => {
  if (frame) cancelAnimationFrame(frame)
})
</script>

<template>
  <canvas
    ref="canvas"
    class="chart"
    :style="{ width: `${width}px`, height: `${height}px` }"
    @pointerdown="onPointerDown"
    @pointermove="onPointerMove"
    @pointerup="onPointerUp"
    @pointercancel="onPointerUp"
  />
</template>

<style scoped>
.chart {
  display: block;
  cursor: grab;
  touch-action: none;
  user-select: none;
}

.chart:active {
  cursor: grabbing;
}
</style>
