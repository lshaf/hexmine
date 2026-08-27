/**
 * Client-side world generation, §5.
 *
 * The map is square, as wide as the server declares, and none of it is stored.
 * Every tile is a pure function of (col, row, seed), so the client derives
 * terrain locally and the server only sends what it cannot know: which tiles are
 * worked out and which have miners on them.
 *
 * That split is the whole point. Sending generated tiles cost roughly 200KB per
 * viewport and a round trip on every pan; this costs a few hundred bytes once,
 * and panning touches the network only for mutations.
 *
 * ── Keeping it honest ────────────────────────────────────────────────────────
 * The *algorithm* here mirrors app/Game/WorldGen.php. The *constants* do not:
 * they arrive from GET /api/world and are installed by configureWorld(), so a
 * balance change on the server cannot silently desync the two. What still has to
 * match by hand is the maths, and tests/Fixtures/worldgen.txt pins both sides to
 * the same expected output. `composer parity` checks PHP against it and
 * `npm run parity` checks this file against the very same fixture; run both.
 */
import { hash2, rand01, randInt } from './hash'
import { BIOME_VARIANTS, type VariantDef } from './variants'
import { MONSTERS_BY_RING } from './monsters'
import { BIOME_MATERIAL, SKILL_BY_KEY } from './catalog'
import { hexDistance } from '@/map/hexGeometry'
import type {
  Biome,
  MaterialKey,
  Pack,
  Ring,
  Settlement,
  SettlementTier,
  SkillKey,
  Tile,
  VariantKey,
  WaterKind,
} from './types'

export interface WorldConfig {
  seed: number
  /** §5.1 -- the map is square. Coordinates run -radius..radius inclusive. */
  radius: number
  /** Tiles a side, both ends included. Derived: radius * 2 + 1. */
  size: number
  biomeCell: number
  biomeRegionCells: number
  rings: { center: number; inner: number; mid: number }
  hpMin: number
  hpMax: number
  /** §5.3 -- the tool rung each grade of ground is measured at. */
  hpGradeAttack: Record<string, number>
  commonAttack: number
  /** §5.1 -- the haul band, and how many hauls a hex holds across it. */
  yieldMin: number
  yieldMax: number
  extractionsMin: number
  extractionsMax: number
  rareSpawnChance: number
  slotsPerTile: number
  /** §5.2 -- the dead-ground field: its lattice, and where each ring cuts it. */
  barrenCell: number
  barrenThreshold: Record<Ring, number>
  herdLifetimeMs: number
  herdChance: number
  /** §9.5.1 -- how long a pack stands, and the odds by ring. */
  packLifetimeMs: number
  packChance: Record<Ring, number>
  biomes: Biome[]
  biomeMaterial: Record<Biome, MaterialKey>
  biomeRare: Record<Biome, MaterialKey>
  skills: SkillKey[]
  namePrefixes: string[]
  nameSuffixes: string[]
  dungeonSites: Array<{ col: number; row: number; key: string; name: string }>
}

let config: WorldConfig | null = null

/** Install the server's generation parameters. Called once, at boot. */
export function configureWorld(next: WorldConfig): void {
  config = next
  biomeCache.clear()
  cellCache.clear()
}

export const isWorldConfigured = (): boolean => config !== null

/** The server's generation parameters, for callers that draw the whole world. */
export const worldParams = (): WorldConfig => cfg()

function cfg(): WorldConfig {
  if (!config) {
    throw new Error('World generation used before /api/world was loaded.')
  }
  return config
}

// --------------------------------------------------------------- ring layout

/**
 * §5.1 -- is this hex on the map at all? The mirror of WorldGen::inBounds().
 *
 * The one place the client decides where the edge is, so the render loop and
 * the atlas cannot disagree about it.
 */
export function inBounds(col: number, row: number): boolean {
  const c = cfg()
  return Math.abs(col) <= c.radius && Math.abs(row) <= c.radius
}

/** Normalised distance from map center, 0 at the capital ring, 1 at the rim. */
export function radiusOf(col: number, row: number): number {
  const c = cfg()
  const maxRadius = c.radius
  const dc = col / maxRadius
  const dr = row / maxRadius
  return Math.sqrt(dc * dc + dr * dr)
}

/** §5.2 -- concentric rings drive generation, not just color. */
export function ringOf(col: number, row: number): Ring {
  const { rings } = cfg()
  const r = radiusOf(col, row)
  if (r < rings.center) return 'center'
  if (r < rings.inner) return 'inner'
  if (r < rings.mid) return 'mid'
  return 'outer'
}

