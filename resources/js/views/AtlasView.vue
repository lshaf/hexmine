<script setup lang="ts">
/**
 * The atlas: the whole world, drawn from the seed, talking to nothing.
 *
 * The play map gave up panning so that every per-viewport cost stays bounded by
 * the screen. Exploration moved here instead, and it is free -- terrain is a
 * pure function of (col, row, seed) (§5), and settlements sit on a lattice, so a
 * region can be charted without a request, a database, or a tile store.
 *
 * It is deliberately a *chart*, not the world shrunk down. Biomes are painted as
 * a coarse sample raster with visible cells, the way a printed survey sheet
 * reads, which both distinguishes it from the play map and keeps the cost of a
 * redraw honest: about 35,000 samples whatever the zoom.
 *
 * Panning is smooth because the raster is cached with a margin and only rebuilt
 * when the view leaves it; in between, a pan is one drawImage.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useGame } from '@/stores/game'
import { COL_STEP, ROW_STEP, hexDistance } from '@/map/hexGeometry'
import {
  biomeAt,
  coarseBiomeAt,
  isWorldConfigured,
  settlementMarksIn,
  worldParams,
} from '@/game/worldgen'
import type { SettlementMark } from '@/game/worldgen'
import { ACTION_PATHS } from '@/icons/actions'
import { BIOME_COLOR, BIOME_LABEL } from '@/theme/palette'
import type { SettlementTier } from '@/game/types'

const game = useGame()

/*
 * Zoom is expressed as pixels per hex column, so the labels mean something: at
 * 6 you can see individual hexes, at 0.2 a whole wide map fits a laptop.
 */
const ZOOMS = [
  { px: 6, label: 'Local' },
  { px: 2, label: 'District' },
  { px: 0.7, label: 'Region' },
  { px: 0.2, label: 'World' },
] as const

/** Rows are shorter than columns are wide, so the world keeps its proportions. */
const ROW_RATIO = ROW_STEP / COL_STEP

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

const wrap = ref<HTMLDivElement | null>(null)
const canvas = ref<HTMLCanvasElement | null>(null)

const zoom = ref(2)
const centre = ref({ col: 0, row: 0 })
const picked = ref<SettlementMark | null>(null)
const size = ref({ w: 0, h: 0 })

const level = computed(() => ZOOMS[zoom.value]!)
const pxPerCol = computed(() => level.value.px)
const pxPerRow = computed(() => level.value.px * ROW_RATIO)

/** Whether this scale still resolves individual biome cells. */
const detailed = computed(
  () => isWorldConfigured() && pxPerCol.value * worldParams().biomeCell >= DETAIL_CELL_PX,
)

/**
 * Which tiers are worth drawing at this scale.
 *
 * Villages outnumber cities roughly four to one and cities outnumber capitals
 * again (§6), so drawing everything at every zoom turns the sheet into
 * confetti and hides the structure the chart exists to show. Each step out
 * drops the smallest tier, the way a road atlas stops printing hamlets.
 */
const tiersShown = computed<SettlementTier[]>(() => {
  const px = pxPerCol.value
  if (px >= 4) return ['village', 'city', 'capital']
  if (px >= 1.2) return ['city', 'capital']
  return ['capital']
})

/** Villages are never labelled -- there are too many. Tap one to name it. */
const labelledTiers = computed<Set<SettlementTier>>(() => {
  const set = new Set<SettlementTier>(['capital'])
  if (pxPerCol.value >= 1.2) set.add('city')
  return set
})

// ------------------------------------------------------------------- raster

const raster = document.createElement('canvas')
let rasterCentre = { col: 0, row: 0 }
let rasterZoom = -1
let rasterMarks: SettlementMark[] = []

/** Rebuild the cached bitmap if the view has left it, or the scale changed. */
function ensureRaster(): void {
  const { w, h } = size.value
  if (!w || !h) return

  const rw = w + RASTER_MARGIN * 2
  const rh = h + RASTER_MARGIN * 2

  const movedX = Math.abs(centre.value.col - rasterCentre.col) * pxPerCol.value
  const movedY = Math.abs(centre.value.row - rasterCentre.row) * pxPerRow.value
  const fits =
    rasterZoom === zoom.value &&
    raster.width === rw &&
    raster.height === rh &&
    movedX < RASTER_MARGIN &&
    movedY < RASTER_MARGIN

  if (fits) return

  raster.width = rw
  raster.height = rh
  rasterCentre = { ...centre.value }
  rasterZoom = zoom.value

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

  const leftCol = rasterCentre.col - rw / 2 / px
  const topRow = rasterCentre.row - rh / 2 / py
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

const DOT: Record<SettlementTier, { r: number; fill: string; rank: number }> = {
  village: { r: 2.2, fill: '#ece3cd', rank: 0 },
  city: { r: 3.4, fill: '#c1793f', rank: 1 },
  capital: { r: 5, fill: '#d8b34a', rank: 2 },
}

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
    x: size.value.w / 2 + (col - centre.value.col) * pxPerCol.value,
    y: size.value.h / 2 + (row - centre.value.row) * pxPerRow.value,
  }
}

