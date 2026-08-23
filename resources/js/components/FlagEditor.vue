<script setup lang="ts">
/**
 * §10.0.3 -- the flag, and the tools to draw one.
 *
 * Pulled out of the guild screen because the same editor is wanted in two
 * places that are otherwise nothing alike: founding a guild at the dock, and
 * repainting one from the panel. The canvas itself knows nothing about tools;
 * this is the palette and the brush around it.
 */
import { ref } from 'vue'
import FlagCanvas from '@/components/FlagCanvas.vue'

withDefaults(defineProps<{ size?: number }>(), { size: 160 })

const flag = defineModel<string | null>({ required: true })

const colour = ref('#c1793f')
const fill = ref(false)
const canvas = ref<InstanceType<typeof FlagCanvas> | null>(null)

/**
 * §13.3 -- the game's own palette first, because a flag drawn out of it belongs
 * to this world. The picker underneath is any colour at all: identity is the
 * one place §13's "no artist required" rule is deliberately set aside.
 */
const SWATCHES = [
  '#141b18', '#1d2622', '#3a463f', '#ece3cd',
  '#c9bd9e', '#c1793f', '#b8453f', '#d8b34a',
  '#7d5fa8', '#8fbf7f', '#5f8058', '#6d8399',
  '#b08a5a', '#96604c', '#a8a05c', '#ffffff',
]
</script>

<template>
  <div class="drawing">
    <FlagCanvas
      ref="canvas"
      v-model:flag="flag"
      :size="size"
      editable
      :colour="colour"
      :fill="fill"
    />
    <div class="tools">
      <div class="swatches">
        <button
          v-for="swatch in SWATCHES"
          :key="swatch"
          type="button"
          class="swatch"
          :class="{ on: colour === swatch }"
          :style="{ background: swatch }"
          :aria-label="swatch"
          @click="colour = swatch"
        />
      </div>
      <label class="tiny muted picker">
        Any colour
        <input v-model="colour" type="color" />
      </label>
      <label class="tiny muted picker">
        <input v-model="fill" type="checkbox" />
        Fill a region
      </label>
      <button class="btn btn-sm" type="button" @click="canvas?.clear()">Flood it all</button>
    </div>
  </div>
</template>

<style scoped>
.drawing {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  flex-wrap: wrap;
}

.tools {
  display: flex;
  flex-direction: column;
  gap: 7px;
  min-width: 130px;
}

.swatches {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 4px;
}

.swatch {
  width: 26px;
  height: 26px;
  border: 1px solid var(--line);
  padding: 0;
  cursor: pointer;
}

.swatch.on {
  outline: 2px solid var(--vellum);
  outline-offset: -2px;
}

.picker {
  display: flex;
  align-items: center;
  gap: 6px;
}

.picker input[type='color'] {
  width: 34px;
  height: 22px;
  padding: 0;
  border: 1px solid var(--line);
  background: none;
}
</style>
