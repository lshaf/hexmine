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
 *  - No alpha anywhere. Transparency ghosts hexes through their neighbors, so
 *    every "faded" state is a precomputed solid color instead.
 */
import { computed, ref } from 'vue'
import type { ComponentPublicInstance } from 'vue'
import {
  HEX_H,
  HEX_SIDE_PATH,
  HEX_TOP_PATH,
  groundMark,
  hexDistance,
  paintersSort,
  pickTile,
  screenToTile,
  tileToScreen,
} from './hexGeometry'
import { corpseProp, dungeonGlyph, herdProp, packProp, settlementGlyph, tileProps } from './props'
import { EMBER, GOLD, INK, VELLUM, VELLUM_DIM, depletedColor, shade, variantColor, waterColor } from '@/theme/palette'
import { MINING } from '@/game/balance'
import type { Job, Tile, TravelState } from '@/game/types'
import type { Carrier } from '@/api/types'

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
  /**
   * §9.5.7 -- every corpse on the map, sight or no sight.
   *
   * Passed whole rather than folded into the tiles, because a carrier is not a
   * property of the ground: it is somebody's row, standing where they fell,
   * and the hex underneath it is unchanged.
   */
  carriers: Carrier[]
}>()

const carrierAt = computed(() => {
  const at = new Map<string, Carrier>()
  for (const c of props.carriers) at.set(`${c.col},${c.row}`, c)

  return at
})

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
 * is mining where -- is scoped server-side to the hexes in sight around the
 * character
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
  // the observer its own resize and mines "ResizeObserver loop completed with
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

/** Client coordinates -> map space, honoring the current viewBox. */
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
  /** §9.5.1 -- the pack standing here, drawn only inside sight. */
  pack: string
  /** §9.5.7 -- the corpse standing here, drawn regardless of sight. */
  corpse: string
  corpseLabel: string | null
  depleted: boolean
  inSight: boolean
  onBoundary: boolean
  isSelected: boolean
  /**
   * §5.1 -- one mark per body at work on this hex, and nothing at all when
   * nobody is.
   *
   * Empty out of sight too: who is working where is the server's half of the
   * map (§5.6), and the fog holds it back like everything else.
   */
  slots: string[]
  label: string | null
  /** Scouted names are vellum; the rest are dim, so the ring still reads. */
  labelLit: boolean
  jobState: 'none' | 'active' | 'ready'
  rare: boolean
  /**
   * Out of sight: whoever lives here, drawn as their own silhouette (§5.6).
   * Tier is still the whole message -- the shape says village, city, capital or
   * dungeon mouth and nothing else does. A pip said the same thing in a
   * vocabulary the map used nowhere else, so a scouted settlement and an
   * unscouted one were two different drawings of one place.
   */
  glyph: string
}

/** Tier 3 keys, §4 -- the gold pip on the map means "contested ring payout". */
const RARE_KEYS = new Set<string>([
  'ironwood', 'mythril_ore', 'beastfang_hide', 'obsidian_shard', 'silkweave_fiber',
])

