<script setup lang="ts">
/**
 * Oddity drops — a design proposal, §4 / §5.1 / §7.3 / §8.0 / §8.1 / §11.
 *
 * NOTHING ON THIS PAGE IS IMPLEMENTED. It is a document to be audited before
 * any of it is built, which is why the tables below are local arrays rather
 * than reads of `catalog.ts`: none of these twenty materials exist yet, and a
 * page that pretended to read them from the catalog would be lying about the
 * state of the game.
 *
 * The one thing it does read for real is the map. The field at the top is drawn
 * through `hexGeometry.ts` and `theme/palette.ts` -- the same geometry and the
 * same shade() the map itself uses -- so the tiles here are not an illustration
 * of hexes, they are hexes. That matters for a proposal whose whole argument is
 * "two forest hexes should stop being the same forest hex": the reader is
 * looking at the real thing with four tiles changed.
 */
import { computed } from 'vue'
import {
  HEX_DEPTH,
  HEX_H,
  HEX_SIDE_PATH,
  HEX_TOP_PATH,
  HEX_W,
  paintersSort,
  tileToScreen,
} from '@/map/hexGeometry'
import { BIOME_COLOR, COPPER, GOLD, depletedColor, shade } from '@/theme/palette'
import type { Biome } from '@/game/types'

/* ------------------------------------------------------------------ field */

type Feature = 'plain' | 'rich' | 'forage' | 'prize'

const COLS = 9
const ROWS = 4

/** §5.3 -- biomes cluster, so the fragment shows a forest belt meeting stone. */
function biomeAt(col: number, row: number): Biome {
  if (col >= 8 || (col >= 7 && row <= 1)) return 'mountain'
  if (col <= 1 && row >= 3) return 'grassland'
  return 'forest'
}

const FEATURES: Record<string, Feature> = {
  '2,1': 'rich',
  '5,2': 'rich',
  '3,3': 'forage',
  '7,1': 'prize',
}

const DEPLETED = new Set(['1,2', '6,0'])
const TREES = new Set(['0,0', '1,1', '3,0', '4,2', '2,3', '5,0', '6,3', '4,3', '0,2'])
const PEAKS = new Set(['7,0', '8,1', '8,3'])

interface FieldTile {
  key: string
  col: number
  row: number
  x: number
  y: number
  top: string
  side: string
  edge: string
  dark: string
  light: string
  feature: Feature
  tree: boolean
  peak: boolean
}

const field = computed<FieldTile[]>(() => {
  const raw: Array<{ col: number; row: number }> = []
  for (let col = 0; col < COLS; col++) {
    for (let row = 0; row < ROWS; row++) raw.push({ col, row })
  }

  // §13.2 -- painter's algorithm, so props stand in front of the tile behind.
  return paintersSort(raw).map(({ col, row }) => {
    const key = `${col},${row}`
    const biome = biomeAt(col, row)
    const base = BIOME_COLOR[biome]
    const top = DEPLETED.has(key) ? depletedColor(biome) : base

    const { x, y } = tileToScreen(col, row)

    return {
      key,
      col,
      row,
      x,
      y,
      top,
      side: shade(top, -0.4),
      edge: shade(top, -0.2),
      dark: shade(base, -0.42),
      light: shade(base, 0.22),
      feature: FEATURES[key] ?? 'plain',
      tree: TREES.has(key) && !DEPLETED.has(key),
      peak: PEAKS.has(key),
    }
  })
})

const viewBox = computed(() => {
  const w = (COLS - 1) * HEX_W * 0.75 + HEX_W + 4
  const h = (ROWS - 1) * HEX_H + HEX_H / 2 + HEX_H + HEX_DEPTH + 17
  return `${-HEX_W / 2 - 2} ${-HEX_H / 2 - 14} ${w} ${h}`
})

/** The prize ring: the tile's own hex, pulled in — §13.1 gives rarity the frame. */
const PRIZE_RING = HEX_TOP_PATH.replace(/-?\d+(\.\d+)?/g, (n) =>
  (Number(n) * 0.56).toFixed(1),
)

/* ------------------------------------------------- legend and the document */

const LEGEND: Array<{ feature: Feature; name: string; note: string }> = [
  { feature: 'plain', name: 'Plain', note: '55% · grade at base' },
  { feature: 'rich', name: 'Rich', note: '25% · grade at 2×' },
  { feature: 'forage', name: 'Forage', note: '15% · reagents at 2×' },
  { feature: 'prize', name: 'Prize', note: '5% · inner ring only' },
]

const ASKS = [
  {
    ask: 'More materials in every tier; every hex has oddity drops',
    how: 'A second roll per trip against the tile’s own oddity table. Twenty new materials across T0–T3; T4 reached through Essence traces.',
    at: '§4, §5.1',
  },
  {
    ask: 'Drops change with the weapon and boost options in use',
    how: 'Tool rarity gates which classes are reachable at all; a new oddity stat on options and potions shifts the odds.',
    at: '§8.0, §8.0.1',
  },
  {
    ask: 'Yield boost becomes cooldown reduction',
    how: 'yield retired as a gear stat. A new cooldown stat multiplies trip time; the §7.3 floor drops 30 min → 20 min so it has room to work.',
    at: '§7.3, §8.1',
  },
  {
    ask: 'Gathering without a tool needs to matter, now that potions exist',
    how: 'Forage — a second verb on a hex that ignores tools entirely and is the only source of the five potion reagents.',
    at: '§4.0, §8.5',
  },
  {
    ask: 'Rare items on some tiles, so tiles within a biome differ',
    how: 'A seeded feature per tile, above — derived from (col, row, seed) like everything else on the map.',
    at: '§5.1, §5.3',
  },
]

