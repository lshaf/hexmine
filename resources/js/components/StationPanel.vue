<script setup lang="ts">
/**
 * Settlement processing, §6.
 *
 * Settlements are SHARED world locations, not personal bases -- the player owns
 * nothing here and places nothing. The five-slot public queue is genuinely
 * first-come-first-served, so a busy capital really does lock you out (§6.1).
 */
import { computed, ref, watch } from 'vue'
import { useGame } from '@/stores/game'
import { MATERIALS, RECIPES, SKILL_BY_KEY, SKILL_LIST, recipesForLines } from '@/game/catalog'
import { formatSpan, processingTime } from '@/game/formulas'
import { PROCESSING } from '@/game/balance'
import { materialIcon } from '@/icons/procedural'
import SvgIcon from './SvgIcon.vue'
import QueueBar from './QueueBar.vue'
import JobCard from './JobCard.vue'
import SlateMark from './SlateMark.vue'
import type { MaterialKey, Recipe, Settlement, SkillKey } from '@/game/types'

const props = defineProps<{ settlement: Settlement }>()
const game = useGame()

const batches = ref(1)

const station = computed(() => game.station)

const onSite = computed(() => {
  const char = game.character
  return Boolean(char && char.col === props.settlement.col && char.row === props.settlement.row)
})

const present = computed(() => game.state?.presenceAt === props.settlement.id)

const available = computed(() => recipesForLines(props.settlement.lines))

/**
 * §6 -- one tab per line this settlement runs.
 *
 * A settlement's tier IS a count of lines -- a village runs one of the five, a
 * city two, a capital all five -- so the lines are the one axis this panel has,
 * and merging them threw it away. At a capital that was five ladders in one
 * column with nothing between them: the saw pit, the smelter, the tannery, the
 * masons and the loom, read as a single list of fifteen rows.
 *
 * Ordered by SKILL_LIST rather than by the settlement's own array, so the same
 * two lines sit in the same order at every city that runs them.
 */
const lines = computed(() =>
  SKILL_LIST.filter((s) => props.settlement.lines.includes(s.key)),
)

const line = ref<SkillKey>(lines.value[0]?.key ?? 'woodcutting')

// Walking into a different settlement can land on a tab that does not exist
// here -- a Sawyer's town after a Weaver's. The first line it runs is the one
// that is always there.
watch(
  lines,
  (open) => {
    if (!open.some((s) => s.key === line.value)) line.value = open[0]?.key ?? 'woodcutting'
  },
  { immediate: true },
)

const shown = computed(() => available.value.filter((r) => r.skill === line.value))

/** Lines this settlement does NOT run. Deduped by skill: several recipes can
 *  share one line (ingots and reinforced frames are both Mining), and listing
 *  the line twice reads as a bug. */
const missingLines = computed(() => {
  const absent = new Set(RECIPES.map((r) => r.skill))
  for (const line of props.settlement.lines) absent.delete(line)
  return [...absent]
})

const freeSlots = computed(
  () => station.value?.slots.filter((s) => s.owner === null).length ?? 0,
)

/**
 * §6.2 -- the run you are actually standing over, if any.
 *
 * Helping is standing there, and a person stands in one place, so this is the
 * run at THIS settlement rather than the first one the character owns. It used
 * to be the latter, back when a character could only have one run out anywhere;
 * now that work is left all over the map (§8.4), "your job" without a place
 * attached is a question with several answers.
 */
const helpingHere = computed(() => mine.value.some((j) => j.endsAt > game.now))

/**
 * §8.4 -- your own work parked in THIS building, finished or not.
 *
 * It is on this screen because this screen is the building. The Benches panel
 * (§8.4) answers "where is my work" across the whole map, which is a route to
 * plan; standing in the doorway the question is the narrower one -- what is
 * mine here, and can I take it -- and being sent to another screen to answer it
 * while the bench is under your feet is a walk to a menu.
 *
 * Finished first, because that is the news and the only part that is a tap.
 */
const mine = computed(() =>
  game.benchJobs
    .filter((j) => j.kind === 'processing' && j.settlementId === props.settlement.id)
    .sort((a, b) => a.endsAt - b.endsAt),
)

const readyHere = computed(() => mine.value.filter((j) => j.endsAt <= game.now))

/**
 * §6.3 -- your own allowance on one line here, spent or not.
 *
 * Two refusals rather than one, because they are two different things in the
 * way: `freeSlots` is a stranger's run and this is your own. Telling a player
 * to wait for somebody else when the thing blocking them is their own pit is
 * the worse of the two wrong answers.
 */
function runsLeft(recipe: Recipe): number {
  const line = station.value?.runs?.[recipe.skill]
  if (!line) return 1

  return Math.max(0, line.allowed - line.going)
}

/** §6.1 + §8.4 -- and the ceiling on work parked anywhere at all. */
const workFull = computed(
  () => (station.value?.outstanding ?? 0) >= (station.value?.outstandingCap ?? Infinity),
)

