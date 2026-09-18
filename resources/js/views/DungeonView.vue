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
import { computed, ref } from 'vue'
import { useGame } from '@/stores/game'
import { HEX_SIDE_PATH, HEX_TOP_PATH, tileToScreen } from '@/map/hexGeometry'
import { COPPER, GOLD, VELLUM, shade } from '@/theme/palette'
import { monsterOnGround } from '@/map/props'

const game = useGame()

const session = computed(() => game.dungeon)

const FLOOR_FILL = '#2a2724'

/** How far around the walker the floor is drawn, fog and all. */
const VIEW = 4

/** Axial conversion, the same one the map and the server use. */
function cube(col: number, row: number): [number, number, number] {
  const x = col
  const z = row - (col - (col & 1)) / 2

  return [x, -x - z, z]
}

function hexAway(aCol: number, aRow: number, bCol: number, bRow: number): number {
  const [ax, ay, az] = cube(aCol, aRow)
  const [bx, by, bz] = cube(bCol, bRow)

  return Math.max(Math.abs(ax - bx), Math.abs(ay - by), Math.abs(az - bz))
}

/**
 * The floor around the walker, fogged past sight.
 *
 * §13.2's rule, arriving underground: **unscouted is a darker SOLID fill, never
 * opacity** -- transparency ghosts through neighbouring hexes. So the ground
 * out there is drawn, and what is standing on it is not: the payload describes
 * only the disc (§9.6.2), and a hex with no description is one nobody has been
 * near.
 *
 * It used to draw the seven described tiles and nothing else, which made a
 * floor look like an island floating in the dark rather than a room you can see
 * part of.
 *
 * Painter's algorithm (§13.2): sorted by screen Y so a slab occludes the one
 * behind it.
 */
const tiles = computed(() => {
  const d = session.value
  if (!d?.inside) return []

  const out = []
  const size = d.size ?? 50

  for (let col = d.col - VIEW; col <= d.col + VIEW; col++) {
    for (let row = d.row - VIEW; row <= d.row + VIEW; row++) {
      if (col < 0 || row < 0 || col >= size || row >= size) continue
      if (hexAway(d.col, d.row, col, row) > VIEW) continue

      const dx = col - d.col
      const dy = row - d.row
      const here = dx === 0 && dy === 0
      const known = game.dungeonTiles.get(`${col},${row}`)
      const stair = Boolean(d.stair && col === d.stair.col && row === d.stair.row)

      const base = stair ? shade(COPPER, -0.55) : FLOOR_FILL

      out.push({
        key: `${col},${row}`,
        col,
        row,
        here,
        // Scouted is "the server described this hex", which is exactly the disc
        // it answered with. Nothing else has to be recomputed.
        scouted: known !== undefined,
        stair,
        monster: known?.monster ?? null,
        ...tileToScreen(dx, dy),
        fill: known !== undefined ? base : shade(base, -0.42),
      })
    }
  }

  return out.sort((a, b) => a.y - b.y)
})

const underfoot = computed(() => game.dungeonUnderfoot)

/**
 * §5.6 -- walk to a hex you picked, the way the overworld does.
 *
 * It was one step onto one of six neighbours, which is not how anybody walks
 * anywhere else in this game: out in the world you point at ground and go. The
 * server still only takes a hex at a time -- a step is a step, and each one
 * costs its five seconds -- so the path is walked here, one press at a time,
 * and it stops the moment anything interrupts.
 *
 * **It stops rather than pushing through.** Stepping onto a live monster pins
 * you (§9.5.3), so the walk ends there with the fight in front of you rather
 * than trying to continue past something that is looking at you.
 */
async function walkTo(col: number, row: number): Promise<void> {
  const d = session.value
  if (!d?.inside || walking.value || underfoot.value) return
  if (col === d.col && row === d.row) return

  walking.value = true

  try {
    // Recomputed each step against where we actually are, rather than plotting
    // once: a step that is refused leaves the position unchanged, and a path
    // held from before would walk on regardless.
    for (let guard = 0; guard < 64; guard++) {
      const at = session.value
      if (!at?.inside) break
      if (at.col === col && at.row === row) break
      if (game.dungeonUnderfoot) break

      const next = toward(at.col, at.row, col, row)
      const before = `${at.col},${at.row}`

      await game.stepDungeon(next[0], next[1])

      const now = session.value
      // Refused, for whatever reason the server gave. Stop rather than spin.
      if (!now || `${now.col},${now.row}` === before) break

      await new Promise((resolve) => setTimeout(resolve, stepMs.value))
    }
  } finally {
    walking.value = false
  }
}

const walking = ref(false)

/** One hex of the line from here to there, in offset coordinates. */
function toward(col: number, row: number, toCol: number, toRow: number): [number, number] {
  const [ax, ay, az] = cube(col, row)
  const [bx, by, bz] = cube(toCol, toRow)
  const steps = Math.max(Math.abs(ax - bx), Math.abs(ay - by), Math.abs(az - bz))
  const t = 1 / Math.max(1, steps)

  let x = ax + (bx - ax) * t
  let y = ay + (by - ay) * t
  let z = az + (bz - az) * t

  let rx = Math.round(x)
  let ry = Math.round(y)
  let rz = Math.round(z)

  const dx = Math.abs(rx - x)
  const dy = Math.abs(ry - y)
  const dz = Math.abs(rz - z)

  if (dx > dy && dx > dz) rx = -ry - rz
  else if (dy > dz) ry = -rx - rz
  else rz = -rx - ry

  return [rx, rz + (rx - (rx & 1)) / 2]
}

/** How long a step takes, so the walk paces itself rather than racing the clock. */
const stepMs = computed(() => Math.max(120, game.travelPerHexMs))

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
 * §13.2 -- the creature STANDS ON the hex; it is not framed inside one.
 *
 * It was a crest, which is a portrait in a hexagon of its own -- so a monster
 * on a floor came out as a picture pasted into the tile's border rather than a
 * thing standing on the ground. The map has never done that: a pack is the same
 * silhouette, unframed and haloed, because the tile already is the frame.
 *
 * This is literally the map's own function, so a Moss Hound underground is the
 * same drawing as a Moss Hound on a road.
 */
function onGround(monster: { key: string; profile: string; tier: number }): string {
  return monsterOnGround(monster.key, monster.profile, monster.tier, 7, 11, 24)
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
          :class="{ walkable: !tile.here && !underfoot && !waiting && !walking }"
          :transform="`translate(${tile.x},${tile.y})`"
          @click="!tile.here && !underfoot && !waiting && !walking && walkTo(tile.col, tile.row)"
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
          <g v-if="tile.monster" v-html="onGround(tile.monster)" />
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
