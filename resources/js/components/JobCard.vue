<script setup lang="ts">
import { computed } from 'vue'
import { useGame } from '@/stores/game'
import { ITEM_BY_KEY, MATERIALS, SKILL_BY_KEY } from '@/game/catalog'
import { formatDuration, placeLabel } from '@/game/formulas'
import { itemIcon, materialIcon } from '@/icons/procedural'
import SvgIcon from './SvgIcon.vue'
import type { Job } from '@/game/types'

const props = defineProps<{ job: Job }>()
const game = useGame()

const remaining = computed(() => props.job.endsAt - game.now)
const ready = computed(() => remaining.value <= 0)

const total = computed(() => props.job.endsAt - props.job.startedAt)
const progress = computed(() =>
  Math.min(100, Math.max(0, ((total.value - remaining.value) / total.value) * 100)),
)

/**
 * §8.4 -- a craft makes an ITEM, so the card reads a different catalog for it.
 * Everything else about a job is the same shape: a thing, a clock, a place.
 */
const crafting = computed(() => props.job.kind === 'craft')

const made = computed(() =>
  props.job.kind === 'craft' ? ITEM_BY_KEY[props.job.output] : undefined,
)

// §5.5 -- a hunt names what it brought back exactly as a mine does. Left off
// this list it fell through to `undefined` and the card read "Work".
const def = computed(() =>
  props.job.kind === 'processing'
    ? MATERIALS[props.job.output]
    : props.job.kind === 'mining' || props.job.kind === 'hunting'
      ? MATERIALS[props.job.material]
      : undefined,
)

const name = computed(() => made.value?.name ?? def.value?.name ?? 'Work')

const icon = computed(() =>
  made.value
    ? itemIcon({
        slot: made.value.slot,
        family: made.value.family,
        rarity: made.value.rarity,
        palette: made.value.palette,
        size: 26,
      })
    : def.value
      ? materialIcon(def.value, 26)
      : '',
)

/**
 * Where it is, and for a bench that is not decoration: §6 and §8.4 both hand
 * the thing over at the building that holds it, so the name is the walk.
 */
const where = computed(() => {
  const job = props.job

  if (job.kind === 'processing' || job.kind === 'craft') {
    return job.settlementName
      ? placeLabel(job.settlementName, job.col, job.row)
      : (job.kind === 'craft' ? 'A bench' : 'Processing line')
  }

  return `Hex ${job.col}, ${job.row}`
})

/** §6.2 -- only bench work has anybody to stand over. */
const presence = computed(
  () => (props.job.kind === 'processing' || props.job.kind === 'craft') && props.job.presence,
)

/**
 * The line a bench job trains. A craft names one of the three bench jobs
 * (§7.4) rather than a gathering line, and those have no entry here -- the
 * card simply says less about it.
 */
const trains = computed(
  () => (SKILL_BY_KEY as Record<string, { name: string } | undefined>)[props.job.skill]?.name ?? null,
)
</script>

<template>
  <div class="inset row-item" :class="{ ready }">
    <SvgIcon :svg="icon" boxed :size="26" />

    <div class="grow">
      <div class="row-between">
        <strong class="title">
          <template v-if="crafting">{{ name }}</template>
          <template v-else>{{ job.quantity }} {{ name }}</template>
        </strong>
        <span class="mono tiny" :class="ready ? 'gold' : 'muted'">
          {{ ready ? 'Ready' : formatDuration(remaining) }}
        </span>
      </div>
      <div class="tiny muted">
        {{ where }}<template v-if="trains"> · trains {{ trains }}</template>
        <template v-if="presence"> · presence bonus</template>
      </div>
      <div class="bar" :class="ready ? 'bar-gold' : ''" style="margin-top: 6px">
        <span :style="{ width: `${progress}%` }" />
      </div>
    </div>

    <div class="actions">
      <button
        v-if="ready"
        class="btn btn-primary btn-sm"
        type="button"
        :disabled="game.busy"
        @click="game.collect(job.id)"
      >
        Collect
      </button>
      <button
        v-else
        class="btn btn-danger btn-sm"
        type="button"
        :disabled="game.busy"
        title="Abandoning forfeits the partial reward"
        @click="game.abandon(job.id)"
      >
        Abandon
      </button>
    </div>
  </div>
</template>

<style scoped>
.ready {
  border-color: #6b5a26;
  background: #221e14;
}

.title {
  font-size: 13px;
  font-weight: 600;
}

.gold {
  color: var(--gold);
  font-weight: 700;
}

.actions {
  flex: 0 0 auto;
}
</style>
