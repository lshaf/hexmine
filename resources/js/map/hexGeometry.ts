/**
 * Hex geometry, §13.2.
 *
 * The tilt is BAKED INTO THE GEOMETRY. Hexes are drawn squashed (58x34, not
 * equilateral) with extruded side faces for thickness.
 *
 * Do NOT reach for CSS perspective/rotateX to get the 3/4 view: it magnifies
 * near tiles, shrinks far ones, and distorts the hex shape. This has already
 * been tried and rejected. Everything below is plain 2D maths.
 */

/** Flat-top hex, deliberately squashed vertically to fake the camera tilt. */
export const HEX_W = 58
export const HEX_H = 34

/** Extruded thickness of the tile slab. */
export const HEX_DEPTH = 11

/** Tiling, §13.2. Flat-top: columns overlap by a quarter width. */
export const COL_STEP = HEX_W * 0.75 // 43.5
export const ROW_STEP = HEX_H // 34
export const ODD_COL_OFFSET = HEX_H / 2 // 17

/** Screen position of a tile's center, before camera translation. */
export function tileToScreen(col: number, row: number): { x: number; y: number } {
  return {
    x: col * COL_STEP,
    y: row * ROW_STEP + (Math.abs(col % 2) === 1 ? ODD_COL_OFFSET : 0),
  }
}

/** Inverse of tileToScreen -- used for click hit-testing and centring. */
export function screenToTile(x: number, y: number): { col: number; row: number } {
  const col = Math.round(x / COL_STEP)
  const offset = Math.abs(col % 2) === 1 ? ODD_COL_OFFSET : 0
  const row = Math.round((y - offset) / ROW_STEP)
  return { col, row }
}

const HALF_W = HEX_W / 2
const QUARTER_W = HEX_W / 4
const HALF_H = HEX_H / 2

/** Six corners of a flat-top hex, centerd on the origin. */
export const HEX_CORNERS: ReadonlyArray<readonly [number, number]> = [
  [-HALF_W, 0],
  [-QUARTER_W, -HALF_H],
  [QUARTER_W, -HALF_H],
  [HALF_W, 0],
  [QUARTER_W, HALF_H],
  [-QUARTER_W, HALF_H],
]

/** Top face, drawn at the tile origin. */
export const HEX_TOP_PATH: string =
  HEX_CORNERS.map(([x, y], i) => `${i === 0 ? 'M' : 'L'}${x},${y}`).join(' ') + ' Z'

/**
 * The three lower edges pushed down by HEX_DEPTH, forming the visible slab
 * sides. Drawn behind the top face and shaded darker.
 */
export const HEX_SIDE_PATH: string = [
  `M${-HALF_W},0`,
  `L${-QUARTER_W},${HALF_H}`,
  `L${QUARTER_W},${HALF_H}`,
  `L${HALF_W},0`,
  `L${HALF_W},${HEX_DEPTH}`,
  `L${QUARTER_W},${HALF_H + HEX_DEPTH}`,
  `L${-QUARTER_W},${HALF_H + HEX_DEPTH}`,
  `L${-HALF_W},${HEX_DEPTH}`,
  'Z',
].join(' ')

/**
 * Painter's algorithm, §13.2: sort by screen Y before render so tall props
 * (mountains, capital towers) correctly occlude the tiles behind them. X is the
 * tiebreaker so the order is stable across frames and Vue does not re-key rows.
 */
export function paintersSort<T extends { col: number; row: number }>(tiles: T[]): T[] {
  return [...tiles].sort((a, b) => {
    const ay = tileToScreen(a.col, a.row).y
    const by = tileToScreen(b.col, b.row).y
    return ay === by ? a.col - b.col : ay - by
  })
}

/** Which tiles fall inside a viewport, plus a margin so props are not clipped. */
export function visibleTiles(
  centerCol: number,
  centerRow: number,
  width: number,
  height: number,
): Array<{ col: number; row: number }> {
  // +2 margin: tall props stand well above their own tile, so tiles just off
  // the top edge still paint into view.
  const colRadius = Math.ceil(width / 2 / COL_STEP) + 2
  const rowRadius = Math.ceil(height / 2 / ROW_STEP) + 3

  const out: Array<{ col: number; row: number }> = []
  for (let col = centerCol - colRadius; col <= centerCol + colRadius; col++) {
    for (let row = centerRow - rowRadius; row <= centerRow + rowRadius; row++) {
      out.push({ col, row })
    }
  }
  return out
}

/** Hex distance in offset coords, via a cube-coordinate round trip. */
export function hexDistance(aCol: number, aRow: number, bCol: number, bRow: number): number {
  const toCube = (col: number, row: number) => {
    const x = col
    const z = row - (col - (col & 1)) / 2
    return { x, y: -x - z, z }
  }
  const a = toCube(aCol, aRow)
  const b = toCube(bCol, bRow)
  return Math.max(Math.abs(a.x - b.x), Math.abs(a.y - b.y), Math.abs(a.z - b.z))
}

/**
 * Vertical squash factor: how much shorter our tilted hex is than a true
 * flat-top hex of the same width. Undoing it makes nearest-center hit-testing
 * exact, because the Voronoi cells of a regular hex grid ARE its hexes.
 */
const SQUASH = HEX_H / ((HEX_W * Math.sqrt(3)) / 2)

/**
 * Tile under a point, in map space.
 *
 * screenToTile() alone is not enough: flat-top columns overlap by a quarter
 * width, so a naive round picks the wrong hex in the slanted corner regions.
 * Checking the 3x3 neighborhood and taking the nearest center (in un-squashed
 * space) is exact.
 */
export function pickTile(x: number, y: number): { col: number; row: number } {
  const approx = screenToTile(x, y)
  let best = approx
  let bestDistance = Infinity

  for (let dc = -1; dc <= 1; dc++) {
    for (let dr = -1; dr <= 1; dr++) {
      const col = approx.col + dc
      const row = approx.row + dr
      const center = tileToScreen(col, row)
      const dx = center.x - x
      const dy = (center.y - y) / SQUASH
      const distance = dx * dx + dy * dy
      if (distance < bestDistance) {
        bestDistance = distance
        best = { col, row }
      }
    }
  }
  return best
}