// -------------------------------------------------------------------- biomes

/**
 * §5.3 -- clustered regions, deliberately NOT noise. A jittered lattice (one
 * seed per cell, 5x5 neighborhood search) rather than scattered seed points:
 * scattered seeds produced ~186-tile regions, far beyond a low-level
 * character's travel range, stranding players in a single biome.
 */
const COARSE_DOMINANCE = 0.6

/** Hexes are wider than tall; weighting keeps biome regions visually round. */
const ASPECT = 1.28

interface CellSeed {
  x: number
  y: number
  biome: Biome
}

const cellCache = new Map<number, CellSeed>()
const biomeCache = new Map<number, Biome>()

function cellSeed(cx: number, cy: number): CellSeed {
  const cacheKey = cy * 100000 + cx
  const hit = cellCache.get(cacheKey)
  if (hit) return hit

  const c = cfg()
  const hx = hash2(cx, cy, c.seed ^ 0xb10e)
  const hy = hash2(cy, cx, c.seed ^ 0xb11e)
  const hMix = hash2(cx * 7 + cy * 13, cx - cy, c.seed ^ 0xb12e)

  // Coarse layer: the dominant biome across a whole region of cells. Without it
  // the map is confetti and "the forest is north-west" stops being true.
  const hCoarse = hash2(
    Math.floor(cx / c.biomeRegionCells),
    Math.floor(cy / c.biomeRegionCells),
    c.seed ^ 0xc0a5,
  )
  const coarse = c.biomes[randInt(hCoarse, 0, c.biomes.length - 1)]!

  const biome =
    rand01(hMix) < COARSE_DOMINANCE
      ? coarse
      : c.biomes[randInt(hash2(cx, cy, c.seed ^ 0xd1a1), 0, c.biomes.length - 1)]!

  const seed: CellSeed = {
    x: cx * c.biomeCell + randInt(hx, 0, c.biomeCell - 1),
    y: cy * c.biomeCell + randInt(hy, 0, c.biomeCell - 1),
    biome,
  }

  if (cellCache.size > 40_000) cellCache.clear()
  cellCache.set(cacheKey, seed)
  return seed
}

export function biomeOf(col: number, row: number): Biome {
  const cacheKey = (row + cfg().radius) * cfg().size + (col + cfg().radius)
  const hit = biomeCache.get(cacheKey)
  if (hit) return hit

  const best = biomeAt(col, row)
  if (biomeCache.size > 40_000) biomeCache.clear()
  biomeCache.set(cacheKey, best)
  return best
}

/**
 * The same answer without touching the per-tile cache.
 *
 * The atlas samples tens of thousands of scattered points in one pass, which
 * would evict the play map's warm tiles for entries it will never ask for
 * twice. The cell seeds underneath are still shared, and those are the
 * expensive half.
 */
export function biomeAt(col: number, row: number): Biome {
  const c = cfg()
  const cx = Math.floor(col / c.biomeCell)
  const cy = Math.floor(row / c.biomeCell)

  let best: Biome = 'plains'
  let bestDistance = Infinity

  for (let i = -2; i <= 2; i++) {
    for (let j = -2; j <= 2; j++) {
      const seed = cellSeed(cx + i, cy + j)
      const dx = (seed.x - col) * ASPECT
      const dy = seed.y - row
      const distance = dx * dx + dy * dy
      if (distance < bestDistance) {
        bestDistance = distance
        best = seed.biome
      }
    }
  }

  return best
}

/**
 * The biome a whole region leans towards, without resolving a single cell.
 *
 * COARSE_DOMINANCE is the share of cells in a region that take this value; the
 * rest are drawn at random, which is what stops biome borders being straight.
 * Point-sampling that mixture below one cell per pixel renders as static, so
 * charts zoomed past that generalise to this instead -- the same data at a
 * coarser level of detail, which is what map generalisation is.
 */
export function coarseBiomeAt(col: number, row: number): Biome {
  const c = cfg()
  const rx = Math.floor(Math.floor(col / c.biomeCell) / c.biomeRegionCells)
  const ry = Math.floor(Math.floor(row / c.biomeCell) / c.biomeRegionCells)
  return c.biomes[randInt(hash2(rx, ry, c.seed ^ 0xc0a5), 0, c.biomes.length - 1)]!
}

