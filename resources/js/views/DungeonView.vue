<script setup lang="ts">
/**
 * §9.6 -- a floor, drawn by the map.
 *
 * **It is the same `HexMap` the overworld uses**, and that is the whole point of
 * this file. It was a second map: its own hexes, its own fog, its own click
 * handling, its own monster drawing -- and every one of those arrived as a bug
 * to be fixed separately, because each was a fresh implementation of something
 * that already worked ten feet away. Fog, the sight ring, the painter's sort,
 * props, panning, the prospector marker wearing your coat and weapon: all of it
 * is free here and none of it was free there.
 *
 * What a floor has to do is produce `Tile[]`, because that is the only thing the
 * map wants. A dungeon hex is an ordinary tile with a monster standing on it --
 * so the monster goes in `pack`, which is what the map already draws as an
 * unframed haloed silhouette on the ground (§13.2), and the fog falls out of
 * passing the walker's position and their sight.
 *
 * The ground is the DUNGEON'S OWN COUNTRY, which is not a compromise: §9.6.2
 * stocks a floor from the country the mouth belongs to, so Rootvault's floors
 * being forest is the same fact the roster already states.
 *
 * Two things are genuinely the dungeon's own and stay here: the crown, because
 * a floor has a depth and a gate and a clock that a hex does not, and the dock,
 * because Descend and Walk out are verbs the overworld has no equivalent of.
 */
import { computed, ref } from 'vue'
import { useGame } from '@/stores/game'
import HexMap from '@/map/HexMap.vue'
import { BIOME_VARIANTS } from '@/game/variants'
import type { Biome, Tile, VariantKey } from '@/game/types'
import { MONSTERS } from '@/game/monsters'
import { DUNGEONS } from '@/game/catalog'

const game = useGame()

const session = computed(() => game.dungeon)

/** §9.6.2 -- the country a mouth belongs to. Beastwarren belongs to none. */
const biome = computed<Biome>(() => {
  const key = session.value?.dungeon
  const site = DUNGEONS.find((d) => d.key === key)

  return (site?.biome as Biome) ?? 'mountain'
})

/** How far around the walker the floor is built. The map fogs it past sight. */
const VIEW = 7

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
 * The floor as tiles the map can draw.
 *
 * `dead` is true and there is no `material`, because a dungeon floor is not
 * ground you work -- §9.6 gives a floor monsters and a stair and nothing to
 * mine -- and that is exactly what dead ground already means to the renderer.
 */
const tiles = computed<Tile[]>(() => {
  const d = session.value
  if (!d?.inside) return []

  const out: Tile[] = []
  const size = d.size ?? 50
  const variant = (BIOME_VARIANTS[biome.value]?.[0]?.key ?? biome.value) as VariantKey

  for (let col = d.col - VIEW; col <= d.col + VIEW; col++) {
    for (let row = d.row - VIEW; row <= d.row + VIEW; row++) {
      if (col < 0 || row < 0 || col >= size || row >= size) continue
      if (hexAway(d.col, d.row, col, row) > VIEW) continue

      const known = game.dungeonTiles.get(`${col},${row}`)
      const monster = known?.monster ?? null

      out.push({
        col,
        row,
        biome: biome.value,
        variant,
        ring: 'center',
        dead: true,
        hp: 0,
        baseYield: 0,
        extractions: 0,
        slotsUsed: 0,
        workers: 0,
        taken: 0,
        regrowsAt: 0,
        // §9.5.1 -- the monster IS a pack as far as the map is concerned, which
        // is what gets it drawn standing on the ground with its halo rather
        // than framed in a crest.
        pack: monster
          ? { key: monster.key, bucket: 0, until: Number.MAX_SAFE_INTEGER }
          : undefined,
        propSeed: col * 73856093 + row * 19349663,
      })
    }
  }

  return out
})

/** §9.6.2 -- the stair, shown from the landing however far off it is. */
const toStair = computed(() => {
  const d = session.value
  if (!d?.stair) return null

  return hexAway(d.col, d.row, d.stair.col, d.stair.row)
})

const onStair = computed(() => {
  const d = session.value

  return Boolean(d?.stair && d.col === d.stair.col && d.row === d.stair.row)
})

const underfoot = computed(() => game.dungeonUnderfoot)

const kills = computed(() => session.value?.kills ?? 0)
const needed = computed(() => session.value?.killsNeeded ?? 6)

const waiting = computed(() => {
  const until = session.value?.busyUntil ?? null

  return until !== null && game.now < until
})

const closesIn = computed(() => {
  const left = Math.max(0, (session.value?.expiresAt ?? 0) - game.now)
  const hours = Math.floor(left / 3_600_000)
  const minutes = Math.floor((left % 3_600_000) / 60_000)

  return hours > 0 ? `${hours}h ${minutes}m` : `${minutes}m`
})

// ------------------------------------------------------------------ walking

const walking = ref(false)

/** The camera, which pans independently of the walker exactly as it does outside. */
const camera = ref<{ col: number; row: number } | null>(null)

const centre = computed(() => camera.value ?? { col: session.value?.col ?? 0, row: session.value?.row ?? 0 })

const picked = ref<{ col: number; row: number } | null>(null)

