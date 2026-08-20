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
    /** Marks a destructive cell -- dropping a trip forfeits the haul. */
    danger?: boolean
    /** A secondary cell, for actions that are not the dock's main business. */
    small?: boolean
  }>(),
  { disabled: false, primary: false, hint: '', danger: false, small: false },
)

defineEmits<{ (e: 'activate'): void }>()
</script>

<template>
  <button
    type="button"
    class="cell"
    :class="{ primary, danger, small, off: disabled }"
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
  .hex {
    width: 46px;
    height: 40px;
  }

  .small .hex {
    width: 40px;
    height: 35px;
  }
}
</style>
