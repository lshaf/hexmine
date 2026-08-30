<script setup lang="ts">
/**
 * The ledger, §12.
 *
 * Two tabs, because a quest is only ever in one of two states worth a player's
 * attention: **pending** is everything still owed to you -- in progress, or
 * finished and waiting to be claimed -- and **completed** is everything already
 * paid. There is no third tab for "locked": a quest whose prerequisite is
 * unclaimed is not sent at all, so what is next is legible and what comes after
 * it is not yet anybody's problem.
 *
 * Ready-to-claim sits at the top of pending and is the only thing on the screen
 * drawn in gold. A ledger where the payable row looks like the others is a
 * ledger you have to read rather than glance at.
 *
 * The catalog and the standing arrive separately -- static defs from
 * GET /quests, live progress from the player state -- and are joined here. That
 * is the same split the job trees use, and for the same reason: one of the two
 * halves never changes, so it should not ride every refresh.
 *
 * ------------------------------------------------------------- and the day's
 *
 * §12.2's three sit ABOVE the tabs rather than becoming a third one, and that
 * is the whole reason there is still no third tab. Pending and Completed are
 * two *states of one ledger*; the day's three are a *different ledger*, with a
 * different reset and a deadline on it. Putting all three in one tab row would
 * make a category out of two things that are not the same kind of thing.
 *
 * They read first because they are the half that expires. A quest waits
 * forever; today does not.
 */
import { computed, onMounted, ref } from 'vue'
import { useGame } from '@/stores/game'
import { ITEM_BY_KEY, MATERIALS, SKILL_BY_KEY, SLOT_LABEL } from '@/game/catalog'
import { formatSpan } from '@/game/formulas'
import { ACTION_PATHS } from '@/icons/actions'
import type { EquipSlot, MaterialKey, SkillKey } from '@/game/types'

const game = useGame()

onMounted(() => game.loadQuests())

type Tab = 'pending' | 'completed'
const tab = ref<Tab>('pending')

/**
 * What a goal is asking for, in the player's words.
 *
 * A goal is stored as a counter and a subject, which is the right shape for the
 * server and the wrong one for a person: "gather / iron_ore / 30" is a row in a
 * table, and "30 iron ore carried out" is a task. The description on the quest
 * says why; this says what, and it is the line the progress bar is measuring.
 */
function goalLabel(kind: string, subject: string | null, target: number): string {
  // A subject may name two different things for the same counter -- a craft goes
  // up as its item AND its bench, an equip as the item AND the slot -- so the
  // label asks what the string actually is rather than assuming.
  const item = subject ? ITEM_BY_KEY[subject] : undefined
  const slot = subject ? SLOT_LABEL[subject as EquipSlot] : undefined

  switch (kind) {
    case 'gather':
      return subject
        ? `${target} × ${MATERIALS[subject as MaterialKey]?.name ?? subject} carried out`
        : `${target} units brought back`
    case 'process':
      return subject
        ? `${target} refined off the ${SKILL_BY_KEY[subject as SkillKey]?.name.toLowerCase() ?? subject} bench`
        : `${target} refined off a bench`
    case 'craft':
      if (item) return `${target} × ${item.name} made`
      return subject ? `${target} made at the ${subject} bench` : `${target} made at a bench`
    case 'buy':
      if (item) return `${target} × ${item.name} bought`
      return slot ? `${target} × ${slot} bought` : `${target} bought from a trader`
    case 'equip':
      if (item) return `${item.name} on the belt`
      return slot ? `${slot} on the belt` : `${target} equipped`
    case 'travel':
      return `${target} hexes walked`
    case 'sell':
      return `${target} gold taken from traders`
    case 'level':
      return `Character level ${target}`
    case 'job':
      return `${subject ? subject[0]!.toUpperCase() + subject.slice(1) : 'Job'} level ${target}`
    default:
      return `${target}`
  }
}

interface Row {
  key: string
  name: string
  description: string
  goal: string
  progress: number
  target: number
  percent: number
  gold: number
  ready: boolean
  claimed: boolean
}

const rows = computed<Row[]>(() => {
  const defs = game.questDefs
  if (!defs) return []

  return game.quests
    .filter((q) => defs[q.key])
    .map((q) => {
      const def = defs[q.key]!

      return {
        key: q.key,
        name: def.name,
        description: def.description,
        goal: goalLabel(def.goal.kind, def.goal.subject, def.goal.target),
        progress: q.progress,
        target: def.goal.target,
        percent: Math.min(100, (q.progress / Math.max(1, def.goal.target)) * 100),
        gold: def.gold,
        ready: q.complete && !q.claimed,
        claimed: q.claimed,
      }
    })
})

/**
 * §12.2 -- today's three, in lane order.
 *
 * Built into the same Row shape as a quest, because a daily *is* the same
 * object minus the chain: one goal, one counter, one figure in gold. Anything
 * that made the two rows look different would be saying they behave
 * differently, and the only thing that does is when they reset.
 */