const CLASSES = [
  {
    name: 'Grade',
    tier: 'T1 raw',
    rank: 'rare' as const,
    chance: '25%',
    needs: 'any tool for the line',
    what: 'The good stuff inside the ordinary stuff — resin in the timber, coal in the spoil.',
  },
  {
    name: 'Feature',
    tier: 'T1 raw',
    rank: 'rare' as const,
    chance: '6%',
    needs: 'rare-tier tool or better',
    what: 'The same five raws at the doubled rate a Rich tile pays. A better tool finds more, not different.',
  },
  {
    name: 'Prize',
    tier: 'T3 rare',
    rank: 'legendary' as const,
    chance: '1%',
    needs: 'epic tool, Prize tile, inner ring',
    what: 'Wallet-capped like every T3. The only new NFT-grade input.',
  },
  {
    name: 'Trace',
    tier: 'T4 raid',
    rank: 'epic' as const,
    chance: '0.5%',
    needs: 'any tool, herd tiles only',
    what: 'Essence. Formalises the bridge §5.5 already promises, and adds no new T4 material.',
  },
]

const FEATURE_ROWS = [
  { name: 'Plain', weight: '55%', effect: 'Grade at base rate. No feature roll.', where: 'anywhere' },
  { name: 'Rich', weight: '25%', effect: 'Grade at 2×, feature roll live.', where: 'anywhere' },
  { name: 'Forage', weight: '15%', effect: 'Forage verb pays 2×; grade halved.', where: 'anywhere' },
  { name: 'Prize', weight: '5%', effect: 'Unlocks the T3 prize roll.', where: 'inner ring only — elsewhere this weight folds into Rich' },
]

const REAGENTS = [
  { name: 'Toadstool', key: 'toadstool', biome: 'forest' as Biome, feeds: 'Forest Draught, and the reworked yield potions' },
  { name: 'Lichen', key: 'lichen', biome: 'mountain' as Biome, feeds: 'Quarry Salts' },
  { name: 'Bitterroot', key: 'bitterroot', biome: 'plains' as Biome, feeds: 'Guild Cordial' },
  { name: 'Ashcap', key: 'ashcap', biome: 'badlands' as Biome, feeds: 'Prospector’s Flask' },
  { name: 'Blue Nettle', key: 'blue_nettle', biome: 'grassland' as Biome, feeds: 'Road Tonic' },
]

const GRADES = [
  { name: 'Resin', key: 'resin', biome: 'forest' as Biome, price: 5, into: 'Pitch' },
  { name: 'Coal', key: 'coal', biome: 'mountain' as Biome, price: 6, into: 'Coke' },
  { name: 'Sinew', key: 'sinew', biome: 'plains' as Biome, price: 6, into: 'Cord' },
  { name: 'Saltpetre', key: 'saltpetre', biome: 'badlands' as Biome, price: 5, into: 'Flux' },
  { name: 'Pollen', key: 'pollen', biome: 'grassland' as Biome, price: 4, into: 'Dye' },
]

const REFINED = [
  { name: 'Pitch', key: 'pitch', from: '3 Resin', line: 'Woodcutting', use: 'Repair: bows and hafted tools' },
  { name: 'Coke', key: 'coke', from: '3 Coal', line: 'Mining', use: 'Repair: all ingot gear; smith recipes' },
  { name: 'Cord', key: 'cord', from: '3 Sinew', line: 'Hunting', use: 'Repair: bows; armorer recipes' },
  { name: 'Flux', key: 'flux', from: '3 Saltpetre', line: 'Quarrying', use: 'Repair: cut-stone gear; smelting' },
  { name: 'Dye', key: 'dye', from: '3 Pollen', line: 'Harvesting', use: 'Cosmetic recolours; cloth recipes' },
]

const PRIZES = [
  { name: 'Amberheart', key: 'amberheart', biome: 'forest' as Biome, recipe: 'Legendary axe line' },
  { name: 'Star Iron', key: 'star_iron', biome: 'mountain' as Biome, recipe: 'Legendary pickaxe line' },
  { name: 'Fangcrown', key: 'fangcrown', biome: 'plains' as Biome, recipe: 'Legendary bow line' },
  { name: 'Glasstear', key: 'glasstear', biome: 'badlands' as Biome, recipe: 'Legendary hammer line' },
  { name: 'Ghostsilk', key: 'ghostsilk', biome: 'grassland' as Biome, recipe: 'Legendary sickle line' },
]

const TOOL_MATRIX = [
  { tool: 'none — bare hands', haul: 'scrap, §4.0 unchanged', grade: '—', feature: '—', prize: '—' },
  { tool: 'common / uncommon', haul: 'the material', grade: '25%', feature: '—', prize: '—' },
  { tool: 'rare', haul: 'the material', grade: '25%', feature: '6%', prize: '—' },
  { tool: 'epic / legendary', haul: 'the material', grade: '25%', feature: '6%', prize: '1%' },
]

const FORAGE = [
  { of: 'Needs a tool', work: 'no — bare hands pay scrap', forage: 'never looks', mark: true },
  { of: 'Returns', work: 'biome material + oddity roll', forage: 'reagent + scrap', mark: false },
  { of: 'Trip time', work: '30–60 min, §7.3', forage: '15 min, flat', mark: false },
  { of: 'AP', work: '2', forage: '1', mark: false },
  { of: 'Depletes the tile', work: 'yes', forage: 'no', mark: true },
  { of: 'Occupies a slot', work: 'yes, 1 of 2', forage: 'no', mark: true },
  { of: 'Trains the line', work: 'full rate', forage: '25%, as scrap does', mark: false },
]

