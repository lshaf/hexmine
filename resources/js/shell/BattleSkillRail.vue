<script setup lang="ts">
/**
 * §9.5.9 -- the weapon's three, and where each of them is.
 *
 * The cooldowns are SHOWN rather than described. A skill is dim until its round
 * comes, fills as it comes round, and flares on the round it goes -- so
 * "everything starts on cooldown and a rout never sees one" is a thing a player
 * watches happen rather than a sentence they were asked to believe.
 *
 * IT COMES ROUND A HEXAGON, because everything in this game does. The map tiles
 * with them (§13.2), the icon frames are cut from them (§13.1), the screens
 * block nests as a honeycomb (§13.3), and `--hex-clip` is the one shape the
 * whole interface is built on. A circle here was a foreign object -- the only
 * round thing on a plate full of cut corners -- and it read as borrowed from
 * some other app.
 *
 * So the sweep travels the SIX EDGES: one flat-top hexagon drawn twice, the
 * second dashed by `pathLength` so it uncovers itself edge by edge from the top
 * middle, clockwise. Not a circle wearing a hex mask -- the stroke is genuinely
 * hexagonal, which is the difference between borrowing the shape and being made
 * of it.
 *
 * Not a bar of buttons: a fight cannot be steered once it starts (§9.5.3), so
 * this reports rather than offers.
 *
 * Shared with the bench and the pin preview, where `round` is 0 and every hex
 * is simply cold -- which is the honest picture of a fight that has not begun.
 */
import { computed } from 'vue'
import SvgIcon from '@/components/SvgIcon.vue'
import { skillTurns, type SkillLike } from '@/game/battle'
import type { BattleRound } from '@/game/types'

/**
 * Flat-top hexagon, points left and right -- the same orientation as
 * `--hex-clip` and as `hexFrame()` in the icon set, so a cooldown sits in a
 * room full of hexes rather than beside them.
 *
 * Drawn CLOCKWISE FROM THE TOP MIDDLE so the sweep starts where a clock starts.
 * `Z` closes the left half of the top edge, which is the last thing to fill.
 */
const HEX = 'M13 1.97 L18.5 1.97 L24 11.5 L18.5 21.03 L7.5 21.03 L2 11.5 L7.5 1.97 Z'

/**
 * The hexagon's own perimeter, in the viewBox's units: six sides of R = 11.
 *
 * Used straight, rather than normalising the path to 100 with `pathLength`.
 * That attribute is camelCase in SVG and there is no `path-length` -- so
 * written the HTML way it is silently ignored, the dash runs against the real
 * 66 and an offset scaled to 100 draws every cooldown far fuller than it is.
 * A number that is simply true needs no attribute to agree with it.
 */
const RING = 66

const props = withDefaults(
  defineProps<{
    skills: SkillLike[] | undefined
    log?: BattleRound[]
    round?: number
  }>(),
  { log: () => [], round: 0 },
)

const turns = computed(() => skillTurns(props.skills, props.log, props.round))
</script>

<template>
  <div v-if="turns.length" class="cooldowns">
    <span
      v-for="turn in turns"
      :key="turn.key"
      class="cell"
      :class="{ ready: turn.ready, firing: turn.firing }"
      :title="turn.effect ? `${turn.name} — ${turn.effect}` : turn.name"
    >
      <span class="shape">
        <svg class="ring" viewBox="0 0 26 23" aria-hidden="true">
          <!-- The face, dark until the round it goes. -->
          <path class="face" :d="HEX" />
          <!-- What is left to wait, and what has come round. -->
          <path class="track" :d="HEX" />
          <path
            class="turned"
            :d="HEX"
            :stroke-dasharray="RING"
            :stroke-dashoffset="RING * (1 - turn.turn)"
          />
        </svg>
        <SvgIcon :svg="turn.svg" class="mark" />
      </span>
      <span class="wait mono" :class="{ hidden: turn.ready }">{{ turn.left }}</span>
    </span>
  </div>
</template>

<style scoped>
/* Named for what it holds rather than for its shape. `.rail` is a row the
   almanac and the bench both already own, and a component ROOT carries the
   parent's scope id as well as its own -- so a generic class name here is
   silently restyled by whoever mounts it. It was, and the three wrapped. */
.cooldowns {
  display: flex;
  justify-content: center;
  gap: 12px;
}

.cell {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  color: var(--line);
}

.shape {
  position: relative;
  display: grid;
  place-items: center;
  width: 32px;
  height: 28px;
}

.ring {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
}

.face {
  fill: rgba(0, 0, 0, 0.34);
  stroke: none;
  transition: fill 0.14s ease;
}

/* The uncovered part, and it has to stay legible as a HEXAGON: at 30% round,
   the copper is a third of an outline and the track is the only thing saying
   what shape it is a third of. It was darker to keep the dimmed sweep distinct
   from it -- now that the sweep is full-strength copper there is nothing to
   keep apart, so this goes back to the interface's own hairline. */
.track {
  fill: none;
  stroke: var(--line);
  stroke-width: 1.6;
  stroke-linejoin: round;
}

/* The sweep, at full strength whether or not the skill is ready.
   It was dimmed while cooling, which was the wrong economy: what a player wants
   off this shape is HOW FAR ROUND it has come, and fading the copper toward the
   track colour is exactly the thing that makes the gap unreadable. Two skills
   at 79% and 92% were indistinguishable. Readiness is told by the mark and the
   face instead -- the sweep only ever reports the clock. */
.turned {
  fill: none;
  stroke: var(--copper);
  stroke-width: 1.6;
  stroke-linejoin: round;
  transition: stroke-dashoffset 0.16s linear;
}

.mark {
  position: relative;
  display: block;
}

/* Under the hex rather than notched into it: the bottom-right of a hexagon is
   a slope, and a numeral pinned to a slope sits in open air. It is the useful
   half on a preview, where nothing is moving and "eight rounds off" is real
   information; in a replay at one round a second the sweep says it better. */
.wait {
  font-size: 9px;
  line-height: 1;
  color: var(--vellum-dim);
  font-variant-numeric: tabular-nums;
}

/* Held rather than removed, so three hexes never shuffle sideways the moment
   one of them comes round. */
.wait.hidden {
  visibility: hidden;
}

.cell.ready {
  color: var(--copper);
}

/* The round it actually goes: the face fills and the mark inverts. §13.3 keeps
   ember for a state to deal with, so this takes copper -- it is the brightest
   thing on the plate for one beat, and it is not a warning. */
.cell.firing {
  color: #17110c;
}

.cell.firing .face {
  fill: var(--copper);
}

.cell.firing .track,
.cell.firing .turned {
  stroke: var(--copper);
}

@media (prefers-reduced-motion: reduce) {
  .face,
  .turned {
    transition: none;
  }
}

/* The mark is what says READY. Dim while the clock runs, copper when it is up,
   ink on a copper face for the one beat it goes -- three states on the thing
   the eye is already on, so the sweep never has to carry two jobs. */

@media (max-width: 380px) {
  .cooldowns {
    gap: 9px;
  }

  .shape {
    width: 28px;
    height: 25px;
  }
}
</style>
