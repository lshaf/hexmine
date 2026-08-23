<script setup lang="ts">
/**
 * §9.5.4 -- what a piece of gear is worth, in one row of chips.
 *
 * One component so the trader, the bench, the almanac, the bag and the gear
 * list all say the same thing in the same order. Before this each screen
 * assembled its own sentence, which is how a weapon ended up leading with an
 * inert "+3% power" while the two numbers that decide a fight sat beside it
 * looking like a footnote.
 *
 * The pair is told apart by its LABEL, not by its color. §13.3 spends ember on
 * a state to deal with and sap on one worth crossing the screen for, and a stat
 * is neither -- an attack drawn in ember would read as a warning about the
 * sword. So: a dim uppercase word, a mono figure, and nothing else.
 */
import { computed } from 'vue'
import { statChips } from '@/game/formulas'
import type { ItemDef, ItemOption } from '@/game/types'

const props = withDefaults(
  defineProps<{
    def: ItemDef
    options?: ItemOption[]
    /** Drop the work stat and show only the pair, where the name says the rest. */
    pairOnly?: boolean
  }>(),
  { options: () => [], pairOnly: false },
)

const chips = computed(() => {
  const all = statChips(props.def, props.options)

  return props.pairOnly ? all.filter((c) => c.label !== null) : all
})
</script>

<template>
  <span v-if="chips.length" class="chips">
    <span v-for="(chip, i) in chips" :key="i" class="chip tiny" :class="{ pair: chip.label }">
      <span v-if="chip.label" class="key">{{ chip.label }}</span>
      <span :class="{ mono: chip.label }">{{ chip.value }}</span>
    </span>
  </span>
</template>

<style scoped>
.chips {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 4px;
}

.pair {
  display: inline-flex;
  align-items: baseline;
  gap: 4px;
}

.key {
  font-size: 8.5px;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--vellum-dim);
}
</style>
