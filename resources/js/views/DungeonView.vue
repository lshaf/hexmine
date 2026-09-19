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
import { computed, ref, watch } from 'vue'
import { useGame } from '@/stores/game'
import HexMap from '@/map/HexMap.vue'

import type { Tile } from '@/game/types'
import { MONSTERS } from '@/game/monsters'
import { VAULT } from '@/theme/palette'
import { COL_STEP, visibleTiles } from '@/map/hexGeometry'

const game = useGame()

const session = computed(() => game.dungeon)

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
 * The camera, and it does NOT follow the walker.
 *
 * It used to be pinned to the prospector, so a floor could only ever be looked
 * at from directly above whoever was reading it -- which is not how the map
 * works outside, where panning costs nothing and the way back is a button. A
 * floor is a room six people are in; being unable to look at the far end of it
 * was the difference doing the most damage.
 *
 * Set once when a floor is entered and then left alone. `Here` recentres.
 */
const camera = ref<{ col: number; row: number } | null>(null)

const centre = computed(() => camera.value ?? { col: session.value?.col ?? 0, row: session.value?.row ?? 0 })

watch(
  () => [session.value?.floor, session.value?.inside] as const,
  () => {
    camera.value = null
  },
)

/** §9.6.9 -- the roster, on this floor, through the fog. */
const mates = computed(() => (session.value?.party ?? []).filter((m) => m.here))

/** Those who are not, so the dock can say so rather than the map lying by omission. */
const elsewhere = computed(() => (session.value?.party ?? []).filter((m) => !m.here))

const viewport = ref({ w: 900, h: 620 })

/**
 * THE WHOLE FLOOR, fogged -- not a patch around the walker.
 *
 * Built for the camera's window the way the overworld builds its own (§5), so
 * panning and zooming out show the floor as a floor rather than as a disc
 * floating in nothing. The fog is the map's, off `sight`: ground is drawn
 * everywhere and what is STANDING on it only inside the disc, which is the same
 * split §5.6 draws outside.
 *
 * Clamped to the floor's bounds, so the edge of a fifty-by-fifty room is a real
 * edge you can see rather than tiles trailing off into coordinates that do not
 * exist.
 */
