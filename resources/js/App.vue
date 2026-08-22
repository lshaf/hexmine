<script setup lang="ts">
/**
 * App shell.
 *
 * The screen is the map. Everything else floats over it as cut plates, anchored
 * to corners, so nothing steals area from the thing the game is actually about.
 *
 *   top-left      instrument cluster (AP / level) + village work
 *   top-right     recentre and the location-independent screens, nested into
 *                 one honeycomb strip
 *   bottom-centre what you are pointing at, and what you can do here
 *
 * The camera pans freely and costs nothing, so it needs a way back -- but sight
 * does not pan with it. Beyond the character's travel range the map shows the
 * land and who lives on it, and nothing that would have taken a request.
 *
 * §13.2 -- sizing is real CSS, not utility classes with arbitrary values, which
 * silently collapsed the viewport to zero height when tried.
 */
import { onMounted, onBeforeUnmount, computed, ref, watch } from 'vue'
import { useGame } from '@/stores/game'
import HexMap from '@/map/HexMap.vue'
import StatusCluster from '@/shell/StatusCluster.vue'
import TripStack from '@/shell/TripStack.vue'
import ActionDock from '@/shell/ActionDock.vue'
import HexAction from '@/shell/HexAction.vue'
import PanelOverlay from '@/shell/PanelOverlay.vue'
import Toasts from '@/shell/Toasts.vue'
import HaulModal from '@/shell/HaulModal.vue'
import TileCard from '@/components/TileCard.vue'
import StationPanel from '@/components/StationPanel.vue'
import BagView from '@/views/BagView.vue'
import CraftView from '@/views/CraftView.vue'
import ShopView from '@/views/ShopView.vue'
import HeroView from '@/views/HeroView.vue'
import AtlasView from '@/views/AtlasView.vue'
import SkillsView from '@/views/SkillsView.vue'
import QuestView from '@/views/QuestView.vue'
import QuestRewardModal from '@/shell/QuestRewardModal.vue'

const game = useGame()

const PANELS = {
  bag: { title: 'Bag', component: BagView, wide: false },
  craft: { title: 'Workshop', component: CraftView, wide: false },
  shop: { title: 'Trader', component: ShopView, wide: false },
  hero: { title: 'Prospector', component: HeroView, wide: false },
  atlas: { title: 'Atlas', component: AtlasView, wide: true },
  // §7.4 -- six trees of thirty. Wide, because a seam of nodes needs room.
  skills: { title: 'Jobs', component: SkillsView, wide: false },
  // §12.1 -- what is owed and what has been paid. Two tabs, no third.
  quests: { title: 'Ledger', component: QuestView, wide: false },
} as const

const panel = computed(() => (game.panel ? PANELS[game.panel] : null))

/** The station opens for whichever settlement the player is standing on. */
const station = computed(() => game.station)

/**
 * The bottom stack is the one plate whose height changes with where you are --
 * a settlement dock under an open tile card is twice an empty one -- and on a
 * phone the toasts have to sit clear of it (§13.2). Measured rather than
 * guessed, and published as --stack-h for anything that needs to ride it.
 */
const bottomStack = ref<HTMLElement | null>(null)
const stackHeight = ref(0)
let stackWatcher: ResizeObserver | undefined

watch(bottomStack, (el) => {
  stackWatcher?.disconnect()
  stackWatcher = undefined

  if (!el) {
    stackHeight.value = 0
    return
  }

  stackWatcher = new ResizeObserver(([entry]) => {
    stackHeight.value = Math.round(entry!.contentRect.height)
  })
  stackWatcher.observe(el)
})

onBeforeUnmount(() => stackWatcher?.disconnect())

/**
 * The map tells the store how much room it has, and where the camera has
 * drifted to. Both are local: tiles are generated from the seed, so neither
 * costs a request. visibleTiles() already pads by a couple of tiles for props
 * standing above their own hex, so no extra margin is needed.
 */
function onMapResize(width: number, height: number) {
  game.setViewport(Math.min(2400, Math.round(width)), Math.min(2400, Math.round(height)))
}

function onRecenter(col: number, row: number) {
  game.setView(col, row)
}

onMounted(() => {
  void game.boot()
  if (import.meta.env.DEV) {
    ;(window as unknown as Record<string, unknown>).game = game
  }
})
</script>

