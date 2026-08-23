<script setup lang="ts">
/**
 * §10.0.3 -- a guild flag, drawn or displayed.
 *
 * 32×32 dots, three bytes each, base64 of exactly 3072 bytes and nothing else.
 * Not a file, not a URL, not a data URI: what can be in the column is bounded
 * by the column's own shape, which is the only kind of player-drawn image this
 * game is willing to carry.
 *
 * Rendered on a canvas at native size and scaled up with `image-rendering:
 * pixelated`, so a dot is a dot rather than a blur. Drawing it as 1024 SVG
 * rects would be truer to §13's procedural rule and would also be 1024 nodes
 * on a phone, redrawn on every stroke.
 *
 * Read-only unless `editable`, which is what lets the same component be the
 * editor, the card on the browse list and the crest beside a name.
 */
import { computed, onMounted, ref, watch } from 'vue'

const props = withDefaults(
  defineProps<{
    /** base64 of 3072 RGB bytes, or null for an unpainted flag. */
    flag: string | null
    size?: number
    editable?: boolean
    colour?: string
    /** Paint whole regions rather than dots. */
    fill?: boolean
  }>(),
  { size: 128, editable: false, colour: '#c1793f', fill: false },
)

const emit = defineEmits<{ (e: 'update:flag', value: string): void }>()

const GRID = 32
const BYTES = GRID * GRID * 3

const canvas = ref<HTMLCanvasElement | null>(null)

/** The working copy. Bytes rather than a string: a stroke touches one dot. */
const pixels = ref<Uint8Array>(new Uint8Array(BYTES))

/** An unpainted flag is not empty, it is the panel colour. */
const BLANK: [number, number, number] = [0x1d, 0x26, 0x22]

function decode(flag: string | null): Uint8Array {
  const out = new Uint8Array(BYTES)

  if (!flag) {
    for (let i = 0; i < BYTES; i += 3) {
      out[i] = BLANK[0]
      out[i + 1] = BLANK[1]
      out[i + 2] = BLANK[2]
    }

    return out
  }

  try {
    const raw = atob(flag)
    // A flag of the wrong length is not a flag; the server refuses it too.
    if (raw.length !== BYTES) return decode(null)

    for (let i = 0; i < BYTES; i++) out[i] = raw.charCodeAt(i)
  } catch {
    return decode(null)
  }

  return out
}

function encode(bytes: Uint8Array): string {
  let s = ''
  for (let i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]!)

  return btoa(s)
}

function paint(): void {
  const el = canvas.value
  if (!el) return

  const ctx = el.getContext('2d')
  if (!ctx) return

  const image = ctx.createImageData(GRID, GRID)
  for (let dot = 0; dot < GRID * GRID; dot++) {
    image.data[dot * 4] = pixels.value[dot * 3]!
    image.data[dot * 4 + 1] = pixels.value[dot * 3 + 1]!
    image.data[dot * 4 + 2] = pixels.value[dot * 3 + 2]!
    image.data[dot * 4 + 3] = 255
  }

  ctx.putImageData(image, 0, 0)
}

watch(
  () => props.flag,
  (flag) => {
    pixels.value = decode(flag)
    paint()
  },
)

onMounted(() => {
  pixels.value = decode(props.flag)
  paint()
})

const rgb = computed<[number, number, number]>(() => {
  const hex = props.colour.replace('#', '')

  return [
    parseInt(hex.slice(0, 2), 16) || 0,
    parseInt(hex.slice(2, 4), 16) || 0,
    parseInt(hex.slice(4, 6), 16) || 0,
  ]
})

function dotAt(event: PointerEvent): number | null {
  const el = canvas.value
  if (!el) return null

  const box = el.getBoundingClientRect()
  const col = Math.floor(((event.clientX - box.left) / box.width) * GRID)
  const row = Math.floor(((event.clientY - box.top) / box.height) * GRID)

  if (col < 0 || row < 0 || col >= GRID || row >= GRID) return null

  return row * GRID + col
}

function same(dot: number, colour: [number, number, number]): boolean {
  return (
    pixels.value[dot * 3] === colour[0]
    && pixels.value[dot * 3 + 1] === colour[1]
    && pixels.value[dot * 3 + 2] === colour[2]
  )
}

function set(dot: number, colour: [number, number, number]): void {
  pixels.value[dot * 3] = colour[0]
  pixels.value[dot * 3 + 1] = colour[1]
  pixels.value[dot * 3 + 2] = colour[2]
}

/** Flood fill, four-connected. Iterative: a recursive one blows the stack. */
function flood(from: number, colour: [number, number, number]): void {
  const target: [number, number, number] = [
    pixels.value[from * 3]!,
    pixels.value[from * 3 + 1]!,
    pixels.value[from * 3 + 2]!,
  ]

  if (target[0] === colour[0] && target[1] === colour[1] && target[2] === colour[2]) return

  const queue = [from]
  while (queue.length) {
    const dot = queue.pop()!
    if (!same(dot, target)) continue

    set(dot, colour)

    const col = dot % GRID
    const row = (dot - col) / GRID

    if (col > 0) queue.push(dot - 1)
    if (col < GRID - 1) queue.push(dot + 1)
    if (row > 0) queue.push(dot - GRID)
    if (row < GRID - 1) queue.push(dot + GRID)
  }
}

let drawing = false

function stroke(event: PointerEvent): void {
  if (!props.editable) return

  const dot = dotAt(event)
  if (dot === null) return

  if (props.fill) flood(dot, rgb.value)
  else set(dot, rgb.value)

  paint()
  emit('update:flag', encode(pixels.value))
}

function down(event: PointerEvent): void {
  if (!props.editable) return

  drawing = true
  canvas.value?.setPointerCapture(event.pointerId)
  stroke(event)
}

function move(event: PointerEvent): void {
  // Fill is a tap, never a drag: dragging a bucket across a flag would repaint
  // it a dozen times and only the last one would be visible.
  if (drawing && !props.fill) stroke(event)
}

function up(event: PointerEvent): void {
  drawing = false
  canvas.value?.releasePointerCapture?.(event.pointerId)
}

/** Repaint the whole flag one colour. The only destructive control here. */
function clear(): void {
  for (let dot = 0; dot < GRID * GRID; dot++) set(dot, rgb.value)
  paint()
  emit('update:flag', encode(pixels.value))
}

defineExpose({ clear })
</script>

<template>
  <canvas
    ref="canvas"
    class="flag"
    :class="{ editable }"
    :width="GRID"
    :height="GRID"
    :style="{ width: `${size}px`, height: `${size}px` }"
    @pointerdown="down"
    @pointermove="move"
    @pointerup="up"
    @pointercancel="up"
  />
</template>

<style scoped>
/*
 * A dot is a dot. Without this the browser smooths a 32px image up to 128 and
 * the thing a player spent ten minutes drawing arrives as a smudge.
 */
.flag {
  image-rendering: pixelated;
  display: block;
  border: 1px solid var(--line);
  background: var(--ink);
}

.editable {
  cursor: crosshair;
  touch-action: none;
}
</style>
