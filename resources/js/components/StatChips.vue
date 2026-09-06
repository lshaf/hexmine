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
import type { StatChip } from '@/game/formulas'

const props = withDefaults(
  defineProps<{
    def: ItemDef
    options?: ItemOption[]
    /** Drop the work stat and show only the pair, where the name says the rest. */
    pairOnly?: boolean
    /**
     * §7.1 -- the reader's own level, where there is a reader.
     *
     * Optional because half the places this is drawn have no character behind
     * them: the almanac and the battle bench answer with no wallet at all. With
     * one, a gate you cannot meet goes ember; without one it is drawn plain,
     * which is honest -- it is still the level the rung wants, and there is
     * nobody for it to be a problem for.
     */
    level?: number
  }>(),
  { options: () => [], pairOnly: false, level: undefined },
)

const chips = computed(() => {
  const all = statChips(props.def, props.options)

  return props.pairOnly ? all.filter((c) => c.label !== null) : all
})

/** §13.3 -- ember is for a state to deal with, and this is the only one here. */
const short = (chip: StatChip) =>
  chip.gate !== undefined && props.level !== undefined && props.level < chip.gate
</script>

<template>
  <span v-if="chips.length" class="chips">
    <span
      v-for="(chip, i) in chips"
      :key="i"
      class="chip tiny"
      :class="{ pair: chip.label, short: short(chip) }"
    >
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

/*
 * §13.3 -- a rung you cannot reach yet. Ember is what a state to deal with
 * looks like, and a gate is the only chip in this row that can be one: every
 * other one is a figure, and a figure is neither good news nor bad.
 */
.short,
.short .key {
  color: var(--ember);
}
</style>
