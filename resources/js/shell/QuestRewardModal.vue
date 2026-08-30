<script setup lang="ts">
/**
 * What a claim paid -- a quest's (§12.1) or one of the day's three (§12.2).
 *
 * This used to be a toast, and a toast was the wrong shape for it twice over.
 * A claim is not a status line -- it is the one thing on the ledger the player
 * came back for -- and there is more to say than a line can hold: what was
 * earned, what the purse is now, and what the claim just opened up.
 *
 * So it is a receipt, drawn on the same beat as the haul's (§4): the plate
 * rises, and the figure runs up to what you were paid. One orchestrated arrival
 * rather than a notification sliding past in the corner.
 *
 * It dismisses on any click and carries no button, for the same reason the haul
 * receipt does: the gold is already yours -- it landed before this was drawn --
 * so a control saying "Take it" would ask a question with one answer.
 *
 * Everything here is the server's own response. The client never decides what a
 * quest paid, only how to show it (§16).
 *
 * One receipt for both ledgers, because a claim is one moment however it was
 * earned: a name, a figure, and the purse it changed. The two lines that are
 * not shared -- what a quest opened, and the fact that a daily comes round
 * again -- are the only place `reward.daily` is read.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { ACTION_PATHS } from '@/icons/actions'
import type { QuestReward } from '@/api/types'

const props = defineProps<{ reward: QuestReward }>()
const emit = defineEmits<{ (e: 'close'): void }>()

/**
 * The same one-beat arrival as the haul receipt, and the same guard on it: the
 * figure starts AT the answer and only drops to zero once a frame has really
 * arrived. requestAnimationFrame does not fire on a backgrounded tab, and a
 * receipt reading "0 gold" over a claimed quest is worse than one that never
 * animated at all.
 */
const settled = ref(false)
const counted = ref(0)

const CALM = window.matchMedia('(prefers-reduced-motion: reduce)').matches
const COUNT_MS = 520

let frame = 0

onMounted(() => {
  counted.value = props.reward.gold

  if (CALM) {
    settled.value = true
    return
  }

  const target = props.reward.gold
  let started: number | null = null

  const tick = (at: number) => {
    if (started === null) started = at

    const through = Math.min(1, (at - started) / COUNT_MS)
    counted.value = Math.round(target * (1 - (1 - through) ** 3))

    if (through < 1) frame = requestAnimationFrame(tick)
  }

  frame = requestAnimationFrame(tick)
  requestAnimationFrame(() => {
    settled.value = true
  })
})

function onKey(event: KeyboardEvent): void {
  if (event.key === 'Escape') emit('close')
}

onMounted(() => window.addEventListener('keydown', onKey))

onBeforeUnmount(() => {
  cancelAnimationFrame(frame)
  window.removeEventListener('keydown', onKey)
})
</script>

<template>
  <div class="wrap" role="dialog" aria-label="Quest reward" @click="$emit('close')">
    <div class="scrim" />

    <div class="receipt plate" :class="{ settled }">
      <div class="inner">
        <span class="eyebrow label">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
               stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path :d="ACTION_PATHS.quest" />
          </svg>
          {{ reward.daily ? 'Daily done' : 'Quest complete' }}
        </span>

        <h3 class="name">{{ reward.name }}</h3>

        <p class="tally">
          <strong class="figure">{{ counted }}</strong>
          <span class="unit label">gold</span>
        </p>

        <!-- §3.2 -- the purse after, because a payout means nothing without the
             number it changed. -->
        <div class="inset purse tiny">
          <div class="row-between">
            <span class="muted">Purse</span>
            <span class="readout gold">{{ reward.goldAfter }}g</span>
          </div>
        </div>

        <!-- §12.1 -- what this claim opened. The chain advances on claiming, so
             this is the one moment the next quest exists to be named. A daily
             opens nothing: §12.2 has no chain, and comes round instead. -->
        <div v-if="reward.unlocked.length" class="opened">
          <span class="label">Now open</span>
          <ul>
            <li v-for="next in reward.unlocked" :key="next.key">{{ next.name }}</li>
          </ul>
        </div>

        <p v-else-if="reward.daily" class="tiny muted end">
          Nothing follows a daily. The day turns and three more are drawn.
        </p>

        <p v-else class="tiny muted end">
          Nothing follows this one yet. The ledger will grow.
        </p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wrap {
  position: absolute;
  inset: 0;
  z-index: var(--z-panel);
  display: grid;
  place-items: center;
  padding: 16px;
}

.scrim {
  position: absolute;
  inset: 0;
  background: rgba(10, 14, 12, 0.72);
}

.receipt {
  position: relative;
  width: min(340px, 100%);
  opacity: 0;
  transform: translateY(10px);
  transition: opacity 0.24s ease, transform 0.28s cubic-bezier(0.32, 0.72, 0, 1);
}

.receipt.settled {
  opacity: 1;
  transform: none;
}

.inner {
  display: flex;
  flex-direction: column;
  gap: 11px;
  padding: 14px 15px 15px;
}

.eyebrow {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--gold);
}

.name {
  margin: -4px 0 0;
  font-size: 15px;
}

/* The figure is the result, not an instrument reading -- same rule as the haul
   receipt, and gold rather than vellum because gold is what it is. */
.tally {
  display: flex;
  align-items: baseline;
  gap: 9px;
  margin: -2px 0 0;
}

.figure {
  font-family: var(--font-display);
  font-size: 40px;
  line-height: 0.9;
  color: var(--gold);
  font-variant-numeric: tabular-nums;
}

.unit {
  color: var(--vellum-dim);
}

.purse {
  padding: 7px 10px;
}

.opened {
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.opened ul {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.opened li {
  font-size: 13px;
  padding-left: 12px;
  position: relative;
}

.opened li::before {
  content: '';
  position: absolute;
  left: 0;
  top: 7px;
  width: 5px;
  height: 5px;
  background: var(--copper);
  clip-path: var(--hex-clip);
}

.end {
  margin: 0;
  line-height: 1.5;
}
</style>
