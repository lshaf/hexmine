<script setup lang="ts">
/**
 * §8 -- what putting one piece on in place of another actually moves.
 *
 * One component because two screens ask it: the bag, where a piece is tapped
 * and the question is whether to wear it, and the prospector sheet, where the
 * spares filed behind a slot (§8.2) ask exactly the same thing. The arithmetic
 * is shared already (`swapChanges`); drawing it twice is how two screens start
 * disagreeing about one answer.
 *
 * §13.3 -- sap for what the swap wins, ember for what it costs. A stat is
 * neither of those and StatChips is right to draw it plain; a CHANGE is exactly
 * a thing to weigh, which is the one reading these plates exist for.
 */
import type { SwapChange } from '@/game/formulas'

withDefaults(
  defineProps<{
    changes: SwapChange[]
    /** What to say when nothing moves. Silence would read as "not computed". */
    same?: string
  }>(),
  { same: 'Identical stats — only condition differs.' },
)
</script>

<template>
  <span v-if="changes.length" class="chips">
    <span
      v-for="(change, i) in changes"
      :key="i"
      class="chip tiny move"
      :class="change.better ? 'up' : 'down'"
    >{{ change.text }}</span>
  </span>
  <span v-else class="tiny muted">{{ same }}</span>
</template>

<style scoped>
.chips {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 4px;
}

.move {
  font-variant-numeric: tabular-nums;
}

.move.up {
  color: #b7d6a4;
  background: #1c2519;
}

.move.down {
  color: #e0a09b;
  background: #2a1a19;
}
</style>