/**
 * §5.1 -- who is at work on this hex, as marks cut into the seam.
 *
 * It was two ink circles, drawn only for the mining slots already taken. Three
 * things were wrong with that, and they compound:
 *
 *  - Ink on a dark biome fill is the least legible mark on the map, and the
 *    state it was hiding is the consequential one: both seats gone means the
 *    hex is shut to everybody, which is a walk you would rather not take.
 *  - It counted mining alone, so a hunter or a fighter standing on a hex left
 *    the map saying nobody was there. A hex somebody is on is not an empty one,
 *    whatever they came to do.
 *  - A circle is the one shape this game does not own (§13).
 *
 * TWO COUNTS, because busy and shut are two different facts:
 *
 *  - `workers` is how many marks there are. Everybody at work here, whatever
 *    the verb -- a mine, a gather, a hunt, a fight.
 *  - `slotsUsed` picks the colour. Only mining takes one of the hex's two seats
 *    (§5.5: a herd is not a seat, and neither is a pack), so it is the only
 *    number that can refuse you -- and ember is kept for exactly that. Two
 *    hunters make a hex busy; they never make it shut.
 *
 * Both colours are picked on VALUE, and the reason is arithmetic rather than
 * taste. Every
 * biome fill is a mid-to-light warm tone, so a light mark competes with the
 * ground it sits on: vellumDim is only 34 points of luminance off grassland and
 * 45 off plains, which is where it disappeared. Ink is 85 to 130 off all five,
 * live or drained -- the most even contrast the palette has, because none of the
 * biomes are dark. It is also what a notch cut into stone actually looks like:
 * a shadow, not a sticker.
 *
 * Ember was worse than the mark it sat beside, and it is the more consequential
 * of the two: raw #b8453f is SEVEN points off badlands and eleven off forest,
 * so the state that says you cannot work here was the washed-out one. Deepened
 * it clears thirty-three everywhere and stays plainly red against the ink --
 * the same shade() the map already tints every slab side and drained tile with,
 * rather than a colour invented off §13.3's list.
 *
 * Nothing is drawn for an empty hex. Ground with nobody on it is the resting
 * state of the whole map, and marking it would put a pair of notches on every
 * tile in sight to say the thing the bare stone already says.
 */
function slotMarks(tile: Tile, inSight: boolean): string[] {
  if (!inSight) return []

  const busy = Math.min(MINING.slotsPerTile, Math.max(tile.workers, tile.slotsUsed))
  if (busy === 0) return []

  const shut = tile.slotsUsed >= MINING.slotsPerTile

  return Array.from({ length: busy }, () => (shut ? SHUT : INK))
}

const jobsByTile = computed(() => {
  const map = new Map<string, 'active' | 'ready'>()
  for (const job of props.jobs) {
    if (job.kind === 'processing') continue
    const key = `${job.col},${job.row}`
    map.set(key, job.endsAt <= props.now ? 'ready' : 'active')
  }
  return map
})