const today = computed<Array<Row & { lane: string }>>(() => {
  const defs = game.dailyDefs
  if (!defs) return []

  return game.dailies
    .filter((d) => defs[d.key])
    .map((d) => {
      const def = defs[d.key]!

      return {
        key: d.key,
        lane: d.lane,
        name: def.name,
        description: def.description,
        goal: goalLabel(def.goal.kind, def.goal.subject, def.goal.target),
        progress: d.progress,
        target: def.goal.target,
        percent: Math.min(100, (d.progress / Math.max(1, def.goal.target)) * 100),
        gold: def.gold,
        ready: d.complete && !d.claimed,
        claimed: d.claimed,
      }
    })
})

/**
 * How long today has left, against the SERVER's clock.
 *
 * A day runs through `Balance::scaled()`, so its length is whatever the
 * environment says it is -- the client is told when the three turn over and
 * never works it out from a constant of its own.
 */
const resetsIn = computed(() => formatSpan(game.dailiesResetAt - game.now))

/** Payable first, then whatever is furthest along: the ledger reads as a queue. */
const pending = computed(() =>
  rows.value
    .filter((r) => !r.claimed)
    .sort((a, b) => Number(b.ready) - Number(a.ready) || b.percent - a.percent),
)

const completed = computed(() => rows.value.filter((r) => r.claimed))

const earned = computed(() => completed.value.reduce((n, r) => n + r.gold, 0))
</script>

<template>
  <div class="page">
    <div v-if="!game.questDefs" class="inset empty">
      <p class="tiny muted" style="margin: 0">Opening the ledger…</p>
    </div>

    <template v-else>
      <!-- §12.2 -- the day's three. Above the tabs rather than inside them:
           Pending and Completed are two states of one ledger, and this is a
           different ledger with a deadline on it. -->
      <div v-if="today.length" class="inset day">
        <div class="row-between day-head">
          <span class="label">Today</span>
          <span class="tiny muted mono">resets in {{ resetsIn }}</span>
        </div>

        <div
          v-for="row in today"
          :key="row.key"
          class="quest daily"
          :class="{ ready: row.ready, spent: row.claimed }"
        >
          <div class="row-between">
            <strong class="name">
              <span class="lane">{{ row.lane }}</span>
              {{ row.name }}
            </strong>
            <span class="reward readout" :class="{ paid: row.claimed }">{{ row.gold }}g</span>
          </div>

          <p class="tiny muted note">{{ row.description }}</p>

          <div class="row-between goal tiny">
            <span :class="row.ready || row.claimed ? 'done' : 'muted'">{{ row.goal }}</span>
            <span class="mono muted">{{ row.progress }}/{{ row.target }}</span>
          </div>

          <div class="bar" :class="row.ready || row.claimed ? 'bar-sap' : ''">
            <span :style="{ width: `${row.percent}%` }" />
          </div>

          <div v-if="row.ready" class="row-between foot">
            <span class="tiny done">Finished — the gold is waiting.</span>
            <button
              class="btn btn-sm btn-primary"
              type="button"
              :disabled="game.busy"
              @click="game.claimDaily(row.key)"
            >
              Claim
            </button>
          </div>
        </div>

        <p class="tiny muted day-note">
          Three a day, one from each lane, and the field one is workable from
          whatever hex you are standing on. Unclaimed gold goes with the day.
        </p>
      </div>

      <!-- §3.2 -- what this ledger has paid out so far. Gold and only gold: a
           quest that paid a material would be a hole in §2 rather than a nicer
           reward, so there is only ever one figure to total. -->
      <div class="inset purse">
        <div class="row-between">
          <div>
            <span class="label">Claimed</span>
            <div class="points">
              <strong>{{ earned }}</strong>
              <span class="tiny muted">gold off the ledger</span>
            </div>
          </div>
          <p class="tiny muted note">
            A quest pays once and never comes back.
          </p>
        </div>
      </div>

      <div class="tabs" role="tablist">
        <button
          type="button"
          role="tab"
          class="tab"
          :class="{ on: tab === 'pending' }"
          :aria-selected="tab === 'pending'"
          @click="tab = 'pending'"
        >
          Pending
          <span class="tally" :class="{ ready: game.questsReady > 0 }">{{ pending.length }}</span>
        </button>
        <button
          type="button"
          role="tab"
          class="tab"
          :class="{ on: tab === 'completed' }"
          :aria-selected="tab === 'completed'"
          @click="tab = 'completed'"
        >
          Completed
          <span class="tally">{{ completed.length }}</span>
        </button>
      </div>

      <!-- --------------------------------------------------------- pending -->
      <template v-if="tab === 'pending'">
        <div v-for="row in pending" :key="row.key" class="inset quest" :class="{ ready: row.ready }">
          <div class="row-between">
            <strong class="name">{{ row.name }}</strong>
            <span class="reward readout">{{ row.gold }}g</span>
          </div>

          <p class="tiny muted note">{{ row.description }}</p>

          <div class="row-between goal tiny">
            <span :class="row.ready ? 'done' : 'muted'">{{ row.goal }}</span>
            <span class="mono muted">{{ row.progress }}/{{ row.target }}</span>
          </div>

          <div class="bar" :class="row.ready ? 'bar-sap' : ''">
            <span :style="{ width: `${row.percent}%` }" />
          </div>

          <div v-if="row.ready" class="row-between foot">
            <span class="tiny done">Finished — the gold is waiting.</span>
            <button
              class="btn btn-sm btn-primary"
              type="button"
              :disabled="game.busy"
              @click="game.claimQuest(row.key)"
            >
              Claim
            </button>
          </div>
        </div>

        <p v-if="!pending.length" class="tiny muted hint">
          Nothing owed. Everything on the ledger has been paid — more will be
          written up as the map opens.
        </p>
      </template>

      <!-- ------------------------------------------------------- completed -->
      <template v-else>
        <div v-for="row in completed" :key="row.key" class="inset quest done-row">
          <div class="row-between">
            <strong class="name">{{ row.name }}</strong>
            <span class="reward readout paid">{{ row.gold }}g</span>
          </div>
          <p class="tiny muted note">{{ row.goal }}</p>
        </div>

        <p v-if="!completed.length" class="tiny muted hint">
          Nothing claimed yet. Finish something in Pending and the gold is yours.
        </p>
      </template>

      <p class="tiny muted footnote">
        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path :d="ACTION_PATHS.quest" />
        </svg>
        Nothing here asks for a new kind of work. Every goal counts something you
        were going to do anyway — a haul, a walk, a run at a bench, a sale.
      </p>
    </template>
  </div>
