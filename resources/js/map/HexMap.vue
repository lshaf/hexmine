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
  tileToScreen,
} from './hexGeometry'
import { herdProp, tileProps } from './props'
import { BIOME_COLOR, GOLD, VELLUM, depletedColor, shade } from '@/theme/palette'
import type { Job, Tile } from '@/game/types'

const props = defineProps<{
  tiles: Tile[]
  centerCol: number
  centerRow: number
  characterCol: number
  characterRow: number
  travelRange: number
  selected: { col: number; row: number } | null
  jobs: Job[]
  now: number
}>()

const emit = defineEmits<{
  (e: 'select', col: number, row: number): void
  /** Measured viewport, so the parent can generate exactly the tiles it needs. */
  (e: 'resize', width: number, height: number): void
}>()

/*
 * ------------------------------------------------------------------ camera
 *
 * There isn't one. The play map is locked to the character and does not pan:
 * the window is always the same size and always centred on where you are, which
 * bounds every per-viewport cost -- tiles generated, mutations fetched, and any
 * realtime feed that later subscribes to what is on screen -- to a constant
 * instead of wherever a player happened to drag.
 *
 * Panning moved to the atlas (views/AtlasView.vue), which is derived purely from
 * the seed and talks to nothing.
 */
const viewport = ref({ w: 900, h: 620 })

const origin = computed(() => tileToScreen(props.centerCol, props.centerRow))

const viewBox = computed(() => {
  const { w, h } = viewport.value
  return `${origin.value.x - w / 2} ${origin.value.y - h / 2} ${w} ${h}`
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

/**
 * Selection is hit-tested from coordinates rather than handled by a click
 * listener on each tile group -- that drops several hundred DOM listeners, and
 * it kept working when the map still captured the pointer for dragging.
 */
function onPointerUp(event: PointerEvent) {
  const point = toMapSpace(event.clientX, event.clientY)
  if (!point) return
  const target = pickTile(point.x, point.y)
  emit('select', target.col, target.row)
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
  inRange: boolean
  onBoundary: boolean
  isSelected: boolean
  slotsUsed: number
  label: string | null
  jobState: 'none' | 'active' | 'ready'
  rare: boolean
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
    const inRange = distance <= props.travelRange

    // Out of range is communicated with a darker SOLID fill, never opacity --
    // see the no-alpha rule above.
    const top = inRange ? base : shade(base, -0.42)
    const isSelected =
      props.selected?.col === tile.col && props.selected?.row === tile.row
    const rare = tile.material !== undefined && RARE_KEYS.has(tile.material)

    return {
      key: `${tile.col},${tile.row}`,
      col: tile.col,
      row: tile.row,
      x,
      y,
      top,
      side: shade(top, -0.4),
      edge: shade(top, -0.2),
      props: tileProps(tile, depleted),
      herd: herdProp(tile),
      depleted,
      inRange,
      onBoundary: distance === props.travelRange,
      isSelected,
      slotsUsed: tile.slotsUsed,
      label: tile.settlement?.name ?? tile.dungeon?.name ?? null,
      jobState: jobsByTile.value.get(`${tile.col},${tile.row}`) ?? 'none',
      rare,
    }
  }),
)

const characterScreen = computed(() => tileToScreen(props.characterCol, props.characterRow))

const SLOT_PIP_Y = HEX_H / 2 - 4
</script>

<template>
  <div class="map-wrap">
    <svg
      :ref="mountSvg"
      class="map-svg"
      :viewBox="viewBox"
      preserveAspectRatio="xMidYMid slice"
      @pointerup="onPointerUp"
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

        <!-- Travel-range boundary: a stroked hex ring, no fill, no alpha. -->
        <path
          v-if="t.onBoundary"
          :d="HEX_TOP_PATH"
          fill="none"
          stroke="#c1793f"
          stroke-width="1.6"
          stroke-dasharray="4 4"
        />

        <!-- Terrain and settlement props stand above the tile. -->
        <g v-html="t.props" />
        <g v-if="t.herd" v-html="t.herd" />

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
  /* Taps only. Nothing here pans any more, so gestures belong to the browser. */
  touch-action: manipulation;
  user-select: none;
}
</style>