/** How many hexes across one region of uniform coarse biome is. */
export const coarseRegionHexes = (): number => cfg().biomeCell * cfg().biomeRegionCells

// --------------------------------------------------------------------- water

/**
 * §5.3 -- lakes and waterways, and neither is stored.
 *
 * The mirror of WorldGen::waterAt(). Both shapes are integer hashes and plain
 * arithmetic on purpose: no sine, no cosine. A boundary test landing within an
 * ulp of the edge would flip a hex between water and land depending on whose
 * libm answered, and the two generators have to agree on every tile.
 */
/**
 * §5.2 -- smooth noise in [0,1], the field dead ground is cut out of.
 *
 * The mirror of WorldGen::barrenField(). Value noise on a coarse lattice,
 * smoothstepped between corners, which is the cheapest thing that clusters --
 * and it has to cluster, because §5.3 wants a mentally navigable map and half
 * an outer ring of independently-rolled dead hexes would be pure speckle.
 *
 * The column axis is stretched by ASPECT like the lakes and the biome regions,
 * so regions read as country rather than as stripes down the columns (§13.2).
 */
export function barrenField(col: number, row: number): number {
  const c = cfg()
  const x = col / (c.barrenCell / ASPECT)
  const y = row / c.barrenCell

  const x0 = Math.floor(x)
  const y0 = Math.floor(y)
  const fx = smoothstep(x - x0)
  const fy = smoothstep(y - y0)

  const seed = c.seed ^ 0x2b1e
  const c00 = rand01(hash2(x0, y0, seed))
  const c10 = rand01(hash2(x0 + 1, y0, seed))
  const c01 = rand01(hash2(x0, y0 + 1, seed))
  const c11 = rand01(hash2(x0 + 1, y0 + 1, seed))

  return (
    (c00 * (1 - fx) + c10 * fx) * (1 - fy) + (c01 * (1 - fx) + c11 * fx) * fy
  )
}

function smoothstep(t: number): number {
  return t * t * (3 - 2 * t)
}

/**
 * §5.2 -- is this hex dead ground?
 *
 * Dead is not depleted. A depleted hex is drained and regrows in about nine
 * hours (§5.1); this one never had a seam and never will, so it keeps no timer,
 * shows no remnants, and is drawn in grey rather than in a tired version of its
 * own biome (§13.3).
 */
export function isBarren(col: number, row: number, ring: Ring): boolean {
  return barrenField(col, row) < (cfg().barrenThreshold[ring] ?? 0)
}

export function waterAt(col: number, row: number): WaterKind | undefined {
  if (lakeAt(col, row)) return 'lake'

  return riverAt(col, row) ? 'river' : undefined
}

const RIVERS = 4
const RIVER_SEGMENT = 24
const RIVER_AMPLITUDE = 0.09
const RIVER_HALF_WIDTH = 0.6

/**
 * Four waterways: two east-west, two north-south, on lines given as fractions
 * of the radius so the same four cross a test map and a ship-scale one. None
 * passes through the middle -- the barren ring is for dungeon mouths, and a
 * river there would read as a way in.
 */
const RIVER_LINES: Array<[0 | 1, number]> = [
  [0, -0.55],
  [0, 0.46],
  [1, -0.44],
  [1, 0.57],
]

const riverAmplitude = () => Math.max(4, Math.round(cfg().radius * RIVER_AMPLITUDE))

/**
 * Where a waterway's channel sits at one step along its length: value noise,
 * one hashed offset every RIVER_SEGMENT hexes, smoothstepped between.
 */
function riverCenter(index: number, t: number): number {
  const c = cfg()
  const cell = Math.floor(t / RIVER_SEGMENT)
  const f = (t - cell * RIVER_SEGMENT) / RIVER_SEGMENT

  const amplitude = riverAmplitude()
  const a = randInt(hash2(cell, index, c.seed ^ 0x21ce), -amplitude, amplitude)
  const b = randInt(hash2(cell + 1, index, c.seed ^ 0x21ce), -amplitude, amplitude)

  const u = f * f * (3 - 2 * f)
  const base = Math.round(RIVER_LINES[index]![1] * c.radius)

  return base + a + (b - a) * u
}

/**
 * A hex is in the channel if it lies between this step's center and the next
 * one's. Consecutive bands share an endpoint, so the water is continuous by
 * construction rather than by guessing a width that covers every slope.
 */