function draw(): void {
  const el = canvas.value
  const ctx = el?.getContext('2d')
  if (!el || !ctx) return

  ensureRaster()

  const { w, h } = size.value
  const dpr = window.devicePixelRatio || 1
  if (el.width !== Math.round(w * dpr) || el.height !== Math.round(h * dpr)) {
    el.width = Math.round(w * dpr)
    el.height = Math.round(h * dpr)
  }
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
  ctx.clearRect(0, 0, w, h)

  // The cached terrain, shifted by however far the view has drifted from it.
  const offsetX = w / 2 - (centre.value.col - rasterCentre.col) * pxPerCol.value - raster.width / 2
  const offsetY = h / 2 - (centre.value.row - rasterCentre.row) * pxPerRow.value - raster.height / 2
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

  // Dungeons: five fixed sites in the barren centre, §9.1.
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

    const dot = DOT[mark.tier]
    ctx.beginPath()
    ctx.arc(x, y, dot.r, 0, Math.PI * 2)
    ctx.fillStyle = dot.fill
    ctx.fill()
    ctx.lineWidth = 1.2
    ctx.strokeStyle = '#141b18'
    ctx.stroke()
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
    .filter((s) => labelledTiers.value.has(s.mark.tier))
    .sort((a, b) => DOT[b.mark.tier].rank - DOT[a.mark.tier].rank)

  for (const { mark, x, y } of byRank) {
    const top = y - DOT[mark.tier].r - 4
    const half = ctx.measureText(mark.name).width / 2 + 3
    const box: Box = { x0: x - half, y0: top - 10, x1: x + half, y1: top + 2 }
    if (placed.some((b) => overlaps(box, b))) continue
    placed.push(box)

    ctx.lineWidth = 3
    ctx.strokeStyle = '#141b18'
    ctx.strokeText(mark.name, x, top)
    ctx.fillStyle = '#ece3cd'
    ctx.fillText(mark.name, x, top)
  }

  /*
   * You. Just you.
   *
   * There used to be a ring here for how far you could walk, and there is no
   * such distance any more (§5.6) -- every hex on this map is reachable, and
   * what it costs is hours rather than permission. Sight is the only radius
   * left and it is one hex, three at the end of the Explorer tree, which at
   * any zoom that fits a ring of capitals on screen is a fraction of a pixel. A ring drawn at its minimum legible size
   * would be claiming a reach the character does not have.
   */
  const char = game.character
  if (char) {
    const { x, y } = toCanvas(char.col, char.row)

    ctx.beginPath()
    ctx.arc(x, y, 4, 0, Math.PI * 2)
    ctx.fillStyle = '#ece3cd'
    ctx.fill()
    ctx.lineWidth = 1.6
    ctx.strokeStyle = '#141b18'
    ctx.stroke()
  }
}

let frame = 0
function schedule(): void {
  if (frame) return
  frame = requestAnimationFrame(() => {
    frame = 0
    draw()
  })
}

watch([zoom, centre, size], schedule, { deep: true })

// --------------------------------------------------------------- interaction

let dragging = false
let moved = 0
let last = { x: 0, y: 0 }

function onPointerDown(event: PointerEvent) {
  dragging = true
  moved = 0
  last = { x: event.clientX, y: event.clientY }
  ;(event.currentTarget as Element).setPointerCapture(event.pointerId)
}

function onPointerMove(event: PointerEvent) {
  if (!dragging) return
  const dx = event.clientX - last.x
  const dy = event.clientY - last.y
  last = { x: event.clientX, y: event.clientY }
  moved += Math.hypot(dx, dy)

  centre.value = clampCentre(
    centre.value.col - dx / pxPerCol.value,
    centre.value.row - dy / pxPerRow.value,
  )
}

