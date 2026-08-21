<script setup lang="ts">
/**
 * The map, §13.2. Read that section before changing anything here -- most of
 * these choices are the result of approaches that already failed:
 *
 *  - Tilt is baked into the geometry (58x34 squashed hexes with extruded sides).
 *    CSS perspective/rotateX magnifies near tiles and distorts the hex shape.
 *  - ONE single SVG holding every tile as a <g transform="translate(...)">.
 *    Per-tile SVGs with overflow:visible stack drop-shadows into mush.
 *  - Painter's algorithm: tiles sorted by screen Y so tall props occlude
 *    correctly.
 *  - No alpha anywhere. Transparency ghosts hexes through their neighbours, so
 *    every "faded" state is a precomputed solid colour instead.
 */
import { computed, ref } from 'vue'
import type { ComponentPublicInstance } from 'vue'
import {
  HEX_H,
  HEX_SIDE_PATH,
  HEX_TOP_PATH,
  hexDistance,
  paintersSort,
  pickTile,
  screenToTile,
  tileToScreen,
} from './hexGeometry'
import { herdProp, tileProps } from './props'
import { BIOME_COLOR, GOLD, VELLUM, depletedColor, shade } from '@/theme/palette'
import type { Job, Tile, TravelState } from '@/game/types'

const props = defineProps<{
  tiles: Tile[]
  centerCol: number
  centerRow: number
  characterCol: number
  characterRow: number
  /** §5.6 -- hexes of sight. Two standing still, zero on the road. */
  sight: number
  selected: { col: number; row: number } | null
  jobs: Job[]
  travel: TravelState | null
  now: number
}>()

const emit = defineEmits<{
  (e: 'select', col: number, row: number): void
  (e: 'recenter', col: number, row: number): void
  /** Measured viewport, so the parent can generate exactly the tiles it needs. */
  (e: 'resize', width: number, height: number): void
}>()

/*
 * ------------------------------------------------------------------ camera
 *
 * Drag to look around. It costs nothing: terrain is a pure function of
 * (col, row, seed) (§5), so panning generates tiles locally and never touches
 * the network.
 *
 * What the camera does not move is SIGHT. Live state -- worked-out tiles, who
 * is mining where -- is scoped server-side to two hexes around the character
 * (§5.6), so outside that small disc there is genuinely nothing to draw but the
 * land itself and whether anybody lives on it. The dashed ring is that
 * boundary, and it is a fog line, not a fence: every hex on the map is walkable
 * whether or not it has been scouted.
 *
 * On the road sight is zero and the ring disappears with it -- the whole world
 * goes to glyphs until the walking stops.
 */
const viewport = ref({ w: 900, h: 620 })
const pan = ref({ x: 0, y: 0 })
const dragging = ref(false)
let dragStart = { x: 0, y: 0, panX: 0, panY: 0 }
let dragDistance = 0

const origin = computed(() => tileToScreen(props.centerCol, props.centerRow))

const viewBox = computed(() => {
  const { w, h } = viewport.value
  const x = origin.value.x - w / 2 + pan.value.x
  const y = origin.value.y - h / 2 + pan.value.y
  return `${x} ${y} ${w} ${h}`
})

function onResize(el: Element | null) {
  if (!el) return
  const rect = el.getBoundingClientRect()
  if (!rect.width || !rect.height) return

  // Only write when the size actually changed. Assigning unconditionally feeds
  // the observer its own resize and trips "ResizeObserver loop completed with
  // undelivered notifications" every frame.
  const { w, h } = viewport.value
  if (Math.abs(w - rect.width) < 0.5 && Math.abs(h - rect.height) < 0.5) return

  viewport.value = { w: rect.width, h: rect.height }
  emit('resize', rect.width, rect.height)
}

const svgEl = ref<SVGSVGElement | null>(null)
let observer: ResizeObserver | null = null

function mountSvg(el: Element | ComponentPublicInstance | null) {
  const svg = el as SVGSVGElement | null
  svgEl.value = svg
  observer?.disconnect()
  if (!svg) return
  observer = new ResizeObserver(() => onResize(svg))
  observer.observe(svg)
  onResize(svg)
}

function onPointerDown(event: PointerEvent) {
  dragging.value = true
  dragDistance = 0
  dragStart = { x: event.clientX, y: event.clientY, panX: pan.value.x, panY: pan.value.y }
  ;(event.currentTarget as Element).setPointerCapture(event.pointerId)
}

