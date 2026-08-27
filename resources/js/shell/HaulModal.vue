<script setup lang="ts">
/**
 * What a finished mine brought back, §4.
 *
 * A haul used to be one stack, so a toast could carry it: "+7 Wood" said
 * everything there was to say. It is several stacks now, drawn from the hex's
 * own table, and a line of toast text would either truncate that or stack five
 * notifications up the screen.
 *
 * So the collect endpoint answers with **no message at all**, and this plate is
 * the entire report. The toast that used to ride alongside it was a leftover
 * from the one-stack days: the same news twice, and the worse of the two.
 *
 * This is the one moment in an idle game where something actually happened, and
 * it is the payoff for an hour of waiting -- so it gets a beat of its own
 * rather than sliding past in the corner.
 *
 * It dismisses on any click, anywhere, and carries no button. The haul is
 * already yours -- it landed in the bag before this was drawn -- so a control
 * saying "Take it" would be asking a question that has no other answer, and a
 * receipt does not need signing. Escape closes it too, for the keyboard.
 *
 * THE ASSAY BAR is the one thing here that is not a list. §7.6 settles the form
 * argument already: units are a quantity, so they are a bar, and rows are
 * places, so they are a comb. A haul is units -- one bar, split by what came
 * back, each share in that material's own accent. It says the *shape* of the
 * haul before a single figure is read, which is the difference between a payoff
 * and an inventory diff, and it is drawn from the game's own material palette
 * rather than from a chart library's idea of a color.
 *
 * Everything here is from the server's own response. The client never decides
 * what dropped, only how to show it (§16).
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ITEM_BY_KEY, MATERIALS, SKILL_BY_KEY } from '@/game/catalog'
import { optionStatLine } from '@/game/formulas'
import { itemIcon, materialAccent, materialIcon, skillIcon } from '@/icons/procedural'
import SvgIcon from '@/components/SvgIcon.vue'
import StatChips from '@/components/StatChips.vue'
import type { CollectResult } from '@/api/types'

const props = defineProps<{ haul: CollectResult }>()
const emit = defineEmits<{ (e: 'close'): void }>()

const rows = computed(() =>
  Object.entries(props.haul.gained ?? {})
    .map(([key, qty]) => {
      const mat = MATERIALS[key as keyof typeof MATERIALS]
      return { mat, qty: qty as number, accent: mat ? materialAccent(mat) : '' }
    })
    .filter((r) => r.mat)
    .sort((a, b) => b.qty - a.qty),
)

const total = computed(() => rows.value.reduce((n, r) => n + r.qty, 0))

const line = computed(() =>
  props.haul.xp?.skill ? (SKILL_BY_KEY[props.haul.xp.skill]?.name ?? null) : null,
)

/**
 * §7.4 -- the bench trade, named. The job keys ARE the words (smith, sawyer),
 * so there is no table to look them up in -- the same reading BattleModal does
 * for the three battle jobs.
 */
/**
 * §8.4 -- a bench hands over one thing, and the plate has to say so.
 *
 * A craft was being drawn as a haul: "0 units brought back", over copy telling
 * the player nothing fit and to work the hex again. Nothing had gone wrong and
 * there was no hex -- a craft simply does not pay in units, and the receipt was
 * reading the wrong half of the result.
 */
const made = computed(() => props.haul.made ?? null)

/**
 * §8.4 / §8.0.1 -- what came off the bench, as an OBJECT rather than a name.
 *
 * A craft receipt used to be a string and a durability figure over a row of XP.
 * That is the least a bench hands over: the thing has a rarity, a silhouette,
 * a pair or a work stat, and -- the part that actually makes an hour at the
 * anvil worth watching for -- whatever the bench rolled onto it.
 *
 * §8.0.1 is emphatic that the roll is the point: two of the same recipe are
 * never the same object, and this is the one moment a player finds out which
 * one they got. The server has always sent the lines. Nothing read them.
 */
const madeDef = computed(() => (made.value ? (ITEM_BY_KEY[made.value.key] ?? null) : null))

const madeRolls = computed(() => made.value?.options ?? [])