function riverAt(col: number, row: number): boolean {
  for (let index = 0; index < RIVERS; index++) {
    const axis = RIVER_LINES[index]![0]
    const along = axis === 0 ? col : row
    const across = axis === 0 ? row : col

    const here = riverCenter(index, along)
    const next = riverCenter(index, along + 1)

    if (
      across >= Math.min(here, next) - RIVER_HALF_WIDTH &&
      across <= Math.max(here, next) + RIVER_HALF_WIDTH
    ) {
      return true
    }
  }

  return false
}

const LAKE_CELL = 34
const LAKE_CHANCE = 0.42
const LAKE_MIN_RADIUS = 3
const LAKE_MAX_RADIUS = 5
const LAKE_EDGE_WOBBLE = 0.7

/**
 * One candidate lake per cell -- the same lattice trick the settlements use,
 * so a blob can be tested for without ever enumerating it.
 */
function lakeIn(cellCol: number, cellRow: number): [number, number, number] | undefined {
  const c = cfg()

  if (rand01(hash2(cellCol, cellRow, c.seed ^ 0x1a4e)) > LAKE_CHANCE) return undefined

  // Inset from the cell edge so a lake never reaches past the 3x3 scanned.
  const inset = LAKE_MAX_RADIUS + 2

  return [
    cellCol * LAKE_CELL +
      randInt(hash2(cellCol, cellRow, c.seed ^ 0x1a5e), inset, LAKE_CELL - inset - 1),
    cellRow * LAKE_CELL +
      randInt(hash2(cellRow, cellCol, c.seed ^ 0x1a6e), inset, LAKE_CELL - inset - 1),
    randInt(
      hash2(cellCol + cellRow, cellCol - cellRow, c.seed ^ 0x1a7e),
      LAKE_MIN_RADIUS,
      LAKE_MAX_RADIUS,
    ),
  ]
}

function lakeAt(col: number, row: number): boolean {
  const c = cfg()
  const cx = Math.floor(col / LAKE_CELL)
  const cy = Math.floor(row / LAKE_CELL)

  // §13.2 -- hexes are wider than tall, so the weighting the biome regions use
  // keeps a lake round on screen instead of drawn out along the columns.
  const wobble =
    rand01(hash2(col, row, c.seed ^ 0x1a8e)) * LAKE_EDGE_WOBBLE - LAKE_EDGE_WOBBLE / 2

  for (let i = -1; i <= 1; i++) {
    for (let j = -1; j <= 1; j++) {
      const site = lakeIn(cx + i, cy + j)
      if (!site) continue

      const dx = (site[0] - col) * ASPECT
      const dy = site[1] - row

      if (Math.sqrt(dx * dx + dy * dy) + wobble < site[2]) return true
    }
  }

  return false
}

// --------------------------------------------------------------- settlements

/**
 * Settlements sit on a jittered lattice: one candidate site per cell, so a
 * region can be enumerated without storing anything. Cell size per tier is what
 * produces "villages > cities > capitals" in count -- §6 calls that a
 * cost-curve outcome, and this is the generation half of it.
 *
 * `minGap` is the guaranteed floor on the distance between two settlements of
 * the same tier, in hexes. A cell alone does not give one: a site free to land
 * anywhere in its cell can sit against the shared edge of two cells, which put
 * villages on touching hexes. `siteOffset` narrows the window instead.
 */
const LATTICE: Record<
  SettlementTier,
  { cell: number; minGap: number; chance: number; salt: number }
> = {
  village: { cell: 11, minGap: 8, chance: 0.8, salt: 0x1111 },
  city: { cell: 14, minGap: 11, chance: 0.45, salt: 0x2222 },
  capital: { cell: 26, minGap: 15, chance: 0.7, salt: 0x3333 },
}

/**
 * Where inside its cell a site sits, on one axis.
 *
 * The window a site may choose from is narrower than the cell and centerd in
 * it, leaving a margin at each edge. Two sites in neighboring cells are then
 * at least `cell - window + 1` apart on that axis -- which is `minGap` -- and
 * hex distance is never less than the larger axial difference, so the floor
 * holds diagonally too.
 *
 * Mirrored in WorldGen::siteOffset. The parity fixture pins both.
 */
function siteOffset(cell: number, minGap: number, h: number): number {
  const window = cell - minGap + 1
  const margin = Math.floor((cell - window) / 2)
  return margin + randInt(h, 0, window - 1)
}

