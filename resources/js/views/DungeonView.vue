<script setup lang="ts">
/**
 * §9.6 -- a floor, from inside it.
 *
 * This is the one screen in the game that is not the map, because a floor is not
 * on the map: it is a second coordinate space that stands for twelve hours and
 * then stops existing. So it takes the whole screen rather than opening as a
 * panel over the world — what is behind it is not where you are.
 *
 * **It draws the disc and nothing else**, which is §5.6's rule arriving
 * somewhere new. The payload is bounded by sight (§9.6.2's second half: the
 * secret stops a client deriving the floor, the bound stops it being told), so
 * there is nothing here to draw a wider view with even if the screen wanted one.
 * The stair is the exception and is drawn at any distance, because §9.6.2 shows
 * it from the landing: where it is, is random; that you know where it is, is not.
 *
 * The geometry is the map's own — the same squashed flat-top slabs, the same
 * tiling, the same painter's sort (§13.2) — because a floor is hexes and the
 * game has one way of drawing hexes.
 */
import { computed } from 'vue'
import { useGame } from '@/stores/game'
import { HEX_SIDE_PATH, HEX_TOP_PATH, tileToScreen } from '@/map/hexGeometry'
import { COPPER, GOLD, VELLUM, shade } from '@/theme/palette'
import { monsterCrest } from '@/icons/combatants'

const game = useGame()

const session = computed(() => game.dungeon)

/** The six neighbours and the hex underfoot, in the same offsets the map uses. */
const NEIGHBOURS = [
  [0, -1],
  [1, -1],
  [1, 0],
  [0, 1],
  [-1, 0],
  [-1, -1],
] as const

const FLOOR_FILL = '#2a2724'

/**
 * Painter's algorithm (§13.2): sorted by screen Y so a slab occludes the one
 * behind it. Drawn relative to the walker, because the disc travels with them.
 */
const tiles = computed(() => {
  const d = session.value
  if (!d?.inside) return []

  const out = []

  for (const tile of d.tiles ?? []) {
    const dx = tile.col - d.col
    const dy = tile.row - d.row
    const here = dx === 0 && dy === 0
    const stair = d.stair && tile.col === d.stair.col && tile.row === d.stair.row

    out.push({
      key: `${tile.col},${tile.row}`,
      col: tile.col,
      row: tile.row,
      here,
      // A neighbour is a hex you may walk onto, monster and all -- §9.6.4 needs
      // everybody fighting to be standing on one hex, so stepping into one is
      // how a fight starts rather than something to be refused.
      step: !here && NEIGHBOURS.some(([nx, ny]) => nx === dx && ny === dy),
      stair: Boolean(stair),
      monster: tile.monster,
      ...tileToScreen(dx, dy),
      fill: stair ? shade(COPPER, -0.55) : FLOOR_FILL,
    })
  }

  return out.sort((a, b) => a.y - b.y)
})

const underfoot = computed(() => game.dungeonUnderfoot)

/**
 * §9.6.8 -- what the last fight paid, and it has to be SEEN.
 *
 * A toast saying "it goes down" is not a receipt: the whole point of going down
 * is the table, and drops that only show up by diffing your own bag are drops
 * nobody knows they got. Kept until the next fight rather than timed out,
 * because the thing it reports is the reason you are here.
 */
const receipt = computed(() => {
  const f = game.dungeonFight
  if (!f?.won) return null

  const rows: Array<{ what: string; good: boolean }> = []

  if (f.gold > 0) rows.push({ what: `${f.gold} gold`, good: false })

  for (const [key, n] of Object.entries(f.spoils ?? {})) {
    rows.push({ what: `${n} × ${key.replace(/_/g, ' ')}`, good: false })
  }

  // §9.6.8's own table, marked as the thing worth crossing the screen for.
  for (const [key, n] of Object.entries(f.treasure ?? {})) {
    rows.push({ what: `${n} × ${key.replace(/_/g, ' ')}`, good: true })
  }

  if (f.looted) rows.push({ what: f.looted.name, good: false })
  if (f.prize) rows.push({ what: f.prize.name, good: true })

  return rows.length > 0 ? rows : null
})

const onStair = computed(() => {
  const d = session.value
  return Boolean(d?.stair && d.col === d.stair.col && d.row === d.stair.row)
})

/** §9.6.2 -- how far the gate still is. */
const kills = computed(() => session.value?.kills ?? 0)
const needed = computed(() => session.value?.killsNeeded ?? 6)

const busyFor = computed(() => {
  const until = session.value?.busyUntil ?? null
  if (until === null) return 0
  return Math.max(0, until - game.now)
})

const waiting = computed(() => busyFor.value > 0)

