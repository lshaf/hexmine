<script setup lang="ts">
/**
 * §6.1 + §8.4 -- a bank of shared slots, drawn one way.
 *
 * The processing lines and the craft benches queue by the same rule: a fixed
 * number of slots at a settlement, first-come-first-served, shared by everybody
 * standing there. They were drawn twice and drifted apart, which made two
 * versions of one idea -- so this is the drawing, and both panels use it.
 *
 * Yours is copper, somebody else's is the dull fill, and an open slot is the
 * hole in the row. Nothing here is a control: the queue is a readout, and the
 * button that joins it lives beside the work.
 */
import { computed } from 'vue'
import type { QueueSlot } from '@/api/types'

const props = withDefaults(
  defineProps<{
    label: string
    slots: QueueSlot[]
    /** Said when the bank is full. The reason differs per building. */
    fullNote?: string
  }>(),
  { fullNote: '' },
)

const free = computed(() => props.slots.filter((s) => s.owner === null).length)
</script>

<template>
  <div class="queue">
    <div class="row-between" style="margin-bottom: 6px">
      <span class="label">{{ label }}</span>
      <span class="tiny mono muted">{{ free }} of {{ slots.length }} free</span>
    </div>

    <div class="slots" :style="{ gridTemplateColumns: `repeat(${slots.length}, 1fr)` }">
      <div
        v-for="slot in slots"
        :key="slot.index"
        class="slot"
        :class="{ mine: slot.owner === 'you', taken: slot.owner !== null && slot.owner !== 'you' }"
        :title="slot.owner === 'you' ? 'Yours' : slot.owner ? `Taken by ${slot.owner}` : 'Open'"
      />
    </div>

    <p v-if="free === 0 && fullNote" class="tiny warn">{{ fullNote }}</p>
    <slot />
  </div>
</template>

<style scoped>
.queue {
  padding: 9px 11px;
  /* §13 -- the standard cut, and no line: a border under a clip-path paints on
     the box and the clip takes the corner with it. The darker fill separates
     this from the panel on its own. */
  clip-path: var(--plate-clip);
  background: var(--ink);
}

.slots {
  display: grid;
  gap: 5px;
}

.slot {
  height: 8px;
  /* §13 -- nothing is round, and this is smaller than the standard cut. */
  background: #0f1512;
  border: 1px solid var(--line);
}

.slot.taken {
  background: #4a4034;
  border-color: #6b5a3e;
}

.slot.mine {
  background: var(--copper);
  border-color: #d98d4f;
}

.warn {
  margin: 7px 0 0;
  color: #e8a06a;
  line-height: 1.45;
}
</style>
