<script setup lang="ts">
/**
 * §8.4 -- write a recipe on the slate, or rub it off.
 *
 * The one control in the game that changes nothing about the world: it is a
 * note to yourself about a walk you mean to take. That is why it is a mark
 * rather than a button with a word on it — it sits beside Craft and Queue,
 * which do things, and must never be mistaken for one of them.
 *
 * Ten lines, and the refusal at the eleventh is the server's (§8.4). The mark
 * dims rather than disappearing when the slate is full: a control that vanishes
 * teaches nothing, and the toast says why.
 */
import { computed } from 'vue'
import { useGame } from '@/stores/game'
import { SLATE_CAP } from '@/game/balance'
import { ACTION_PATHS } from '@/icons/actions'

const props = defineProps<{ recipe: string }>()

const game = useGame()

const on = computed(() => game.saved(props.recipe))

/** §8.4 -- full, and this is not one of the ten. Saving is the only refusal. */
const full = computed(() => !on.value && game.slate.length >= SLATE_CAP)

const label = computed(() =>
  on.value ? 'On the slate — tap to rub it off' : full.value ? 'The slate is full' : 'Write it on the slate',
)
</script>

<template>
  <button
    class="btn btn-sm mark"
    :class="{ on }"
    type="button"
    :disabled="game.busy || full"
    :title="label"
    :aria-label="label"
    :aria-pressed="on"
    @click.stop="game.toggleSlate(recipe)"
  >
    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
         stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path :d="ACTION_PATHS[on ? 'slateOn' : 'slate']" />
    </svg>
  </button>
</template>

<style scoped>
/* Square, like the gear verbs, so a row of controls keeps one rhythm. */
.mark {
  padding: 0;
  width: 30px;
  height: 30px;
  flex: 0 0 auto;
}

/* §13.3 -- copper is what the dock spends on work in progress, and a line on
   the slate is exactly that: something started and not finished. Not sap, which
   is a payout, and not ember, which is a problem. */
.mark.on {
  color: var(--copper);
  border-color: var(--copper);
}
</style>