/**
 * §5.2 -- which tier, if any, each concentric ring carries.
 *
 * Capitals sit in the contested ring, not the dead center: the walk to a capital
 * bench is meant to cross ground other prospectors are working, and the center
 * is reserved for dungeon mouths alone. Both of those rings are PvP ground.
 */
const TIER_FOR_RING: Record<Ring, SettlementTier | null> = {
  outer: 'village',
  mid: 'city',
  inner: 'capital', // contested, and where the best bench stands
  center: null, // dungeon mouths only, and barren of everything else
}

/** Weakest first. A tier yields to everything above it and to nothing below. */
const TIER_ORDER: SettlementTier[] = ['village', 'city', 'capital']

const TIERS_ABOVE = Object.fromEntries(
  TIER_ORDER.map((tier, i) => [tier, TIER_ORDER.slice(i + 1)]),
) as Record<SettlementTier, SettlementTier[]>

/** Where a tier's candidate sits inside one cell. Position only -- this says
 *  nothing about whether the cell actually fills. */
function siteIn(tier: SettlementTier, cellCol: number, cellRow: number): [number, number] {
  const c = cfg()
  const { cell, minGap, salt } = LATTICE[tier]
  return [
    cellCol * cell + siteOffset(cell, minGap, hash2(cellCol, cellRow, c.seed ^ salt)),
    cellRow * cell + siteOffset(cell, minGap, hash2(cellRow, cellCol, c.seed ^ (salt + 1))),
  ]
}

/** Not every cell gets a settlement -- that is what makes density feel organic. */
function cellFills(tier: SettlementTier, cellCol: number, cellRow: number): boolean {
  const c = cfg()
  const { chance, salt } = LATTICE[tier]
  return rand01(hash2(cellCol, cellRow, c.seed ^ (salt + 2))) <= chance
}

/**
 * The settlement of this tier standing in this cell, or null.
 *
 * Everything a site has to pass except the crowding test, which is deliberately
 * left out: this is what `crowdedByBetter` asks about its *neighbors*, and
 * putting it here would recurse.
 */
function settledSite(
  tier: SettlementTier,
  cellCol: number,
  cellRow: number,
): [number, number] | null {
  if (!cellFills(tier, cellCol, cellRow)) return null

  const [col, row] = siteIn(tier, cellCol, cellRow)
  // A site generated for one ring but landing in another is not a settlement of
  // that tier -- tiers never bleed across ring boundaries.
  if (TIER_FOR_RING[ringOf(col, row)] !== tier) return null

  return [col, row]
}

/**
 * §6.0 -- where two tiers could crowd, the *higher* tier's gap applies and the
 * lower tier is the one that yields. A village keeps a city's 11 hexes rather
 * than its own 8; a city is never moved by a village.
 *
 * Same-tier spacing is guaranteed by construction (see `siteOffset`). This one
 * cannot be, because the two tiers sit on lattices of different sizes and no
 * choice of window separates them. So it is a rejection instead, costing one
 * small lattice scan per higher tier -- and only for a candidate that has
 * already earned its place, which is a few dozen tiles in every ten thousand.
 *
 * Not recursive, and it does not need to be: a capital can only ever suppress a
 * city, and the whole barren inner ring lies between those two tiers, so that
 * pair never comes within reach. Revisit if §5.2 moves a ring boundary.
 */
function crowdedByBetter(tier: SettlementTier, col: number, row: number): boolean {
  for (const above of TIERS_ABOVE[tier]) {
    const { cell, minGap } = LATTICE[above]

    // Hex distance is never below the larger axial difference, so anything
    // within minGap hexes is also within minGap columns and rows -- these are
    // every cell that could hold one. Negative cells hold nothing: their sites
    // would land off the map.
    const cxMin = Math.floor((col - minGap) / cell)
    const cyMin = Math.floor((row - minGap) / cell)

    for (let cx = cxMin; cx <= Math.floor((col + minGap) / cell); cx++) {
      for (let cy = cyMin; cy <= Math.floor((row + minGap) / cell); cy++) {
        const site = settledSite(above, cx, cy)
        if (site && hexDistance(col, row, site[0], site[1]) < minGap) return true
      }
    }
  }

  return false
}