function maxBatches(recipe: Recipe): number {
  const first = Math.floor(game.held(recipe.input) / recipe.inputQty)
  if (!recipe.secondInput) return first
  return Math.min(first, Math.floor(game.held(recipe.secondInput) / (recipe.secondInputQty ?? 1)))
}

/**
 * Predicted queue time. The formula is the honest one; the server may compress
 * timers for development, so the prediction is divided by the scale it reports.
 */
function duration(recipe: Recipe, count: number): string {
  const seconds = processingTime(
    recipe.baseSeconds * count,
    props.settlement.tier,
    present.value,
    game.bonuses?.processingSpeed ?? 0,
  )
  return formatSpan((seconds * 1000) / game.timeScale)
}

const TIER_NOTE: Record<Settlement['tier'], string> = {
  village: 'Runs one line. Slowest, cheapest.',
  city: 'Runs two lines. Moderate speed.',
  capital: 'Runs all five lines. Fastest, and next to the dungeons.',
}

// Reset the batch stepper whenever the player opens a different settlement.
watch(() => props.settlement.id, () => { batches.value = 1 })
</script>

<template>
  <div class="stack">
    <div class="head-row">
      <span class="chip" :class="settlement.tier === 'capital' ? 'chip-gold' : ''">
        {{ settlement.tier }}
      </span>
      <span class="tiny muted">{{ TIER_NOTE[settlement.tier] }}</span>
    </div>

    <!-- Public queue, §6.1 -->
    <QueueBar
      label="Public queue"
      :slots="station?.slots ?? []"
      full-note="Every slot is busy. Congestion at popular settlements is intended — try a quieter village, or wait."
    />

    <!--
      §8.4 -- your own runs at this bench.
      Above the recipe list, because collecting what is finished is what you
      came back for; queueing more is what you do next.
    -->
    <div v-if="mine.length" class="mine">
      <div class="label" style="margin-bottom: 6px">
        Your runs here
        <span v-if="readyHere.length" class="tally ready">{{ readyHere.length }} ready</span>
      </div>

      <!--
        The card carries its own Collect and its own Abandon, so this adds a
        line and no second button: two Collects side by side is one decision
        drawn twice.

        What the line is for is the case the card cannot know about -- a run
        that is finished at a settlement you are looking at from somewhere else.
        §8.4 hands work over only to somebody standing there.
      -->
      <div v-for="job in mine" :key="job.id" class="run">
        <JobCard :job="job" />
        <p v-if="job.endsAt <= game.now && !onSite" class="tiny foot muted">
          Finished — but it is handed over at the bench, and you are not there.
        </p>
      </div>
    </div>

    <!-- Presence bonus, §6.2. A readout, not a control: presence is simply
         where you are standing, so there is nothing here to switch on. -->
    <div v-if="onSite" class="presence on">
      <div class="grow">
        <strong class="tiny">
          {{ helpingHere ? 'Helping' : 'Presence bonus' }} —
          {{ Math.round(PROCESSING.presenceSpeedBonus * 100) }}% faster
        </strong>
        <p class="tiny muted" style="margin: 2px 0 0">
          The line runs whether you are here or not. Staying shortens what is
          left of it and earns skill XP; walk away and the remaining time goes
          back up.
        </p>
      </div>
    </div>

    <!-- Lines this settlement runs -->
    <div>
      <div class="label" style="margin-bottom: 6px">Processing lines</div>
      <div v-if="!onSite" class="notice tiny">
        Travel here to queue work.
      </div>
      <div v-else-if="workFull" class="notice tiny">
        You have {{ station?.outstanding }} lots of work out across the map.
        Collect one before leaving another behind.
      </div>

      <!-- §6 -- one tab a line, because a settlement's tier IS a count of
           lines. Drawn only where there is a choice: a village runs one of the
           five, and a single tab is a label pretending to be a control. -->
      <nav v-if="lines.length > 1" class="lines" role="tablist">
        <button
          v-for="s in lines"
          :key="s.key"
          class="line"
          type="button"
          role="tab"
          :class="{ on: line === s.key }"
          :aria-selected="line === s.key"
          @click="line = s.key"
        >
          <SvgIcon :svg="materialIcon(MATERIALS[s.material as MaterialKey]!, 16)" />
          {{ s.name }}
        </button>
      </nav>

      <div v-for="recipe in shown" :key="recipe.key" class="recipe">
        <SvgIcon :svg="materialIcon(MATERIALS[recipe.output], 26)" boxed :size="26" />
        <div class="grow">
          <div class="row-between">
            <!-- §6 -- a run is named for what comes OFF it, not for what is
                 done to make it. "Saw Planks" put a verb where every other
                 list in the game puts the thing: the icon beside it is the
                 output's, the arrow under it ends on the output, and the
                 material that lands in the bag is the output -- so the row
                 was the one part of it naming something else. -->
            <strong class="tiny">{{ MATERIALS[recipe.output].name }}</strong>
            <span class="tiny mono muted">{{ duration(recipe, batches) }}</span>
          </div>
          <div class="tiny muted">
            {{ recipe.inputQty * batches }} {{ MATERIALS[recipe.input].name }}
            <template v-if="recipe.secondInput">
              + {{ (recipe.secondInputQty ?? 1) * batches }} {{ MATERIALS[recipe.secondInput].name }}
            </template>
            → {{ recipe.outputQty * batches }} {{ MATERIALS[recipe.output].name }}
          </div>
        </div>
        <!-- §8.4 -- a line runs at a bench somewhere on the map, and this is
             what remembers which one you meant. It is the one control here
             that works when you are not standing on the settlement. -->
        <SlateMark :recipe="recipe.key" />
        <button
          class="btn btn-sm"
          type="button"
          :disabled="
            game.busy || !onSite || workFull || runsLeft(recipe) === 0 || freeSlots === 0
              || maxBatches(recipe) < batches
          "
          :title="
            runsLeft(recipe) === 0
              ? `You already have ${MATERIALS[recipe.output].name} going here. Collect it first.`
              : undefined
          "
          @click="game.startProcessing(settlement.id, recipe.key, batches)"
        >
          Queue
        </button>
      </div>

      <div v-if="onSite && shown.length" class="batch">
        <span class="tiny muted">Batches</span>
        <div class="stepper">
          <button type="button" :disabled="batches <= 1" @click="batches--">−</button>
          <span class="mono">{{ batches }}</span>
          <button type="button" :disabled="batches >= 10" @click="batches++">+</button>
        </div>
      </div>
    </div>

    <!-- §6: village/city players are always missing lines, which keeps them
         dependent on other systems. Say so plainly rather than hiding it. -->
    <div v-if="missingLines.length" class="missing tiny">
      Not run here:
      <span class="muted">
        {{ missingLines.map((key) => SKILL_BY_KEY[key].name).join(' · ') }}
      </span>
    </div>
  </div>
