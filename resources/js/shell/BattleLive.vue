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
 *
 * The band, the cooldown rail and the skill rows are all shared components: the
 * bench at /battle draws this same fight off the same pieces, so there is no
 * second copy of any of it to drift.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { MONSTERS } from '@/game/monsters'
import { FAMILY_FOR_BATTLE_JOB, fighterCrest, monsterCrest } from '@/icons/combatants'
import { useGame } from '@/stores/game'
import SvgIcon from '@/components/SvgIcon.vue'
import BattleBand from '@/shell/BattleBand.vue'
import BattleSkillRail from '@/shell/BattleSkillRail.vue'
import { BATTLE_SKILLS } from '@/game/battleSkills'
import { skillEffect, skillGlyph } from '@/icons/skills'
import type { BattleJob, BattleRound } from '@/game/types'

const props = defineProps<{ job: BattleJob; pair?: { attack: number; defense: number } }>()
const emit = defineEmits<{ (e: 'done'): void }>()

const game = useGame()

/**
 * Your pair, for the corner readout.
 *
 * Taken from the store in play, and from a prop on the battle bench (/battle),
 * where there is no character and no state to read one off. The store is
 * touched either way -- a composable cannot be called conditionally -- but
 * nothing is asked of it when the prop is there.
 *
 * §9.5.4 -- it rides the state rather than the fight, because what your kit is
 * worth is a thing to know while shopping and not something to discover by
 * finding a pack on your hex. Nothing can change it mid-replay either: a pin
 * offers two exits and neither of them is the gear screen (§9.5.3).
 */
const mine = computed(() => props.pair ?? game.state?.combat ?? null)

const monster = computed(() => (props.job.monster ? MONSTERS[props.job.monster] : null))

const hp = ref(props.job.pool)
const foe = ref(props.job.monsterHp)

/** The blow each side just took, held for one beat so it can be read. */
const tookMine = ref(0)
const tookTheirs = ref(0)

const round = ref(0)

/**
 * §9.5.9 -- the skill that went off this round, held for the beat it is drawn.
 *
 * One a round at most, so this is a single value rather than a list. Cleared on
 * every round that has none, which is most of them: the cooldowns are long and
 * a rout never sees one at all.
 */
const fired = ref<BattleRound | null>(null)

const firedName = computed(() =>
  fired.value?.skill ? (BATTLE_SKILLS[fired.value.skill]?.name ?? fired.value.skill) : null,
)

const firedGlyph = computed(() =>
  fired.value?.skill ? skillGlyph(BATTLE_SKILLS[fired.value.skill]?.glyph, 18) : null,
)

const firedEffect = computed(() => (fired.value ? skillEffect(fired.value) : null))

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
    stop()
    // A beat on the last blow before the plate replaces it.
    window.setTimeout(() => emit('done'), 450)

    return
  }

  tookMine.value = next.back
  tookTheirs.value = next.hit
  hp.value = next.hp
  foe.value = next.foe
  fired.value = next.skill ? next : null
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
      <!-- ONE child, because `.plate` is the hairline-border trick: the outer
           element is the border colour and a single inner one, inset by a
           pixel, carries the fill. `.plate > *` therefore hands EVERY direct
           child the chamfered clip, the panel fill and a 14px backdrop blur --
           so five children were drawn as five stacked slabs with the rows
           floating on them. -->
      <div class="inner">
        <!-- §9.5.5 -- a round is ONE exchange, so the two pools face each other
             across a single line rather than stacking. Two stacked bars imply a
             turn order the arithmetic does not have; opposed bars draining away
             from a shared center say what actually happens -- one blow, two
             consequences, and whoever empties first loses.

             The counter sits in the middle rather than above because it is the
             one thing belonging to BOTH sides: a round is one exchange. -->
        <BattleBand
          :their-crest="theirCrest"
          :my-crest="myCrest"
          :their-name="monster?.name ?? 'It'"
          :their-profile="monster?.profile"
          :their-attack="monster?.attack"
          :their-defense="monster?.defense"
          :my-sub="job.skill"
          :my-attack="mine?.attack ?? 0"
          :my-defense="mine?.defense ?? 0"
          :struck-them="tookTheirs > 0"
          :struck-you="tookMine > 0"
        >
          <span class="tick">
            <span class="tiny muted">Round</span>
            <strong class="count">{{ round }}</strong>
          </span>
        </BattleBand>

        <!-- §9.5.9 -- what the weapon just did, on the round it did it.
             A strip of its own rather than a mark tucked beside the round
             counter: a skill is the only thing in the exchange a player did not
             already expect, and it has to be readable at one round a second.
             The row reserves its height whether or not anything fired, so the
             pools underneath never jump. -->
        <div class="cast" :class="{ lit: firedName !== null }">
          <Transition name="fire">
            <span v-if="firedName" :key="`s${round}`" class="cast-inner">
              <SvgIcon v-if="firedGlyph" :svg="firedGlyph" class="cast-glyph" />
              <strong class="cast-name">{{ firedName }}</strong>
              <span v-if="firedEffect" class="cast-effect tiny">{{ firedEffect }}</span>
            </span>
            <!-- Most rounds have no skill, and reserved-and-empty read as a
                 hole once the rest of the plate was tightened. A hairline is
                 the house answer to that (it is what stands where the round
                 counter was on the receipt): the strip is the seam between WHO
                 is fighting and HOW IT IS GOING, and a skill breaks it open. -->
            <span v-else class="cast-rule" aria-hidden="true" />
          </Transition>
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

        <!-- §9.5.9 -- the weapon's three, under the pools they are spending. -->
        <BattleSkillRail class="arts" :skills="job.skills" :log="rounds" :round="round" />

      </div>
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
}