/** §9.6.1 -- when the session closes and everybody is outside it. */
const closesIn = computed(() => {
  const at = session.value?.expiresAt ?? 0
  const left = Math.max(0, at - game.now)
  const hours = Math.floor(left / 3_600_000)
  const minutes = Math.floor((left % 3_600_000) / 60_000)

  return hours > 0 ? `${hours}h ${minutes}m` : `${minutes}m`
})

/** How far the stair is, in hexes, so the walk is a number rather than a guess. */
const toStair = computed(() => {
  const d = session.value
  if (!d?.stair) return null

  const ax = d.col
  const az = d.row - (d.col - (d.col & 1)) / 2
  const bx = d.stair.col
  const bz = d.stair.row - (d.stair.col - (d.stair.col & 1)) / 2

  return Math.max(Math.abs(ax - bx), Math.abs(az - bz), Math.abs(-ax - az + bx + bz))
})

/**
 * §9.5.2 -- the profile owns the silhouette and the tier owns the hide, so a
 * crest needs both. The key is what picks the one mark that says which of them
 * it is.
 */
function crest(monster: { key: string; profile: string; tier: number }): string {
  return monsterCrest(monster.profile, monster.tier, 28, false, monster.key)
}
</script>

<template>
  <div v-if="session?.inside" class="floor">
    <!-- What this run is, and how much of it is left. -->
    <header class="crown">
      <div class="where">
        <p class="place">{{ session.dungeon }}</p>
        <p class="depth">Floor {{ session.floor }} of {{ session.floors }}</p>
      </div>

      <div class="gate" :class="{ open: session.floorOpen }">
        <p class="label">Cleared</p>
        <p class="tally">{{ kills }}<span>/{{ needed }}</span></p>
      </div>

      <div class="clock">
        <p class="label">Closes</p>
        <p class="left">{{ closesIn }}</p>
      </div>
    </header>

    <!-- The disc, and only the disc. -->
    <div class="disc">
      <svg viewBox="-110 -86 220 172" role="presentation">
        <g
          v-for="tile in tiles"
          :key="tile.key"
          class="tile"
          :class="{ walkable: tile.step && !underfoot && !waiting && !game.busy }"
          :transform="`translate(${tile.x},${tile.y})`"
          @click="tile.step && !underfoot && !waiting && !game.busy && game.stepDungeon(tile.col, tile.row)"
        >
          <path :d="HEX_SIDE_PATH" :fill="shade(tile.fill, -0.45)" />
          <path
            :d="HEX_TOP_PATH"
            :fill="tile.fill"
            :stroke="tile.here ? COPPER : shade(tile.fill, -0.5)"
            :stroke-width="tile.here ? 1.6 : 1"
          />

          <!-- The stair, drawn as a way down rather than a symbol to decode. -->
          <g v-if="tile.stair" :transform="`translate(-9,-7)`">
            <rect width="18" height="3.5" :fill="GOLD" />
            <rect y="4.5" x="2.5" width="13" height="3.5" :fill="shade(GOLD, -0.25)" />
            <rect y="9" x="5" width="8" height="3.5" :fill="shade(GOLD, -0.45)" />
          </g>

          <!-- §13.2 -- the halo says this is news. A guardian wears gold. -->
          <g v-if="tile.monster" v-html="crest(tile.monster)" :transform="`translate(-14,-20)`" />
        </g>

        <!-- The prospector, last, over their own hex. -->
        <g class="me">
          <circle r="3.2" :fill="VELLUM" />
        </g>
      </svg>
    </div>

    <!-- What is on this hex, and what may be done about it. -->
    <div class="dock">
      <p v-if="underfoot" class="here-is">
        <span class="mob" :class="{ boss: underfoot.guardian }">{{ underfoot.name }}</span>
        <span class="mob-of">{{ underfoot.profile }} · tier {{ underfoot.tier }}</span>
      </p>
      <p v-else-if="onStair && !session.floorOpen" class="here-is quiet">
        The stair. {{ needed - kills }} more to clear before it opens.
      </p>
      <p v-else-if="onStair" class="here-is quiet">The stair, and the floor is done with you.</p>
      <p v-else class="here-is quiet">
        Open floor.<span v-if="toStair !== null"> The stair is {{ toStair }} hexes off.</span>
      </p>

      <div class="acts">
        <button
          v-if="underfoot"
          class="btn fight"
          type="button"
          :disabled="game.busy || waiting"
          @click="game.fightDungeon()"
        >
          Fight
        </button>

        <button
          v-if="onStair && session.floorOpen && !underfoot"
          class="btn"
          type="button"
          :disabled="game.busy || waiting"
          @click="game.descendDungeon()"
        >
          Descend
        </button>

        <button class="btn ghost" type="button" :disabled="game.busy" @click="game.leaveDungeon()">
          Walk out
        </button>
      </div>

      <!-- What the last one paid. §13.3: sap is worth crossing the screen for. -->
      <ul v-if="receipt" class="receipt">
        <li v-for="row in receipt" :key="row.what" :class="{ prize: row.good }">{{ row.what }}</li>
      </ul>

      <p v-if="underfoot" class="pinned">It is on you. Nothing else until it is settled.</p>
    </div>
  </div>
