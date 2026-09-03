<script setup lang="ts">
/**
 * App shell.
 *
 * The screen is the map. Everything else floats over it as cut plates, anchored
 * to corners, so nothing steals area from the thing the game is actually about.
 *
 *   top-left      instrument cluster (AP / level) + village work
 *   top-right     recenter, and one burger holding every screen you can open
 *                 from any hex
 *   bottom-center what you are pointing at, and what you can do here
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
import { logout } from '@/wallet/wax'
import HexMap from '@/map/HexMap.vue'
import StatusCluster from '@/shell/StatusCluster.vue'
import TravelStack from '@/shell/TravelStack.vue'
import ActionDock from '@/shell/ActionDock.vue'
import HexAction from '@/shell/HexAction.vue'
import { placeLabel } from '@/game/formulas'
import { ACTION_PATHS } from '@/icons/actions'
import PanelOverlay from '@/shell/PanelOverlay.vue'
import BenchView from '@/views/BenchView.vue'
import GuildView from '@/views/GuildView.vue'
import HallsPanel from '@/components/HallsPanel.vue'
import Toasts from '@/shell/Toasts.vue'
import HaulModal from '@/shell/HaulModal.vue'
import BattleModal from '@/shell/BattleModal.vue'
import BattleLive from '@/shell/BattleLive.vue'
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
import LoginView from '@/views/LoginView.vue'
import { loadSettings } from '@/wallet/wax'

const game = useGame()

/**
 * §2 -- letting go of the wallet.
 *
 * The page is RELOADED rather than the store being emptied. A session ending
 * invalidates every slice of it at once -- character, bag, jobs, quests, the
 * map's live half -- and clearing them by hand would be a second definition of
 * "a fresh session" for the first one to eventually disagree with. It is also
 * the rarest action in the game; there is nothing to optimise.
 */
const leaving = ref(false)

async function disconnect(): Promise<void> {
  if (leaving.value) return

  leaving.value = true

  try {
    await logout()
  } finally {
    window.location.reload()
  }
}

/**
 * §2 -- the door.
 *
 * Three states rather than two, because "no wallet yet" and "still loading" are
 * different screens and showing the surveying mark to somebody who has not
 * logged in would be a spinner that never resolves.
 *
 * Whether the door is even there is the SERVER'S answer (`required`), not a
 * build flag: while the API still mints a character for any session, a gate
 * here would only be a screen to click past.
 */
const gate = ref<'checking' | 'login' | 'open'>('checking')

async function openGate(): Promise<void> {
  try {
    const settings = await loadSettings()
    gate.value = settings.required && !settings.wallet ? 'login' : 'open'
  } catch {
    // The door is not the game. If asking about it fails, fall through to the
    // boot the app has always done and let that report its own trouble.
    gate.value = 'open'
  }

  if (gate.value === 'open') void game.boot()
}

function onConnected(): void {
  gate.value = 'open'
  void game.boot()
}

const PANELS = {
  bag: { title: 'Inventory', component: BagView, wide: false },
  craft: { title: 'Workshop', component: CraftView, wide: false },
  shop: { title: 'Trader', component: ShopView, wide: false },
  hero: { title: 'Character', component: HeroView, wide: false },
  atlas: { title: 'Atlas', component: AtlasView, wide: true },
  // §7.4 -- six trees of thirty. Wide, because a seam of nodes needs room.
  skills: { title: 'Skill', component: SkillsView, wide: false },
  // §12.1 -- what is owed and what has been paid. Two tabs, no third.
  quests: { title: 'Quest', component: QuestView, wide: false },
  // §8.4 -- what is on a bench somewhere, and how far away that bench is.
  bench: { title: 'Craft', component: BenchView, wide: false },
  // §10 -- who you are with, and the hall that makes legendary reachable.
  guild: { title: 'Guild', component: GuildView, wide: false },
} as const

const panel = computed(() => (game.panel ? PANELS[game.panel] : null))

