<script setup lang="ts">
/**
 * §8 -- one of the four things you can do to a piece of gear, as a glyph.
 *
 * Equip, stow, repair and scrap are the same four verbs wherever a piece is
 * met, and they used to be four words typed out again on every screen that
 * listed gear. Words are wide: a row on the prospector sheet spent more of its
 * width on "Repair Stow" than on the item, and the bag could not afford them at
 * all, which is most of why stowed gear had no mend button (§8.2 -- the server
 * never refused one).
 *
 * A glyph is the same instruction in a square, so the row keeps its width for
 * the thing the row is about. The word is still there for anyone who needs it:
 * `title` on hover, `aria-label` for a reader.
 */
import { ACTION_PATHS } from '@/icons/actions'

withDefaults(
  defineProps<{
    /** Which verb. Picks the glyph, the tooltip and the tone. */
    action: 'equip' | 'stow' | 'repair' | 'scrap'
    label: string
    disabled?: boolean
    /** Say the word as well as drawing it. For a plate that has the room --
     *  a popup asking you to choose, rather than a row listing what you own. */
    wide?: boolean
  }>(),
  { disabled: false, wide: false },
)

/* §8.2 -- scrap is the one that does not give the piece back, so it is the one
   drawn in ember (§13.3: a state to deal with, and a destructive button is
   one). The other three are reversible in an action. */
const GLYPH = { equip: 'equip', stow: 'stow', repair: 'repair', scrap: 'scrap' } as const
</script>

<template>
  <button
    class="btn btn-sm gear-act"
    :class="{ 'btn-danger': action === 'scrap', wide }"
    type="button"
    :disabled="disabled"
    :title="label"
    :aria-label="label"
  >
    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
         stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path :d="ACTION_PATHS[GLYPH[action]]" />
    </svg>
    <span v-if="wide">{{ label }}</span>
  </button>
</template>

<style scoped>
/* Square, so four of them stack into a tidy column on a narrow row rather than
   four rectangles of four different widths. */
.gear-act {
  padding: 0;
  width: 30px;
  height: 30px;
  flex: 0 0 auto;
}

.gear-act.wide {
  padding: 6px 12px;
  width: auto;
}
</style>