function select(col: number, row: number): void {
  picked.value = { col, row }
}

/**
 * §5.6 -- point at ground and go, which is what the overworld does.
 *
 * The server takes one hex at a time, because a step is a step and each costs
 * its five seconds, so the path is walked a press at a time and stops the
 * moment anything interrupts -- a live monster pins you (§9.5.3), and the walk
 * ends with the fight in front of you rather than pushing past it.
 */
async function walk(): Promise<void> {
  const target = picked.value
  const d = session.value
  if (!target || !d?.inside || walking.value || underfoot.value) return

  walking.value = true

  try {
    for (let guard = 0; guard < 80; guard++) {
      const at = session.value
      if (!at?.inside) break
      if (at.col === target.col && at.row === target.row) break
      if (game.dungeonUnderfoot) break

      const before = `${at.col},${at.row}`
      const next = toward(at.col, at.row, target.col, target.row)

      await game.stepDungeon(next[0], next[1])

      const now = session.value
      if (!now || `${now.col},${now.row}` === before) break

      await new Promise((resolve) => setTimeout(resolve, Math.max(110, game.travelPerHexMs)))
    }
  } finally {
    walking.value = false
    picked.value = null
  }
}

/** One hex of the line from here to there, in offset coordinates. */
function toward(col: number, row: number, toCol: number, toRow: number): [number, number] {
  const [ax, ay, az] = cube(col, row)
  const [bx, by, bz] = cube(toCol, toRow)
  const steps = Math.max(Math.abs(ax - bx), Math.abs(ay - by), Math.abs(az - bz))
  const t = 1 / Math.max(1, steps)

  const x = ax + (bx - ax) * t
  const y = ay + (by - ay) * t
  const z = az + (bz - az) * t

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

const pickedName = computed(() => {
  const p = picked.value
  if (!p) return null

  const known = game.dungeonTiles.get(`${p.col},${p.row}`)
  const monster = known?.monster

  return monster ? MONSTERS[monster.key]?.name ?? monster.name : null
})

const pickedAway = computed(() => {
  const p = picked.value
  const d = session.value

  return p && d ? hexAway(d.col, d.row, p.col, p.row) : 0
})

/** §9.6.8 -- what the last fight paid, kept until the next one. */
const receipt = computed(() => {
  const f = game.dungeonFight
  if (!f?.won) return null

  const rows: Array<{ what: string; good: boolean }> = []

  if (f.gold > 0) rows.push({ what: `${f.gold} gold`, good: false })
  for (const [k, n] of Object.entries(f.spoils ?? {})) {
    rows.push({ what: `${n} × ${k.replace(/_/g, ' ')}`, good: false })
  }
  for (const [k, n] of Object.entries(f.treasure ?? {})) {
    rows.push({ what: `${n} × ${k.replace(/_/g, ' ')}`, good: true })
  }
  if (f.looted) rows.push({ what: f.looted.name, good: false })
  if (f.prize) rows.push({ what: f.prize.name, good: true })

  return rows.length > 0 ? rows : null
})
</script>

<template>
  <div v-if="session?.inside" class="floor">
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

    <!-- The map. The same one, doing all of it. -->
    <div class="board">
      <HexMap
        :tiles="tiles"
        :center-col="centre.col"
        :center-row="centre.row"
        :character-col="session.col"
        :character-row="session.row"
        :sight="session.sight ?? 1"
        :px="game.view.px"
        :selected="picked"
        :jobs="[]"
        :travel="null"
        :now="game.now"
        :carriers="[]"
        :worn="game.worn"
        @select="select"
        @recenter="camera = null"
      />
    </div>

    <div class="dock">
      <p v-if="underfoot" class="here-is">
        <span class="mob" :class="{ boss: underfoot.guardian }">{{ underfoot.name }}</span>
        <span class="mob-of">{{ underfoot.profile }} · tier {{ underfoot.tier }}</span>
      </p>
      <p v-else-if="picked" class="here-is quiet">
        {{ pickedName ?? 'Floor' }} · {{ pickedAway }} hexes off
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
          v-else-if="picked && pickedAway > 0"
          class="btn"
          type="button"
          :disabled="game.busy || waiting || walking"
          @click="walk"
        >
          {{ walking ? 'Walking…' : `Walk ${pickedAway}` }}
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

      <ul v-if="receipt" class="receipt">
        <li v-for="row in receipt" :key="row.what" :class="{ prize: row.good }">{{ row.what }}</li>
      </ul>

      <p v-if="underfoot" class="pinned">It is on you. Nothing else until it is settled.</p>
    </div>
  </div>
</template>

<style scoped>
/*
 * FIXED, not absolute: the app shell is a positioned ancestor, and hanging off
 * it clipped the header off the top of the screen. A screen that replaces the
 * screen is positioned against the screen.
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
 * Named `crown` rather than `bar`, because `app.css` owns `.bar` globally as
 * the progress-bar class and a scoped block is not a namespace: it inherited a
 * five-pixel height it never asked for and clipped its own contents.
 */
.crown {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 12px 16px;
  border-bottom: 1px solid var(--line);
}

.board {
  flex: 1 1 auto;
  min-height: 0;
  position: relative;
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