const tiles = computed<Tile[]>(() => {
  const d = session.value
  if (!d?.inside) return []

  const out: Tile[] = []
  const size = d.size ?? 50
  const stair = d.stair ?? null

  // Divided by the scale, the way the store does it: `visibleTiles` works in
  // map units and the viewport is measured in pixels.
  const scale = game.view.px / COL_STEP

  for (const { col, row } of visibleTiles(
    centre.value.col,
    centre.value.row,
    viewport.value.w / scale,
    viewport.value.h / scale,
  )) {
    if (col < 0 || row < 0 || col >= size || row >= size) continue

    const known = game.dungeonTiles.get(`${col},${row}`)
    const monster = known?.monster ?? null

    out.push({
      col,
      row,
      // §5.6 -- the BIOME is what a fogged hex is painted with, and the
      // variant is what a scouted one gets. Both are the vault here, so the
      // fog is the same stone one shade darker rather than a different place.
      //
      // Setting a real biome made fog LIGHTER than the ground you were standing
      // on, because it fell back to mountain blue-grey -- the map saying "out
      // there is a different country" about the next room along.
      biome: VAULT as unknown as Tile['biome'],
      variant: VAULT,
      ring: 'center',
      dead: false,
      hp: 0,
      baseYield: 0,
      extractions: 0,
      slotsUsed: 0,
      workers: 0,
      taken: 0,
      regrowsAt: 0,
      stair: Boolean(stair && col === stair.col && row === stair.row),
      // §9.5.1 -- the monster IS a pack as far as the map is concerned, which
      // is what gets it drawn standing on the ground with its halo.
      pack: monster ? { key: monster.key, bucket: 0, until: Number.MAX_SAFE_INTEGER } : undefined,
      propSeed: col * 73856093 + row * 19349663,
    })
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

/**
 * §9.6.2 -- what the floor is waiting for, in one sentence.
 *
 * Computed once and used by the row, the button and its tooltip, so the three
 * cannot end up saying different things about the same gate.
 */
const gateSays = computed(() => {
  const short = needed.value - kills.value

  if (short <= 0) return 'The floor is done with you'
  if (short === 1) return 'One more, and the guardian is it'

  return `${short} more to clear`
})

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

const picked = ref<{ col: number; row: number } | null>(null)

function select(col: number, row: number): void {
  picked.value = { col, row }
}

/**
 * §5.6 -- point at ground and go.
 *
 * One call. The server walks the road and the marker animates along it, exactly
 * as it does outside -- there is no loop here pacing itself a hex at a time,
 * because that was the thing making a floor feel like a different game.
 */
async function walk(): Promise<void> {
  const target = picked.value
  if (!target || underfoot.value) return

  await game.walkDungeon(target.col, target.row)
  picked.value = null
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
        :travel="session.walk ?? null"
        :now="game.now"
        :carriers="[]"
        :worn="game.worn"
        :mates="mates"
        @select="select"
        @recenter="camera = null"
        @resize="(w, h) => (viewport = { w, h })"
      />
    </div>

    <div class="dock">
      <!--
        §9.6.2 -- THE GATE, said as one line rather than left to arithmetic.

        It was a tally in the corner and a sentence in the dock that only
        appeared once you were standing on the stair, so "why can I not go
        down" was answered in two places and neither of them where you were
        looking. One row now: how many have fallen, how many the floor wants,
        and what that means -- drawn as pips, because six is a number you
        should be able to count rather than read.
      -->
      <div class="gate-row">
        <span class="pips" :aria-label="`${kills} of ${needed} cleared`">
          <i v-for="n in needed" :key="n" :class="{ on: n <= kills, last: n === needed }" />
        </span>
        <span class="gate-says" :class="{ open: session.floorOpen }">{{ gateSays }}</span>
      </div>

      <p v-if="underfoot" class="here-is">
        <span class="mob" :class="{ boss: underfoot.guardian }">{{ underfoot.name }}</span>
        <span class="mob-of">{{ underfoot.profile }} · tier {{ underfoot.tier }}</span>
      </p>
      <p v-else-if="picked && !session.walk" class="here-is quiet">
        {{ pickedName ?? 'Floor' }} · {{ pickedAway }} hexes off
      </p>
      <p v-else-if="session.walk" class="here-is quiet">Walking.</p>
      <p v-else-if="onStair" class="here-is quiet">You are on the stair.</p>
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
          v-else-if="picked && pickedAway > 0 && !session.walk"
          class="btn"
          type="button"
          :disabled="game.busy || waiting"
          @click="walk"
        >
          Walk {{ pickedAway }}
        </button>

        <!-- §5.6 -- stopping a journey is a verb the overworld has too. -->
        <button
          v-if="session.walk"
          class="btn"
          type="button"
          :disabled="game.busy"
          @click="game.stopDungeonWalk()"
        >
          Stop
        </button>

        <!--
          The way down, and it says WHY when it cannot be taken rather than
          greying out and leaving you to work it out. A control that refuses
          silently is a control that looks broken.
        -->
        <button
          v-if="onStair && !underfoot"
          class="btn down"
          type="button"
          :disabled="game.busy || waiting || !session.floorOpen"
          :title="session.floorOpen ? 'Down to the next floor' : gateSays"
          @click="game.descendDungeon()"
        >
          {{ session.floorOpen ? `Descend to ${session.floor + 1}` : `${needed - kills} more to clear` }}
        </button>

        <!--
          §9.6 -- leaving. Set apart from the rest with a rule, the way §7's
          wallet row is: everything else on this bar happens ON the floor, and
          this one ends the run. It is not destructive -- the session stands and
          the roster keeps going -- so it is quiet rather than ember.
        -->
        <span class="rule" aria-hidden="true" />
        <button
          class="btn out"
          type="button"
          :disabled="game.busy"
          :title="`Leave ${session.dungeon}. The session stays open for the others.`"
          @click="game.leaveDungeon()"
        >
          Walk out
        </button>
      </div>

      <p v-if="elsewhere.length" class="mates-elsewhere">
        {{ elsewhere.map((m) => m.name ?? 'Someone').join(', ') }}
        {{ elsewhere.length === 1 ? 'is' : 'are' }} on another floor.
      </p>

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

/*
 * §9.6.2 -- the gate, as a row you can count rather than a fraction to read.
 *
 * Pips, not a bar, for §7.6's own reason about the bag comb: an empty pip is
 * the same shape as a full one, so what is LEFT is seen rather than subtracted.
 * Six is a number a person counts at a glance and 4/6 is a number they parse.
 */
.gate-row {
  display: flex;
  align-items: center;
  gap: 9px;
  margin: 0 0 10px;
}

.pips {
  display: flex;
  gap: 3px;
}

.pips i {
  width: 9px;
  height: 10px;
  clip-path: polygon(50% 0, 100% 25%, 100% 75%, 50% 100%, 0 75%, 0 25%);
  background: var(--line);
}

.pips i.on {
  background: var(--copper);
}

/* The sixth is the guardian (§9.6.2), so it is drawn as the one that is. */
.pips i.last {
  outline: 1px solid var(--ember);
  outline-offset: 1px;
}

.pips i.last.on {
  background: var(--ember);
}

.gate-says {
  color: var(--vellum-dim);
  font-size: 11px;
}

/* §13.3 -- sap is a thing worth crossing the screen for, and an open stair is. */
.gate-says.open {
  color: var(--sap);
}

.acts {
  display: flex;
  align-items: center;
  gap: 8px;
}

/* §7 -- a rule, so leaving does not sit inline with the verbs that do not. */
.rule {
  flex: 0 0 auto;
  width: 1px;
  align-self: stretch;
  background: var(--line);
  margin-inline: 2px;
}

.mates-elsewhere {
  margin: 9px 0 0;
  color: var(--vellum-dim);
  font-size: 10.5px;
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

/*
 * These two come AFTER `.acts .btn` on purpose. Both are the same specificity
 * as it, so source order is what decides -- declared above it, "Walk out" came
 * out as a full-width copper primary, which is the loudest possible reading of
 * the one control that ends the run.
 */
.acts .down {
  background: var(--gold);
  color: var(--ink);
}

/* §13.3 -- quiet. Leaving is not destructive (the session stands and the
   roster goes on), so it is not ember; it is simply not the thing to press. */
.acts .out {
  flex: 0 0 auto;
  padding-inline: 16px;
  background: transparent;
  color: var(--vellum-dim);
}

.acts .out:hover:not(:disabled) {
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
