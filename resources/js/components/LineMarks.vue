<script setup lang="ts">
/**
 * §6 -- which of the five lines a settlement runs.
 *
 * Said as the material each line turns OUT, because that is the question a
 * prospector actually asks of a place: not "does this village run the wood
 * line" but "can I get planks here".
 *
 * Two arrangements, because the two places it is drawn are different shapes:
 *
 *  - `comb` packs them into a settlement's PORTRAIT, on the same nested lattice
 *    the map tiles with (§13.2). It is what the tile card puts where a seam
 *    puts its material icon -- the slot that says what this hex is about.
 *  - `row` lays them along a line, for the atlas readout, which has a column of
 *    text and no portrait slot to fill.
 */
import { computed } from 'vue'
import { LINE_OUTPUT, MATERIALS } from '@/game/catalog'
import { materialIcon } from '@/icons/procedural'
import SvgIcon from './SvgIcon.vue'
import type { SkillKey } from '@/game/types'

const props = withDefaults(
  defineProps<{ lines: SkillKey[]; layout?: 'row' | 'comb'; size?: number }>(),
  { layout: 'row', size: 0 },
)

/**
 * The portrait slot is 34px, so one line is one tile filling it and the rest
 * nest out from there. Big enough that a tile shows its own mark rather than
 * only its accent: at 15px a plank and a cut stone were two colored hexagons,
 * and this slot is the one place the card says what a settlement IS.
 */
const cell = computed(() => props.size || (props.layout === 'comb' ? 34 : 18))

/** Three across, the widest a comb can be and still sit in a card's gutter. */
const columns = computed(() => Math.min(props.lines.length, 3))

const marks = computed(() => props.lines.map((line) => MATERIALS[LINE_OUTPUT[line]]!))

/** The whole thing in words, for anything that cannot read a picture. */
const spoken = computed(() => `Refines ${marks.value.map((m) => m.name).join(', ')}`)

/*
 * The comb is laid out against the ICON'S OWN HEXAGON, not against its box.
 *
 * A material icon is a square with a flat-top hexagon inscribed in it (§13.1):
 * the hexagon is 37.2 of the 40-unit viewBox across and 32.2 tall, so it sits
 * inside a margin on every side. Tiling by the box left a gap at every seam --
 * three icons in a row rather than a comb -- and reserving the overhang with
 * padding made the group's box bigger than the group, which is what put a
 * single tile off-center in the card's gutter.
 *
 * So the positions are computed from the hexagon and the wrapper is sized to
 * the ink. Centering it is then whatever the parent does, with nothing to
 * correct for.
 */
const HEX_W = 37.2 / 40
const HEX_H = 32.2 / 40
const INSET_X = (1 - HEX_W) / 2
const INSET_Y = (1 - HEX_H) / 2

/** §13.2's tiling: three quarters of a width across, half a height down. */
const COL_STEP = 0.75 * HEX_W

const placed = computed(() =>
  marks.value.map((mark, i) => {
    const column = i % columns.value
    const x = column * COL_STEP * cell.value
    const y = (Math.floor(i / columns.value) + (column % 2 ? 0.5 : 0)) * HEX_H * cell.value

    return {
      mark,
      x,
      y,
      style: {
        left: `${x - INSET_X * cell.value}px`,
        top: `${y - INSET_Y * cell.value}px`,
      },
    }
  }),
)

/** The ink, which is what the wrapper is: no padding, nothing to center around. */
const box = computed(() => ({
  width: `${Math.max(...placed.value.map((p) => p.x)) + HEX_W * cell.value}px`,
  height: `${Math.max(...placed.value.map((p) => p.y)) + HEX_H * cell.value}px`,
}))
</script>

<template>
  <span v-if="marks.length && layout === 'comb'" class="comb" :style="box" :aria-label="spoken">
    <span
      v-for="mark in placed"
      :key="mark.mark.key"
      class="mark"
      :style="mark.style"
      :title="`Refines ${mark.mark.name}`"
    >
      <SvgIcon :svg="materialIcon(mark.mark, cell)" />
    </span>
  </span>

  <span v-else-if="marks.length" class="row" :aria-label="spoken">
    <span
      v-for="mark in marks"
      :key="mark.key"
      class="mark"
      :title="`Refines ${mark.name}`"
    >
      <SvgIcon :svg="materialIcon(mark, cell)" />
    </span>
  </span>
</template>

<style scoped>
/*
 * No plate behind either arrangement. A material icon already carries its own
 * hex frame (§13.1), so a group of them reads as a group on its own; a seat
 * under it was a frame around a row of frames.
 */
.comb,
.row {
  flex: 0 0 auto;
}

.row {
  display: inline-flex;
  align-items: center;
  gap: 3px;
}

.comb {
  position: relative;
  display: block;
}

.comb .mark {
  position: absolute;
}

.mark {
  display: block;
  line-height: 0;
}

.mark :deep(svg) {
  display: block;
}
</style>
