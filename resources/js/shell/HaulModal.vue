<script setup lang="ts">
/**
 * What a finished trip brought back, §4.
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
 * rather than from a chart library's idea of a colour.
 *
 * Everything here is from the server's own response. The client never decides
 * what dropped, only how to show it (§16).
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { MATERIALS, SKILL_BY_KEY } from '@/game/catalog'
import { materialAccent, materialIcon, skillIcon } from '@/icons/procedural'
import SvgIcon from '@/components/SvgIcon.vue'
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
          {{ line ?? 'Brought back' }}
        </span>

        <p class="tally">
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

        <p v-else class="tiny muted empty">
          Nothing fit. Sell, process or drop something and work the hex again.
        </p>

        <!-- Both XP figures, because they are two different ladders: the line
             gates what you can extract, the character gates where you may go. -->
        <div class="inset xp tiny">
          <div v-if="line" class="row-between">
            <span class="muted">{{ line }}</span>
            <span class="readout good">+{{ haul.xp.amount }} xp</span>
          </div>
          <div class="row-between">
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

        <!-- §8.2 -- at zero the thing is gone, on a trip exactly as in a fight.
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

/* The count takes the material's own colour, so the list and the bar above it
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