/**
 * §13.3 -- the screens, behind one cell.
 *
 * They were a honeycomb of nine in this corner, which was the right drawing and
 * the wrong amount of it: nine cells reached a third of the way down a phone,
 * over the one thing the game is actually about. The flower is a burger now,
 * and what is left in the corner is the pair that is *about the map* -- the way
 * back to your prospector -- rather than the screens you can open from anywhere.
 *
 * Built as data rather than eight hand-written cells, because the badge on the
 * closed burger has to be a roll-up of the badges inside it. Two copies of that
 * list is two chances for a cell to light in the menu and not on the thing
 * hiding it.
 */
type Screen = {
  panel: keyof typeof PANELS
  icon: string
  label: string
  /** A state to deal with -- ember, and it outranks `good` on the roll-up. */
  alert?: boolean
  /** A state worth crossing the screen for -- sap. */
  good?: boolean
  hint: string
}

const screens = computed<Screen[]>(() => [
  // §7.6 -- the bag says when it is full, because nothing else does any more:
  // no strap free means the next new kind is turned away.
  {
    panel: 'bag',
    icon: 'bag',
    label: 'Inventory',
    alert: game.bagFull,
    hint: game.bagFull ? 'Full — no strap free for a new kind' : 'What you are carrying, and on how many straps',
  },
  /*
   * §8.4 -- a bench holds work somewhere on the map and hands it over only to
   * somebody standing there. It lights for anything FINISHED, wherever it is:
   * the news is that a run is done, and where is what the panel exists to say.
   *
   * It used to light only for work under your feet, on the argument that a
   * "ready" you cannot reach is crying wolf. The opposite turned out to be the
   * problem -- a run finishing four hexes away went unmentioned until you
   * happened to look. The hint carries the distinction the colour no longer does.
   */
  {
    panel: 'bench',
    icon: 'craft',
    label: 'Craft',
    good: game.benchReady > 0,
    hint: game.benchHere > 0
      ? `${game.benchHere} finished here — take it off the bench`
      : game.benchReady > 0
        ? `${game.benchReady} finished, elsewhere on the map`
        : 'Crafts and processing runs, and which bench holds them',
  },
  // §12 -- green rather than the bag's ember, because ember is what a PROBLEM
  // looks like and a finished quest is a payout.
  {
    panel: 'quests',
    icon: 'quest',
    label: 'Quest',
    good: game.questsReady > 0,
    hint: game.questsReady > 0
      ? `${game.questsReady} finished — the gold is waiting`
      : 'What is owed, and what has been paid',
  },
  { panel: 'skills', icon: 'skills', label: 'Skill', hint: 'Seventeen trees, and the points to spend on them' },
  { panel: 'hero', icon: 'hero', label: 'Character', hint: 'What you are wearing, and what is about to break' },
  // §10 -- the cell says when you have none, since a guild is the only thing
  // standing between a prospector and §8.0's top rung, and "you are not in one"
  // is a decision to make rather than a problem to fix.
  {
    panel: 'guild',
    icon: 'guild',
    label: 'Guild',
    good: (game.guild?.pending ?? 0) > 0,
    hint: game.guild
      ? (game.guild.pending
        ? `${game.guild.pending} asking to join ${game.guild.name}`
        : `${game.guild.name} · ${game.guild.members} in it`)
      : 'Not in one — halls stand in cities and capitals',
  },
])

/**
 * What the closed burger has to say on behalf of everything behind it.
 *
 * Without this the change would cost real news: a full bag and gold waiting on
 * the ledger both used to be visible without opening anything, and a menu that
 * swallowed them would be a corner that is tidier and worse.
 *
 * **Ember outranks sap**, because a problem outranks a payout -- §13.3 gives
 * the pair opposite jobs and one cell can only wear one of them.
 */
const menuAlert = computed(() => screens.value.some((s) => s.alert))
const menuGood = computed(() => !menuAlert.value && screens.value.some((s) => s.good))

const menuOpen = ref(false)

/** Everything that shuts it: a pick, the scrim, Escape. */
function closeMenu(): void {
  menuOpen.value = false
}

function openScreen(key: keyof typeof PANELS): void {
  closeMenu()
  game.openPanel(key)
}

