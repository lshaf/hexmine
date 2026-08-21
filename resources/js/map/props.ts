/**
 * Procedural tile props, §13. No artist required: every prop is SVG generated
 * from the tile's deterministic seed, so the same hex always grows the same
 * trees. Returned as markup strings and injected into the tile group -- one
 * string concat beats hundreds of Vue component instances at this tile count.
 *
 * Props stand *above* their own tile, which is exactly why the map uses a
 * painter's-algorithm sort (§13.2): a mountain has to occlude the hexes behind it.
 */
import { hash2, rand01, randInt } from '@/game/hash'
import { BIOME_COLOR, shade } from '@/theme/palette'
import type { SettlementTier, Tile } from '@/game/types'

/** Escape nothing -- all values are numbers we generate. Kept tiny on purpose. */
const poly = (points: string, fill: string, stroke?: string) =>
  `<polygon points="${points}" fill="${fill}"${stroke ? ` stroke="${stroke}" stroke-width="1"` : ''}/>`

const rect = (x: number, y: number, w: number, h: number, fill: string) =>
  `<rect x="${x}" y="${y}" width="${w}" height="${h}" fill="${fill}"/>`

function conifer(x: number, y: number, scale: number, color: string): string {
  const trunk = shade(color, -0.55)
  const h = 16 * scale
  const w = 7 * scale
  return (
    rect(x - 1 * scale, y - 3 * scale, 2 * scale, 4 * scale, trunk) +
    poly(
      `${x},${y - h} ${x + w},${y - 2 * scale} ${x - w},${y - 2 * scale}`,
      color,
    ) +
    poly(
      `${x},${y - h} ${x},${y - 2 * scale} ${x - w},${y - 2 * scale}`,
      shade(color, -0.16),
    )
  )
}

function stump(x: number, y: number, color: string): string {
  const bark = shade(color, -0.5)
  return (
    rect(x - 2, y - 4, 4, 4, bark) +
    poly(`${x - 2},${y - 4} ${x + 2},${y - 4} ${x + 2},${y - 5} ${x - 2},${y - 5}`, shade(color, -0.3))
  )
}

function sapling(x: number, y: number, color: string): string {
  return (
    rect(x, y - 5, 1, 5, shade(color, -0.5)) +
    poly(`${x + 0.5},${y - 9} ${x + 4},${y - 4} ${x - 3},${y - 4}`, shade(color, 0.12))
  )
}

function peak(x: number, y: number, scale: number, color: string): string {
  const h = 22 * scale
  const w = 15 * scale
  const lit = shade(color, 0.2)
  const dark = shade(color, -0.28)
  return (
    poly(`${x},${y - h} ${x + w},${y} ${x - w},${y}`, dark) +
    poly(`${x},${y - h} ${x},${y} ${x - w},${y}`, lit) +
    // snow cap
    poly(`${x},${y - h} ${x + 4.5 * scale},${y - h + 6 * scale} ${x - 4.5 * scale},${y - h + 6 * scale}`, '#d9e2e6')
  )
}

function rockShard(x: number, y: number, scale: number, color: string): string {
  const h = 10 * scale
  const w = 6 * scale
  return (
    poly(`${x},${y - h} ${x + w},${y} ${x - w},${y}`, shade(color, -0.3)) +
    poly(`${x},${y - h} ${x},${y} ${x - w},${y}`, shade(color, -0.1))
  )
}

function tuft(x: number, y: number, color: string): string {
  const c = shade(color, -0.22)
  return (
    poly(`${x},${y - 6} ${x + 1.4},${y} ${x - 1.4},${y}`, c) +
    poly(`${x - 3.5},${y - 4} ${x - 2.4},${y} ${x - 4.6},${y}`, c) +
    poly(`${x + 3.5},${y - 4} ${x + 4.6},${y} ${x + 2.4},${y}`, c)
  )
}

/** Herd marker, §5.5 -- temporary, so it reads as a visitor not terrain. */
function herd(x: number, y: number): string {
  const body = '#6b4f39'
  return (
    `<ellipse cx="${x}" cy="${y - 5}" rx="5" ry="3.2" fill="${body}"/>` +
    `<ellipse cx="${x + 6}" cy="${y - 3}" rx="3.6" ry="2.4" fill="${shade(body, -0.15)}"/>` +
    rect(x - 3, y - 3, 1.2, 3, shade(body, -0.4)) +
    rect(x + 2, y - 3, 1.2, 3, shade(body, -0.4))
  )
}