function onPointerMove(event: PointerEvent) {
  if (!dragging.value) return
  const dx = event.clientX - dragStart.x
  const dy = event.clientY - dragStart.y
  dragDistance = Math.max(dragDistance, Math.hypot(dx, dy))
  pan.value = { x: dragStart.panX - dx, y: dragStart.panY - dy }
}

function onPointerUp(event: PointerEvent) {
  if (!dragging.value) return
  dragging.value = false
  ;(event.currentTarget as Element).releasePointerCapture?.(event.pointerId)

  // A tap, not a drag: resolve which tile is under the pointer.
  //
  // Selection is hit-tested from coordinates rather than by a click listener on
  // each tile group. Two reasons: setPointerCapture above retargets pointer
  // events to the <svg>, so the derived click never reaches the tile <g> at
  // all; and this drops several hundred DOM listeners.
  if (dragDistance <= 6) {
    const point = toMapSpace(event.clientX, event.clientY)
    if (point) {
      const target = pickTile(point.x, point.y)
      emit('select', target.col, target.row)
    }
    return
  }

  // Once the camera has drifted far enough, ask the parent to regenerate the
  // window around wherever we now are. The new origin lands where the pan
  // already put us, so the handover is invisible -- and it is free, because
  // generating tiles is local.
  if (Math.abs(pan.value.x) > 120 || Math.abs(pan.value.y) > 120) {
    const target = screenToTile(origin.value.x + pan.value.x, origin.value.y + pan.value.y)
    pan.value = { x: 0, y: 0 }
    emit('recenter', target.col, target.row)
  }
}

/** Client coordinates -> map space, honouring the current viewBox. */
function toMapSpace(clientX: number, clientY: number): DOMPoint | null {
  const svg = svgEl.value
  const ctm = svg?.getScreenCTM()
  if (!svg || !ctm) return null
  return new DOMPoint(clientX, clientY).matrixTransform(ctm.inverse())
}

// ------------------------------------------------------------------ render

interface RenderTile {
  key: string
  col: number
  row: number
  x: number
  y: number
  top: string
  side: string
  edge: string
  props: string
  herd: string
  depleted: boolean
  inSight: boolean
  onBoundary: boolean
  isSelected: boolean
  slotsUsed: number
  label: string | null
  jobState: 'none' | 'active' | 'ready'
  rare: boolean
  /** Out of sight: all a tile gets is whether somebody lives on it. */
  mark: { fill: string; r: number; dungeon: boolean } | null
}

/*
 * Beyond sight the map states two things and no more: the lie of the land, and
 * whether anybody lives on it. Tier is the whole message -- no name, no size of
 * settlement beyond the pip, nothing that needed asking the server. Colours are
 * the atlas legend, so one vocabulary covers both maps.
 */
const MARK: Record<string, { fill: string; r: number; dungeon: boolean }> = {
  village: { fill: VELLUM, r: 4, dungeon: false },
  city: { fill: '#c1793f', r: 5.5, dungeon: false },
  capital: { fill: GOLD, r: 7, dungeon: false },
  dungeon: { fill: '#7d5fa8', r: 6, dungeon: true },
}

/** A flat-top hexagon of the given radius, centred on the origin. */
function pip(r: number): string {
  const h = r * 0.866
  return `M${-r},0 L${-r / 2},${-h} L${r / 2},${-h} L${r},0 L${r / 2},${h} L${-r / 2},${h} Z`
}

/** Tier 3 keys, §4 -- the gold pip on the map means "contested ring payout". */
const RARE_KEYS = new Set<string>([
  'ironwood', 'mythril_ore', 'beastfang_hide', 'obsidian_shard', 'silkweave_fiber',
])

const jobsByTile = computed(() => {
  const map = new Map<string, 'active' | 'ready'>()
  for (const job of props.jobs) {
    if (job.kind !== 'mining') continue
    const key = `${job.col},${job.row}`
    map.set(key, job.endsAt <= props.now ? 'ready' : 'active')
  }
  return map
})