const madeIcon = computed(() => {
  const def = madeDef.value
  if (!def) return ''

  // §13.1 -- the slot picks the silhouette and rarity owns the frame. A potion
  // has no slot on purpose (§8.4), and falls back to the flask.
  return itemIcon({
    slot: def.slot,
    family: def.family,
    rarity: def.rarity,
    palette: def.palette,
    size: 42,
  })
})

/**
 * §7.4.3 -- how much of the durability was the Smith rather than the recipe.
 *
 * Printed only when a node actually moved it, because "60 durability" says
 * nothing about whether that was a good craft and "+6 over standard" says
 * exactly that. It is the one number on the plate the player earned twice.
 */
const overStandard = computed(() => {
  const base = madeDef.value?.maxDurability ?? 0
  const got = made.value?.durability ?? 0

  return base > 0 && got > base ? got - base : 0
})

const jobName = computed(() => {
  const key = props.haul.job
  return key && props.haul.jobXp ? key[0]!.toUpperCase() + key.slice(1) : null
})

const lineGlyph = computed(() =>
  props.haul.xp?.skill ? skillIcon(props.haul.xp.skill, 15) : '',
)

/**
 * One beat: the plate rises, the bar wipes across, the count runs up to what
 * you got. Scattered effects would read as decoration; one orchestrated arrival
 * is what makes an hour of waiting land.
 *
 * `settled` drives the CSS half of it. The count is the only part that needs
 * script, because a number ticking is not something a transition can express.
 *
 * IT STARTS AT THE ANSWER, and only drops to zero once a frame has actually
 * arrived. requestAnimationFrame does not fire on a backgrounded tab, and a
 * receipt that reads "0 units" over a list of three stacks is worse than one
 * that never animated -- so the truth is what is rendered until the animation
 * proves it can run, rather than after it.
 */
const settled = ref(false)
const counted = ref(0)

const CALM = window.matchMedia('(prefers-reduced-motion: reduce)').matches
const COUNT_MS = 460

let frame = 0