<template>
  <div class="app" :style="stackHeight ? { '--stack-h': `${stackHeight}px` } : undefined">
    <template v-if="game.booted && game.character">
      <HexMap
        :tiles="game.tiles"
        :center-col="game.view.col"
        :center-row="game.view.row"
        :character-col="game.character.col"
        :character-row="game.character.row"
        :sight="game.sight"
        :selected="game.selected"
        :jobs="game.jobs"
        :travel="game.travel"
        :now="game.now"
        @select="game.select"
        @recenter="onRecenter"
        @resize="onMapResize"
      />

      <!-- ------------------------------------------------------- top left -->
      <div class="corner top-left">
        <StatusCluster />
        <TripStack />
      </div>

      <!-- ------------------------------------------------------ top right -->
      <div class="corner top-right">
        <div class="screens">
          <!-- The map's own control, at the head of the strip: the camera pans
               anywhere and costs nothing, so the way back belongs with the
               things you can reach from any hex rather than in a corner of
               its own. -->
          <HexAction
            icon="recenter"
            label="Recentre"
            hint="Centre the map on your prospector"
            @activate="game.centreOnCharacter()"
          />
          <HexAction icon="atlas" label="Atlas" @activate="game.openPanel('atlas')" />
          <HexAction icon="skills" label="Jobs" @activate="game.openPanel('skills')" />
          <!-- §12 -- the cell says when there is gold waiting, for the same
               reason the bag says when it is full: the state is worth crossing
               the screen for. Green rather than the bag's ember, because ember
               is what a PROBLEM looks like and a finished quest is a payout. -->
          <HexAction
            icon="quest"
            label="Ledger"
            :good="game.questsReady > 0"
            :hint="game.questsReady > 0
              ? `${game.questsReady} finished — the gold is waiting`
              : 'What is owed, and what has been paid'"
            @activate="game.openPanel('quests')"
          />
          <!-- §7.6 -- the bag says when it is full, because nothing else does
               any more: no strap free means the next new kind is turned away. -->
          <HexAction
            icon="bag"
            label="Bag"
            :alert="game.bagFull"
            :hint="game.bagFull ? 'Full — no strap free for a new kind' : ''"
            @activate="game.openPanel('bag')"
          />
          <HexAction icon="hero" label="Hero" @activate="game.openPanel('hero')" />
        </div>
      </div>

      <!-- -------------------------------------------------- bottom centre -->
      <div ref="bottomStack" class="corner bottom-centre">
        <TileCard />
        <ActionDock />
      </div>

      <!-- ---------------------------------------------------------- panels -->
      <Transition name="fade">
        <PanelOverlay
          v-if="panel"
          :title="panel.title"
          :wide="panel.wide"
          @close="game.closePanel()"
        >
          <component :is="panel.component" />
        </PanelOverlay>
      </Transition>

      <Transition name="fade">
        <PanelOverlay
          v-if="station && !panel"
          :title="station.settlement.name"
          @close="game.closeStation()"
        >
          <StationPanel :settlement="station.settlement" />
        </PanelOverlay>
      </Transition>

      <!-- Last, and highest: a toast reporting a failure inside a panel has to
           land in front of it. Source order matches the stacking ladder so this
           survives someone editing the z-index out. -->
      <Toasts />

      <!-- §4 -- the receipt for a finished trip. Over everything, because it
           is the one moment in an idle game where something happened. -->
      <HaulModal v-if="game.haul" :haul="game.haul" @close="game.clearHaul()" />

      <!-- §12.1 -- a claim gets a receipt rather than a toast: there is more to
           say than a status line holds, and it is the thing the player came
           back for. -->
      <QuestRewardModal
        v-if="game.questReward"
        :reward="game.questReward"
        @close="game.clearQuestReward()"
      />
    </template>

    <div v-else class="boot">
      <div class="boot-mark">
        <svg viewBox="0 0 40 40" width="46" height="46" aria-hidden="true">
          <polygon points="20,4 34,12 34,28 20,36 6,28 6,12" fill="none" stroke="#c1793f" stroke-width="2" />
          <polygon points="20,13 27,17 27,25 20,29 13,25 13,17" fill="#c1793f" />
        </svg>
      </div>
      <p class="label">Surveying</p>
    </div>
  </div>
