<script setup lang="ts">
/**
 * App shell.
 *
 * The screen is the map. Everything else floats over it as cut plates, anchored
 * to corners, so nothing steals area from the thing the game is actually about.
 *
 *   top-left      instrument cluster (AP / storage / level) + village work
 *   top-right     the location-independent screens, and the tutorial
 *   bottom-centre what you are pointing at, and what you can do here
 *   bottom-right  recentre
 *
 * The camera pans freely and costs nothing, so it needs a way back -- but sight
 * does not pan with it. Beyond the character's travel range the map shows the
 * land and who lives on it, and nothing that would have taken a request.
 *
 * §13.2 -- sizing is real CSS, not utility classes with arbitrary values, which
 * silently collapsed the viewport to zero height when tried.
 */
import { onMounted, computed } from 'vue'
import { useGame } from '@/stores/game'
import { useBreakpoint } from '@/composables/useBreakpoint'
import HexMap from '@/map/HexMap.vue'
import StatusCluster from '@/shell/StatusCluster.vue'
import TripStack from '@/shell/TripStack.vue'
import ActionDock from '@/shell/ActionDock.vue'
import HexAction from '@/shell/HexAction.vue'
import PanelOverlay from '@/shell/PanelOverlay.vue'
import TutorialCard from '@/shell/TutorialCard.vue'
import Toasts from '@/shell/Toasts.vue'
import TileCard from '@/components/TileCard.vue'
import StationPanel from '@/components/StationPanel.vue'
import BagView from '@/views/BagView.vue'
import CraftView from '@/views/CraftView.vue'
import ShopView from '@/views/ShopView.vue'
import HeroView from '@/views/HeroView.vue'
import AtlasView from '@/views/AtlasView.vue'
import { ACTION_PATHS } from '@/icons/actions'

const game = useGame()
const { isWide } = useBreakpoint()

const PANELS = {
  bag: { title: 'Bag', component: BagView, wide: false },
  craft: { title: 'Workshop', component: CraftView, wide: false },
  shop: { title: 'Trader', component: ShopView, wide: false },
  hero: { title: 'Prospector', component: HeroView, wide: false },
  atlas: { title: 'Atlas', component: AtlasView, wide: true },
} as const

const panel = computed(() => (game.panel ? PANELS[game.panel] : null))

/** The station opens for whichever settlement the player is standing on. */
const station = computed(() => game.station)

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
  <div class="app">
    <template v-if="game.booted && game.character">
      <HexMap
        :tiles="game.tiles"
        :center-col="game.view.col"
        :center-row="game.view.row"
        :character-col="game.character.col"
        :character-row="game.character.row"
        :travel-range="game.travelRange"
        :selected="game.selected"
        :jobs="game.jobs"
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
          <HexAction icon="atlas" label="Atlas" @activate="game.openPanel('atlas')" />
          <HexAction icon="bag" label="Bag" @activate="game.openPanel('bag')" />
          <HexAction icon="hero" label="Hero" @activate="game.openPanel('hero')" />
        </div>
        <TutorialCard v-if="isWide" />
      </div>

      <!-- -------------------------------------------------- bottom centre -->
      <div class="corner bottom-centre">
        <!-- The bottom stack grows and shrinks with context, so on phones the
             recentre control rides inside it rather than fighting it for a
             fixed corner. -->
        <button
          v-if="!isWide"
          class="recentre inline"
          type="button"
          title="Centre on your prospector"
          @click="game.centreOnCharacter()"
        >
          <span class="hex"><span class="face"><svg viewBox="0 0 24 24" width="17" height="17"
            fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"
            aria-hidden="true"><path :d="ACTION_PATHS.recenter" /></svg></span></span>
        </button>

        <!-- No room beside the gauges on a phone, so the prompt sits where the
             acting happens instead. -->
        <TutorialCard v-if="!isWide" />
        <TileCard />
        <ActionDock />
      </div>

      <!-- --------------------------------------------------- bottom right -->
      <button
        v-if="isWide"
        class="recentre corner-fixed"
        type="button"
        title="Centre on your prospector"
        @click="game.centreOnCharacter()"
      >
        <span class="hex">
          <span class="face">
            <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor"
                 stroke-width="1.7" stroke-linecap="round" aria-hidden="true">
              <path :d="ACTION_PATHS.recenter" />
            </svg>
          </span>
        </span>
      </button>

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
  display: flex;
  gap: 6px;
}

.bottom-centre {
  left: 50%;
  bottom: 12px;
  transform: translateX(-50%);
  align-items: center;
}

.recentre {
  color: var(--vellum-dim);
}

.recentre.corner-fixed {
  position: absolute;
  right: 12px;
  bottom: 12px;
  z-index: var(--z-hud);
}

.recentre.inline {
  align-self: flex-end;
}

.recentre .hex {
  display: block;
  width: 46px;
  height: 40px;
}

.recentre .face {
  display: grid;
  place-items: center;
  background: var(--hud);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
}

.recentre:hover {
  color: var(--vellum);
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
  .top-left,
  .top-right {
    top: 8px;
  }

  .top-left {
    left: 8px;
  }

  .top-right {
    right: 8px;
  }

  .bottom-centre {
    bottom: 8px;
    left: 8px;
    right: 8px;
    transform: none;
  }

}
</style>
