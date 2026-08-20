/**
 * Client-side world generation, §5.
 *
 * The map is ~5000x5000 = 25 million tiles and none of it is stored or shipped.
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
import type { Biome, MaterialKey, Ring, Settlement, SettlementTier, SkillKey, Tile } from './types'

export interface WorldConfig {
  seed: number
  cols: number
  rows: number
  biomeCell: number
  biomeRegionCells: number
  rings: { center: number; inner: number; mid: number }
  baseMinSeconds: number
  baseMaxSeconds: number
  rareSpawnChance: number
  slotsPerTile: number
  herdLifetimeMs: number
  herdChance: number
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

/** Normalised distance from map centre, 0 at the capital ring, 1 at the rim. */
export function radiusOf(col: number, row: number): number {
  const c = cfg()
  const maxRadius = Math.min(c.cols, c.rows) / 2
  const dc = (col - c.cols / 2) / maxRadius
  const dr = (row - c.rows / 2) / maxRadius
  return Math.sqrt(dc * dc + dr * dr)
}

/** §5.2 -- concentric rings drive generation, not just colour. */
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
 * seed per cell, 5x5 neighbourhood search) rather than scattered seed points:
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
  const cacheKey = row * cfg().cols + col
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

// --------------------------------------------------------------- settlements

/**
 * Settlements sit on a jittered lattice: one candidate site per cell gives
 * minimum spacing without storing anything. Cell size per tier is what produces
 * "villages > cities > capitals" in count -- §6 calls that a cost-curve
 * outcome, and this is the generation half of it.
 */
const LATTICE: Record<SettlementTier, { cell: number; chance: number; salt: number }> = {
  village: { cell: 7, chance: 0.55, salt: 0x1111 },
  city: { cell: 14, chance: 0.45, salt: 0x2222 },
  capital: { cell: 26, chance: 0.7, salt: 0x3333 },
}

const TIER_FOR_RING: Record<Ring, SettlementTier | null> = {
  outer: 'village',
  mid: 'city',
  inner: null, // contested mining ground, no safe infrastructure
  center: 'capital',
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
  const c = cfg()
  const ring = ringOf(col, row)
  const tier = TIER_FOR_RING[ring]
  if (!tier) return undefined

  const { cell, chance, salt } = LATTICE[tier]
  const cellCol = Math.floor(col / cell)
  const cellRow = Math.floor(row / cell)

  const hc = hash2(cellCol, cellRow, c.seed ^ salt)
  const hr = hash2(cellRow, cellCol, c.seed ^ (salt + 1))
  const siteCol = cellCol * cell + randInt(hc, 0, cell - 1)
  const siteRow = cellRow * cell + randInt(hr, 0, cell - 1)
  if (siteCol !== col || siteRow !== row) return undefined

  // Not every cell gets a settlement -- that is what makes density feel organic.
  const hp = hash2(cellCol, cellRow, c.seed ^ (salt + 2))
  if (rand01(hp) > chance) return undefined

  // A site generated in one ring but landing in another is rejected, so tiers
  // never bleed across ring boundaries.
  if (ringOf(siteCol, siteRow) !== ring) return undefined

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
  const c = cfg()
  const out: SettlementMark[] = []

  for (const tier of tiers) {
    const { cell, chance, salt } = LATTICE[tier]

    for (let cx = Math.floor(colMin / cell); cx <= Math.floor(colMax / cell); cx++) {
      for (let cy = Math.floor(rowMin / cell); cy <= Math.floor(rowMax / cell); cy++) {
        // Cheapest rejection first: most cells hold nothing.
        if (rand01(hash2(cx, cy, c.seed ^ (salt + 2))) > chance) continue

        const col = cx * cell + randInt(hash2(cx, cy, c.seed ^ salt), 0, cell - 1)
        const row = cy * cell + randInt(hash2(cy, cx, c.seed ^ (salt + 1)), 0, cell - 1)

        if (col < colMin || col > colMax || row < rowMin || row > rowMax) continue
        if (col < 0 || row < 0 || col >= c.cols || row >= c.rows) continue
        // A site generated for one ring but landing in another is not a
        // settlement of that tier -- tiers never bleed across ring boundaries.
        if (TIER_FOR_RING[ringOf(col, row)] !== tier) continue

        out.push({ col, row, tier, name: nameFor(col, row, tier) })
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
function herdUntil(col: number, row: number, biome: Biome, now: number): number | undefined {
  const c = cfg()
  if (biome !== 'plains' && biome !== 'grassland') return undefined

  const bucket = Math.floor(now / c.herdLifetimeMs)
  const h = hash2(col * 31 + bucket, row * 17 + bucket, c.seed ^ 0xbeef)
  if (rand01(h) > c.herdChance) return undefined

  return (bucket + 1) * c.herdLifetimeMs
}

export interface TileMutation {
  slotsUsed?: number
  regrowsAt?: number
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
  const hRare = hash2(col, row, c.seed ^ 0xc3)

  // §5.2 -- the capital ring is barren of resources. That is the pressure that
  // forces traffic outward for materials and inward for processing.
  //
  // A depleted tile keeps its material: it is drained, not dead (§5.1), and
  // callers gate on regrowsAt. The UI needs it to draw the right remnants.
  let material: MaterialKey | undefined
  if (ring !== 'center' && !settlement) {
    material =
      ring === 'inner' && rand01(hRare) < c.rareSpawnChance
        ? c.biomeRare[biome]
        : c.biomeMaterial[biome]
  }

  return {
    col,
    row,
    biome,
    ring,
    material,
    baseSeconds: randInt(hTime, c.baseMinSeconds, c.baseMaxSeconds),
    baseYield: randInt(hYield, 3, 8),
    slotsUsed: mutation?.slotsUsed ?? 0,
    regrowsAt: mutation?.regrowsAt ?? 0,
    settlement,
    dungeon,
    herdUntil: herdUntil(col, row, biome, now),
    propSeed: hash2(col, row, c.seed ^ 0xf00d),
  }
}
