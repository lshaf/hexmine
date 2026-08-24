<script setup lang="ts">
/**
 * §9.5.5 -- the exchange, drawn.
 *
 * The fight is settled the instant you close: the server ran the whole loop,
 * stored it, and handed the rounds over with the job. So nothing here decides
 * anything and nothing here is asked of the server -- this is a replay, running
 * at one round a beat, and the only request it makes is the collect at the end
 * that turns it into a receipt (§16: the client renders state, never asserts
 * it).
 *
 * Both sides strike in the same beat, because they do: §9.5.5 has you swing
 * first inside a round, but a round is one exchange and drawing it as two would
 * imply a turn order that the arithmetic does not have.
 *
 * The health bars ARE the durability of what each side is carrying (§9.5.5).
 * That is the whole reason to watch: what drains on screen is what you will be
 * paying to repair, so the cost of a bad matchup is legible while it happens
 * rather than only on the plate afterwards.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { MONSTERS } from '@/game/monsters'
import type { BattleJob } from '@/game/types'

const props = defineProps<{ job: BattleJob }>()
const emit = defineEmits<{ (e: 'done'): void }>()

const monster = computed(() => (props.job.monster ? MONSTERS[props.job.monster] : null))

const hp = ref(props.job.pool)
const foe = ref(props.job.monsterHp)

/** The blow each side just took, held for one beat so it can be read. */
const tookMine = ref(0)
const tookTheirs = ref(0)

const round = ref(0)
const over = ref(false)

const rounds = computed(() => props.job.log ?? [])

const myPercent = computed(() =>
  props.job.pool > 0 ? Math.max(0, (hp.value / props.job.pool) * 100) : 0,
)
const foePercent = computed(() =>
  props.job.monsterHp > 0 ? Math.max(0, (foe.value / props.job.monsterHp) * 100) : 0,
)

/** §13.3 -- ember is a state to deal with, and a pool this low is exactly that. */
const failing = computed(() => myPercent.value <= 25)

let timer: ReturnType<typeof setInterval> | undefined

function step(): void {
  const next = rounds.value[round.value]

  if (!next) {
    over.value = true
    stop()
    // A beat on the last blow before the plate replaces it.
    window.setTimeout(() => emit('done'), 450)

    return
  }

  tookMine.value = next.back
  tookTheirs.value = next.hit
  hp.value = next.hp
  foe.value = next.foe
  round.value += 1
}

function stop(): void {
  if (timer !== undefined) clearInterval(timer)
  timer = undefined
}

onMounted(() => {
  // Coming back to a fight already part-run: catch the bars up to where the
  // clock says it is rather than replaying from the first blow. The result was
  // never in the animation, so nothing is lost by skipping to the middle.
  const elapsed = Date.now() - props.job.startedAt
  const already = Math.max(0, Math.min(rounds.value.length, Math.floor(elapsed / props.job.roundMs)))

  if (already > 0) {
    const at = rounds.value[already - 1]!
    hp.value = at.hp
    foe.value = at.foe
    round.value = already
  }

  timer = setInterval(step, props.job.roundMs)
})

onBeforeUnmount(stop)
</script>

<template>
  <div class="scrim">
    <div class="plate" role="dialog" aria-live="polite">
      <p class="tiny muted round">Round {{ round }}</p>

      <!-- ------------------------------------------------------ the monster -->
      <div class="side" :class="{ struck: tookTheirs > 0 }">
        <div class="row" style="gap: 9px; align-items: center">
          <span class="grow">
            <strong class="name">{{ monster?.name ?? 'It' }}</strong>
            <span class="tiny muted block">
              {{ monster?.profile }} · {{ monster?.attack }} attack ·
              {{ monster?.defense }} defense
            </span>
          </span>
          <span class="readout mono figure">{{ foe }}</span>
        </div>
        <div class="bar">
          <span class="fill foe" :style="{ width: `${foePercent}%` }" />
        </div>
        <Transition name="hit">
          <span v-if="tookTheirs > 0" :key="round" class="blow">−{{ tookTheirs }}</span>
        </Transition>
      </div>

      <p class="tiny muted versus">against</p>

      <!-- --------------------------------------------------------- your kit -->
      <div class="side" :class="{ struck: tookMine > 0 }">
        <div class="row" style="gap: 9px; align-items: center">
          <span class="grow">
            <strong class="name">Your kit</strong>
            <span class="tiny muted block">
              weapon, armor, boots and gloves — what drains here is the repair bill
            </span>
          </span>
          <span class="readout mono figure" :class="{ low: failing }">{{ hp }}</span>
        </div>
        <div class="bar">
          <span class="fill mine" :class="{ low: failing }" :style="{ width: `${myPercent}%` }" />
        </div>
        <Transition name="hit">
          <span v-if="tookMine > 0" :key="round" class="blow">−{{ tookMine }}</span>
        </Transition>
      </div>

      <p class="tiny muted foot">
        <template v-if="over">Settling up…</template>
        <template v-else>It is already decided — this is how it went.</template>
      </p>
    </div>
  </div>
</template>

<style scoped>
.scrim {
  position: fixed;
  inset: 0;
  z-index: 60;
  display: grid;
  place-items: center;
  padding: 18px;
  background: rgba(8, 12, 10, 0.72);
}

.plate {
  width: min(420px, 100%);
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 16px;
  background: var(--ink-panel);
  border: 1px solid var(--line);
}

.round {
  letter-spacing: 0.18em;
  text-transform: uppercase;
  text-align: center;
  margin: 0;
}

.side {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 7px;
  padding: 10px;
  background: rgba(0, 0, 0, 0.28);
  transition: transform 0.09s ease, background 0.18s ease;
}

/* The blow lands on the plate, not just on the number. One frame of shove is
   enough -- anything longer reads as a bug rather than as a hit. */
.side.struck {
  transform: translateX(-2px);
  background: rgba(0, 0, 0, 0.44);
}

.name {
  font-family: var(--font-display);
  font-size: 14px;
}

.block {
  display: block;
  line-height: 1.4;
}

.figure {
  font-size: 17px;
  font-variant-numeric: tabular-nums;
}

.figure.low {
  color: var(--ember);
}

.bar {
  height: 9px;
  background: rgba(0, 0, 0, 0.5);
  overflow: hidden;
}

/* The width transition is the animation: it is told where to go once a beat and
   slides there, so nothing has to be tweened by hand. */
.fill {
  display: block;
  height: 100%;
  transition: width 0.16s linear;
}

.fill.foe {
  background: var(--copper);
}

.fill.mine {
  background: var(--vellum-dim);
}

.fill.mine.low {
  background: var(--ember);
}

.blow {
  position: absolute;
  right: 12px;
  top: 6px;
  font-family: var(--font-display);
  font-size: 15px;
  color: var(--ember);
  pointer-events: none;
}

.hit-enter-active {
  transition: opacity 0.14s ease, transform 0.22s ease;
}

.hit-enter-from {
  opacity: 0;
  transform: translateY(6px);
}

.hit-leave-active {
  transition: opacity 0.16s ease;
}

.hit-leave-to {
  opacity: 0;
}

.versus,
.foot {
  margin: 0;
  text-align: center;
  letter-spacing: 0.12em;
}
</style>
