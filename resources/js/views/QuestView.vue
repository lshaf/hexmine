<script setup lang="ts">
/**
 * The ledger, §12.
 *
 * Three tabs. **Today** is §12.2's three and is dealt with below; **pending** is
 * everything still owed to you -- in progress, or finished and waiting to be
 * claimed -- and **completed** is everything already paid. Those two are the
 * only states a quest is ever in that are worth a player's attention: there is
 * no tab for "locked", because a quest whose prerequisite is unclaimed is not
 * sent at all, so what is next is legible and what comes after it is not yet
 * anybody's problem.
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
 * §12.2's three get a **tab of their own**, and it is the first one.
 *
 * They used to sit above the tabs, on the argument that Pending and Completed
 * are two states of one ledger while the day's three are a different ledger —
 * so one tab row for all three would make a category out of two things that
 * are not the same kind of thing. The argument was right about what they *are*
 * and wrong about what a screen is for: stacked, the day sat on top of however
 * many quests happened to be out, so the half of the page that expires was the
 * half you had to scroll a quest list to get past. A tab is not a claim that
 * two things are the same kind of thing. It is a way to be looking at one of
 * them.
 *
 * It is first, and it opens first, because that is the half with a deadline on
 * it. A quest waits forever; today does not. Losing that ordering is the one
 * way this change could have been a downgrade, so the default tab carries it.
 */
import { computed, onMounted, ref } from 'vue'
import { useGame } from '@/stores/game'
import { ITEM_BY_KEY, MATERIALS, SKILL_BY_KEY, SLOT_LABEL } from '@/game/catalog'
import { formatSpan } from '@/game/formulas'
import type { DailyGrade } from '@/api/types'
import type { EquipSlot, MaterialKey, SkillKey } from '@/game/types'

const game = useGame()

onMounted(() => game.loadQuests())

type Tab = 'today' | 'pending' | 'completed'

/**
 * §12.2 -- the day opens first, because it is the half that expires.
 *
 * Not conditional on there being anything left to claim: three spent rows with
 * their bars full is a day going well, and a screen that quietly showed you a
 * different tab depending on how your morning went would be a screen you cannot
 * learn.
 */
const tab = ref<Tab>('today')

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
const today = computed<Array<Row & { lane: string; grade: DailyGrade }>>(() => {
  const defs = game.dailyDefs
  if (!defs) return []

  return game.dailies
    .filter((d) => defs[d.key])
    .map((d) => {
      const def = defs[d.key]!

      return {
        key: d.key,
        lane: d.lane,
        grade: d.grade,
        name: def.name,
        description: def.description,
        // §12.2 -- the TARGET and the GOLD come off the day, never off the
        // catalog. The pool ships once for everybody and holds the `B` version
        // of every task; what today asks of this character is a graded figure,
        // and drawing the catalog's would put a bar against the wrong number
        // and a reward on the button that the server will not pay.
        goal: goalLabel(def.goal.kind, def.goal.subject, d.target),
        progress: d.progress,
        target: d.target,
        percent: Math.min(100, (d.progress / Math.max(1, d.target)) * 100),
        gold: d.gold,
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

/** How many of the day's three are finished and still owed. Drives the tally. */
const dailiesReady = computed(() => today.value.filter((r) => r.ready).length)

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
      <!-- §3.2 -- what this ledger has paid out so far. Gold and only gold: a
           quest that paid a material would be a hole in §2 rather than a nicer
           reward, so there is only ever one figure to total.

           It totals the QUEST ledger, so it is not drawn over the day's three:
           "a quest pays once and never comes back" is a true sentence in the
           wrong room when what you are looking at is the thing that comes back
           tomorrow. -->
      <div v-if="tab !== 'today'" class="inset purse">
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
        <!-- §12.2 -- first, and open by default: the half with a deadline. -->
        <button
          type="button"
          role="tab"
          class="tab"
          :class="{ on: tab === 'today' }"
          :aria-selected="tab === 'today'"
          @click="tab = 'today'"
        >
          Today
          <span class="tally" :class="{ ready: dailiesReady > 0 }">{{ today.length }}</span>
        </button>
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

      <!--
        ----------------------------------------------------------- today

        §12.2 -- a daily draws as the same row a quest does, because it is the
        same object minus the chain: one goal, one counter, one figure in gold.
        So it gets a quest's own inset rather than sharing one block with
        dividers between them. That block existed to fence the day off from the
        quest list; the tab does that now, and drawing them differently would
        be claiming they behave differently when the only thing that does is
        when they reset.
      -->
      <template v-if="tab === 'today'">
        <div class="row-between day-head">
          <span class="tiny muted">One from each lane. Unclaimed gold goes with the day.</span>
          <span class="tiny muted mono nowrap">resets in {{ resetsIn }}</span>
        </div>

        <div
          v-for="row in today"
          :key="row.key"
          class="inset quest daily"
          :class="{ ready: row.ready, spent: row.claimed }"
        >
          <div class="row-between">
            <strong class="name">
              <!-- §12.2 -- how big today's version is, beside where it can be
                   done. Two tags because they answer two questions: the lane is
                   *can I do this from here* and the grade is *what is it going
                   to cost me*. -->
              <span class="grade" :class="`g-${row.grade}`">{{ row.grade }}</span>
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

        <p v-if="!today.length" class="tiny muted hint">
          Today's three have not been written up yet. They arrive with the day.
        </p>

        <p v-else class="tiny muted hint">
          The field task is workable from whatever hex you are standing on — no
          daily ever names a material, a line or a biome, because the map takes
          days to cross.
        </p>
      </template>

      <!-- --------------------------------------------------------- pending -->
      <template v-else-if="tab === 'pending'">
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

/*
 * The one line the day needs that a quest does not: when it turns over.
 *
 * It sits above the three rather than on each of them, because it is a fact
 * about the day and not about any one task -- and it is read off the server's
 * own figure, since a day runs through `Balance::scaled()` and is whatever the
 * environment says it is.
 */
.day-head {
  align-items: baseline;
  margin-bottom: 10px;
  gap: 12px;
}

.nowrap {
  white-space: nowrap;
}

/*
 * A claimed daily stays on the list until the day turns rather than vanishing.
 * Three rows that quietly become two would read as something having gone wrong;
 * a finished one greyed out with its bar full reads as a day going well.
 */
.daily.spent {
  opacity: 0.55;
}

/*
 * §12.2 -- the grade, C through S.
 *
 * It leads the row because it is what the row is *sized* by, and it climbs in
 * weight rather than in hue: C and B are the ordinary days and are drawn like
 * any other label, A lifts to vellum, and S is the only one that takes a colour
 * -- gold, because §13.3 spends gold on the currency itself and an S day is
 * exactly a day worth more. Ember would read as an alarm over good news and sap
 * is what *finished* means on this very screen, three lines down.
 */
.grade {
  display: inline-block;
  min-width: 15px;
  margin-right: 6px;
  padding: 1px 4px;
  font-family: var(--font-display);
  font-size: 10px;
  line-height: 1.3;
  text-align: center;
  color: var(--vellum-dim);
  background: rgba(0, 0, 0, 0.4);
  vertical-align: 1px;
}

.grade.g-A {
  color: var(--vellum);
}

.grade.g-S {
  color: var(--gold);
  background: rgba(216, 179, 74, 0.14);
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
</style>