/** §6 -- village runs 1 of 5 lines, city 2, capital all 5. */
function linesFor(tier: SettlementTier, col: number, row: number): SkillKey[] {
  const c = cfg()
  if (tier === 'capital') return [...c.skills]

  const count = tier === 'city' ? 2 : 1
  const pool = [...c.skills]
  const picked: SkillKey[] = []

  for (let i = 0; i < count; i++) {
    const h = hash2(col, row + i * 977, c.seed ^ 0x5171)
    picked.push(pool.splice(randInt(h, 0, pool.length - 1), 1)[0]!)
  }
  return picked
}

function nameFor(col: number, row: number, tier: SettlementTier): string {
  const c = cfg()
  const hp = hash2(col, row, c.seed ^ 0x7ae1)
  const hs = hash2(row, col, c.seed ^ 0x7ae2)

  const base =
    c.namePrefixes[randInt(hp, 0, c.namePrefixes.length - 1)]! +
    c.nameSuffixes[randInt(hs, 0, c.nameSuffixes.length - 1)]!

  if (tier === 'capital') return `${base} Keep`
  if (tier === 'city') return `${base} City`
  return base
}

/** The settlement on this tile, if any. Pure function of position. */
export function settlementAt(col: number, row: number): Settlement | undefined {
  // §5.1 -- nobody lives off the edge, and the lattice extends past it.
  if (!inBounds(col, row)) return undefined

  const ring = ringOf(col, row)
  const tier = TIER_FOR_RING[ring]
  if (!tier) return undefined

  const { cell } = LATTICE[tier]
  const cellCol = Math.floor(col / cell)
  const cellRow = Math.floor(row / cell)

  // Cheapest rejection first, and it turns away almost every tile: this one is
  // not the site its cell chose.
  const [siteCol, siteRow] = siteIn(tier, cellCol, cellRow)
  if (siteCol !== col || siteRow !== row) return undefined

  if (!cellFills(tier, cellCol, cellRow)) return undefined

  // The ring test settledSite() makes is already satisfied here: `tier` was
  // read from this tile's own ring, and the site is this tile.
  if (crowdedByBetter(tier, col, row)) return undefined

  return {
    id: `s_${col}_${row}`,
    name: nameFor(col, row, tier),
    tier,
    col,
    row,
    lines: linesFor(tier, col, row),
  }
}

/** Just enough of a settlement to draw and name it on a chart. */
export interface SettlementMark {
  col: number
  row: number
  tier: SettlementTier
  name: string
  /** §6 -- which of the five lines it runs. Two hashes, so the chart affords it. */
  lines: SkillKey[]
}

/**
 * Every settlement inside a rectangle of hexes, without visiting a single tile.
 *
 * Sites live on a lattice -- one candidate per cell per tier -- so a region can
 * be enumerated by walking cells instead of the tiles they are scattered
 * across. A chart covering 600 columns costs a few thousand hashes rather than
 * 300,000 tile tests, which is what lets the atlas pan freely while asking the
 * server for nothing.
 *
 * Equivalent to calling settlementAt() on every hex in the box, and the parity
 * fixture pins them to each other.
 */
export function settlementMarksIn(
  colMin: number,
  colMax: number,
  rowMin: number,
  rowMax: number,
  tiers: SettlementTier[] = ['village', 'city', 'capital'],
): SettlementMark[] {
  const out: SettlementMark[] = []

  for (const tier of tiers) {
    const { cell } = LATTICE[tier]

    for (let cx = Math.floor(colMin / cell); cx <= Math.floor(colMax / cell); cx++) {
      for (let cy = Math.floor(rowMin / cell); cy <= Math.floor(rowMax / cell); cy++) {
        // Cheapest rejections first: most cells hold nothing, and crowding is
        // the only test here that costs a lattice scan of its own.
        const site = settledSite(tier, cx, cy)
        if (!site) continue

        const [col, row] = site
        if (col < colMin || col > colMax || row < rowMin || row > rowMax) continue
        if (!inBounds(col, row)) continue
        if (crowdedByBetter(tier, col, row)) continue

        out.push({ col, row, tier, name: nameFor(col, row, tier), lines: linesFor(tier, col, row) })
      }
    }
  }

  return out
}

// ------------------------------------------------------------------ dungeons

/** §9.1 -- exactly five, one per biome, sited in the barren capital ring. */
export function dungeonAt(col: number, row: number): { key: string; name: string } | undefined {
  const site = cfg().dungeonSites.find((s) => s.col === col && s.row === row)
  return site ? { key: site.key, name: site.name } : undefined
}

// --------------------------------------------------------------------- tiles

