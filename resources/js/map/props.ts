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

const ROOF = '#8f4f3c'
const WALL = '#d6cbb0'
const STONE = '#8b8f93'

function hut(x: number, y: number, scale = 1): string {
  const w = 9 * scale
  const h = 7 * scale
  return (
    rect(x - w / 2, y - h, w, h, WALL) +
    poly(`${x},${y - h - 6 * scale} ${x + w / 2 + 1.5},${y - h} ${x - w / 2 - 1.5},${y - h}`, ROOF) +
    rect(x - 1.2 * scale, y - 3.4 * scale, 2.4 * scale, 3.4 * scale, shade(WALL, -0.55))
  )
}

function tower(x: number, y: number, height: number): string {
  const w = 11
  return (
    rect(x - w / 2, y - height, w, height, STONE) +
    rect(x - w / 2, y - height, w / 2, height, shade(STONE, 0.14)) +
    // crenellations
    rect(x - w / 2 - 1.5, y - height - 4, w + 3, 4, shade(STONE, -0.2)) +
    poly(`${x + w / 2 + 1.5},${y - height - 4} ${x + w / 2 + 10},${y - height} ${x + w / 2 + 1.5},${y - height + 5}`, '#b8453f')
  )
}

function settlementProp(tier: SettlementTier, seed: number): string {
  if (tier === 'village') {
    return hut(-7, 6) + hut(6, 9, 0.85) + (rand01(seed) > 0.5 ? hut(1, 2, 0.7) : '')
  }
  if (tier === 'city') {
    return (
      hut(-13, 8, 0.9) + hut(12, 9, 0.9) + tower(0, 10, 16) + hut(-2, 13, 0.7)
    )
  }
  // Capital: the tallest silhouette on the map. It should occlude aggressively.
  return (
    tower(-11, 11, 20) + tower(11, 12, 17) + tower(0, 13, 30) + hut(-18, 13, 0.7) + hut(18, 13, 0.7)
  )
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
