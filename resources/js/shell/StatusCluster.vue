<script setup lang="ts">
/**
 * Top-left: who you are, and whether you can act.
 *
 * Two readings on two scales. They are not progress bars -- they are the
 * graduated rules printed on a survey chart, read by a needle. That distinction
 * does real work here:
 *
 *  - A fresh character's AP is 0 of 20 after a dig. A fill of zero is
 *    invisible; a needle parked at the origin is still a reading.
 *  - Quarter ticks say how full at a glance, without a second number.
 *  - The needle overshoots the track, like the cursor on a slide rule, so your
 *    eye lands on the position rather than on a colored area.
 *
 * The level number is the XP row's label, not a badge -- the thing being
 * measured names its own scale. Gold has no cap, so it gets no scale; it sits
 * with the name as a plain readout. Nothing here is a bar for the sake of it.
 *
 * §7.6 -- the bag is deliberately NOT here. It has a screen of its own, and its
 * one urgent state -- full -- is said by the bag cell turning ember in the
 * top-right rather than by two more needles competing with AP for the eye.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useGame } from '@/stores/game'
import { CHARACTER } from '@/game/balance'
import { copyText, shortWallet } from '@/game/identity'
import { walletSeal } from '@/icons/sigil'
import { SCOPE_PATHS } from '@/icons/actions'
import { ITEM_BY_KEY, SCOPE_ACTION, SCOPE_LABEL } from '@/game/catalog'
import { statLine } from '@/game/formulas'
import type { StatKey } from '@/game/types'

const game = useGame()
const char = computed(() => game.character)

const seal = computed(() => (char.value ? walletSeal(char.value.wallet, 30) : ''))
const short = computed(() => (char.value ? shortWallet(char.value.wallet) : ''))

const copied = ref(false)
let resetCopied: ReturnType<typeof setTimeout> | undefined

async function copyWallet(): Promise<void> {
  if (!char.value) return

  if (await copyText(char.value.wallet)) {
    copied.value = true
    clearTimeout(resetCopied)
    resetCopied = setTimeout(() => {
      copied.value = false
    }, 1600)
    game.note('Wallet address copied.', 'info')
  } else {
    game.note('Could not reach the clipboard. The full address is on your hero sheet.', 'bad')
  }
}

onBeforeUnmount(() => clearTimeout(resetCopied))

interface Scale {
  key: string
  at: number
  of: number
  accent: string
}

const scales = computed<Scale[]>(() => {
  const c = char.value
  if (!c) return []

  // Action points are gone: the level bar is the only scale left on the HUD.
  return [{ key: `Lv ${c.level}`, at: c.xp, of: c.xpToNext, accent: 'var(--gold)' }]
})

const pct = (s: Scale) => `${Math.min(100, Math.max(0, (s.at / s.of) * 100))}%`

/* ------------------------------------------------------------------ charges */

/**
 * §8.5 -- what has been drunk and is still waiting.
 *
 * Two channels, the same split §13.1 uses for gear: the **glyph** is the action
 * the charge is armed for, the **color** is the stat it moves. A player with
 * three drafts in them can read all three at a glance without a word of text,
 * which is the only reason this earns space on a plate that otherwise carries
 * one number.
 *
 * Deliberately not a countdown. A charge has no clock -- it waits until the
 * action is taken -- so nothing here may drain, or it would be read as one.
 */
const STAT_TONE: Record<StatKey, string> = {
  yield: 'var(--gold)',
  travelSpeed: 'var(--violet)',
  processingSpeed: '#8fbf7f',
  power: 'var(--ember)',
  defense: 'var(--ember)',
}

const charges = computed(() =>
  game.buffs.map((b) => ({
    id: `${b.stat}:${b.scope}`,
    name: ITEM_BY_KEY[b.key]?.name ?? 'Draft',
    path: SCOPE_PATHS[b.scope] ?? SCOPE_PATHS.global!,
    tone: STAT_TONE[b.stat],
    effect: `${statLine(b.stat, b.value)} ${SCOPE_LABEL[b.scope]}`,
    spent: `Spent by your next ${SCOPE_ACTION[b.scope]}`,
  })),
)