/**
 * §5.5 -- herd markers are temporary and time-bucketed, so they are derivable
 * rather than stored and every client agrees on where they are.
 */
// §5.5 -- a herd stands on hunting ground and nowhere else. Herds wandering
// onto every biome made the bow the one tool with no ground of its own: every
// line has a biome it is worked on (§8.0), and hunting's is the plains.
function herdUntil(col: number, row: number, biome: Biome, now: number): number | undefined {
  if (BIOME_MATERIAL[biome] !== SKILL_BY_KEY.hunting.material) return undefined

  const c = cfg()

  const bucket = Math.floor(now / c.herdLifetimeMs)
  const h = hash2(col * 31 + bucket, row * 17 + bucket, c.seed ^ 0xbeef)
  if (rand01(h) > c.herdChance) return undefined

  return (bucket + 1) * c.herdLifetimeMs
}

/**
 * §9.5.1 -- is a pack standing here, and which one.
 *
 * The mirror of WorldGen::packAt(). Same trick the herd uses, plus a per-hex
 * OFFSET into the bucket: without it every pack in the world would appear and
 * vanish on the same two-hour heartbeat, which with a pin on the far end of one
 * (§9.5.3) is a rhythm players would set a watch by. `until` comes back in the
 * caller's time base, so nothing outside here knows the offset exists.
 */
function packAt(col: number, row: number, ring: Ring, now: number): Pack | undefined {
  const c = cfg()

  const lifetime = c.packLifetimeMs
  const offset = randInt(hash2(col, row, c.seed ^ 0x9ac1), 0, Math.max(0, lifetime - 1))

  const bucket = Math.floor((now + offset) / lifetime)
  const h = hash2(col * 37 + bucket, row * 19 + bucket, c.seed ^ 0x5eed)
  if (rand01(h) > (c.packChance[ring] ?? 0)) return undefined

  // §9.5.2 -- a ring fights its own two and the two from outside it, so which
  // of the four turns up is another roll on the same bucket.
  const pool = MONSTERS_BY_RING[ring] ?? []
  if (pool.length === 0) return undefined

  const pick = randInt(
    hash2(col * 41 + bucket, row * 23 + bucket, c.seed ^ 0x77a3),
    0,
    pool.length - 1,
  )

  return {
    key: pool[pick]!,
    bucket,
    until: (bucket + 1) * lifetime - offset,
  }
}

export interface TileMutation {
  slotsUsed?: number
  /** §5.1 -- bodies at work here, any verb. See Tile.workers. */
  workers?: number
  regrowsAt?: number
  /** §5.1 -- hauls already off this hex. Shared, and the seed cannot know it. */
  taken?: number
  /**
   * §9.5.1 -- somebody already fought the pack standing here this bucket. The
   * one thing about a pack the seed cannot know, folded in here so every reader
   * downstream sees the same absence.
   */
  packCleared?: boolean
}

/**
 * §5.3 -- which of the biome's four variants this hex turned out to be.
 *
 * The mirror of WorldGen::variantOf(). A weighted walk in fixed grade order
 * over the ring's column, which sums to 1 by construction. Fixed order is what
 * keeps the client's answer identical to the server's: same table, same roll,
 * same stopping place.
 */
export function variantOf(
  col: number,
  row: number,
  biome: Biome,
  ring: Ring,
): VariantDef {
  const variants = BIOME_VARIANTS[biome]
  const roll = rand01(hash2(col, row, cfg().seed ^ 0xc3))

  // §5.2 -- the center rolls on the inner ring's table, because it IS the
  // contested ring: same grades, same Tier 3 rate. The weight table has three
  // columns and gains no fourth, so the alias lives here rather than as a
  // duplicated column that could drift from its twin.
  const column = ring === 'center' ? 'inner' : (ring as 'outer' | 'mid' | 'inner')

  let seen = 0
  for (const variant of variants) {
    seen += variant.weights[column] ?? 0
    if (roll < seen) return variant
  }

  return variants[0]
}

/**
 * §5.3 -- a hex's HP, scaled by the grade of ground it turned out to be.
 *
 * Mirrors WorldGen::tileHp(). The roll is the same 2,700-5,400 it always was;
 * what the grade decides is the rung that roll is measured at, so an Ironwood
 * Grove is four and two thirds times the work an ordinary forest is -- the
 * ratio between an Ironwood Axe and a Stone one.
 *
 * Integer arithmetic, because a float multiplier would be two generators
 * rounding a repeating decimal and hoping (scripts/parity.ts).
 */