function onMenuKey(e: KeyboardEvent): void {
  if (e.key === 'Escape' && menuOpen.value) {
    closeMenu()
  }
}

onMounted(() => window.addEventListener('keydown', onMenuKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onMenuKey))

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
  void openGate()
  if (import.meta.env.DEV) {
    ;(window as unknown as Record<string, unknown>).game = game
  }
})
</script>

<template>
  <div class="app" :style="stackHeight ? { '--stack-h': `${stackHeight}px` } : undefined">
    <LoginView v-if="gate === 'login'" @connected="onConnected" />

    <template v-else-if="game.booted && game.character">
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
        :carriers="game.carriers"
        :now="game.now"
        @select="game.select"
        @recenter="onRecenter"
        @resize="onMapResize"
      />

      <!-- ------------------------------------------------------- top left -->
      <div class="corner top-left">
        <StatusCluster />
        <TravelStack />
      </div>

      <!-- ------------------------------------------------------ top right -->
      <!--
        §13.3 -- what is left in this corner is the map's own controls, and one
        cell holding every screen you can open from any hex.

        The screens were a honeycomb of nine here. That was the right drawing
        and the wrong amount of it: nine cells reached a third of the way down a
        phone, standing over the one thing the game is about. What the corner
        keeps is what is about *the map* — the way back to your prospector, and
        the atlas — and the rest is behind the burger.
      -->
      <div class="corner top-right">
        <div class="screens">
          <!-- The camera pans anywhere and costs nothing, so the way back is
               the one control that has to stay in reach without a tap first. -->
          <HexAction
            icon="recenter"
            label="Recenter"
            hint="Center the map on your prospector"
            @activate="game.centerOnCharacter()"
          />
          <!-- The atlas is about the map too, so it keeps a cell of its own
               rather than a row in the menu: this corner is where the map's own
               controls live, and the burger is where the screens went. -->
          <HexAction
            icon="atlas"
            label="Atlas"
            hint="The whole map, and everything charted on it"
            @activate="game.openPanel('atlas')"
          />
          <!-- The roll-up: ember if anything behind it needs dealing with, sap
               if anything is worth crossing the screen for. Without it a full
               bag and gold on the ledger would go quiet the moment they were
               put behind a menu, which would make this corner tidier and
               worse. -->
          <HexAction
            icon="menu"
            label="Menu"
            :alert="menuAlert"
            :good="menuGood"
            :hint="menuOpen ? 'Close' : 'Bag, benches, ledger, jobs and the rest'"
            @activate="menuOpen = !menuOpen"
          />
        </div>

        <!-- The list. A plate rather than more honeycomb: a flower is a shape
             to take in at a glance and this is a thing to read down, and nine
             hexagons unfolding out of a corner would be the drawing this change
             was made to get rid of. -->
        <Transition name="fade">
          <nav v-if="menuOpen" class="menu plate" aria-label="Screens">
            <div class="menu-inner">
              <button
                v-for="item in screens"
                :key="item.panel"
                type="button"
                class="item"
                :class="{ alert: item.alert, good: item.good }"
                :title="item.hint"
                @click="openScreen(item.panel)"
              >
                <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor"
                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path :d="ACTION_PATHS[item.icon]" />
                </svg>
                <span class="grow">{{ item.label }}</span>
                <!-- The badge says WHY the burger is lit, which is the half a
                     roll-up cannot carry. -->
                <span v-if="item.alert || item.good" class="mark" />
              </button>

              <!-- §2 -- ending the session, kept below a rule. It is the one
                   destructive thing in the list and it must not sit inline
                   with navigation, where a mistap costs a wallet connect. -->
              <button
                type="button"
                class="item leave"
                :disabled="leaving"
                :title="leaving
                  ? 'Disconnecting…'
                  : 'Disconnect the wallet — the character stays with it, and signing back in costs another transfer'"
                @click="disconnect"
              >
                <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor"
                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path :d="ACTION_PATHS.leave" />
                </svg>
                <span class="grow">{{ leaving ? 'Disconnecting…' : 'Log Out' }}</span>
              </button>
            </div>
          </nav>
        </Transition>
      </div>

      <!-- Shuts the menu from anywhere on the map without swallowing the tap
           that opened it. Under the plate, over everything else. -->
      <div v-if="menuOpen" class="menu-scrim" @click="closeMenu" />

      <!-- -------------------------------------------------- bottom center -->
      <div ref="bottomStack" class="corner bottom-center">
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
          :title="placeLabel(station.settlement.name, station.settlement.col, station.settlement.row)"
          @close="game.closeStation()"
        >
          <StationPanel :settlement="station.settlement" />
        </PanelOverlay>
      </Transition>

      <!-- §10.0.4 -- getting a guild and running one, both at the settlement. -->
      <Transition name="fade">
        <PanelOverlay
          v-if="game.halls && !panel"
          :title="game.guild?.name ?? 'Halls'"
          @close="game.closeHalls()"
        >
          <HallsPanel />
        </PanelOverlay>
      </Transition>

      <!-- Last, and highest: a toast reporting a failure inside a panel has to
           land in front of it. Source order matches the stacking ladder so this
           survives someone editing the z-index out. -->
      <Toasts />

      <!-- §4 -- the receipt for a finished mine. Over everything, because it
           is the one moment in an idle game where something happened. -->
      <HaulModal v-if="game.haul" :haul="game.haul" @close="game.clearHaul()" />
      <!-- §9.5.5 -- the exchange while it is drawn, then the receipt it
           produced. Never both: the plate replaces the fight. -->
      <BattleLive
        v-if="game.liveBattle && !game.battle"
        :job="game.liveBattle"
        @done="game.finishLiveBattle()"
      />
      <BattleModal v-if="game.battle" :battle="game.battle" @close="game.clearBattle()" />

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
  /*
   * The cell size lives on the corner rather than on `.screens`, because the
   * menu hangs off the bottom of the block and has to know how tall it is. Two
   * places holding that number is two places to change it and one of them to
   * forget.
   */
  --cell-w: 46px;
  --cell-h: 40px;
}

