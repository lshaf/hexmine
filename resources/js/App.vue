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
 *
 * The map does not pan, so there is no camera to put back and no recentre
 * control. It is always on the character. Free exploration is the atlas.
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
 * The map tells the store how much room it has; the store generates exactly that
 * window, centred on the character. visibleTiles() already pads by a couple of
 * tiles for props standing above their own hex, so no extra margin is needed.
 */
async function onMapResize(width: number, height: number) {
  await game.setViewport(Math.min(2400, Math.round(width)), Math.min(2400, Math.round(height)))
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
        <!-- No room beside the gauges on a phone, so the prompt sits where the
             acting happens instead. -->
        <TutorialCard v-if="!isWide" />
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