/**
 * The readout opens as a card under the plate rather than inside it: the
 * cluster is a fixed instrument and a panel that grew out of it would push the
 * travel stack down the screen every time someone checked what they had drunk.
 *
 * Teleported to the body for the same reason the bag's popup is -- the plate
 * carries a backdrop-filter, which would otherwise become the containing block
 * for anything fixed inside it.
 */
const panelEl = ref<HTMLElement | null>(null)
const openCharges = ref(false)
const anchor = ref({ top: 0, left: 0 })

function toggleCharges(): void {
  if (openCharges.value) {
    openCharges.value = false
    return
  }

  const box = panelEl.value?.getBoundingClientRect()
  if (box) anchor.value = { top: box.bottom + 8, left: box.left }
  openCharges.value = true
}

/**
 * §7 -- the naming, which happens ONCE and is therefore offered here.
 *
 * Before the name rather than on the hero sheet, because this is where a
 * player reads their name -- the sheet is a condition read-out of what they are
 * wearing (§8.2), and the one irreversible thing about a character had no
 * business being filed under gear. It is gone the moment it is spent: a control
 * that can only ever be pressed once should not sit there afterwards saying so.
 */
const naming = ref(false)
const draft = ref('')
const field = ref<HTMLInputElement | null>(null)

const nameProblem = computed<string | null>(() => {
  const value = draft.value.trim()

  if (!/^[A-Za-z0-9]*$/.test(value)) return 'Letters and digits only.'
  if (value.length < CHARACTER.nameMin || value.length > CHARACTER.nameMax) {
    return `Between ${CHARACTER.nameMin} and ${CHARACTER.nameMax} characters.`
  }
  if (value.toLowerCase() === 'prospector') return 'That is what an unnamed character is called.'

  return null
})

async function startNaming(): Promise<void> {
  openCharges.value = false

  const box = panelEl.value?.getBoundingClientRect()
  if (box) anchor.value = { top: box.bottom + 8, left: box.left }

  draft.value = ''
  naming.value = true
  await nextTick()
  field.value?.focus()
}

async function claimName(): Promise<void> {
  if (nameProblem.value || game.busy) return
  if (await game.rename(draft.value.trim())) naming.value = false
}

function onKey(event: KeyboardEvent): void {
  if (event.key !== 'Escape') return

  openCharges.value = false
  naming.value = false
}

onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <div v-if="char" ref="panelEl" class="panel">
    <div class="who">
      <span class="seal" v-html="seal" />

      <div class="named">
        <span class="line">
          <!-- §7 -- once, and then never again, so it stands before the name
               while it is still owed and vanishes when it is spent. -->
          <button
            v-if="!char.named"
            class="claim"
            type="button"
            aria-label="Take a name"
            title="Take a name — a prospector names themselves once"
            @click="startNaming"
          >
            <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor"
                 stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M4 20h4L19.5 8.5a2.1 2.1 0 0 0-3-3L5 17v3Z" />
              <path d="M14.5 6.5 17.5 9.5" />
            </svg>
          </button>
          <span class="name">{{ char.name }}</span>
        </span>
        <button
          class="wallet"
          type="button"
          :aria-label="`Copy wallet address ${char.wallet}`"
          :title="copied ? 'Copied' : 'Copy wallet address'"
          @click="copyWallet"
        >
          <span class="addr">{{ short }}</span>
          <svg
            viewBox="0 0 24 24"
            width="11"
            height="11"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
          >
            <path v-if="copied" d="M4 12.5 9.5 18 20 6.5" />
            <template v-else>
              <rect x="9" y="9" width="12" height="12" rx="1.5" />
              <path d="M15 5H4.5A1.5 1.5 0 0 0 3 6.5V17" />
            </template>
          </svg>
        </button>
      </div>

      <!-- No cap, so no scale. Gold is a count, not a level. -->
      <span class="gold readout">{{ char.gold }}<i>g</i></span>
    </div>

    <div class="scales">
      <div
        v-for="s in scales"
        :key="s.key"
        class="row"
        :style="{ '--accent': s.accent }"
      >
        <span class="key label">{{ s.key }}</span>

        <span
          class="scale"
          role="meter"
          :aria-valuenow="s.at"
          :aria-valuemax="s.of"
          :aria-label="s.key"
        >
          <i class="fill" :style="{ width: pct(s) }" />
          <!-- Graduations sit above the fill: a scale you can only read where
               it is empty is not a scale. -->
          <i class="tick" style="left: 25%" />
          <i class="tick" style="left: 50%" />
          <i class="tick" style="left: 75%" />
          <i class="needle" :style="{ left: pct(s) }" />
        </span>

        <span class="read readout">
          {{ s.at }}<span class="of">/{{ s.of }}</span>
        </span>
      </div>
    </div>

    <!-- §8.5 -- what is drunk and still waiting. Absent entirely when there is
         nothing armed: an empty rack is not a reading. -->
    <div v-if="charges.length" class="row charged">
      <span class="key label">Charged</span>
      <button
        class="rack"
        type="button"
        :aria-expanded="openCharges"
        :aria-label="`${charges.length} armed, tap to read them`"
        @click="toggleCharges"
      >
        <span
          v-for="c in charges"
          :key="c.id"
          class="charge"
          :style="{ '--tone': c.tone }"
          :title="`${c.name} — ${c.effect}`"
        >
          <i class="bloom" aria-hidden="true" />
          <span class="hex socket">
            <span class="face">
              <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor"
                   stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                   aria-hidden="true">
                <path :d="c.path" />
              </svg>
            </span>
          </span>
        </span>
      </button>
    </div>
  </div>

  <Teleport to="body">
    <div v-if="naming" class="pop-wrap">
      <div class="pop-scrim" @click="naming = false" />
      <form
        class="pop plate"
        :style="{ top: `${anchor.top}px`, left: `${anchor.left}px` }"
        @submit.prevent="claimName"
      >
        <div class="pop-inner">
          <span class="label pop-key">Take a name</span>
          <input
            ref="field"
            v-model="draft"
            class="field"
            type="text"
            :maxlength="CHARACTER.nameMax"
            autocapitalize="off"
            autocomplete="off"
            spellcheck="false"
            placeholder="Letters and digits"
            aria-label="Your name"
          />
          <!-- One line, and it holds whichever objection applies: this screen's
               own, or the server's when it refused something this could not
               know -- that somebody already goes by it. -->
          <p class="tiny say" :class="draft && nameProblem ? 'bad' : 'muted'">
            {{ (draft && nameProblem) || 'Once only. No two prospectors hold the same name.' }}
          </p>
          <div class="pop-do">
            <button class="btn btn-sm" type="button" @click="naming = false">Cancel</button>
            <button
              class="btn btn-sm btn-primary"
              type="submit"
              :disabled="Boolean(nameProblem) || game.busy"
            >
              Claim
            </button>
          </div>
        </div>
      </form>
    </div>

    <div v-if="openCharges && charges.length" class="pop-wrap">
      <div class="pop-scrim" @click="openCharges = false" />
      <div
        class="pop plate"
        role="dialog"
        aria-label="What you have drunk"
        :style="{ top: `${anchor.top}px`, left: `${anchor.left}px` }"
      >
        <div class="pop-inner">
          <span class="label pop-key">Charged</span>
          <div v-for="c in charges" :key="c.id" class="entry" :style="{ '--tone': c.tone }">
            <span class="hex socket small">
              <span class="face">
                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true">
                  <path :d="c.path" />
                </svg>
              </span>
            </span>
            <span class="what">
              <strong>{{ c.name }}</strong>
              <span class="tiny gives">{{ c.effect }}</span>
              <span class="tiny muted">{{ c.spent }}</span>
            </span>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.panel {
  width: 238px;
  padding: 9px 11px 10px;
  background: var(--hud);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  box-shadow: var(--shadow-plate), inset 0 0 0 1px var(--hud-line-soft);
  clip-path: var(--plate-clip);
}

/* ------------------------------------------------------------------- who */

.who {
  display: flex;
  align-items: center;
  gap: 9px;
}

.seal {
  flex: 0 0 auto;
  display: block;
  line-height: 0;
}