.screens {
  /*
   * Three cells zigzagging DOWN, nested the way §13.2 tiles the map: three
   * quarters of a width between the two columns, and the left one dropped half
   * a height so the points interlock.
   *
   * Down rather than across, because down is the free direction. Running across
   * spent the one thing this corner has least of: the top edge is shared with
   * the instrument cluster, so anything growing leftward grows toward it, while
   * the map below the corner is the part of the screen a HUD may hang into.
   *
   * A straight column was tried in between, and it is the honest version of
   * "down" and the wrong one of "honeycomb": stacked in one column these meet
   * along their flat edges and read as three separate buttons in a row. The
   * zigzag is what makes them a piece of lattice — the same shape the map is
   * made of, which is the whole argument §13 makes for the hexagon in the first
   * place. Two cells tall and under two wide, so it costs the corner nothing.
   */
  display: grid;
  grid-template-columns: calc(var(--cell-w) * 0.75) var(--cell-w);
  grid-auto-rows: calc(var(--cell-h) / 2);
  justify-items: start;
}

/*
 * The right column holds the first and last, the left one hangs between them.
 * The burger is last and therefore lowest and rightmost — nearest the thumb,
 * and directly over the list it drops.
 */
.screens :deep(.cell:nth-child(1)) { grid-column: 2; grid-row: 1 / span 2; }
.screens :deep(.cell:nth-child(2)) { grid-column: 1; grid-row: 2 / span 2; }
.screens :deep(.cell:nth-child(3)) { grid-column: 2; grid-row: 3 / span 2; }

/* Nested cells overlap at the tips, so hit-testing has to follow the hexagon
   rather than the box, or the pointed corner of one cell would swallow clicks
   meant for its neighbor. */
.screens :deep(.cell) {
  clip-path: var(--hex-clip);
}

/*
 * The face is sized from the same two variables the lattice is, so the cells and
 * the gaps between them can never disagree. HexAction carries its own default
 * size for the bottom dock, where the hexes are not nested into anything; left
 * to that default, this block's grid would grow and its hexes would not.
 */