const MIGRATION = [
  { where: 'Catalog::items()', rows: '32', what: "'stat' => 'yield' becomes 'cooldown', values re-pitched to the new curve" },
  { where: 'Jobs::NODES', rows: '57', what: 'Regenerated from gen_jobs.py; gathering trees rebalance across cooldown and oddity' },
  { where: 'OPTION_STATS_TOOL / _WORN', rows: '2', what: 'Swap yield for cooldown, add oddity' },
  { where: 'Formulas::tripYield / tripTime', rows: '2', what: 'Drop the equip term from yield; add the multiplier to time' },
  { where: 'State payload', rows: '1', what: 'toolYield becomes toolCooldown; client types and the Hero sheet follow' },
  { where: 'balance.ts, catalog.ts', rows: '~40', what: 'Hand-kept mirror — the drift risk the jobs plan already flags as overdue' },
]

const SINKS = [
  {
    group: '5 reagents',
    tier: 'forage',
    rank: 'common' as const,
    faucet: 'Forage verb',
    sink: 'Potions, which expire — §8.5',
    quality: 'Strongest in the game: the buff is gone in an hour whether or not you used it.',
  },
  {
    group: '5 grade raws',
    tier: 'T1',
    rank: 'uncommon' as const,
    faucet: 'Oddity roll',
    sink: 'Processing into T2; storage decay over cap',
    quality: 'Good — two independent sinks, one of them passive.',
  },
  {
    group: '5 refined',
    tier: 'T2',
    rank: 'rare' as const,
    faucet: 'Processing only',
    sink: 'Equipment repair — §8.2',
    quality: 'Good, and it fixes a standing problem: repair currently competes with crafting for the same five refined materials.',
  },
  {
    group: '5 prizes',
    tier: 'T3',
    rank: 'legendary' as const,
    faucet: 'Prize tile + epic tool',
    sink: 'Legendary crafts; per-wallet cap',
    quality: 'Capped rather than sunk, same as the existing five T3s.',
  },
  {
    group: 'Essence trace',
    tier: 'T4',
    rank: 'epic' as const,
    faucet: 'Herd tiles, 0.5%',
    sink: 'Existing raid recipes',
    quality: 'No new material; makes an existing one slightly less raid-locked.',
  },
]

const DECISIONS = [
  {
    q: 'Which cooldown, and does the §7.3 floor move?',
    body: '“Cooldown” could mean the trip timer, the tile’s nine-hour regrow, or a new between-trips wait. Regrow is shared world state, so one player’s boots would change a tile for everyone. A new wait would be adding friction in order to sell the removal of it.',
    rec: 'The trip timer, and yes — floor 30 min → 20 min, or the stat does nothing at best-in-slot.',
  },
  {
    q: 'Accept that value moves from idle players to active ones?',
    body: 'Cooldown only pays out if you come back to spend it. This is the one change here that argues with the product’s own north star.',
    rec: 'Accept. Skill-based yield is untouched, so idle players keep the larger of the two yield sources and lose only the gear slice.',
  },
  {
    q: 'Twenty new materials at once, or phased?',
    body: '29 → 49 is a 69% larger catalog, hand-mirrored across PHP and TypeScript, with no parity test guarding the item half of it.',
    rec: 'Phase it, per the build order below — and land the catalog parity test before phase 2, not after.',
  },
  {
    q: 'Is oddity a capped StatKey, or its own uncapped axis?',
    body: 'Capped means +15% turns 25% into 28.75% — honest, consistent, and possibly too quiet to feel. Uncapped would let it read as a real build choice, at the cost of the rule §8.1 calls load-bearing.',
    rec: 'Capped StatKey. Let the tool rung carry the drama; if it plays too quiet, raise the base rates rather than the ceiling.',
  },
  {
    q: 'Is a tile’s feature public, or does it cost a query?',
    body: 'Seed-derived means the client can label every Rich and Prize tile on the map, unscouted or not — which makes the map worth reading, and makes the §5.6 fog thinner than it was.',
    rec: 'Public feature, server-side roll. Knowing a hex is Rich is a reason to walk there; what it just paid someone else is the thing fog should keep.',
  },
  {
    q: 'Confirm the two §4 rules this rewrites.',
    body: 'The “20 materials, plus 5 scrap” headline becomes “40, plus 5 scrap, plus 4 raid”. And §4.0’s “scrap reaches no other tier” stays true only because reagents are deliberately not scrap.',
    rec: 'Rewrite the §4 headline; keep §4.0 exactly as written and let forage sit beside it.',
  },
  {
    q: 'Forage as a separate verb, or a mode of mining?',
    body: 'A separate verb needs a second action on the hex, a second job kind in the trip system, and its own UI. A mode would be cheaper — but it means unequipping, which §8.0 rule 3 forbids by name.',
    rec: 'Separate verb. The more expensive build, and the only one that does not contradict a mandatory rule.',
  },
]

const PHASES = [
  {
    name: 'Forage',
    body: 'The verb, the five reagents, the tile feature roll, and potion recipes rewired onto reagents. Self-contained: touches no stat, no existing drop, no equipment. Answers the potion ask on its own.',
  },
  {
    name: 'Oddity rolls — grade and feature',
    body: 'The 25% and 6% tables, five T1 raws, five T2 refined, tool-rarity gating, and repair costs moved onto the new T2s. The catalog parity test lands here, before the catalog doubles.',
  },
  {
    name: 'yield → cooldown',
    body: 'Eighty-nine data rows, the §7.3 floor change, the oddity stat, and the gathering trees regenerated. One commit, one balance proof, and the §7.3 clamp test extended to cover the multiplier.',
  },
  {
    name: 'Prizes',
    body: 'Prize tiles, the five T3 materials, epic-tool gating, wallet caps, and the legendary recipes they feed. Last, because it is the only part that touches NFT value and §2 wants that reviewed on its own.',
  },
]
</script>