const renderTiles = computed<RenderTile[]>(() =>
  paintersSort(props.tiles).map((tile) => {
    const { x, y } = tileToScreen(tile.col, tile.row)
    const depleted = tile.regrowsAt > props.now
    const base = depleted ? depletedColor(tile.biome) : BIOME_COLOR[tile.biome]
    const distance = hexDistance(props.characterCol, props.characterRow, tile.col, tile.row)
    const inSight = distance <= props.sight

    // Unscouted is communicated with a darker SOLID fill, never opacity --
    // see the no-alpha rule above.
    const top = inSight ? base : shade(base, -0.42)
    const isSelected =
      props.selected?.col === tile.col && props.selected?.row === tile.row
    const rare = tile.material !== undefined && RARE_KEYS.has(tile.material)

    // Everything below the fill is either live state the server only sends for
    // tiles in sight, or ornament that would bury the pips out there.
    const mark = inSight
      ? null
      : (tile.dungeon ? MARK.dungeon : tile.settlement ? MARK[tile.settlement.tier] : null) ?? null

    return {
      key: `${tile.col},${tile.row}`,
      col: tile.col,
      row: tile.row,
      x,
      y,
      top,
      side: shade(top, -0.4),
      edge: shade(top, -0.2),
      props: inSight ? tileProps(tile, depleted) : '',
      herd: inSight ? herdProp(tile) : '',
      depleted,
      inSight,
      // No ring at all when sight is zero: on the road the boundary would be
      // the hex you just left, which is not a boundary, it is a memory.
      onBoundary: props.sight > 0 && distance === props.sight,
      isSelected,
      slotsUsed: inSight ? tile.slotsUsed : 0,
      label: inSight ? (tile.settlement?.name ?? tile.dungeon?.name ?? null) : null,
      jobState: jobsByTile.value.get(`${tile.col},${tile.row}`) ?? 'none',
      rare: inSight && rare,
      mark,
    }
  }),
)

/**
 * Where the walker actually is.
 *
 * Standing still that is the tile underfoot. On the road it is a point between
 * two hexes: the server publishes the road and the clock, and the marker is
 * interpolated against them, so the walk needs no per-step message and survives
 * a reload mid-journey.
 */
const characterScreen = computed(() => {
  const journey = props.travel

  if (!journey || journey.path.length < 2) {
    return tileToScreen(props.characterCol, props.characterRow)
  }

  const walked = Math.max(
    0,
    Math.min(journey.hexes, (props.now - journey.startedAt) / journey.perHexMs),
  )
  const leg = Math.min(journey.path.length - 2, Math.floor(walked))
  const within = walked - leg

  const from = tileToScreen(journey.path[leg]![0], journey.path[leg]![1])
  const to = tileToScreen(journey.path[leg + 1]![0], journey.path[leg + 1]![1])

  return {
    x: from.x + (to.x - from.x) * within,
    y: from.y + (to.y - from.y) * within,
  }
})

const destinationScreen = computed(() =>
  props.travel ? tileToScreen(props.travel.toCol, props.travel.toRow) : { x: 0, y: 0 },
)

/** The ground still to cover, as a line the walker is visibly eating into. */
const roadAhead = computed(() => {
  const journey = props.travel
  if (!journey || journey.path.length < 2) return ''

  const rest = journey.path
    .slice(Math.min(journey.path.length - 1, Math.floor(
      Math.max(0, Math.min(journey.hexes, (props.now - journey.startedAt) / journey.perHexMs)),
    ) + 1))
    .map(([col, row]) => {
      const at = tileToScreen(col, row)
      return `L${at.x.toFixed(1)},${(at.y + 3).toFixed(1)}`
    })
    .join(' ')

  return `M${characterScreen.value.x.toFixed(1)},${(characterScreen.value.y + 3).toFixed(1)} ${rest}`
})

const SLOT_PIP_Y = HEX_H / 2 - 4
</script>