/* No caption under the pools. A line explaining what the bars mean is read
   once and read past forever, and the bars are the whole plate -- so the last
   thing on it is the thing being watched. */
.inner {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 15px 16px 16px;
}

/* The seam the whole plate is built around. */
.tick {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1px;
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

/* §9.5.9 -- the skill strip. Fixed height so nothing below it moves when a
   skill lands; the copper is borrowed rather than owned, because §13.3 spends
   ember on a state to deal with and sap on a thing worth crossing the screen
   for, and a skill firing is neither.

   It is `.cast` rather than `.art` because the cooldown dials underneath were
   ALSO called `.art`: same specificity, declared later, so the dials' 30px
   grid box quietly won and this strip was drawn as a square. */
.cast {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 26px;
  margin: 0 0 2px;
  border-top: 1px solid transparent;
  border-bottom: 1px solid transparent;
  color: var(--copper);
}

.cast.lit {
  border-color: rgba(193, 121, 63, 0.35);
}

.cast-rule {
  width: 46px;
  height: 1px;
  background: var(--line);
}

.cast-inner {
  display: flex;
  align-items: center;
  gap: 7px;
  min-width: 0;
}

.cast-glyph {
  flex: 0 0 auto;
  display: block;
}

.cast-name {
  font-size: 12px;
  letter-spacing: 0.03em;
  white-space: nowrap;
}

.cast-effect {
  color: var(--vellum-dim);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.fire-enter-active {
  transition: opacity 140ms ease, transform 140ms ease;
}

.fire-leave-active {
  transition: opacity 100ms ease;
}

.fire-enter-from {
  opacity: 0;
  transform: scale(0.9);
}

.fire-leave-to {
  opacity: 0;
}

@media (max-width: 380px) {
  .cast-effect {
    display: none;
  }
}

.arts {
  margin-top: 4px;
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

/* What is LEFT sits against the seam, so the two remaining pools can be
   compared across the middle.

   What was just TAKEN is out of the flow entirely: it travels from the seam to
   the outside edge (below), and a figure in the flow would shove the readout
   about for the beat the old blow and the new one overlap. */
.under {
  position: relative;
  display: flex;
  align-items: baseline;
  gap: 8px;
  min-height: 18px;
}

.pool.left .under {
  justify-content: flex-end;
}

.pool.right .under {
  justify-content: flex-start;
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
  height: 13px;
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

/* The blow comes off the seam and flies out, the way the fill under it is
   draining: it is struck in the middle, where the exchange happens, and it is
   read at the edge, beside the pool it came out of. Falling upward from below
   said nothing about which side lost it.

   Out of the flow, and starting where the remaining figure sits -- but at zero
   opacity, so it fades in already moving and never lands on top of the number
   it just changed. */
.blow {
  position: absolute;
  top: 0;
  font-family: var(--font-display);
  font-size: 14px;
  line-height: 18px;
  color: var(--ember);
  pointer-events: none;
}

.pool.left .blow {
  left: 0;
}

.pool.right .blow {
  right: 0;
}

.hit-enter-active {
  transition: opacity 0.16s ease, transform 0.34s cubic-bezier(0.16, 0.84, 0.34, 1);
}

.hit-enter-from {
  opacity: 0;
}

.pool.left .hit-enter-from {
  transform: translateX(44px);
}

.pool.right .hit-enter-from {
  transform: translateX(-44px);
}

/* It keeps going as it goes out, rather than stopping dead and dimming. */
.hit-leave-active {
  transition: opacity 0.16s ease, transform 0.16s ease;
}

.hit-leave-to {
  opacity: 0;
}

.pool.left .hit-leave-to {
  transform: translateX(-7px);
}

.pool.right .hit-leave-to {
  transform: translateX(7px);
}

@media (prefers-reduced-motion: reduce) {
  .fill,
  .hit-enter-active,
  .hit-leave-active,
  .fire-enter-active,
  .fire-leave-active {
    transition: none;
  }
}

</style>