/**
 * Keep the sheet full of world. Panning stops at the edges, and once the whole
 * map fits the canvas it simply sits centred -- a chart framed against a void is
 * a chart you have to fight to read.
 */
function clampCentre(col: number, row: number): { col: number; row: number } {
  const cfg = worldParams()
  const halfCols = size.value.w / 2 / pxPerCol.value
  const halfRows = size.value.h / 2 / pxPerRow.value

  // §5.1 -- the map runs -radius..radius, so the far edge is negative on two
  // sides. When the chart is wider than the world the centre is the origin.
  return {
    col: halfCols * 2 >= cfg.size ? 0 : clamp(col, halfCols - cfg.radius, cfg.radius - halfCols),
    row: halfRows * 2 >= cfg.size ? 0 : clamp(row, halfRows - cfg.radius, cfg.radius - halfRows),
  }
}

function onPointerUp(event: PointerEvent) {
  if (!dragging) return
  dragging = false
  ;(event.currentTarget as Element).releasePointerCapture?.(event.pointerId)
  if (moved > 6) return

  // A tap: name the nearest settlement, if the tap landed near one.
  const rect = (event.currentTarget as Element).getBoundingClientRect()
  const px = event.clientX - rect.left
  const py = event.clientY - rect.top

  let best: SettlementMark | null = null
  let bestDistance = 18
  for (const mark of rasterMarks) {
    const { x, y } = toCanvas(mark.col, mark.row)
    const d = Math.hypot(x - px, y - py)
    if (d < bestDistance) {
      bestDistance = d
      best = mark
    }
  }
  picked.value = best
}

function onWheel(event: WheelEvent) {
  event.preventDefault()
  const rect = (event.currentTarget as Element).getBoundingClientRect()
  setZoom(zoom.value + (event.deltaY > 0 ? 1 : -1), {
    x: event.clientX - rect.left,
    y: event.clientY - rect.top,
  })
}

/**
 * Change scale, keeping one point of the world fixed on screen.
 *
 * The wheel anchors on the cursor: the hex you are pointing at stays under the
 * pointer, so zooming reads as moving through the sheet rather than jumping to
 * a different one. The buttons anchor on the centre, because pressing a button
 * implies no position.
 *
 * Clamping can still pull the view in at the edges of the world -- there is
 * nowhere further to go, and a sheet framed against a void reads worse than a
 * cursor that drifts a little.
 */
function setZoom(next: number, anchor?: { x: number; y: number }): void {
  const clamped = Math.min(ZOOMS.length - 1, Math.max(0, next))
  if (clamped === zoom.value) return

  const offsetX = anchor ? anchor.x - size.value.w / 2 : 0
  const offsetY = anchor ? anchor.y - size.value.h / 2 : 0

  // Where the anchor is in the world, at the scale we are leaving.
  const col = centre.value.col + offsetX / pxPerCol.value
  const row = centre.value.row + offsetY / pxPerRow.value

  zoom.value = clamped

  // Put it back under the anchor at the scale we are arriving at.
  centre.value = clampCentre(col - offsetX / pxPerCol.value, row - offsetY / pxPerRow.value)
}

function centreOnCharacter(): void {
  const char = game.character
  if (char) centre.value = clampCentre(char.col, char.row)
}

const clamp = (v: number, lo: number, hi: number) => Math.min(hi, Math.max(lo, v))

const pickedDistance = computed(() => {
  const char = game.character
  const mark = picked.value
  return char && mark ? hexDistance(char.col, char.row, mark.col, mark.row) : 0
})

const biomeHere = computed(() => {
  const char = game.character
  // Rendering can outrun boot on a hot reload; the generator is not optional.
  if (!char || !isWorldConfigured()) return ''
  return BIOME_LABEL[biomeAt(char.col, char.row)]
})

// ------------------------------------------------------------------- mount

let observer: ResizeObserver | null = null

function measure(): void {
  const el = wrap.value
  if (!el) return
  const rect = el.getBoundingClientRect()
  if (!rect.width || !rect.height) return
  if (Math.abs(rect.width - size.value.w) < 0.5 && Math.abs(rect.height - size.value.h) < 0.5) return
  size.value = { w: rect.width, h: rect.height }
  centre.value = clampCentre(centre.value.col, centre.value.row)
}

onMounted(() => {
  centreOnCharacter()
  measure()
  if (wrap.value) {
    observer = new ResizeObserver(measure)
    observer.observe(wrap.value)
  }
  draw()
})

