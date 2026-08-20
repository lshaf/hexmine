<script setup lang="ts">
/**
 * Top-left: who you are, and whether you can act.
 *
 * Three readings on three scales. They are not progress bars -- they are the
 * graduated rules printed on a survey chart, read by a needle. That distinction
 * does real work here:
 *
 *  - A fresh character's storage is 0 of 120. A fill of zero is invisible; a
 *    needle parked at the origin is still a reading.
 *  - Quarter ticks say how full at a glance, without a second number.
 *  - The needle overshoots the track, like the cursor on a slide rule, so your
 *    eye lands on the position rather than on a coloured area.
 *
 * The level number is the XP row's label, not a badge -- the thing being
 * measured names its own scale. Gold has no cap, so it gets no scale; it sits
 * with the name as a plain readout. Nothing here is a bar for the sake of it.
 */
import { computed, onBeforeUnmount, ref } from 'vue'
import { useGame } from '@/stores/game'
import { formatSpan } from '@/game/formulas'
import { copyText, shortWallet } from '@/game/identity'
import { walletSeal } from '@/icons/sigil'

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

const overCap = computed(() =>
  char.value ? char.value.storageUsed > char.value.storageCap : false,
)

/**
 * Time until the next action point, against the server clock. Null once the
 * tick is due -- the number is about to move anyway.
 */
const nextAp = computed(() => {
  if (!char.value || char.value.ap >= char.value.apMax) return null
  const remaining = char.value.apUpdatedAt + char.value.apRegenMs - game.now
  return remaining > 0 ? formatSpan(remaining) : null
})

interface Scale {
  key: string
  at: number
  of: number
  accent: string
  /** The reading has passed the cap. Only storage can, and it costs you. */
  over?: boolean
}

const scales = computed<Scale[]>(() => {
  const c = char.value
  if (!c) return []

  return [
    { key: 'AP', at: c.ap, of: c.apMax, accent: 'var(--copper)' },
    {
      key: 'Store',
      at: c.storageUsed,
      of: c.storageCap,
      accent: overCap.value ? 'var(--ember)' : 'var(--violet)',
      over: overCap.value,
    },
    { key: `Lv ${c.level}`, at: c.xp, of: c.xpToNext, accent: 'var(--gold)' },
  ]
})

const pct = (s: Scale) => `${Math.min(100, Math.max(0, (s.at / s.of) * 100))}%`
</script>

<template>
  <div v-if="char" class="panel">
    <div class="who">
      <span class="seal" v-html="seal" />

      <div class="named">
        <span class="name">{{ char.name }}</span>
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
          <i class="needle" :class="{ alert: s.over }" :style="{ left: pct(s) }" />
        </span>

        <span class="read readout" :class="{ over: s.over }">
          {{ s.at }}<span class="of">/{{ s.of }}</span>
        </span>
      </div>
    </div>

    <p v-if="nextAp" class="tick-in label">Next point in {{ nextAp }}</p>
  </div>
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

.name {
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

.read.over {
  color: #e8a49f;
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

.needle.alert {
  background: var(--ember);
  animation: throb 1.4s ease-in-out infinite;
}

@keyframes throb {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0.35;
  }
}

.tick-in {
  margin: 8px 0 0;
  font-size: 8px;
  letter-spacing: 0.12em;
  color: var(--vellum-dim);
}

@media (prefers-reduced-motion: reduce) {
  .fill,
  .needle {
    transition: none;
  }

  .needle.alert {
    animation: none;
  }
}

@media (max-width: 560px) {
  .panel {
    transform: scale(0.9);
    transform-origin: top left;
  }
}
</style>
