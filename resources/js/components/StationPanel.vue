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
import { MATERIALS, RECIPES, SKILL_BY_KEY, recipesForLines } from '@/game/catalog'
import { formatSpan, processingTime } from '@/game/formulas'
import { PROCESSING } from '@/game/balance'
import { materialIcon } from '@/icons/procedural'
import SvgIcon from './SvgIcon.vue'
import QueueBar from './QueueBar.vue'
import type { Recipe, Settlement } from '@/game/types'

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
 * You help one job at a time. Helping is standing there (§6.2), and a person
 * stands in one place -- so a second queued line would be a bonus nobody is
 * present for.
 */
const helping = computed(() => game.processingJob)

/** Whether the job being helped with is the one running *here*. */
const helpingHere = computed(() => helping.value?.settlementId === props.settlement.id)

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
      <div v-else-if="helping" class="notice tiny">
        You are already helping with a job. Collect it before starting another —
        you can only stand in one place.
      </div>

      <div v-for="recipe in available" :key="recipe.key" class="recipe">
        <SvgIcon :svg="materialIcon(MATERIALS[recipe.output], 26)" boxed :size="26" />
        <div class="grow">
          <div class="row-between">
            <strong class="tiny">{{ recipe.name }}</strong>
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
        <button
          class="btn btn-sm"
          type="button"
          :disabled="
            game.busy || !onSite || Boolean(helping) || freeSlots === 0
              || maxBatches(recipe) < batches
          "
          @click="game.startProcessing(settlement.id, recipe.key, batches)"
        >
          Queue
        </button>
      </div>

      <div v-if="onSite && available.length" class="batch">
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
  border-radius: var(--radius-sm);
  background: var(--ink);
  border: 1px solid var(--line);
}

.presence.on {
  border-color: #6b5a26;
  background: #221e14;
}

.recipe {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border-radius: var(--radius-sm);
  background: var(--ink);
  border: 1px solid var(--line);
}

.recipe + .recipe {
  margin-top: 6px;
}

.notice {
  padding: 7px 10px;
  border-radius: var(--radius-sm);
  background: var(--ink);
  border: 1px solid var(--line);
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
  border-radius: var(--radius-sm);
  border: 1px solid var(--line);
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