onMounted(() => {
  counted.value = total.value

  if (CALM) {
    settled.value = true
    return
  }

  const target = total.value
  let started: number | null = null

  const tick = (at: number) => {
    // The first frame is where the tally is allowed to start from nothing.
    if (started === null) started = at

    // Ease out: the tally slows into its answer rather than stopping dead.
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
  <div class="wrap" role="dialog" aria-label="Haul" @click="$emit('close')">
    <div class="scrim" />

    <div class="haul plate" :class="{ settled }">
      <div class="inner">
        <!-- Which verb paid, before what it paid. A haul with no line behind it
             is possible, so the eyebrow falls back to the bare fact. -->
        <span class="eyebrow label">
          <SvgIcon v-if="lineGlyph" :svg="lineGlyph" class="line-mark" />
          {{ line ?? (made ? 'Off the bench' : 'Brought back') }}
        </span>

        <!-- §8.4 -- the object, not its name. A bench hands over one thing and
             the plate is about that thing: its silhouette, its rung, what it is
             worth, and what the bench rolled onto it.

             ONE template around the whole craft branch, because the haul tally
             below is its `v-else`: three elements sat between the two and the
             pair came apart, which put "0 units brought back" under a potion. -->
        <template v-if="made">
        <div class="made">
          <SvgIcon v-if="madeIcon" :svg="madeIcon" class="made-icon" />

          <div class="made-of">
            <strong class="figure" :class="`rarity-${madeDef?.rarity ?? 'common'}`">
              {{ made.name }}
            </strong>
            <span class="made-sub label">
              <span v-if="madeDef" class="rung">{{ madeDef.rarity }}</span>
              <span v-if="made.quantity && made.quantity > 1" class="mono">×{{ made.quantity }}</span>
              <span v-else-if="made.durability" class="mono">{{ made.durability }} dur</span>
              <!-- §7.4.3 -- the half of that figure the Smith is responsible
                   for. Silent when a node did not move it. -->
              <span v-if="overStandard" class="mono over">+{{ overStandard }} over standard</span>
            </span>
          </div>
        </div>

        <!-- §9.5.4 -- what it is worth, in the same row the trader, the bench,
             the almanac and the bag all use, so a piece reads the same wherever
             it is met. A potion has no stats and draws nothing. -->
        <div v-if="madeDef && !made.consumable" class="made-stats">
          <StatChips :def="madeDef" :options="madeRolls" />
        </div>

        <!-- §8.0.1 -- the payoff, and the reason this plate exists at all: two
             of one recipe are never the same object. Last, because it is what
             the hour was for. Nothing rolled is a NORMAL outcome and says so
             plainly rather than leaving a gap the player has to interpret. -->
        <div v-if="madeDef && !made.consumable" class="rolled" :class="{ plain: !madeRolls.length }">
          <span class="rolled-label label">{{ madeRolls.length ? 'Rolled' : 'No lines' }}</span>
          <template v-if="madeRolls.length">
            <span
              v-for="(option, i) in madeRolls"
              :key="i"
              class="roll mono tiny"
              :style="{ transitionDelay: `${220 + i * 70}ms` }"
            >
              {{ optionStatLine(option, madeDef) }}
            </span>
          </template>
          <span v-else class="tiny muted">It came out plain. The next one may not.</span>
        </div>
        </template>

        <p v-else class="tally">
          <strong class="figure">{{ counted }}</strong>
          <span class="unit label">{{ total === 1 ? 'unit' : 'units' }} brought back</span>
        </p>

        <!-- The assay bar. See the note at the top: this is the haul's shape,
             and the list underneath is its detail. -->
        <div v-if="rows.length" class="assay" aria-hidden="true">
          <i
            v-for="(row, i) in rows"
            :key="row.mat.key"
            class="share"
            :style="{
              flexGrow: row.qty,
              background: row.accent,
              transitionDelay: `${90 + i * 55}ms`,
            }"
          />
        </div>

        <!-- The stacks, biggest first: the thing you went for reads first
             because it is nearly always the thing you got most of. -->
        <ul v-if="rows.length" class="stacks">
          <li v-for="row in rows" :key="row.mat.key" :style="{ '--accent': row.accent }">
            <SvgIcon :svg="materialIcon(row.mat, 22)" />
            <span class="grow name">{{ row.mat.name }}</span>
            <span class="qty readout">{{ row.qty }}</span>
          </li>
        </ul>

        <p v-else-if="!made" class="tiny muted empty">
          Nothing fit. Sell, process or drop something and work the hex again.
        </p>

        <!-- Both XP figures, because they are two different ladders: the line
             gates what you can extract, the character gates where you may go. -->
        <div class="inset xp tiny">
          <div v-if="line" class="row-between">
            <span class="muted">{{ line }}</span>
            <span class="readout good">+{{ haul.xp.amount }} xp</span>
          </div>
          <!-- §7.4 -- the bench's own trade. A craft teaches nothing else, so
               without this row an hour at the anvil reported a flat zero. -->
          <div v-if="jobName" class="row-between">
            <span class="muted">{{ jobName }}</span>
            <span class="readout good">+{{ haul.jobXp }} xp</span>
          </div>
          <!-- A craft earns no character XP at all (§7.4.1: job levels are the
               proof you did the work, not a reward for it), and a row of zero
               reads as something withheld rather than something absent. -->
          <div v-if="haul.characterXp || !jobName" class="row-between">
            <span class="muted">Character</span>
            <span class="readout good">+{{ haul.characterXp }} xp</span>
          </div>
          <div v-if="haul.levelsGained" class="row-between">
            <span>Level up</span>
            <span class="readout gold">+{{ haul.levelsGained }}</span>
          </div>
          <div v-if="haul.durabilityLost" class="row-between">
            <span class="muted">Tool wear</span>
            <span class="readout bad">−{{ haul.durabilityLost }}</span>
          </div>
        </div>

        <p v-if="haul.lostToOverflow" class="tiny note bad">
          {{ haul.lostToOverflow }} units would not fit and were left behind.
        </p>

        <!-- §8.2 -- at zero the thing is gone, on a mine exactly as in a fight.
             Named here because nothing may be taken quietly. -->
        <p v-if="haul.destroyed?.length" class="tiny note bad">
          {{ haul.destroyed.join(', ') }} wore through and
          {{ haul.destroyed.length === 1 ? 'is' : 'are' }} gone.
        </p>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* A name, not a figure -- so it is set at a size a name can live at rather than
   the display size a single digit needs. */
/* ------------------------------------------------------- off the bench §8.4 */

/*
 * The object gets the room the tally gets on a haul, because it IS the tally:
 * one thing, and everything below it is what that thing turned out to be.
 */
.made {
  display: flex;
  align-items: center;
  gap: 11px;
  min-width: 0;
}

.made-icon {
  flex: 0 0 auto;
}

.made-of {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}

.made .figure {
  font-size: 22px;
  line-height: 1.1;
  overflow-wrap: anywhere;
}

.made-sub {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 8px;
  color: var(--vellum-dim);
}

.rung {
  text-transform: capitalize;
}

/* §7.4.3 -- the bench's own doing rather than the recipe's, so it reads as a
   thing that went right rather than as another figure. */
.over {
  color: var(--sap);
}

.made-stats {
  margin: -2px 0 -1px;
}

/*
 * §8.0.1 -- the roll, and it arrives LAST.
 *
 * The plate has one orchestrated beat (see the note on `settled`): it rises,
 * the bar wipes, the tally runs up. A craft has no count to run, so this is
 * what the beat lands on -- which is right, because it is the only part of an
 * hour at the anvil the player could not have predicted.
 */
.rolled {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 6px 9px;
  padding: 7px 9px;
  background: rgba(0, 0, 0, 0.22);
  border-left: 2px solid var(--copper);
}

/* Nothing rolled is a normal outcome (§8.0.1), so it is drawn quiet rather
   than absent: a gap where the lines go reads as something withheld. */
.rolled.plain {
  border-left-color: var(--line);
}

.rolled-label {
  color: var(--copper);
  letter-spacing: 0.14em;
}

.rolled.plain .rolled-label {
  color: var(--vellum-dim);
}

.roll {
  color: var(--vellum);
  opacity: 0;
  transform: translateY(4px);
  transition: opacity 0.24s ease, transform 0.24s ease;
}

.settled .roll {
  opacity: 1;
  transform: none;
}

@media (prefers-reduced-motion: reduce) {
  .roll {
    opacity: 1;
    transform: none;
    transition: none;
  }
}

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

.haul {
  position: relative;
  width: min(340px, 100%);
  opacity: 0;
  transform: translateY(10px);
  transition: opacity 0.24s ease, transform 0.28s cubic-bezier(0.32, 0.72, 0, 1);
}

.haul.settled {
  opacity: 1;
  transform: none;
}

.inner {
  display: flex;
  flex-direction: column;
  gap: 11px;
  padding: 14px 15px 15px;
}

/* ------------------------------------------------------------------ tally */

.eyebrow {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--copper);
}

.line-mark {
  color: var(--copper);
}

/*
 * The figure is the biggest thing in the HUD, deliberately. Everything else on
 * screen is an instrument reading; this is the one number that is a result.
 */
.tally {
  display: flex;
  align-items: baseline;
  gap: 9px;
  margin: -3px 0 0;
}

.figure {
  font-family: var(--font-display);
  font-size: 40px;
  line-height: 0.9;
  color: var(--vellum);
  font-variant-numeric: tabular-nums;
}

.unit {
  color: var(--vellum-dim);
}

/* ------------------------------------------------------------------ assay */

.assay {
  display: flex;
  gap: 2px;
  height: 7px;
}

/*
 * Each share wipes out from its own left edge, one after the next, so the bar
 * assembles in the order the list reads. A minimum width keeps a single unit
 * visible -- a share too thin to see would make the bar lie about the haul.
 */
.share {
  flex-basis: 0;
  min-width: 5px;
  transform: scaleX(0);
  transform-origin: left;
  transition: transform 0.34s cubic-bezier(0.32, 0.72, 0, 1);
}

.settled .share {
  transform: none;
}

/* ----------------------------------------------------------------- stacks */

.stacks {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.stacks li {
  display: flex;
  align-items: center;
  gap: 9px;
}

.name {
  font-size: 13px;
}

/* The count takes the material's own color, so the list and the bar above it
   are plainly the same reading twice. */
.qty {
  font-size: 13px;
  color: var(--accent);
}

.qty::before {
  content: '×';
  margin-right: 1px;
  font-size: 10px;
  opacity: 0.7;
}

.grow {
  flex: 1 1 auto;
  min-width: 0;
}

.xp {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.empty,
.note {
  margin: 0;
}

@media (prefers-reduced-motion: reduce) {
  .haul,
  .share {
    transition: none;
  }
}
</style>