onBeforeUnmount(() => {
  observer?.disconnect()
  if (frame) cancelAnimationFrame(frame)
})
</script>

<template>
  <div ref="wrap" class="atlas">
    <canvas
      ref="canvas"
      class="sheet"
      :style="{ width: `${size.w}px`, height: `${size.h}px` }"
      @pointerdown="onPointerDown"
      @pointermove="onPointerMove"
      @pointerup="onPointerUp"
      @pointercancel="onPointerUp"
      @wheel="onWheel"
    />

    <!-- What the chart is showing, bottom-left. -->
    <div class="readout">
      <template v-if="picked">
        <span class="label">{{ picked.tier }}</span>
        <span class="name">{{ picked.name }}</span>
        <span class="tiny muted">
          {{ picked.col }},{{ picked.row }} · {{ pickedDistance }} hexes from you
        </span>
      </template>
      <template v-else>
        <span class="label">You are in</span>
        <span class="name">{{ biomeHere }}</span>
        <span class="tiny muted">
          {{ detailed ? 'Terrain' : 'Dominant biome' }} · drag to survey · tap a settlement
        </span>
      </template>
    </div>

    <div class="controls">
      <span class="label scale">{{ level.label }}</span>
      <button
        type="button"
        :disabled="zoom >= ZOOMS.length - 1"
        title="Zoom out"
        aria-label="Zoom out"
        @click="setZoom(zoom + 1)"
      >
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path :d="ACTION_PATHS.zoomOut" />
        </svg>
      </button>
      <button
        type="button"
        :disabled="zoom <= 0"
        title="Zoom in"
        aria-label="Zoom in"
        @click="setZoom(zoom - 1)"
      >
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path :d="ACTION_PATHS.zoomIn" />
        </svg>
      </button>
      <button
        type="button"
        class="home"
        title="Centre on your prospector"
        aria-label="Centre on your prospector"
        @click="centreOnCharacter"
      >
        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path :d="ACTION_PATHS.locate" />
        </svg>
      </button>
    </div>

    <div class="key">
      <span><i class="dot cap" />Capital</span>
      <span><i class="dot city" />City</span>
      <span><i class="dot vil" />Village</span>
      <span><i class="dot dun" />Dungeon</span>
    </div>
  </div>
</template>

<style scoped>
.atlas {
  position: relative;
  width: 100%;
  height: 100%;
  overflow: hidden;
  background: #0b0f0d;
}

.sheet {
  display: block;
  cursor: grab;
  touch-action: none;
  user-select: none;
}

.sheet:active {
  cursor: grabbing;
}

.readout,
.controls,
.key {
  position: absolute;
  background: var(--hud);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
}

.readout {
  left: 10px;
  bottom: 10px;
  display: flex;
  flex-direction: column;
  gap: 3px;
  padding: 8px 12px 9px;
  clip-path: var(--plate-clip);
  max-width: 60%;
}

.readout .name {
  font-family: var(--font-display);
  font-size: 14px;
  font-weight: 600;
  text-transform: capitalize;
}

.controls {
  right: 10px;
  bottom: 10px;
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 6px 8px;
  clip-path: var(--plate-clip);
}

.controls .scale {
  margin-right: 4px;
  color: var(--copper);
}

.controls button {
  width: 26px;
  height: 26px;
  display: grid;
  place-items: center;
  line-height: 0;
  color: var(--vellum);
  background: var(--ink-raised);
  clip-path: var(--hex-clip);
}

.controls button:disabled {
  color: #5f6b64;
  cursor: not-allowed;
}

.controls button:not(:disabled):hover {
  background: #304036;
}

.key {
  left: 10px;
  top: 10px;
  display: flex;
  gap: 12px;
  padding: 6px 11px;
  clip-path: var(--plate-clip);
  font-size: 9.5px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--vellum-dim);
}

.key span {
  display: flex;
  align-items: center;
  gap: 5px;
}

.dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
}

.dot.cap {
  background: var(--gold);
}

.dot.city {
  background: var(--copper);
}

.dot.vil {
  background: var(--vellum);
}

.dot.dun {
  background: var(--violet);
  border-radius: 0;
  transform: rotate(45deg);
  width: 6px;
  height: 6px;
}

@media (max-width: 560px) {
  .key {
    display: none;
  }

  /* No room for a bottom row of both, so the controls take the freed corner. */
  .controls {
    top: 10px;
    bottom: auto;
  }

  .readout {
    max-width: calc(100% - 20px);
  }
}
</style>
