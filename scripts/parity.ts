/**
 * Client/server world-generation parity.
 *
 * The whole seed architecture rests on one unverified-by-default assumption:
 * that resources/js/game/worldgen.ts and app/Game/WorldGen.php compute the same
 * world. Nothing at runtime would notice if they drifted -- the client would
 * simply draw terrain the server does not believe in, and a player would tap a
 * forest hex and be told there is nothing there.
 *
 * `composer parity` pins the PHP side to tests/Fixtures/worldgen.txt. This pins
 * the TypeScript side to the same file, configured from the same parameters the
 * server hands the client at boot.
 *
 *   npm run parity
 *
 * Regenerate both fixtures with `php artisan game:worldgen-fixture` -- only when
 * a generation change is deliberate, and then read the diff.
 */
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import {
  configureWorld,
  generateTile,
  settlementAt,
  settlementMarksIn,
} from '../resources/js/game/worldgen.ts'
import type { WorldConfig } from '../resources/js/game/worldgen.ts'

const here = dirname(fileURLToPath(import.meta.url))
const fixtures = resolve(here, '../tests/Fixtures')

const config = JSON.parse(
  readFileSync(resolve(fixtures, 'world-config.json'), 'utf8'),
) as WorldConfig
configureWorld(config)

let failures = 0

function fail(what: string, expected: string, actual: string): void {
  failures++
  if (failures <= 20) console.error(`  ${what}\n    php: ${expected}\n    ts:  ${actual}`)
}

// ------------------------------------------------------------------- tiles

const lines = readFileSync(resolve(fixtures, 'worldgen.txt'), 'utf8').trim().split('\n')

for (const line of lines) {
  const [
    coord,
    biome,
    variant,
    ring,
    material,
    baseSeconds,
    baseYield,
    settlement,
    dungeon,
    propSeed,
  ] = line.split('|')
  const [col, row] = coord!.split(',').map(Number)

  const tile = generateTile(col!, row!, 0)
  const s = tile.settlement

  const actual = [
    tile.biome,
    tile.variant,
    tile.ring,
    tile.material ?? '-',
    String(tile.baseSeconds),
    String(tile.baseYield),
    s ? `${s.name}:${s.tier}:${s.lines.join(',')}` : '-',
    tile.dungeon ? tile.dungeon.key : '-',
    String(tile.propSeed),
  ].join('|')

  const expected = [
    biome,
    variant,
    ring,
    material,
    baseSeconds,
    baseYield,
    settlement,
    dungeon,
    propSeed,
  ].join('|')

  if (actual !== expected) fail(`tile ${coord}`, expected, actual)
}

console.log(`tiles: ${lines.length} checked`)

// ---------------------------------------------------- lattice enumeration

/*
 * settlementMarksIn() walks lattice cells instead of tiles, which is what makes
 * the atlas free. It claims to return exactly what settlementAt() would find by
 * brute force, so check that against the slow path over a few boxes -- one per
 * ring, since the tier a site is allowed to be depends on where it lands.
 */
const span = config.size

/**
 * Placed as fractions of the map, not fixed coordinates, so the boxes follow
 * MAP_RADIUS rather than being pinned to one map size.
 *
 * §5.1 -- coordinates are signed and centred, so fraction 0 is the far west
 * edge, 0.5 is the origin, and 1 is the far east. A box is still kept clear of
 * the very edge: both sides now refuse to place a settlement off the map, but a
 * box that is half void is testing half as much.
 */
const at = (f: number) => Math.round((f - 0.5) * span)

const BOXES = [
  { col: 0.02, row: 0.04, w: 0.18, h: 0.18 }, // outer rim, villages
  { col: 0.24, row: 0.28, w: 0.16, h: 0.16 }, // mid ring, cities
  { col: 0.48, row: 0.48, w: 0.24, h: 0.24 }, // dead centre, capitals
  { col: 0.78, row: 0.16, w: 0.2, h: 0.2 }, // the far rim
].map((b) => ({
  col: at(b.col),
  row: at(b.row),
  w: Math.round(b.w * span),
  h: Math.round(b.h * span),
}))

let boxed = 0

for (const box of BOXES) {
  const brute = new Set<string>()
  for (let col = box.col; col <= box.col + box.w; col++) {
    for (let row = box.row; row <= box.row + box.h; row++) {
      const s = settlementAt(col, row)
      if (s) brute.add(`${col},${row},${s.tier},${s.name}`)
    }
  }

  const fast = new Set(
    settlementMarksIn(box.col, box.col + box.w, box.row, box.row + box.h).map(
      (m) => `${m.col},${m.row},${m.tier},${m.name}`,
    ),
  )

  boxed += brute.size

  for (const key of brute) {
    if (!fast.has(key)) fail(`missing from settlementMarksIn near ${box.col},${box.row}`, key, '-')
  }
  for (const key of fast) {
    if (!brute.has(key)) fail(`invented by settlementMarksIn near ${box.col},${box.row}`, '-', key)
  }
}

console.log(`settlements: ${boxed} found by brute force, all matched by lattice walk`)

if (failures) {
  console.error(`\n${failures} mismatch(es). The client and server disagree about the world.`)
  process.exit(1)
}

console.log('parity ok')