.screens :deep(.cell .hex) {
  width: var(--cell-w);
  height: var(--cell-h);
}

.screens :deep(.cell .name) {
  display: none;
}

/* ------------------------------------------------------------- the screens */

/*
 * The list, hung under the pair.
 *
 * A plate rather than more honeycomb: a flower is a shape you take in at a
 * glance, and this is a thing you read down. Nine hexagons unfolding out of a
 * corner would be exactly the drawing this replaced.
 */
.menu {
  position: absolute;
  /* The zigzag is two cells tall, plus the corner's own gap. */
  top: calc(var(--cell-h) * 2 + 10px);
  right: 0;
  z-index: 30;
  width: 208px;
}

.menu-inner {
  padding: 5px;
}

.menu .item {
  display: flex;
  align-items: center;
  gap: 9px;
  width: 100%;
  padding: 8px 9px;
  font-size: 12.5px;
  font-weight: 600;
  color: var(--vellum-dim);
  text-align: left;
  clip-path: polygon(7px 0, 100% 0, 100% calc(100% - 7px), calc(100% - 7px) 100%, 0 100%, 0 7px);
}

.menu .item:hover:not(:disabled) {
  color: var(--vellum);
  background: var(--ink-raised);
}

.menu .item:disabled {
  opacity: 0.55;
  cursor: default;
}

.menu .item svg {
  flex: 0 0 auto;
  color: var(--vellum-dim);
}

.menu .item:hover:not(:disabled) svg {
  color: var(--vellum);
}

/*
 * §13.3 -- the badge says WHY the burger is lit, which is the half a roll-up
 * cannot carry. A dot rather than a count: the number is in the hint and on the
 * panel, and three digits in a menu row is a dashboard.
 */
.menu .mark {
  flex: 0 0 auto;
  width: 7px;
  height: 7px;
  clip-path: var(--hex-clip);
  background: var(--sap);
}

.menu .item.alert .mark {
  background: var(--ember);
}

.menu .item.alert,
.menu .item.good {
  color: var(--vellum);
}

/* §2 -- the one destructive row, kept below a rule so a mistap on the way down
   the list cannot cost a wallet connect. */
.menu .item.leave {
  margin-top: 5px;
  padding-top: 10px;
  border-top: 1px solid var(--line);
  clip-path: none;
}

.menu .item.leave:hover:not(:disabled) {
  color: var(--ember);
  background: none;
}

.menu .item.leave:hover:not(:disabled) svg {
  color: var(--ember);
}

/*
 * Over the map and UNDER the corner, so a tap anywhere else shuts the list
 * without also landing on a hex.
 *
 * Under is the load-bearing word. `.corner` carries `--z-hud` and is therefore
 * a stacking context, so the menu's own z-index is only meaningful *inside* it:
 * a scrim numbered above `--z-hud` sits above the entire corner, buries the
 * plate it was meant to sit behind, and eats the clicks aimed at it. The menu
 * opened and was invisible.
 */
.menu-scrim {
  position: absolute;
  inset: 0;
  z-index: calc(var(--z-hud) - 1);
}

.screens :deep(.cell:hover:not(:disabled) .hex) {
  transform: none;
}

.bottom-center {
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
 * Phones: the dock needs the full width, and the mine stack has to yield to it
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

  .bottom-center {
    bottom: calc(6px + env(safe-area-inset-bottom, 0px));
    left: calc(6px + env(safe-area-inset-left, 0px));
    right: calc(6px + env(safe-area-inset-right, 0px));
    transform: none;
  }

  /*
   * Tighter cells on a phone, where the pair shares the top edge with the
   * instrument cluster. The nesting maths reads these two, so it closes up with
   * them rather than coming apart.
   */
  .top-right {
    --cell-w: 38px;
    --cell-h: 33px;
  }

  /* The list takes the width it is given rather than a fixed 208 that would run
     off a narrow screen. */
  .menu {
    width: min(208px, calc(100vw - 24px));
  }

}
</style>
