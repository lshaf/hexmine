/**
 * Wallet seals, §13 -- procedural, no artist required.
 *
 * A wallet address is fourteen characters of noise that nobody reads and
 * everybody has to recognize. So it gets a mark: seven flat-top hexes in a
 * honeycomb flower, each one lit or dark, derived from the address itself.
 *
 * Made of the game's own atomic unit rather than a circle or a gradient blob --
 * the map is hexes, the gauges are hexes, and your account is seven of them. The
 * center cell always burns, so every seal reads as one object rather than a
 * scatter, and the six around it carry the entropy: 3^6 arrangements across five
 * accents, which is far more than enough to tell yours from the one next to it.
 *
 * The generated markup contains only palette colors and numbers. No part of the
 * address reaches the DOM -- SvgIcon renders these with v-html.
 */
import { hash2, randInt } from '@/game/hash'
import { COPPER, EMBER, GOLD, VELLUM_DIM, VIOLET } from '@/theme/palette'

const ACCENTS = [COPPER, GOLD, VIOLET, EMBER, '#5f8058'] as const

/** Dark cells are the plate showing through, not a color of their own. */
const DARK = '#2c3730'

const VIEW = 40
const CENTER = VIEW / 2

/** Lattice step. Cells are drawn slightly smaller so the grout line reads. */
const STEP = 13
const CELL = STEP * 0.88
const ROW = STEP * 0.866

/** Center, then the six neighbors of a flat-top hex, clockwise from the top. */
const CELLS: Array<[number, number]> = [
  [0, 0],
  [0, -ROW],
  [STEP * 0.75, -ROW / 2],
  [STEP * 0.75, ROW / 2],
  [0, ROW],
  [-STEP * 0.75, ROW / 2],
  [-STEP * 0.75, -ROW / 2],
]

/** Fold a string into a 32-bit integer so the numeric hash can take it. */
function fold(text: string): number {
  let h = 0x811c9dc5
  for (let i = 0; i < text.length; i++) {
    h ^= text.charCodeAt(i)
    h = Math.imul(h, 0x01000193) >>> 0
  }
  return h
}

function hexPath(cx: number, cy: number, width: number): string {
  const w = width / 2
  const h = (width * 0.866) / 2
  return [
    `M${(cx - w).toFixed(2)},${cy.toFixed(2)}`,
    `L${(cx - w / 2).toFixed(2)},${(cy - h).toFixed(2)}`,
    `L${(cx + w / 2).toFixed(2)},${(cy - h).toFixed(2)}`,
    `L${(cx + w).toFixed(2)},${cy.toFixed(2)}`,
    `L${(cx + w / 2).toFixed(2)},${(cy + h).toFixed(2)}`,
    `L${(cx - w / 2).toFixed(2)},${(cy + h).toFixed(2)}`,
    'Z',
  ].join('')
}

/**
 * The seal for one address. Deterministic: the same wallet always draws the
 * same mark, on any device, with no state anywhere.
 */
export function walletSeal(wallet: string, size = 34): string {
  const seed = fold(wallet)
  const accent = ACCENTS[randInt(hash2(seed, 0, 0x53a1), 0, ACCENTS.length - 1)]!

  const cells = CELLS.map(([dx, dy], i) => {
    // The center always burns; the ring carries the entropy.
    const state = i === 0 ? 1 : randInt(hash2(seed, i, 0x53a2), 0, 2)
    const fill = state === 0 ? DARK : state === 1 ? accent : VELLUM_DIM
    return `<path d="${hexPath(CENTER + dx, CENTER + dy, CELL)}" fill="${fill}"/>`
  }).join('')

  return `<svg viewBox="0 0 ${VIEW} ${VIEW}" width="${size}" height="${size}"
    role="img" aria-label="Wallet seal">${cells}</svg>`
}