<template>
  <div class="map-wrap">
    <svg
      :ref="mountSvg"
      class="map-svg"
      :viewBox="viewBox"
      preserveAspectRatio="xMidYMid slice"
      :style="{ cursor: dragging ? 'grabbing' : 'grab' }"
      @pointerdown="onPointerDown"
      @pointermove="onPointerMove"
      @pointerup="onPointerUp"
      @pointercancel="onPointerUp"
    >
      <!-- One SVG, every tile a translated group. Order is painter-sorted. -->
      <g
        v-for="t in renderTiles"
        :key="t.key"
        :transform="`translate(${t.x},${t.y})`"
      >
        <!-- Extruded slab side, drawn first so the top face sits on it. -->
        <path :d="HEX_SIDE_PATH" :fill="t.side" />
        <path :d="HEX_TOP_PATH" :fill="t.top" :stroke="t.edge" stroke-width="1" />

        <!-- Sight boundary: a stroked hex ring, no fill, no alpha. -->
        <path
          v-if="t.onBoundary"
          :d="HEX_TOP_PATH"
          fill="none"
          stroke="#c1793f"
          stroke-width="1.6"
          stroke-dasharray="4 4"
        />

        <!-- Terrain and settlement props stand above the tile, in sight only. -->
        <g v-if="t.props" v-html="t.props" />
        <g v-if="t.herd" v-html="t.herd" />

        <!-- Beyond sight: is anybody there. Nothing else is knowable. -->
        <path
          v-if="t.mark"
          :d="pip(t.mark.r)"
          :fill="t.mark.fill"
          stroke="#141b18"
          stroke-width="1.4"
          stroke-linejoin="round"
          :transform="t.mark.dungeon ? 'rotate(90)' : undefined"
        />

        <!-- Rare-material tell: a gold pip, only in the contested ring. -->
        <circle v-if="t.rare && !t.depleted" cx="0" :cy="-HEX_H / 2 + 5" r="2.6" :fill="GOLD" />

        <!-- Mining slot pips, §5.1: exactly two per hex. -->
        <template v-if="t.slotsUsed > 0">
          <circle
            v-for="i in t.slotsUsed"
            :key="i"
            :cx="-5 + (i - 1) * 10"
            :cy="SLOT_PIP_Y"
            r="2.2"
            fill="#141b18"
          />
        </template>

        <!-- Your own job on this tile. -->
        <g v-if="t.jobState !== 'none'" :transform="`translate(0,${-HEX_H / 2 - 12})`">
          <circle r="6.5" :fill="t.jobState === 'ready' ? '#d8b34a' : '#1d2622'" stroke="#d8b34a" stroke-width="1.6" />
          <path
            v-if="t.jobState === 'active'"
            d="M0,-3 L0,0 L2.6,1.6"
            fill="none"
            stroke="#d8b34a"
            stroke-width="1.4"
            stroke-linecap="round"
          />
          <path
            v-else
            d="M-2.8,0 L-0.8,2 L3,-2.2"
            fill="none"
            stroke="#141b18"
            stroke-width="1.8"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </g>

        <!-- Selection cursor. -->
        <path
          v-if="t.isSelected"
          :d="HEX_TOP_PATH"
          fill="none"
          :stroke="VELLUM"
          stroke-width="2.2"
          stroke-linejoin="round"
        />

        <text
          v-if="t.label"
          y="-26"
          text-anchor="middle"
          :fill="VELLUM"
          font-size="9"
          font-weight="700"
          letter-spacing="0.4"
          paint-order="stroke"
          stroke="#141b18"
          stroke-width="3"
        >
          {{ t.label }}
        </text>
      </g>

      <!-- The road ahead, drawn under the marker so the walker eats into it. -->
      <template v-if="travel">
        <path
          :d="roadAhead"
          fill="none"
          stroke="#c1793f"
          stroke-width="2"
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-dasharray="5 5"
        />
        <g :transform="`translate(${destinationScreen.x},${destinationScreen.y + 3})`">
          <path
            d="M0,-11 L9,-5.5 L9,5.5 L0,11 L-9,5.5 L-9,-5.5 Z"
            fill="none"
            stroke="#c1793f"
            stroke-width="2"
            stroke-linejoin="round"
          />
        </g>
      </template>

      <!-- The player marker draws last so nothing occludes it, but it sits ON
           the tile rather than floating above it -- hovering put it straight
           through the settlement name label. -->
      <g :transform="`translate(${characterScreen.x},${characterScreen.y + 3})`">
        <path d="M0,4 L-6,-8 L0,-5 L6,-8 Z" fill="#ece3cd" stroke="#141b18" stroke-width="1.4" stroke-linejoin="round" />
        <circle cy="-13" r="4.4" fill="#ece3cd" stroke="#141b18" stroke-width="1.4" />
      </g>
    </svg>
  </div>
</template>

<style scoped>
.map-wrap {
  position: relative;
  width: 100%;
  height: 100%;
  overflow: hidden;
  background: #0f1512;
}

.map-svg {
  display: block;
  width: 100%;
  height: 100%;
  /* The map owns the drag, so the browser must not claim the gesture. */
  touch-action: none;
  user-select: none;
}
</style>
