<script setup lang="ts">
import { computed } from 'vue'
import { useGame } from '@/stores/game'
import { MATERIALS, SKILL_BY_KEY } from '@/game/catalog'
import { formatDuration } from '@/game/formulas'
import { materialIcon } from '@/icons/procedural'
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

const output = computed(() =>
  props.job.kind === 'mining' ? props.job.material : props.job.output,
)
const def = computed(() => MATERIALS[output.value])
const icon = computed(() => materialIcon(def.value, 26))

const where = computed(() =>
  props.job.kind === 'mining'
    ? `Hex ${props.job.col}, ${props.job.row}`
    : 'Processing line',
)
</script>

<template>
  <div class="inset row-item" :class="{ ready }">
    <SvgIcon :svg="icon" boxed :size="26" />

    <div class="grow">
      <div class="row-between">
        <strong class="title">{{ job.quantity }} {{ def.name }}</strong>
        <span class="mono tiny" :class="ready ? 'gold' : 'muted'">
          {{ ready ? 'Ready' : formatDuration(remaining) }}
        </span>
      </div>
      <div class="tiny muted">
        {{ where }} · trains {{ SKILL_BY_KEY[job.skill].name }}
        <template v-if="job.kind === 'processing' && job.presence"> · presence bonus</template>
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