// ------------------------------------------------------------- settlements

/*
 * The three settlement tiers, §6.
 *
 * These have to be told apart while the map is moving, at roughly 40px inside a
 * 58x34 hex, often with only one of them on screen -- so size cannot be the
 * signal. You cannot compare a height against a settlement that is not there.
 *
 * Each tier gets a different *kind* of shape instead:
 *
 *   village   scatter      loose huts, unaligned, no enclosing line -> dots
 *   city      enclosure    a wide crenellated wall with a gate      -> a toothed bar
 *   capital   spire        one dominant vertical with a pennant     -> a tall mark
 *
 * That is a categorical difference, not a quantitative one, which is why it
 * survives being small. Pale walls do the other half of the work: nothing
 * organic on this map is near vellum, so masonry reads as human-made instantly.
 */
const ROOF = '#8f4f3c'
const WALL = '#d6cbb0'
const STONE = '#8b8f93'
/** Cities roof in slate, not terracotta -- colder, and institutional. */
const SLATE = '#5f6b72'
const PENNANT = '#d8b34a'

function hut(x: number, y: number, scale = 1, roof = ROOF): string {
  const w = 9 * scale
  const h = 7 * scale
  return (
    rect(x - w / 2, y - h, w, h, WALL) +
    poly(`${x},${y - h - 6 * scale} ${x + w / 2 + 1.5},${y - h} ${x - w / 2 - 1.5},${y - h}`, roof) +
    rect(x - 1.2 * scale, y - 3.4 * scale, 2.4 * scale, 3.4 * scale, shade(WALL, -0.55))
  )
}

/** A gabled roof with no walls -- for buildings standing behind a city wall. */
function gable(x: number, y: number, scale: number, roof: string): string {
  const w = 8 * scale
  return poly(`${x},${y - 7 * scale} ${x + w},${y} ${x - w},${y}`, roof)
}

/**
 * The city wall: a run of masonry with teeth along the top and a gate through
 * it. The teeth are the tell -- no terrain prop on this map has a repeating
 * hard edge, so a toothed horizontal reads as "city" even at a glance.
 */
function rampart(y: number, halfWidth: number, height: number): string {
  const top = y - height
  let out = rect(-halfWidth, top, halfWidth * 2, height, STONE)
  // Lit face along the bottom, so the wall does not read as a flat slab.
  out += rect(-halfWidth, y - height * 0.4, halfWidth * 2, height * 0.4, shade(STONE, -0.2))

  // Few and chunky. Seven fine teeth vanished at map scale -- five fat ones
  // survive, and the tooth rhythm is the whole point of the shape.
  const teeth = 5
  const step = (halfWidth * 2) / teeth
  for (let i = 0; i < teeth; i++) {
    out += rect(-halfWidth + i * step + step * 0.1, top - 4.4, step * 0.6, 4.6, STONE)
  }

  // Gate: the one opening in the run, and the darkest thing on the tile.
  out += rect(-4, y - 8.5, 8, 8.5, '#221d1a')
  out += poly(`0,${y - 12.4} 4,${y - 8.5} -4,${y - 8.5}`, '#221d1a')

  return out
}

/**
 * The capital's spire. Deliberately the tallest thing on the map -- taller than
 * a mountain peak (22) -- so the painter's sort (§13.2) makes it occlude hard.
 */
function spire(x: number, y: number, height: number): string {
  const w = 9
  const topW = 6
  const top = y - height
  return (
    // Tapered shaft, drawn as a trapezoid so it narrows with height.
    poly(
      `${x - w / 2},${y} ${x + w / 2},${y} ${x + topW / 2},${top} ${x - topW / 2},${top}`,
      STONE,
    ) +
    poly(
      `${x - w / 2},${y} ${x},${y} ${x},${top} ${x - topW / 2},${top}`,
      shade(STONE, 0.14),
    ) +
    // Spike roof.
    poly(`${x},${top - 11} ${x + topW / 2 + 1.6},${top} ${x - topW / 2 - 1.6},${top}`, SLATE) +
    // Mast and pennant. The only cloth on the map, and the only gold: §10 makes
    // capitals the thing guilds spend their gold to hold.
    rect(x - 0.6, top - 19, 1.2, 9, shade(STONE, -0.3)) +
    poly(`${x + 0.6},${top - 19} ${x + 9},${top - 16.4} ${x + 0.6},${top - 13.8}`, PENNANT)
  )
}