</template>

<style scoped>
.app {
  position: relative;
  height: 100%;
  overflow: hidden;
  background: var(--ink);
}

.corner {
  position: absolute;
  z-index: var(--z-hud);
  display: flex;
  flex-direction: column;
  gap: 10px;
  pointer-events: none;
}

.corner > * {
  pointer-events: auto;
}

.top-left {
  top: 12px;
  left: 12px;
}

.top-right {
  top: 12px;
  right: 12px;
  align-items: flex-end;
}

.screens {
  /*
   * A honeycomb block, three across and two down.
   *
   * Six cells in a row would eat 350px of the top edge; six in a nested column
   * gave the horizon back but reached a third of the way down the screen, which
   * is worse on a phone than it was wide. Three by two is the squarest the
   * lattice allows, and it puts every screen inside one thumb's reach of the
   * corner.
   *
   * It nests exactly the way the map's own hexes do (§13.2): three quarters of
   * a width between columns, and the middle column dropped half a height so the
   * points interlock. That is the same lattice, read across instead of down.
   *
   * The captions go with the block. They were never the thing being read at a
   * glance, and there is nowhere to put them between two nested cells; every
   * button keeps its title and aria-label, so nothing is lost but the ink.
   */
  --cell-w: 58px;
  --cell-h: 50px;
  display: grid;
  /* The first two columns are a step apart; the last is a whole cell, so the
     block ends on its own right edge rather than overhanging the corner. */
  grid-template-columns: repeat(2, calc(var(--cell-w) * 0.75)) var(--cell-w);
  grid-auto-rows: var(--cell-h);
  justify-items: start;
  /* Room for the dropped middle column, which would otherwise be clipped. */
  padding-bottom: calc(var(--cell-h) / 2);
}

/* Nested cells overlap at the tips, so hit-testing has to follow the hexagon
   rather than the box, or the pointed corner of one cell would swallow clicks
   meant for its neighbour. */
.screens :deep(.cell) {
  clip-path: var(--hex-clip);
}

/* The middle column of each row drops half a height. Two straight columns and
   one dropped between them is what makes six hexes a honeycomb rather than a
   grid of six hexagons. */
.screens :deep(.cell:nth-child(3n + 2)) {
  transform: translateY(calc(var(--cell-h) / 2));
}

.screens :deep(.cell .name) {
  display: none;
}

/* The lift would be clipped by the shape above, and a cell that rises out of a
   honeycomb reads as a mistake anyway. The face still lights on hover. */
.screens :deep(.cell:hover:not(:disabled) .hex) {
  transform: none;
}

.bottom-centre {
  left: 50%;
  bottom: 12px;
  transform: translateX(-50%);
  align-items: center;
}

/* ---------------------------------------------------------------- boot */

.boot {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 14px;
}

.boot-mark {
  animation: pulse 1.6s ease-in-out infinite;
}

@keyframes pulse {
  0%,
  100% {
    transform: scale(1);
  }
  50% {
    transform: scale(1.08);
  }
}

/*
 * Phones: the dock needs the full width, and the trip stack has to yield to it
 * rather than stacking a column down the middle of the screen.
 */
@media (max-width: 560px) {
  .corner {
    gap: 7px;
  }

  .top-left,
  .top-right {
    top: calc(6px + env(safe-area-inset-top, 0px));
  }

  .top-left {
    left: calc(6px + env(safe-area-inset-left, 0px));
  }

  .top-right {
    right: calc(6px + env(safe-area-inset-right, 0px));
  }

  .bottom-centre {
    bottom: calc(6px + env(safe-area-inset-bottom, 0px));
    left: calc(6px + env(safe-area-inset-left, 0px));
    right: calc(6px + env(safe-area-inset-right, 0px));
    transform: none;
  }

  /*
   * Tighter cells on a phone, where the strip shares the top edge with the
   * instrument cluster. The nesting maths reads these two, so the honeycomb
   * closes up with them rather than coming apart.
   */
  .screens {
    --cell-w: 37px;
    --cell-h: 32px;
  }

  .screens :deep(.cell .hex) {
    width: var(--cell-w);
    height: var(--cell-h);
  }
}
</style>