<template>
  <div class="doc scroll">
    <div class="col">

      <!-- ─────────────────────────────────────────────────── the thesis -->
      <section class="lede">
        <p class="tiny muted intro">
          Two forest hexes are currently the same forest hex. This gives each tile a
          table of its own — a second, smaller reward rolled on top of the haul, where
          what the table <em>can</em> pay is gated by the tool in your hand and how often
          it pays is shifted by rolled options and potions. Alongside it: twenty new
          materials, a bare-handed <em>forage</em> verb that potions depend on, and the
          retirement of the <code>yield</code> stat in favour of cooldown.
        </p>
      </section>

      <section class="field-block">
        <div class="field-hold">
          <svg
            class="field"
            :viewBox="viewBox"
            role="img"
            aria-label="A fragment of the hex map: a forest belt meeting mountain, with two rich tiles, one forage tile and one prize tile among the plain ones."
          >
            <g v-for="t in field" :key="t.key" :transform="`translate(${t.x},${t.y})`">
              <path :d="HEX_SIDE_PATH" :fill="t.side" />
              <path :d="HEX_TOP_PATH" :fill="t.top" :stroke="t.edge" stroke-width="1" />

              <path v-if="t.tree" d="M0,-7 L3.4,0 L-3.4,0 Z" :fill="t.dark" />
              <template v-if="t.peak">
                <path d="M-7,5 L0,-8 L7,5 Z" :fill="shade(t.top, -0.3)" />
                <path d="M0,-8 L3.4,-1.6 L-3.4,-1.6 Z" :fill="t.light" />
              </template>

              <!-- Copper reads “more of it”, gold reads “rarity” — §13.1. -->
              <template v-if="t.feature === 'rich'">
                <circle cx="-5" cy="-3" r="1.9" :fill="COPPER" />
                <circle cx="0" cy="3" r="1.9" :fill="COPPER" />
                <circle cx="5" cy="-3" r="1.9" :fill="COPPER" />
              </template>

              <g
                v-else-if="t.feature === 'forage'"
                stroke="#cdd8ae"
                stroke-width="1.5"
                stroke-linecap="round"
                fill="none"
              >
                <path d="M-6,5 C-6,0 -7,-2 -8,-4" />
                <path d="M0,6 C0,0 0,-3 0,-5" />
                <path d="M6,5 C6,0 7,-2 8,-4" />
              </g>

              <template v-else-if="t.feature === 'prize'">
                <path :d="PRIZE_RING" fill="none" :stroke="GOLD" stroke-width="1.7" stroke-linejoin="round" />
                <circle cx="0" cy="0" r="2.4" :fill="GOLD" />
              </template>
            </g>
          </svg>
        </div>

        <div class="legend">
          <span v-for="l in LEGEND" :key="l.feature" class="leg">
            <svg viewBox="-31 -19 62 48" width="52" height="40" aria-hidden="true">
              <path :d="HEX_SIDE_PATH" :fill="shade(BIOME_COLOR.forest, -0.4)" />
              <path
                :d="HEX_TOP_PATH"
                :fill="BIOME_COLOR.forest"
                :stroke="shade(BIOME_COLOR.forest, -0.2)"
                stroke-width="1"
              />
              <template v-if="l.feature === 'rich'">
                <circle cx="-5" cy="-3" r="1.9" :fill="COPPER" />
                <circle cx="0" cy="3" r="1.9" :fill="COPPER" />
                <circle cx="5" cy="-3" r="1.9" :fill="COPPER" />
              </template>
              <g
                v-else-if="l.feature === 'forage'"
                stroke="#cdd8ae"
                stroke-width="1.5"
                stroke-linecap="round"
                fill="none"
              >
                <path d="M-6,5 C-6,0 -7,-2 -8,-4" />
                <path d="M0,6 C0,0 0,-3 0,-5" />
                <path d="M6,5 C6,0 7,-2 8,-4" />
              </g>
              <template v-else-if="l.feature === 'prize'">
                <path :d="PRIZE_RING" fill="none" :stroke="GOLD" stroke-width="1.7" stroke-linejoin="round" />
                <circle cx="0" cy="0" r="2.4" :fill="GOLD" />
              </template>
            </svg>
            <span class="leg-t">
              <strong>{{ l.name }}</strong>
              <span class="label">{{ l.note }}</span>
            </span>
          </span>
        </div>

        <p class="tiny muted caption">
          A forest belt meeting mountain, drawn through <code>hexGeometry.ts</code> and
          <code>palette.ts</code> — the same geometry and the same shading the map uses.
          Every tile here is the biome material it always was; what changed is that four
          of them are now worth choosing. The two drained tiles are depleted, as today.
        </p>
      </section>

      <!-- ─────────────────────────────────────────────────── asks -->
      <section>
        <header class="head">
          <span class="label">What was asked for → what this proposes</span>
          <h2>The five changes</h2>
        </header>
        <div class="sheet-hold">
          <table class="sheet">
            <thead><tr><th>Ask</th><th>Mechanism</th><th>Touches</th></tr></thead>
            <tbody>
              <tr v-for="a in ASKS" :key="a.at">
                <td class="lead">{{ a.ask }}</td>
                <td>{{ a.how }}</td>
                <td class="nowrap muted">{{ a.at }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ─────────────────────────────────────────────────── model -->
      <section>
        <header class="head">
          <span class="label">Amends §4 materials, §5.1 map structure</span>
          <h2>The oddity model</h2>
        </header>

        <p class="tiny muted prose">
          A trip returns what it returns today — the biome material, sized by
          <code>tripYield()</code> — and then rolls <strong>once</strong> against the tile’s
          oddity table. At most one oddity per trip. That cap is what keeps this a
          texture change rather than a second economy: the main haul stays the thing you
          plan around, and the oddity is the reason one hex beats its neighbour.
        </p>

        <div class="sheet-hold">
          <table class="sheet">
            <thead>
              <tr><th>Class</th><th>Tier</th><th class="right">Base</th><th>Reachable with</th><th>What it is</th></tr>
            </thead>
            <tbody>
              <tr v-for="c in CLASSES" :key="c.name">
                <td class="lead">{{ c.name }}</td>
                <td class="nowrap"><span class="chip" :class="`rarity-${c.rank}`">{{ c.tier }}</span></td>
                <td class="right readout">{{ c.chance }}</td>
                <td class="nowrap">{{ c.needs }}</td>
                <td>{{ c.what }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <p class="tiny muted prose">
          The tile’s feature comes from its own hash, exactly the way it already draws
          <code>baseSeconds</code> and <code>baseYield</code>. Nothing is stored and nothing
          is queried — which also means the client can derive it, and that is a decision
          rather than a detail.
        </p>

        <div class="sheet-hold">
          <table class="sheet">
            <thead><tr><th>Feature</th><th class="right">Weight</th><th>Effect on the table</th><th>Where</th></tr></thead>
            <tbody>
              <tr v-for="f in FEATURE_ROWS" :key="f.name">
                <td class="lead">{{ f.name }}</td>
                <td class="right readout">{{ f.weight }}</td>
                <td>{{ f.effect }}</td>
                <td class="muted">{{ f.where }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="flag">
          <span class="label warn">Decide this — decision 5</span>
          <p class="tiny muted">
            A seed-derived feature is <strong>public</strong>: the client can compute it for
            any hex on the map, unscouted ones included. That is either the best thing
            about this system or a hole in the fog just built in §5.6, depending on what
            fog is meant to mean. The roll itself stays server-side either way.
          </p>
        </div>
      </section>

      <!-- ─────────────────────────────────────────────────── materials -->
      <section>
        <header class="head">
          <span class="label">Amends §4 — “20 plus 5 scrap” becomes 40, plus 5 scrap, plus 4 raid</span>
          <h2>Twenty new materials</h2>
        </header>

        <p class="tiny muted prose">
          The catalog holds 29 today. This adds 20, for 49. Each group exists because it
          has a sink, not because a tier looked thin — §11’s north star is what shaped the
          list, and the audit is two sections down.
        </p>

        <h3 class="sub">Forage reagents — bare hands only</h3>
        <p class="tiny muted prose">
          Gathered by the forage verb and by nothing else. These are the only inputs to
          the potion bench, so every alchemist forages and no tool ever substitutes for it.
        </p>
        <div class="sheet-hold">
          <table class="sheet">
            <thead><tr><th>Material</th><th>Key</th><th>Biome</th><th>Feeds</th></tr></thead>
            <tbody>
              <tr v-for="m in REAGENTS" :key="m.key">
                <td class="lead"><span class="pip" :style="{ background: BIOME_COLOR[m.biome] }" />{{ m.name }}</td>
                <td><code>{{ m.key }}</code></td>
                <td class="nowrap muted">{{ m.biome }}</td>
                <td>{{ m.feeds }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <h3 class="sub">Grade oddities — the T1 raws a trip turns up</h3>
        <p class="tiny muted prose">
          Non-tradeable and decay-over-cap like every other raw. Each refines into exactly
          one T2 below, which gives them a processing sink from day one rather than
          sitting in bags waiting for a recipe.
        </p>
        <div class="sheet-hold">
          <table class="sheet">
            <thead><tr><th>Material</th><th>Key</th><th>Biome</th><th class="right">NPC</th><th>Refines into</th></tr></thead>
            <tbody>
              <tr v-for="m in GRADES" :key="m.key">
                <td class="lead"><span class="pip" :style="{ background: BIOME_COLOR[m.biome] }" />{{ m.name }}</td>
                <td><code>{{ m.key }}</code></td>
                <td class="nowrap muted">{{ m.biome }}</td>
                <td class="right readout">{{ m.price }}</td>
                <td>{{ m.into }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <h3 class="sub">T2 refined — made, never dropped</h3>
        <p class="tiny muted prose">
          Keeping T2 unreachable by dropping is deliberate: “refined means someone
          processed it” is the rule that makes settlements matter, and an oddity that
          skipped the bench would quietly weaken §6. These five carry the
          <strong>repair</strong> sink, which today competes with crafting for the same five
          refined materials and would stop doing so.
        </p>
        <div class="sheet-hold">
          <table class="sheet">
            <thead><tr><th>Material</th><th>Key</th><th>From</th><th>Line</th><th>Consumed by</th></tr></thead>
            <tbody>
              <tr v-for="m in REFINED" :key="m.key">
                <td class="lead">{{ m.name }}</td>
                <td><code>{{ m.key }}</code></td>
                <td class="nowrap">{{ m.from }}</td>
                <td class="nowrap muted">{{ m.line }}</td>
                <td>{{ m.use }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <h3 class="sub">T3 tile prizes — inner ring, wallet-capped</h3>
        <p class="tiny muted prose">
          Same rules as the existing five T3 rares: no NPC price, per-wallet cap,
          contested ring only. What differs is <em>how</em> they arrive — a Prize tile plus an
          epic tool, rather than a rare-variant hex — which makes a specific hex worth
          holding rather than a whole band of them.
        </p>
        <div class="sheet-hold">
          <table class="sheet">
            <thead><tr><th>Material</th><th>Key</th><th>Biome</th><th>Intended NFT recipe</th></tr></thead>
            <tbody>
              <tr v-for="m in PRIZES" :key="m.key">
                <td class="lead"><span class="pip" :style="{ background: BIOME_COLOR[m.biome] }" />{{ m.name }}</td>
                <td><code>{{ m.key }}</code></td>
                <td class="nowrap muted">{{ m.biome }}</td>
                <td>{{ m.recipe }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="flag ok">
          <span class="label good">§2 threat model — checked</span>
          <p class="tiny muted">
            The prizes are the only new path toward NFT value, and they run through the
            chokepoints §2 already relies on: per-wallet cap, contested-ring location, and
            a craft rather than a drop. No new grind→NFT faucet. The other fifteen
            materials are non-tradeable and reach no NFT recipe at all.
          </p>
        </div>
      </section>

      <!-- ─────────────────────────────────────────────────── tools -->
      <section>
        <header class="head">
          <span class="label">Amends §8.0 slots, §8.0.1 options</span>
          <h2>What the tool changes, and what the options change</h2>
        </header>

        <p class="tiny muted prose">
          Two different levers, on purpose. <strong>Rarity gates the table</strong> — it
          decides which classes exist for you at all, as a hard step rather than a
          percentage. <strong>Options and potions shift the odds</strong> on a table you can
          already reach. A player with a village axe and every potion in the game still
          cannot pull a prize.
        </p>

        <div class="sheet-hold">
          <table class="sheet">
            <thead>
              <tr><th>Tool for the line</th><th>Main haul</th><th class="right">Grade</th><th class="right">Feature</th><th class="right">Prize</th></tr>
            </thead>
            <tbody>
              <tr v-for="t in TOOL_MATRIX" :key="t.tool">
                <td class="lead">{{ t.tool }}</td>
                <td class="muted">{{ t.haul }}</td>
                <td class="right readout">{{ t.grade }}</td>
                <td class="right readout">{{ t.feature }}</td>
                <td class="right readout">{{ t.prize }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <h3 class="sub">The new <code>oddity</code> stat</h3>
        <ul class="rules">
          <li class="on">
            A <code>StatKey</code> like any other, so it joins the one aggregate and the one
            <code>STAT_CEILING</code> clamp. At the cap it turns a 25% grade roll into 28.75%
            — deliberately modest, because the tool rung is meant to be the interesting
            lever and the stat the tuning one.
          </li>
          <li class="on">
            Joins <code>OPTION_STATS_TOOL</code>, so a rolled line on an axe can read
            <em>+2% oddity</em>. That is the direct answer to “it changes with the boost
            options the hero uses”.
          </li>
          <li class="on">
            Gets a potion at each bench tier, built from forage reagents — the loop that
            makes foraging non-optional for anyone chasing oddities.
          </li>
          <li>
            <strong>Does not</strong> unlock a class you have not earned with a tool. If it
            did, a common axe and a stacked potion shelf would reach prizes, and the
            gathering-tool ladder in §8.3 would stop being a ladder.
          </li>
        </ul>
      </section>

      <!-- ─────────────────────────────────────────────────── forage -->
      <section>
        <header class="head">
          <span class="label">Amends §4.0 scrap, §8.0 rule 3, §8.5 consumables</span>
          <h2>Forage — the bare-handed verb</h2>
        </header>

        <p class="tiny muted prose">
          The ask was that gathering without a tool stay necessary now that potions exist.
          The obvious build — <em>unequip to forage</em> — is the one thing §8.0 rule 3
          forbids by name, because swapping gear before every trip is friction rather than
          a decision. So this is not a mode of mining. It is a
          <strong>second verb on the hex</strong>, beside Work, that never looks at your slots.
        </p>

        <div class="sheet-hold">
          <table class="sheet">
            <thead><tr><th></th><th>Work</th><th>Forage</th></tr></thead>
            <tbody>
              <tr v-for="r in FORAGE" :key="r.of">
                <td class="lead">{{ r.of }}</td>
                <td class="muted">{{ r.work }}</td>
                <td :class="r.mark ? 'strong' : ''">{{ r.forage }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <ul class="rules">
          <li class="on">
            <strong>§4.0 survives intact.</strong> Scrap still feeds no recipe and still sells
            for one gold. Reagents are a separate thing arriving by a separate verb, so the
            rule that bare hands cannot get a hex’s material out of it is untouched.
          </li>
          <li class="on">
            <strong>Not depleting and not taking a slot</strong> is what stops forage
            competing with mining for the map. A Forage tile can be worked by two miners
            and foraged by everyone else at the same time.
          </li>
          <li>
            <strong>Open:</strong> fifteen minutes flat is short enough to be a check-in
            action and long enough not to be spammable, but it is the one number here with
            no measured basis. It wants a pass once potion demand is real.
          </li>
        </ul>
      </section>

      <!-- ─────────────────────────────────────────────────── the stat swap -->
      <section>
        <header class="head">
          <span class="label">Amends §7.3 mining time, §8.1 rule 1, §8.3 recipes</span>
          <h2>Retiring <code>yield</code> for <code>cooldown</code></h2>
        </header>

        <p class="tiny muted prose">
          The largest and least reversible change here, so the whole of it before the
          arguments.
        </p>

        <pre class="formula">raw    = base − skill_reduction − equip_reduction     // unchanged, §7.3
timed  = raw × (1 − cooldown)                        // new, cooldown ≤ 0.15
trip   = clamp(timed, FLOOR, CEILING)                // FLOOR 30 min → 20 min</pre>

        <ul class="rules">
          <li class="on">
            <strong>The floor has to move, or the stat is dead on arrival.</strong> Today a
            best-in-slot character lands exactly on the 30-minute floor, so a multiplier
            applied afterwards would be clamped away to nothing. Dropping the floor to 20
            gives cooldown ten minutes to work in and keeps the clamp doing its real job —
            catching future stacking — rather than being a number BiS happens to sit on.
          </li>
          <li class="on">
            <strong>Skill still pays in yield.</strong> The <em>+50% at max skill</em> inside
            <code>tripYield()</code> is untouched. What retires is the gear and potion
            contribution only.
          </li>
          <li class="on">
            <strong>At best-in-slot the swap is close to economically neutral.</strong>
            Today: 2 trips/hour × 1.15 haul = 2.30. After: 60 ÷ 25.5 = 2.35 trips/hour ×
            1.00 = 2.35. A 2% faucet increase, which §11 absorbs without retuning.
          </li>
        </ul>

        <div class="flag">
          <span class="label warn">The real objection — decision 2</span>
          <p class="tiny muted">
            <strong>Yield rewards the idle player; cooldown rewards the active one.</strong>
            Someone who opens the game twice a day gets two hauls either way and simply
            loses the 15%. Someone who plays in sessions converts the whole reduction into
            extra trips. In a game whose north star is idle play, that is a real
            redistribution, and it is worth saying out loud before spending 89 rows of
            migration on it. Skill-based yield surviving is what keeps it from being a
            straight loss for idle players.
          </p>
        </div>

        <h3 class="sub">Migration surface</h3>
        <div class="sheet-hold">
          <table class="sheet">
            <thead><tr><th>Where</th><th class="right">Rows</th><th>What happens</th></tr></thead>
            <tbody>
              <tr v-for="m in MIGRATION" :key="m.where">
                <td class="lead"><code>{{ m.where }}</code></td>
                <td class="right readout">{{ m.rows }}</td>
                <td>{{ m.what }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ─────────────────────────────────────────────────── sinks -->
      <section>
        <header class="head">
          <span class="label">Against §11 — the north star</span>
          <h2>Every new material’s sink</h2>
        </header>

        <p class="tiny muted prose">
          “A game that only accumulates is a spreadsheet.” Fifteen of the twenty new
          materials are consumed by something that destroys them; the other five are
          wallet-capped so they cannot accumulate in the first place.
        </p>

        <div class="sheet-hold">
          <table class="sheet">
            <thead><tr><th>Group</th><th>Faucet</th><th>Sink</th><th>Quality of the sink</th></tr></thead>
            <tbody>
              <tr v-for="s in SINKS" :key="s.group">
                <td class="lead nowrap">
                  <span class="chip" :class="`rarity-${s.rank}`">{{ s.tier }}</span>
                  {{ s.group }}
                </td>
                <td class="nowrap muted">{{ s.faucet }}</td>
                <td>{{ s.sink }}</td>
                <td>{{ s.quality }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <!-- ─────────────────────────────────────────────────── decisions -->
      <section>
        <header class="head">
          <span class="label">Your call — the build order references these by number</span>
          <h2>Seven decisions before any of this is written</h2>
        </header>

        <div class="decisions">
          <article v-for="(d, i) in DECISIONS" :key="d.q" class="decision">
            <span class="dnum hex"><span class="face">{{ i + 1 }}</span></span>
            <div class="dbody">
              <h3>{{ d.q }}</h3>
              <p class="tiny muted">{{ d.body }}</p>
              <p class="tiny rec"><span class="label">Recommend</span>{{ d.rec }}</p>
            </div>
          </article>
        </div>
      </section>

      <!-- ─────────────────────────────────────────────────── phases -->
      <section>
        <header class="head">
          <span class="label">If approved</span>
          <h2>Build order</h2>
        </header>

        <p class="tiny muted prose">
          Ordered so each phase ships something playable, and the irreversible one goes
          last — after the systems that will re-pitch its numbers already exist.
        </p>

        <div class="phases">
          <div v-for="(p, i) in PHASES" :key="p.name" class="phase">
            <span class="label when">Phase {{ i + 1 }}</span>
            <div>
              <h3>{{ p.name }}</h3>
              <p class="tiny muted">{{ p.body }}</p>
            </div>
          </div>
        </div>
      </section>

      <footer class="foot tiny muted">
        Proposal for CLAUDE.md — nothing here is implemented, and none of these twenty
        materials exist in <code>catalog.ts</code>. Every number is a starting value for
        tuning, per the design doc’s own standing rule. Percentages in the oddity tables
        are per trip, before the <code>oddity</code> stat is applied.
      </footer>

    </div>
  </div>
</template>

<style scoped>
.doc {
  height: 100%;
  min-height: 0;
  overflow-y: auto;
}

.col {
  max-width: 1020px;
  margin: 0 auto;
  padding: 22px 18px 90px;
  display: flex;
  flex-direction: column;
  gap: 34px;
}

section {
  display: flex;
  flex-direction: column;
  gap: 13px;
}

.lede {
  gap: 0;
}

.intro {
  font-size: 13.5px;
  line-height: 1.62;
  max-width: 76ch;
}

.head {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.head h2 {
  font-size: 19px;
}

.sub {
  font-size: 14px;
  margin-top: 6px;
}

.prose {
  max-width: 76ch;
  line-height: 1.6;
}

code {
  font-family: ui-monospace, 'Cascadia Mono', Consolas, monospace;
  font-size: 0.92em;
  color: var(--copper);
}

/* ------------------------------------------------------------------ field */

.field-block {
  gap: 16px;
  padding: 20px 18px 18px;
  background: var(--ink-panel);
  clip-path: var(--plate-clip);
}

/* The map is wide before it is tall; let it scroll rather than shrink to
   illegibility on a phone. */
.field-hold {
  overflow-x: auto;
  scrollbar-width: thin;
  scrollbar-color: var(--line) transparent;
}

.field {
  display: block;
  width: 100%;
  min-width: 460px;
  height: auto;
}

.legend {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 22px;
}

.leg {
  display: flex;
  align-items: center;
  gap: 9px;
}

.leg svg {
  display: block;
  flex: 0 0 auto;
}

.leg-t {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.leg-t strong {
  font-size: 12.5px;
  font-weight: 600;
}

.caption {
  max-width: 72ch;
  line-height: 1.55;
  margin: 0;
}

/* ----------------------------------------------------------------- sheets */

.sheet-hold {
  overflow-x: auto;
  border: 1px solid var(--hud-line-soft);
  scrollbar-width: thin;
  scrollbar-color: var(--line) transparent;
}

.sheet {
  width: 100%;
  min-width: 620px;
  border-collapse: collapse;
  font-size: 12px;
}

.sheet th {
  text-align: left;
  padding: 9px 12px;
  background: var(--ink-panel);
  border-bottom: 1px solid var(--hud-line-soft);
  font-size: 9.5px;
  font-weight: 700;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  color: var(--vellum-dim);
  white-space: nowrap;
}

.sheet td {
  padding: 9px 12px;
  border-bottom: 1px solid var(--hud-line-soft);
  vertical-align: top;
  color: var(--vellum-dim);
  line-height: 1.5;
}

.sheet tbody tr:last-child td {
  border-bottom: 0;
}

.sheet td.lead {
  color: var(--vellum);
  font-weight: 600;
}

.sheet td.right {
  text-align: right;
}

.sheet th.right {
  text-align: right;
}

.sheet td.nowrap {
  white-space: nowrap;
}

.sheet td.strong {
  color: var(--vellum);
  font-weight: 600;
}

.pip {
  display: inline-block;
  width: 10px;
  height: 11px;
  margin-right: 7px;
  vertical-align: -1px;
  clip-path: var(--hex-clip);
}

/* ------------------------------------------------------------------ lists */

.rules {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 11px;
  max-width: 78ch;
}

.rules li {
  display: grid;
  grid-template-columns: 18px 1fr;
  align-items: start;
  font-size: 12px;
  line-height: 1.6;
  color: var(--vellum-dim);
}

.rules li::before {
  content: '';
  width: 9px;
  height: 10px;
  margin-top: 0.5em;
  background: var(--hud-line);
  clip-path: var(--hex-clip);
}

.rules li.on::before {
  background: var(--copper);
}

.rules strong {
  color: var(--vellum);
}

/* ----------------------------------------------------------------- blocks */

.flag {
  display: flex;
  flex-direction: column;
  gap: 7px;
  padding: 13px 15px;
  border: 1px solid var(--ember);
  max-width: 80ch;
}

.flag.ok {
  border-color: var(--line);
}

.flag p {
  margin: 0;
  line-height: 1.55;
}

.warn {
  color: #e08c86;
}

.good {
  color: #a8c79a;
}

.formula {
  margin: 0;
  padding: 13px 16px;
  overflow-x: auto;
  background: rgba(0, 0, 0, 0.3);
  border-left: 2px solid var(--copper);
  font-family: ui-monospace, 'Cascadia Mono', Consolas, monospace;
  font-size: 11.5px;
  line-height: 1.8;
  color: var(--vellum);
}

/* -------------------------------------------------------------- decisions */

.decisions {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.decision {
  display: grid;
  grid-template-columns: 32px 1fr;
  gap: 14px;
  align-items: start;
  padding: 15px 17px;
  background: var(--ink-panel);
  clip-path: var(--plate-clip);
}

.dnum {
  width: 26px;
  height: 29px;
  background: var(--copper);
}

.dnum .face {
  display: grid;
  place-items: center;
  background: var(--copper);
  color: #17110c;
  font-family: var(--font-display);
  font-weight: 700;
  font-size: 12px;
}

.dbody {
  display: flex;
  flex-direction: column;
  gap: 7px;
}

.dbody h3 {
  font-size: 13.5px;
}

.dbody p {
  margin: 0;
  line-height: 1.55;
}

.rec {
  padding-top: 8px;
  border-top: 1px solid var(--hud-line-soft);
  color: var(--vellum);
}

.rec .label {
  color: var(--copper);
  margin-right: 8px;
}

/* ----------------------------------------------------------------- phases */

.phases {
  display: flex;
  flex-direction: column;
}

.phase {
  display: grid;
  grid-template-columns: 96px 1fr;
  gap: 16px;
  align-items: start;
  padding: 14px 0;
  border-top: 1px solid var(--hud-line-soft);
}

.phase:first-child {
  border-top: 1px solid var(--line);
}

.phase h3 {
  font-size: 13.5px;
  margin-bottom: 4px;
}

.phase p {
  margin: 0;
  line-height: 1.55;
}

.when {
  color: var(--copper);
  margin-top: 4px;
}

.foot {
  padding-top: 16px;
  border-top: 1px solid var(--line);
  max-width: 78ch;
  line-height: 1.6;
}

@media (max-width: 560px) {
  .col {
    padding: 16px 12px 70px;
    gap: 26px;
  }

  .decision {
    grid-template-columns: 1fr;
    gap: 10px;
  }

  .phase {
    grid-template-columns: 1fr;
    gap: 5px;
  }
}
</style>