const renderTiles = computed<RenderTile[]>(() =>
  paintersSort(props.tiles).map((tile) => {
    const { x, y } = tileToScreen(tile.col, tile.row)
    const depleted = tile.regrowsAt > props.now
    // §5.3 -- water takes its own fill, tinted by the ground it crosses so a
    // waterway belongs to the badlands or the forest rather than cutting one
    // uniform blue line across five kinds of country. Never depleted: there is
    // nothing on it to work out.
    const base = tile.water
      ? waterColor(tile.biome, tile.water)
      : depleted
        ? depletedColor(tile.variant)
        : variantColor(tile.variant)
    const distance = hexDistance(props.characterCol, props.characterRow, tile.col, tile.row)
    const inSight = distance <= props.sight
    const corpse = carrierAt.value.get(`${tile.col},${tile.row}`) ?? null

    // Unscouted is communicated with a darker SOLID fill, never opacity --
    // see the no-alpha rule above.
    const top = inSight ? base : shade(base, -0.42)
    const isSelected =
      props.selected?.col === tile.col && props.selected?.row === tile.row
    const rare = tile.material !== undefined && RARE_KEYS.has(tile.material)

    // Everything below the fill is either live state the server only sends for
    // tiles in sight, or ornament that would bury the pips out there.
    // Beyond sight the map states two things and no more: the lie of the land,
    // and whether anybody lives on it. Same silhouette as in sight, at the same
    // size, with the light taken out of it -- so scouting a hex lights the town
    // up rather than replacing one drawing with another.
    const glyph = inSight
      ? ''
      : tile.dungeon
        ? dungeonGlyph()
        : tile.settlement
          ? settlementGlyph(tile.settlement.tier, tile.propSeed)
          : ''

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
      // §9.5.1 -- a pack is live state, so it is drawn inside sight and nowhere
      // else. Beyond the ring the map says what the ground is and who lives on
      // it, never what is happening there (§13.2).
      pack: inSight ? packProp(tile) : '',
      // §9.5.7 -- drawn wherever the server sent one, INCLUDING outside the
      // ring. Which ones it sends is the rule: your own through any fog, and
      // anybody else's only inside sight. The client does not re-derive that.
      corpse: corpse ? corpseProp(corpse.mine) : '',
      corpseLabel: corpse ? `${corpse.owner}'s corpse` : null,
      depleted,
      inSight,
      // No ring at all when sight is zero: on the road the boundary would be
      // the hex you just left, which is not a boundary, it is a memory.
      onBoundary: props.sight > 0 && distance === props.sight,
      isSelected,
      slots: slotMarks(tile, inSight),
      // §5.6 -- a place is named whether or not you have stood in it. Identity
      // is terrain: name, tier and lines all fall out of (col, row, seed), and
      // the atlas has always drawn them at any distance. What the fog holds
      // back is the server's half -- depletion, who is working here, what the
      // hex would pay -- so an unscouted name is dimmed rather than withheld.
      label: tile.settlement?.name ?? tile.dungeon?.name ?? null,
      labelLit: inSight,
      jobState: jobsByTile.value.get(`${tile.col},${tile.row}`) ?? 'none',
      rare: inSight && rare,
      glyph,
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

/**
 * Where the marks sit, and how big.
 *
 * Low on the top face, on the shelf the extruded slab already suggests, and cut
 * to the same squash as the tile itself (§13.2's baked tilt) -- so they read as
 * notches in the stone rather than as badges laid over it.
 *
 * Small, and no outline. A mark carries one bit -- somebody is here -- and it
 * does not need a border to say it: the stroke was doing the work the fill
 * already does, at twice the visual weight. Two of them span 19px against a
 * 34px face, which leaves the stone around them room to be stone.
 */
/** §13.3's ember, carrying enough value to survive a light biome (see below). */
const SHUT = shade(EMBER, -0.3)

const SLOT_Y = HEX_H / 2 - 5
const SLOT_MARK = groundMark(8)
const SLOT_GAP = 5.5

/** §4 -- the contested-ring tell, cut from the same stone as the marks. */
const RARE_MARK = groundMark(6)
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
        <g v-if="t.pack" v-html="t.pack" />
        <g v-if="t.corpse" v-html="t.corpse" />

        <!-- Beyond sight: is anybody there. Nothing else is knowable. -->
        <g v-if="t.glyph" v-html="t.glyph" />

        <!-- Rare-material tell, §4: gold, only in the contested ring. -->
        <path
          v-if="t.rare && !t.depleted"
          :d="RARE_MARK"
          :transform="`translate(0,${-HEX_H / 2 + 5})`"
          :fill="GOLD"
        />

        <!-- §5.1 -- one mark per body at work here, whatever the verb, and
             ember when the two mining seats are gone and the hex is shut. An
             empty hex is left alone: bare stone is what open looks like. -->
        <path
          v-for="(fill, i) in t.slots"
          :key="`slot${i}`"
          :d="SLOT_MARK"
          :transform="`translate(${t.slots.length === 1 ? 0 : -SLOT_GAP + i * SLOT_GAP * 2},${SLOT_Y})`"
          :fill="fill"
        />

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

        <!-- §9.5.7 -- the corpse says whose it is, at any distance. It is the
             one label on the map that is not a place. -->
        <text
          v-if="t.corpseLabel"
          y="-34"
          text-anchor="middle"
          :fill="VELLUM"
          font-size="8"
          font-weight="700"
          letter-spacing="0.3"
          paint-order="stroke"
          stroke="#141b18"
          stroke-width="3"
        >
          {{ t.corpseLabel }}
        </text>

        <text
          v-if="t.label"
          y="-26"
          text-anchor="middle"
          :fill="t.labelLit ? VELLUM : VELLUM_DIM"
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