</template>

<style scoped>
.page {
  padding: 0;
}

.empty {
  padding: 22px 16px;
  text-align: center;
}

.purse {
  margin-bottom: 12px;
}

.points {
  display: flex;
  align-items: baseline;
  gap: 6px;
}

.points strong {
  font-family: var(--font-display);
  font-size: 22px;
  color: var(--gold);
}

.note {
  margin: 6px 0 0;
  line-height: 1.45;
  max-width: 190px;
  text-align: right;
}

/* ------------------------------------------------------------------ today */

.day {
  margin-bottom: 12px;
}

.day-head {
  margin-bottom: 9px;
}

.daily + .daily {
  margin-top: 9px;
  padding-top: 9px;
  border-top: 1px solid var(--line);
}

/*
 * A claimed daily stays on the block until the day turns rather than vanishing.
 * Three rows that quietly become two would read as something having gone wrong;
 * a finished one greyed out with its bar full reads as a day going well.
 */
.daily.spent {
  opacity: 0.55;
}

/*
 * The lane, in copper -- §13.3 spends copper on work in progress, which is
 * exactly what an errand with hours left on it is. It is a tag rather than a
 * sentence because it answers one question: can I do this from here.
 */
.lane {
  font-size: 9px;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--copper);
  margin-right: 6px;
  vertical-align: 1px;
}

.day-note {
  margin: 10px 0 0;
  line-height: 1.5;
}

/* ------------------------------------------------------------------- tabs */

.tabs {
  display: flex;
  gap: 6px;
  margin-bottom: 10px;
}

.tab {
  flex: 1 1 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  padding: 8px 10px;
  font-size: 12px;
  color: var(--vellum-dim);
  background: rgba(0, 0, 0, 0.28);
  border: 1px solid transparent;
  clip-path: polygon(8px 0, 100% 0, 100% calc(100% - 8px), calc(100% - 8px) 100%, 0 100%, 0 8px);
}

.tab.on {
  color: var(--vellum);
  border-color: var(--line);
  background: var(--ink-panel);
}

.tally {
  font-family: var(--font-display);
  font-variant-numeric: tabular-nums;
  font-size: 11px;
  padding: 1px 6px;
  background: rgba(0, 0, 0, 0.4);
  color: var(--vellum-dim);
}

/*
 * Finished reads GREEN, not gold and not ember.
 *
 * Ember is the color of something wrong -- a full bag, a broken tool -- and a
 * payout wearing it reads as an alarm going off over good news. Gold is taken
 * too: it is the currency itself, and it is on every reward figure on this
 * screen, so spending it on status as well would make "20g" and "done" the same
 * color saying two different things.
 */
.tally.ready {
  background: var(--sap);
  color: var(--ink);
}

/* ----------------------------------------------------------------- quests */

.quest {
  display: flex;
  flex-direction: column;
  gap: 7px;
}

.quest + .quest {
  margin-top: 7px;
}

.quest .note {
  max-width: none;
  text-align: left;
  margin: 0;
}

.quest.ready {
  background: rgba(143, 191, 127, 0.07);
}

.name {
  font-size: 13px;
}

.reward {
  font-size: 13px;
  color: var(--gold);
}

.reward.paid {
  color: var(--vellum-dim);
}

.goal {
  gap: 12px;
}

.done {
  color: var(--sap);
}

.foot {
  margin-top: 1px;
}

.done-row {
  opacity: 0.7;
}

.hint {
  margin: 14px 0 0;
  text-align: center;
  line-height: 1.5;
}

.footnote {
  display: flex;
  align-items: flex-start;
  gap: 7px;
  margin: 18px 0 0;
  line-height: 1.5;
}

.footnote svg {
  flex: 0 0 auto;
  margin-top: 2px;
  color: var(--copper);
}
</style>
