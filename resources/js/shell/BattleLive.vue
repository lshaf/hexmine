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
import { FAMILY_FOR_BATTLE_JOB, fighterCrest, monsterCrest } from '@/icons/combatants'
import { useGame } from '@/stores/game'
import SvgIcon from '@/components/SvgIcon.vue'
import type { BattleJob } from '@/game/types'

const props = defineProps<{ job: BattleJob }>()
const emit = defineEmits<{ (e: 'done'): void }>()

const game = useGame()

/**
 * §9.5.4 -- your half of the matchup, off the player state.
 *
 * The pair rides the state rather than the fight, because what your kit is
 * worth is a thing to know while shopping and not something to discover by
 * finding a pack on your hex. Nothing can change it mid-replay either: a pin
 * offers two exits and neither of them is the gear screen (§9.5.3).
 */
const mine = computed(() => game.state?.combat ?? null)

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

/**
 * §9.5 -- the two faces, so the fight is read before it is parsed.
 *
 * The monster is its profile and its ring; you are the family in your hand,
 * which §9.5.4 already makes your class. Neither needs anything new on the
 * payload -- the job's own key says which of the three you fought with.
 */
const theirCrest = computed(() =>
  monsterCrest(monster.value?.profile ?? 'brute', monster.value?.tier ?? 1, 44),
)

const myCrest = computed(() =>
  fighterCrest(FAMILY_FOR_BATTLE_JOB[props.job.skill] ?? null, failing.value, 44),
)

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
      <!-- §9.5.5 -- a round is ONE exchange, so the two pools face each other
           across a single line rather than stacking. Two stacked bars imply a
           turn order the arithmetic does not have; opposed bars draining away
           from a shared center say what actually happens -- one blow, two
           consequences, and whoever empties first loses. -->
      <div class="band">
        <div class="corner them" :class="{ struck: tookTheirs > 0 }">
          <SvgIcon :svg="theirCrest" class="crest" />
          <span class="who">
            <strong class="name">{{ monster?.name ?? 'It' }}</strong>
            <span class="tiny muted block">{{ monster?.profile }}</span>
            <span class="tiny muted block mono">
              {{ monster?.attack }} atk · {{ monster?.defense }} def
            </span>
          </span>
        </div>

        <div class="tick">
          <span class="tiny muted">Round</span>
          <strong class="count">{{ round }}</strong>
        </div>

        <div class="corner you" :class="{ struck: tookMine > 0 }">
          <span class="who">
            <strong class="name">You</strong>
            <span class="tiny muted block">{{ job.skill }}</span>
            <span class="tiny muted block mono">
              {{ mine?.attack ?? 0 }} atk · {{ mine?.defense ?? 0 }} def
            </span>
          </span>
          <SvgIcon :svg="myCrest" class="crest" />
        </div>
      </div>

      <!-- Both pools drain toward the outside, so the gap in the middle is the
           fight: it opens on whichever side is losing. -->
      <div class="pools">
        <div class="pool left">
          <div class="bar"><span class="fill foe" :style="{ width: `${foePercent}%` }" /></div>
          <div class="under">
            <Transition name="hit">
              <span v-if="tookTheirs > 0" :key="`t${round}`" class="blow">−{{ tookTheirs }}</span>
            </Transition>
            <span class="readout mono figure">{{ foe }}</span>
          </div>
        </div>

        <span class="seam" aria-hidden="true" />

        <div class="pool right">
          <div class="bar">
            <span class="fill mine" :class="{ low: failing }" :style="{ width: `${myPercent}%` }" />
          </div>
          <div class="under">
            <span class="readout mono figure" :class="{ low: failing }">{{ hp }}</span>
            <Transition name="hit">
              <span v-if="tookMine > 0" :key="`m${round}`" class="blow">−{{ tookMine }}</span>
            </Transition>
          </div>
        </div>
      </div>

      <p class="tiny muted foot">
        <template v-if="over">Settling up…</template>
        <template v-else>What drains here is the repair bill.</template>
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

/* ------------------------------------------------------------------- band */

/* §9.5.5 -- the two faces, and the round counter on the line between them. The
   counter sits in the middle rather than above because it is the one thing
   belonging to BOTH sides: a round is one exchange. */
.band {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  align-items: center;
  gap: 10px;
}

.corner {
  display: flex;
  align-items: center;
  gap: 9px;
  min-width: 0;
  transition: transform 0.1s ease;
}

.corner.you {
  justify-content: flex-end;
  text-align: right;
}

/* The blow lands on the fighter, not just on the number, and each side recoils
   AWAY from the center. One frame is enough -- longer reads as a bug. */
.corner.them.struck {
  transform: translateX(-3px);
}

.corner.you.struck {
  transform: translateX(3px);
}

.crest {
  flex: 0 0 auto;
}

.who {
  min-width: 0;
}

/* Wraps rather than truncates. A crest says what KIND of thing this is; the
   name is the only thing saying WHICH, and "Barrow K…" on a phone is the half
   of it that carries no information. Two short lines cost a few pixels the
   band has, since it is centered against a 44px crest either way. */
.name {
  display: block;
  font-family: var(--font-display);
  font-size: 15px;
  line-height: 1.15;
  overflow-wrap: anywhere;
}

.block {
  display: block;
  line-height: 1.35;
}

.corner .block:first-of-type {
  text-transform: capitalize;
}

.corner .mono {
  white-space: nowrap;
}

/* The seam the whole plate is built around. */
.tick {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1px;
  padding: 0 4px;
}

.tick .tiny {
  letter-spacing: 0.16em;
  text-transform: uppercase;
}

.count {
  font-family: var(--font-display);
  font-size: 21px;
  line-height: 1;
  font-variant-numeric: tabular-nums;
}

/* ------------------------------------------------------------------ pools */

.pools {
  display: grid;
  grid-template-columns: 1fr 1px 1fr;
  align-items: start;
  gap: 10px;
}

.pool {
  display: flex;
  flex-direction: column;
  gap: 5px;
}

/* Each pool drains toward the outside, so the gap that opens in the middle is
   the fight: it widens on whichever side is losing. */
.pool.left .bar {
  direction: rtl;
}

/* What is LEFT sits against the seam and what was just TAKEN sits at the
   outside edge, so the two remaining pools can be compared across the middle
   and a blow never lands on top of the figure it changed. */
.under {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
  min-height: 19px;
}

.pool.left .under {
  flex-direction: row;
}

.seam {
  align-self: stretch;
  background: var(--line);
}

.figure {
  font-size: 16px;
  font-variant-numeric: tabular-nums;
}

.figure.low {
  color: var(--ember);
}

.bar {
  width: 100%;
  height: 10px;
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
  background: var(--ember);
}

.fill.mine {
  background: var(--sap);
}

.fill.mine.low {
  background: var(--ember);
}

.blow {
  font-family: var(--font-display);
  font-size: 14px;
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

@media (prefers-reduced-motion: reduce) {
  .corner,
  .fill,
  .hit-enter-active,
  .hit-leave-active {
    transition: none;
  }

  .corner.struck {
    transform: none;
  }
}

.foot {
  margin: 0;
  text-align: center;
  letter-spacing: 0.12em;
}

@media (max-width: 380px) {
  .crest :deep(svg) {
    width: 36px;
    height: 36px;
  }
}
</style>