.named {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

/* The claim sits ON the name's line, before it -- an unnamed prospector reads
   "take a name" and then what stands in for one, in that order. */
.line {
  display: flex;
  align-items: baseline;
  gap: 6px;
  min-width: 0;
}

.name {
  min-width: 0;
  font-family: var(--font-display);
  font-size: 13px;
  font-weight: 600;
  color: var(--vellum);
  line-height: 1.15;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.wallet {
  display: flex;
  align-items: center;
  gap: 5px;
  color: var(--vellum-dim);
  transition: color 0.14s ease;
}

.wallet:hover {
  color: var(--copper);
}

.addr {
  font-size: 9.5px;
  font-weight: 700;
  letter-spacing: 0.06em;
  font-variant-numeric: tabular-nums;
}

.gold {
  flex: 0 0 auto;
  align-self: flex-start;
  font-size: 14px;
  color: var(--gold);
}

.gold i {
  font-style: normal;
  font-size: 9.5px;
  opacity: 0.75;
}

/* ---------------------------------------------------------------- scales */

.scales {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 10px;
}

.row {
  display: flex;
  align-items: center;
  gap: 9px;
}

.key {
  flex: 0 0 38px;
  color: var(--accent);
}

.read {
  flex: 0 0 auto;
  min-width: 46px;
  text-align: right;
  font-size: 11.5px;
  color: var(--vellum);
}

.read .of {
  font-size: 9px;
  color: var(--vellum-dim);
}

/* The scale itself: a graduated rule, read by a needle. */
.scale {
  position: relative;
  flex: 1 1 auto;
  height: 6px;
  background: var(--hud-line-soft);
}

/*
 * Quarters. Dark rather than light, so they cut the fill as notches and still
 * read as graduation against the empty track.
 */
.tick {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 1px;
  background: rgba(11, 15, 13, 0.55);
}

.fill {
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  background: var(--accent);
  transition: width 0.5s cubic-bezier(0.32, 0.72, 0, 1);
}

/* Overshoots the track, like the cursor on a slide rule. */
.needle {
  position: absolute;
  top: -3px;
  bottom: -3px;
  width: 2px;
  margin-left: -1px;
  background: var(--vellum);
  transition: left 0.5s cubic-bezier(0.32, 0.72, 0, 1);
}

.tick-in {
  margin: 8px 0 0;
  font-size: 8px;
  letter-spacing: 0.12em;
  color: var(--vellum-dim);
}

/* ----------------------------------------------------------------- charges */

.charged {
  margin-top: 9px;
  align-items: center;
}

.rack {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 0;
  border: 0;
  background: none;
  cursor: pointer;
}

/*
 * A charge is primed, not running down.
 *
 * The toast already owns the draining hexagon (§13.1's perimeter-as-progress),
 * so nothing here may drain or the two would say opposite things in the same
 * shape. What this does instead is *breathe*: a bloom behind the socket swells
 * and fades on its own slow cycle, which reads as held energy rather than as
 * time going. Color is the stat, the glyph inside is the action -- the same
 * two channels §13.1 splits rarity and material across.
 */
.charge {
  position: relative;
  display: block;
  width: 22px;
  height: 19px;
  color: var(--tone);
}

.bloom {
  position: absolute;
  inset: -3px;
  background: var(--tone);
  clip-path: var(--hex-clip);
  opacity: 0.18;
  animation: prime 2.4s ease-in-out infinite;
}

@keyframes prime {
  0%,
  100% {
    opacity: 0.13;
    transform: scale(0.9);
  }
  50% {
    opacity: 0.34;
    transform: scale(1);
  }
}

.socket {
  position: relative;
  display: block;
  width: 100%;
  height: 100%;
}

.socket .face {
  display: grid;
  place-items: center;
  background: var(--ink-raised);
  box-shadow: inset 0 0 0 1px var(--tone);
}

.rack:hover .socket .face {
  background: #2b3831;
}

.pop-key {
  display: block;
  margin-bottom: 8px;
  color: var(--vellum-dim);
}

/* §7 -- the naming. Copper rather than vellum: §13.3 spends copper on work in
   progress, and an unclaimed name is exactly that -- something owed, not
   something wrong (ember) and not something won (sap). */
/*
 * §7 -- the naming: a pencil, and nothing round it.
 *
 * No chip and no border, because a box makes it a second element on a line
 * that already holds two, and the plate is 238px wide -- a bordered "NAME"
 * cost forty of them and took every one from the label beside it, so an
 * unnamed prospector read "Prospect...". A mark in the margin of your own name
 * is the smallest thing that can carry this, and it is the right shape for it:
 * a pencil beside a word is what "you may still write this" looks like
 * everywhere else in the world.
 *
 * Copper, and the ONLY copper on the plate: §13.3 spends it on work in
 * progress, and an unclaimed name is exactly that -- something owed, not
 * something wrong (ember) and not something won (sap).
 */
.claim {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
  align-self: center;
  /* Drawn at 12px and hit at 24: the glyph is small because the plate is, and
     a thumb is not. Negative margins keep the hit area off the line's metrics. */
  width: 24px;
  height: 24px;
  margin: -6px -7px -6px -6px;
  padding: 0;
  border: 0;
  background: transparent;
  color: var(--copper);
  cursor: pointer;
  opacity: 0.92;
}

.claim:hover,
.claim:focus-visible {
  opacity: 1;
  color: var(--gold);
}

/* The naming field. It came here with the form it belongs to -- it was scoped
   to the hero sheet, so the moment the form moved the input rendered as a bare
   browser default in the middle of the HUD. */
.field {
  width: 100%;
  padding: 6px 9px;
  border: 1px solid var(--line);
  background: var(--ink);
  color: var(--vellum);
  font: inherit;
}

.field:focus {
  outline: none;
  border-color: var(--copper);
}

.say {
  margin: 6px 0 0;
}

/* §13.3 -- ember is a state to deal with, and a refused name is one. */
.bad {
  color: var(--ember);
}

.pop-do {
  display: flex;
  justify-content: flex-end;
  gap: 7px;
  margin-top: 9px;
}

/* The card is anchored under the cluster rather than centerd: it is a readout
   of that plate, and a modal in the middle of the map would lose the thread. */
.pop-wrap {
  position: fixed;
  inset: 0;
  z-index: var(--z-panel);
}

.pop-scrim {
  position: absolute;
  inset: 0;
}

.pop {
  position: absolute;
  width: 258px;
  max-width: calc(100vw - 24px);
}

.pop-inner {
  padding: 10px 12px 11px;
}

.entry {
  display: flex;
  align-items: flex-start;
  gap: 9px;
  color: var(--tone);
}

.entry + .entry {
  margin-top: 9px;
  padding-top: 9px;
  border-top: 1px solid var(--hud-line-soft);
}

.socket.small {
  flex: 0 0 auto;
  width: 22px;
  height: 19px;
  margin-top: 1px;
}

.what {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.what strong {
  font-size: 12.5px;
  color: var(--vellum);
}

.gives {
  color: var(--tone);
}

@media (prefers-reduced-motion: reduce) {
  .fill,
  .needle {
    transition: none;
  }

  .bloom {
    animation: none;
    opacity: 0.24;
  }
}

/*
 * Phones get a genuinely smaller plate rather than a scaled one: scaling shrank
 * the ink but kept the 238px box, so the strip on the other side of the top edge
 * still had to fit around the full width.
 */
@media (max-width: 560px) {
  .panel {
    width: 186px;
    padding: 7px 9px 8px;
  }

  .who {
    gap: 7px;
  }

  .seal :deep(svg) {
    width: 25px;
    height: 25px;
  }

  .name {
    font-size: 12px;
  }

  .addr {
    font-size: 9px;
  }

  .gold {
    font-size: 13px;
  }

  .scales {
    margin-top: 7px;
  }

  .charged {
    margin-top: 7px;
  }

  .row {
    gap: 7px;
  }

  .key {
    flex: 0 0 30px;
  }

  .scale {
    height: 5px;
  }

  .read {
    min-width: 38px;
    font-size: 10.5px;
  }
}
</style>