function settlementProp(tier: SettlementTier, seed: number): string {
  if (tier === 'village') {
    // Scatter: spread wide enough that they read as separate marks. Any closer
    // and three huts become one blob, which is the city's read, not this one.
    return (
      hut(-14, 9, 0.78) +
      hut(12, 6, 0.72) +
      hut(-1, 13, 0.68) +
      (rand01(seed) > 0.5 ? hut(6, 15, 0.55) : '')
    )
  }

  if (tier === 'city') {
    // Enclosure: one steep roof rising behind the wall, in a slate dark enough
    // to separate from the masonry, then the wall cropping its feet.
    return gable(-2, 3, 1.5, shade(SLATE, -0.3)) + rampart(12, 20, 9)
  }

  // Capital: one mark and its footing. The flanking huts were an accessory --
  // they only muddied the base into the same grey lump a city makes.
  return rect(-15, 6, 30, 6, shade(STONE, -0.24)) + spire(0, 6, 34)
}

/** Dungeon entrance, §9.1 -- sited in the barren capital ring. */
function dungeonProp(): string {
  return (
    poly('-13,12 -9,-4 9,-4 13,12', '#2a2320') +
    poly('-7,12 -5,2 5,2 7,12', '#0b0d0c') +
    poly('-13,-4 0,-11 13,-4', '#4a3d35') +
    `<circle cx="-9" cy="4" r="1.8" fill="#d8b34a"/>` +
    `<circle cx="9" cy="4" r="1.8" fill="#d8b34a"/>`
  )
}

// ------------------------------------------------------------------- public

/**
 * Everything that stands on top of a tile. `y` values are negative-up from the
 * tile centre, matching the hex geometry origin.
 */
export function tileProps(tile: Tile, depleted: boolean): string {
  if (tile.dungeon) return dungeonProp()
  if (tile.settlement) return settlementProp(tile.settlement.tier, tile.propSeed)

  const base = BIOME_COLOR[tile.biome]
  const seed = tile.propSeed
  let out = ''

  const spot = (index: number) => {
    const hx = hash2(tile.col + index * 71, tile.row + index * 131, seed)
    const hy = hash2(tile.col + index * 191, tile.row + index * 37, seed)
    return { x: randInt(hx, -17, 17), y: randInt(hy, -4, 9) }
  }

  switch (tile.biome) {
    case 'forest': {
      const count = depleted ? 2 : randInt(hash2(tile.col, tile.row, seed), 2, 4)
      for (let i = 0; i < count; i++) {
        const p = spot(i)
        // §5.1 -- depleted tiles show remnant/sapling props, not bare ground.
        out += depleted
          ? (i % 2 === 0 ? stump(p.x, p.y, base) : sapling(p.x, p.y, base))
          : conifer(p.x, p.y, 0.72 + rand01(hash2(i, tile.col, seed)) * 0.45, base)
      }
      break
    }
    case 'mountain': {
      const p = spot(0)
      out += depleted
        ? rockShard(p.x, p.y, 0.7, base)
        : peak(p.x * 0.4, p.y, 0.62 + rand01(seed) * 0.5, base)
      if (!depleted && rand01(hash2(tile.col, tile.row, seed ^ 7)) > 0.55) {
        const q = spot(1)
        out += peak(q.x * 0.5, q.y + 2, 0.4, shade(base, -0.1))
      }
      break
    }
    case 'badlands': {
      const count = depleted ? 1 : 3
      for (let i = 0; i < count; i++) {
        const p = spot(i)
        out += rockShard(p.x, p.y, depleted ? 0.5 : 0.6 + rand01(hash2(i, tile.row, seed)) * 0.5, base)
      }
      break
    }
    case 'plains':
    case 'grassland': {
      const count = depleted ? 1 : 3
      for (let i = 0; i < count; i++) {
        const p = spot(i)
        out += tuft(p.x, p.y, base)
      }
      break
    }
  }

  return out
}

/** Rendered separately so a herd can sit on top of whatever terrain is there. */
export function herdProp(tile: Tile): string {
  return tile.herdUntil ? herd(0, 6) : ''
}

export { dungeonProp }
