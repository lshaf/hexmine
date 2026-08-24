<script setup lang="ts">
/**
 * A hexagonal action cell. Disabled cells stay visible and legible rather than
 * disappearing -- what you cannot do here is information about where you are.
 */
import { ACTION_PATHS } from '@/icons/actions'

withDefaults(
  defineProps<{
    icon: keyof typeof ACTION_PATHS | string
    label: string
    disabled?: boolean
    /** Draws the cell in copper: the one thing to do next. */
    primary?: boolean
    /** Explains a disabled cell on hover and to screen readers. */
    hint?: string
    /** Marks a destructive cell -- dropping a mine forfeits the haul. */
    danger?: boolean
    /** A secondary cell, for actions that are not the dock's main business. */
    small?: boolean
    /**
     * §7.6 -- the cell is reporting a state that needs dealing with, not an
     * action that is unavailable. Ember, and still perfectly clickable: a full
     * bag is exactly the thing you want to open.
     */
    alert?: boolean
    /**
     * §12 -- the same idea pointed the other way: a state worth crossing the
     * screen for, and a welcome one. Ember is what something WRONG looks like,
     * so gold waiting on the ledger must not borrow it -- a reward drawn in the
     * color of a full bag reads as an alarm going off over good news.
     */
    good?: boolean
  }>(),
  {
    disabled: false, primary: false, hint: '', danger: false,
    small: false, alert: false, good: false,
  },
)

defineEmits<{ (e: 'activate'): void }>()
</script>

<template>
  <button
    type="button"
    class="cell"
    :class="{ primary, danger, small, alert, good, off: disabled }"
    :disabled="disabled"
    :title="hint || label"
    :aria-label="hint ? `${label} — ${hint}` : label"
    @click="$emit('activate')"
  >
    <span class="hex">
      <span class="face">
        <svg viewBox="0 0 24 24" :width="small ? 16 : 20" :height="small ? 16 : 20"
             fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path :d="ACTION_PATHS[icon]" />
        </svg>
      </span>
    </span>
    <span class="label name">{{ label }}</span>
  </button>
</template>

<style scoped>
.cell {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 5px;
  color: var(--vellum);
}

.hex {
  width: 58px;
  height: 50px;
  display: block;
  transition: transform 0.14s ease;
}

.face {
  display: grid;
  place-items: center;
  background: var(--ink-raised);
  transition: background 0.14s ease, color 0.14s ease;
}

.cell:hover:not(:disabled) .hex {
  transform: translateY(-2px);
}

.cell:hover:not(:disabled) .face {
  background: #304036;
}

.primary .face {
  background: var(--copper);
  color: #17110c;
}

.primary:hover:not(:disabled) .face {
  background: #d5884a;
}

.primary .name {
  color: var(--copper);
}

.danger .name {
  color: #c98680;
}

.cell.danger:hover:not(:disabled) .face {
  background: #4a2724;
  color: #f0b3ad;
}

.off {
  cursor: not-allowed;
}

.off .face {
  background: #171d1a;
  color: #5f6b64;
}

.off .name {
  color: #5f6b64;
}

.name {
  font-size: 8.5px;
  letter-spacing: 0.14em;
}

/* §7.6 -- a state, not a mode. The cell keeps working; it is just the color of
   something you have to deal with. The rim is the hairline the shape already
   has (see .hex in app.css), so this is a color swap rather than a second
   border that a clip-path would eat. */
.alert .hex {
  background: var(--ember);
}

.alert .face {
  color: var(--ember);
}

.alert:hover:not(:disabled) .face {
  background: #3a2422;
}

.alert .name {
  color: var(--ember);
}

/* §12 -- the affirmative twin of .alert, and drawn exactly the same way so the
   two read as one grammar: the cell has something to tell you, and the color
   says whether that is a problem or a payout. */
.good .hex {
  background: var(--sap);
}

.good .face {
  color: var(--sap);
}

.good:hover:not(:disabled) .face {
  background: #1e2a1c;
}

.good .name {
  color: var(--sap);
}

/* Secondary cells: same shape, less weight. Used by the tile card, where
   travel supports the reading rather than being the point of the panel. */
.small .hex {
  width: 44px;
  height: 38px;
}

.small .name {
  font-size: 7.5px;
}

@media (max-width: 560px) {
  .cell {
    gap: 4px;
  }

  .hex {
    width: 41px;
    height: 36px;
  }

  .small .hex {
    width: 36px;
    height: 31px;
  }

  .name {
    font-size: 7.5px;
    letter-spacing: 0.1em;
  }

  .small .name {
    font-size: 7px;
  }
}
</style>