</template>

<style scoped>
/* Your own work, set apart from the shared queue above it: one is a fact about
   the building, the other is a list of things to pick up. */
.mine {
  padding: 11px;
  border: 1px solid var(--line);
  background: var(--ink);
}

.run + .run {
  margin-top: 9px;
  padding-top: 9px;
  border-top: 1px solid var(--line);
}

.foot {
  margin-top: 5px;
}

/* §13.3 -- sap for a thing worth crossing the screen for, which a finished run
   is. Ember would read as a warning about work that went right. */
.ready {
  color: var(--sap);
}

.tally {
  margin-left: 6px;
  color: var(--vellum-dim);
}

.tally.ready {
  color: var(--sap);
}

.head-row {
  display: flex;
  align-items: center;
  gap: 9px;
}

.presence {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 11px;
  /* §13 -- the standard cut, and no line: a border under a clip-path paints on
     the box and the clip takes the corner with it. The darker fill separates
     this from the panel on its own. */
  clip-path: var(--plate-clip);
  background: var(--ink);
}

.presence.on {
  border-color: #6b5a26;
  background: #221e14;
}

/* §6 -- the lines this settlement runs, as a row of tabs.
 *
 * Chamfered and quiet like every other preference control (§13): choosing a
 * line changes nothing about the world, so it is drawn the way the bag's sort
 * chips are rather than as something that acts. The chosen one is lit by its
 * ground, never by a border -- §13 is explicit that a border under a clip-path
 * does not follow the cut. */
.lines {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-bottom: 8px;
}

.line {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 9px;
  background: rgba(0, 0, 0, 0.28);
  clip-path: var(--plate-clip);
  border: 0;
  color: var(--vellum-dim);
  font: inherit;
  font-size: 11px;
  cursor: pointer;
}

.line.on {
  background: var(--line);
  color: var(--vellum);
}

.line :deep(svg) {
  display: block;
}

.recipe {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  /* §13 -- the standard cut, and no line: a border under a clip-path paints on
     the box and the clip takes the corner with it. The fill separates it. */
  clip-path: var(--plate-clip);
  background: var(--ink);
}

.recipe + .recipe {
  margin-top: 6px;
}

.notice {
  padding: 7px 10px;
  /* §13 -- the standard cut, and no line: a border under a clip-path paints on
     the box and the clip takes the corner with it. The fill separates it. */
  clip-path: var(--plate-clip);
  background: var(--ink);
  color: var(--vellum-dim);
  margin-bottom: 7px;
}

.batch {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 8px;
}

.stepper {
  display: flex;
  align-items: center;
  gap: 10px;
}

.stepper button {
  width: 26px;
  height: 26px;
  /* §13 -- the standard cut, and no line: a border under a clip-path paints on
     the box and the clip takes the corner with it. The fill separates it. */
  clip-path: var(--plate-clip);
  background: var(--ink);
  color: var(--vellum);
  font-size: 15px;
  line-height: 1;
}

.stepper button:disabled {
  opacity: 0.4;
}

.missing {
  padding-top: 8px;
  border-top: 1px solid var(--line);
}
</style>
