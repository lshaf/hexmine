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
import { qualityNote, statChips } from '@/game/formulas'
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
    /**
     * §7.1 -- and the reader's job levels, for the pieces gated on one.
     *
     * A tool and a weapon answer to the job that swings them, so the career's
     * number is the wrong one to hold their gate up against. Optional for the
     * same reason `level` is: the almanac and the battle bench answer with no
     * wallet at all, and a gate with nobody behind it is drawn plain.
     */
    jobLevels?: Record<string, number>
    /**
     * §8.0.2 -- how well THIS copy came out, where the reader is looking at a
     * copy rather than at a recipe.
     *
     * Undefined on a shelf tag, a bench card and the almanac, which describe
     * the thing rather than a thing -- so those draw the recipe's own figures,
     * which is what they are quoting.
     */
    quality?: number | null
  }>(),
  { options: () => [], pairOnly: false, level: undefined, jobLevels: undefined, quality: undefined },
)

const chips = computed(() => {
  const all = statChips(props.def, props.options, props.quality)

  return props.pairOnly ? all.filter((c) => c.label !== null) : all
})

/** §8.0.2 -- and the word for the copy itself, on the ones worth a word. */
const note = computed(() => qualityNote(props.quality))

/**
 * §13.3 -- ember is for a state to deal with, and this is the only one here.
 *
 * Which of the reader's two levels answers it is the chip's own business: a
 * job-gated piece names its job, and a worn one names none.
 */
const short = (chip: StatChip) => {
  if (chip.gate === undefined) return false

  const mine = chip.gateJob ? props.jobLevels?.[chip.gateJob] : props.level

  return mine !== undefined && mine < chip.gate
}
</script>

<template>
  <span v-if="chips.length || note" class="chips">
    <span
      v-for="(chip, i) in chips"
      :key="i"
      class="chip tiny"
      :class="{ pair: chip.label, short: short(chip) }"
    >
      <span v-if="chip.label" class="key">{{ chip.label }}</span>
      <span :class="{ mono: chip.label }">{{ chip.value }}</span>
    </span>
    <!--
      §8.0.2 -- the copy itself, last, because it qualifies every figure before
      it rather than adding one of its own.
    -->
    <span v-if="note" class="chip tiny pair make" :class="{ good: note.good }">
      <span class="key">{{ note.word }}</span>
      <span class="mono">{{ note.percent }}</span>
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

/* §13.3 -- sap marks a thing worth crossing the screen for, and a copy that
   came out well is one. A poor one takes no colour at all: it is a fact, not a
   state to deal with, and ember there would be an alarm about a working tool. */
.make.good,
.make.good .key {
  color: var(--sap);
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