</template>

<style scoped>
/*
 * FIXED, not absolute. `absolute` resolves against the nearest positioned
 * ancestor, and the app shell is one -- so the floor was hung off a box that
 * starts above the viewport: the header clipped off the top and the map showed
 * through at the edges. A screen that replaces the screen is positioned against
 * the screen.
 */
.floor {
  position: fixed;
  inset: 0;
  z-index: var(--z-panel);
  display: flex;
  flex-direction: column;
  background: var(--ink);
}

/*
 * Named `crown` rather than `bar`, and that is not a style preference.
 *
 * `app.css` owns `.bar` globally -- it is the progress-bar class, five pixels
 * tall -- so a scoped `.bar` here inherited a height it never asked for and
 * clipped its own contents to a single pixel. Scoping raises specificity for
 * properties you SET; it does nothing about a property you left alone that the
 * global rule happens to set for you.
 *
 * The lesson is the name, not the override: a scoped block is not a namespace,
 * and reusing a word the global sheet already owns will keep finding new ways
 * to be wrong.
 */
.crown {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 12px 16px;
  border-bottom: 1px solid var(--line);
}

.where {
  flex: 1;
  min-width: 0;
}

.place {
  margin: 0;
  font-family: var(--font-display);
  font-size: 17px;
  text-transform: capitalize;
}

.depth,
.left,
.tally {
  margin: 0;
}

.depth {
  color: var(--vellum-dim);
  font-size: 11px;
}

.label {
  margin: 0;
  color: var(--copper);
  font-size: 9px;
  letter-spacing: 0.14em;
  text-transform: uppercase;
}

.gate,
.clock {
  text-align: right;
}

.tally {
  font-family: var(--font-mono, monospace);
  font-size: 16px;
  color: var(--vellum);
}

.tally span {
  color: var(--vellum-dim);
  font-size: 12px;
}

/* §13.3 -- sap is a thing worth crossing the screen for, and an open stair is. */
.gate.open .tally {
  color: var(--sap);
}

.left {
  font-family: var(--font-mono, monospace);
  font-size: 13px;
  color: var(--vellum-dim);
}

.disc {
  flex: 1 1 auto;
  min-height: 0;
  display: grid;
  place-items: center;
  padding: 8px;
}

.disc svg {
  width: min(100%, 420px);
  height: auto;
  overflow: visible;
}

.dock {
  position: relative;
  flex: 0 0 auto;
  padding: 12px 16px 16px;
  border-top: 1px solid var(--line);
}

.here-is {
  margin: 0 0 10px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.here-is.quiet {
  color: var(--vellum-dim);
  font-size: 12px;
}

.mob {
  font-size: 15px;
  color: var(--vellum);
}

/* §13.3 -- ember is a state to deal with, and the thing on the stair is one. */
.mob.boss {
  color: var(--ember);
}

.mob-of {
  color: var(--vellum-dim);
  font-size: 10.5px;
  text-transform: capitalize;
}

.acts {
  display: flex;
  gap: 8px;
}

.acts .btn {
  flex: 1;
  min-height: 42px;
  background: var(--copper);
  color: var(--ink);
}

.acts .fight {
  background: var(--ember);
  color: var(--vellum);
}

.acts .ghost {
  flex: 0 0 auto;
  padding-inline: 16px;
  background: var(--ink-raised);
  color: var(--vellum);
}

.btn:disabled {
  opacity: 0.45;
  cursor: default;
}

/*
 * The hex IS the control, rather than a row of buttons floating over the disc.
 *
 * They were absolutely-positioned overlays anchored to the dock, which put them
 * two hundred pixels below the hexes they were meant to be on -- the drawing and
 * the target agreed about nothing. A hex you can walk onto is a hex you press.
 */
.tile.walkable {
  cursor: pointer;
}

.tile.walkable:hover path:last-of-type {
  stroke: var(--copper);
  stroke-width: 1.4;
}

.receipt {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 10px;
  margin: 9px 0 0;
  padding: 0;
  list-style: none;
  color: var(--vellum-dim);
  font-size: 11px;
  text-transform: capitalize;
}

.receipt .prize {
  color: var(--sap);
}

.pinned {
  margin: 8px 0 0;
  color: var(--ember);
  font-size: 11px;
}
</style>
