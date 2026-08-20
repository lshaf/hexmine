<script setup lang="ts">
/**
 * §12 -- the tutorial is the real game loop, so this card never blocks input or
 * takes over the screen. It states the next real action and gets out of the way.
 *
 * It also folds. A player who already knows the loop should not have to read the
 * same prompt for eleven steps, and on a phone the card is competing for the
 * bottom stack with the dock. Collapsed it keeps the step and its title, so the
 * thread is never lost, and the choice persists -- "hide" that unhides itself on
 * the next step is not hiding.
 */
import { computed, ref, watch } from 'vue'
import { useGame } from '@/stores/game'
import { TUTORIAL_OUTRO } from '@/game/tutorial'
import type { PanelKey } from '@/stores/game'

const game = useGame()
const outroDismissed = ref(false)

const STORE_KEY = 'hexmine.quest-collapsed'

const collapsed = ref(readCollapsed())

function readCollapsed(): boolean {
  try {
    return localStorage.getItem(STORE_KEY) === '1'
  } catch {
    // Private modes and blocked storage: the card just opens every session.
    return false
  }
}

watch(collapsed, (value) => {
  try {
    localStorage.setItem(STORE_KEY, value ? '1' : '0')
  } catch {
    /* not worth failing a render over */
  }
})

/**
 * Steps that happen on the map need no shortcut -- the map is already the
 * screen. Only the ones behind a panel get an opener.
 */
const jumpTo = computed<PanelKey | null>(() => {
  const tab = game.currentStep?.tab
  return tab && tab !== 'map' && game.panel !== tab ? (tab as PanelKey) : null
})
</script>

<template>
  <div v-if="game.currentStep" class="tut" :class="{ folded: collapsed }">
    <button
      class="head"
      type="button"
      :aria-expanded="!collapsed"
      :title="collapsed ? 'Show the current step' : 'Hide the current step'"
      @click="collapsed = !collapsed"
    >
      <span class="label step">Step {{ game.tutorialProgress }}</span>
      <span v-if="collapsed" class="peek">{{ game.currentStep.title }}</span>
      <span class="toggle">
        <span class="label word">{{ collapsed ? 'Show' : 'Hide' }}</span>
        <span class="chevron" :class="{ open: !collapsed }" aria-hidden="true">
          <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor"
               stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
            <path d="m6 15 6-6 6 6" />
          </svg>
        </span>
      </span>
    </button>

    <template v-if="!collapsed">
      <h3 class="title">{{ game.currentStep.title }}</h3>
      <p class="body">{{ game.currentStep.body }}</p>
      <button
        v-if="jumpTo"
        class="jump"
        type="button"
        @click="game.openPanel(jumpTo)"
      >
        Open {{ jumpTo === 'shop' ? 'trader' : jumpTo }} →
      </button>
    </template>
  </div>

  <div v-else-if="game.tutorialDone && !outroDismissed" class="tut outro">
    <h3 class="title">{{ TUTORIAL_OUTRO.title }}</h3>
    <p class="body">{{ TUTORIAL_OUTRO.body }}</p>
    <button class="jump tiny" type="button" @click="outroDismissed = true">Understood</button>
  </div>
</template>

<style scoped>
.tut {
  width: min(340px, 100%);
  padding: 9px 12px 12px 14px;
  background: rgba(31, 26, 14, 0.94);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  box-shadow: var(--shadow-plate), inset 0 0 0 1px #5c4d22;
  clip-path: var(--plate-clip);
}

/* Folded it is a strip, not a card -- the chamfer would eat the one line left. */
.tut.folded {
  padding: 7px 10px 7px 14px;
  clip-path: none;
}

.head {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 9px;
  color: var(--vellum-dim);
  text-align: left;
}

.step {
  flex: 0 0 auto;
}

/* The title stays visible when folded, so the thread is never lost. */
.peek {
  flex: 1 1 auto;
  min-width: 0;
  font-size: 12px;
  color: var(--gold);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/*
 * A word and an arrow, not a lone glyph. Nobody expects a quest card to fold,
 * so the control has to say what it does -- a bare chevron on a plate reads as
 * ornament, which is exactly how this was missed the first time.
 */
.toggle {
  flex: 0 0 auto;
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 3px 5px 3px 7px;
  color: var(--gold);
  background: rgba(216, 179, 74, 0.1);
  box-shadow: inset 0 0 0 1px rgba(216, 179, 74, 0.3);
  clip-path: polygon(5px 0, 100% 0, 100% calc(100% - 5px), calc(100% - 5px) 100%, 0 100%, 0 5px);
  transition: background 0.14s ease;
}

.word {
  color: inherit;
  font-size: 8.5px;
}

.chevron {
  display: flex;
  transform: rotate(180deg);
  transition: transform 0.16s ease;
}

.chevron.open {
  transform: rotate(0deg);
}

.head:hover .toggle {
  background: rgba(216, 179, 74, 0.22);
}

@media (prefers-reduced-motion: reduce) {
  .chevron {
    transition: none;
  }
}

.outro {
  background: rgba(31, 26, 42, 0.94);
  box-shadow: var(--shadow-plate), inset 0 0 0 1px #4e3d68;
}

.title {
  font-size: 15px;
  margin-top: 4px;
  color: var(--gold);
}

.outro .title {
  color: #c3a9e6;
}

.body {
  margin: 5px 0 0;
  font-size: 12px;
  line-height: 1.55;
  color: var(--vellum-dim);
}

.jump {
  display: block;
  margin-top: 9px;
  color: var(--gold);
  font-weight: 700;
  font-size: 10px;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  white-space: nowrap;
}

.outro .jump {
  color: #c3a9e6;
}

@media (max-width: 560px) {
  .tut {
    width: 100%;
  }
}
</style>