export function tileHp(hash: number, grade: string): number {
  const c = cfg()
  const roll = randInt(hash, c.hpMin, c.hpMax)
  const attack = c.hpGradeAttack[grade] ?? c.commonAttack

  return Math.floor((roll * attack) / c.commonAttack)
}

/**
 * §5.1 -- how many hauls this hex has in it, from what one haul is worth.
 *
 * Mirrors WorldGen::tileExtractions(). Inverse and linear across the band: the
 * richest ground gives up the fewest hauls and the poorest the most, so what a
 * hex is worth over its life comes out roughly level and what a better hex buys
 * is FEWER WALKS for the same total.
 *
 * Integer arithmetic, so the two generators cannot round apart.
 */
export function tileExtractions(baseYield: number): number {
  const c = cfg()
  const span = c.yieldMax - c.yieldMin
  const drop = c.extractionsMax - c.extractionsMin
  const over = Math.max(0, Math.min(span, baseYield - c.yieldMin))

  return c.extractionsMax - Math.floor((over * drop) / span)
}

/** Build a tile. `mutation` is the only server-owned state a tile can carry. */
export function generateTile(
  col: number,
  row: number,
  now: number,
  mutation?: TileMutation,
): Tile {
  const c = cfg()
  const ring = ringOf(col, row)
  const biome = biomeOf(col, row)
  const settlement = settlementAt(col, row)
  const dungeon = dungeonAt(col, row)

  const hTime = hash2(col, row, c.seed ^ 0xa1)
  const hYield = hash2(col, row, c.seed ^ 0xb2)
  const baseYield = randInt(hYield, c.yieldMin, c.yieldMax)

  // §5.2 -- what pressure there is toward the middle is the seam gradient now
  // (barrenThreshold), not a hole in the map. The center used to be excluded
  // here outright.
  //
  // A depleted tile keeps its material: it is drained, not dead (§5.1), and
  // callers gate on regrowsAt. The UI needs it to draw the right remnants.
  // §5.3 -- water yields to the things that are placed rather than grown. A
  // settlement on a river is a ford and a dungeon mouth is a fixed site; the
  // water is the cheaper of the two to bend.
  const water = !settlement && !dungeon ? waterAt(col, row) : undefined

  // §5.2 -- dead ground, and the one thing that decides whether a hex has a
  // seam in it at all. The center used to be excluded outright -- "barren of
  // everything" -- and is now ordinary contested ground taking its chances with
  // the same field as the three rings around it.
  const barren = !settlement && !water && !dungeon && isBarren(col, row, ring)

  let material: MaterialKey | undefined
  // Dead ground belongs to no biome, so it says so rather than wearing the
  // colour of the country it interrupts.
  let variant: VariantKey = barren ? 'barren' : biome
  let grade = 'common'
  if (!barren && !settlement && !water) {
    const picked = variantOf(col, row, biome, ring)
    variant = picked.key
    grade = picked.grade
    material = picked.material as MaterialKey
  }

  return {
    col,
    row,
    biome,
    variant,
    ring,
    material,
    hp: tileHp(hTime, grade),
    baseYield,
    extractions: tileExtractions(baseYield),
    slotsUsed: mutation?.slotsUsed ?? 0,
    workers: mutation?.workers ?? 0,
    taken: mutation?.taken ?? 0,
    regrowsAt: mutation?.regrowsAt ?? 0,
    settlement,
    dungeon,
    water,
    // §5.5 -- a herd stands on open ground. Nothing grazes a lake, and nothing
    // grazes a town: a settlement is worked ground (§6), and a deer in the
    // market square is the same category error as a pack camped on a capital.
    //
    // Nothing grazes dead ground either, and that one is the plainest of the
    // three: §5.2's barren is scoured to the pan, and a herd needs something to
    // have been eating.
    herdUntil:
      water || settlement || dungeon || barren ? undefined : herdUntil(col, row, biome, now),
    // §9.5.1 -- nothing camps on water, a settlement or a dungeon mouth. The
    // second is the load-bearing one: a pack on a capital would lock a region
    // out of the only five-line bench it has.
    pack:
      water || settlement || dungeon || mutation?.packCleared
        ? undefined
        : packAt(col, row, ring, now),
    propSeed: hash2(col, row, c.seed ^ 0xf00d),
  }
}
