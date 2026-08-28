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
import { desaturate, shade, variantColor, waterColor } from '@/theme/palette'
import { HEX_H, HEX_SIDE_PATH, HEX_TOP_PATH, HEX_W, ROW_STEP } from './hexGeometry'
import { VARIANT_PROPS } from '@/game/variants'
import type { Biome, SettlementTier, Tile, VariantKey, WaterKind } from '@/game/types'

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


/**
 * §5.3 -- the grade treatments. A variant is not just a tint: the props change
 * shape or density with it, so contested ground reads as different ground while
 * the map is moving, at roughly 40px inside a 58x34 hex.
 */

function broadleaf(x: number, y: number, scale: number, color: string): string {
  const trunk = shade(color, -0.55)
  const r = 7 * scale
  const h = 11 * scale
  return (
    rect(x - 1.2 * scale, y - h + r, 2.4 * scale, h - r + 1, trunk) +
    `<ellipse cx="${x}" cy="${y - h}" rx="${r}" ry="${r * 0.82}" fill="${color}"/>` +
    `<ellipse cx="${x - r * 0.4}" cy="${y - h}" rx="${r * 0.5}" ry="${r * 0.6}" fill="${shade(color, -0.14)}"/>`
  )
}

/** Old growth: fewer, taller, and buttressed at the foot. */
function giant(x: number, y: number, scale: number, color: string): string {
  const trunk = shade(color, -0.58)
  const h = 26 * scale
  const w = 8 * scale
  return (
    poly(`${x - 3.5 * scale},${y} ${x + 3.5 * scale},${y} ${x + 1.6 * scale},${y - 8 * scale} ${x - 1.6 * scale},${y - 8 * scale}`, trunk) +
    poly(`${x},${y - h} ${x + w},${y - 7 * scale} ${x - w},${y - 7 * scale}`, color) +
    poly(`${x},${y - h} ${x},${y - 7 * scale} ${x - w},${y - 7 * scale}`, shade(color, -0.18)) +
    poly(`${x},${y - h * 0.72} ${x + w * 1.15},${y - 2 * scale} ${x - w * 1.15},${y - 2 * scale}`, shade(color, -0.08))
  )
}

/** Ironwood: the same giant, banded, so the metal in it shows at a glance. */
function ironwoodTree(x: number, y: number, scale: number, color: string): string {
  const band = '#9aa6a0'
  return (
    giant(x, y, scale, color) +
    rect(x - 3.2 * scale, y - 6 * scale, 6.4 * scale, 1.4, band) +
    rect(x - 2.6 * scale, y - 3 * scale, 5.2 * scale, 1.2, shade(band, -0.2))
  )
}

/** A peak with the ore band showing in the face. */
function bandedPeak(x: number, y: number, scale: number, color: string, seam: string): string {
  const h = 22 * scale
  const w = 15 * scale
  return (
    poly(`${x},${y - h} ${x + w},${y} ${x - w},${y}`, shade(color, -0.28)) +
    poly(`${x},${y - h} ${x},${y} ${x - w},${y}`, shade(color, 0.2)) +
    poly(`${x - w * 0.55},${y - h * 0.22} ${x + w * 0.2},${y - h * 0.52} ${x + w * 0.3},${y - h * 0.4} ${x - w * 0.5},${y - h * 0.1}`, seam)
  )
}

/** Crater field: a rim ring with the strike still sitting in it. */
function crater(x: number, y: number, scale: number, color: string): string {
  const rim = shade(color, -0.3)
  const floor = shade(color, -0.52)
  return (
    `<ellipse cx="${x}" cy="${y}" rx="${13 * scale}" ry="${5.5 * scale}" fill="${rim}"/>` +
    `<ellipse cx="${x}" cy="${y - 0.6 * scale}" rx="${8.5 * scale}" ry="${3.4 * scale}" fill="${floor}"/>` +
    poly(`${x + 1.5 * scale},${y - 7 * scale} ${x + 4.5 * scale},${y - 1 * scale} ${x - 1.5 * scale},${y - 1 * scale}`, shade(color, 0.14))
  )
}

/** Basalt: hexagonal columns, cracked square along their own joints. */
function column(x: number, y: number, scale: number, color: string): string {
  const w = 4 * scale
  const h = 13 * scale
  return (
    rect(x - w, y - h, w * 2, h, shade(color, -0.24)) +
    rect(x - w, y - h, w * 0.8, h, shade(color, -0.06)) +
    poly(`${x - w},${y - h} ${x},${y - h - 2.5 * scale} ${x + w},${y - h} ${x},${y - h + 2.2 * scale}`, shade(color, 0.16))
  )
}

/** Granite: one flat slab the weather never got under. */
/*
 * §5.2 -- the five dead grounds.
 *
 * Four drawings across the five, and each is the LIVING silhouette with the
 * life taken out of it: a snag is a conifer stripped to its trunk, scree is a
 * peak collapsed, stubble is a tuft cut off at the ankle. Plains and grassland
 * share one, because they share one alive. That is what makes a waste read as
 * forest-that-died rather than as a sixth kind of country -- which matters,
 * because the fill underneath is the biome's own and the props are the whole
 * of the tell.
 *
 * They are drawn in the biome's colour, drained: shade(base, -0.3) desaturated
 * toward the fill it stands on, so at a glance the hex is the right country and
 * on a second look nothing on it is alive. There is no grey anywhere here --
 * grey said "different place", and this has to say "same place, finished".
 */

/** Forest: a conifer with the canopy gone. */
function snag(x: number, y: number, scale: number, color: string): string {
  const wood = shade(color, -0.5)
  const h = 13 * scale

  return (
    rect(x - 1.1 * scale, y - h, 2.2 * scale, h, wood) +
    // Two broken limbs, one each side, so the trunk is not a fence post.
    `<path d="M${x},${(y - h * 0.72).toFixed(1)} l${(4.5 * scale).toFixed(1)},${(-2.6 * scale).toFixed(1)}" ` +
    `stroke="${wood}" stroke-width="${(1.5 * scale).toFixed(2)}" stroke-linecap="round"/>` +
    `<path d="M${x},${(y - h * 0.46).toFixed(1)} l${(-3.8 * scale).toFixed(1)},${(-2.1 * scale).toFixed(1)}" ` +
    `stroke="${wood}" stroke-width="${(1.3 * scale).toFixed(2)}" stroke-linecap="round"/>`
  )
}

/** Mountain: the peak come down. Same footprint, a third of the height. */
function scree(x: number, y: number, scale: number, color: string): string {
  const h = 7 * scale
  const w = 13 * scale
  const lit = shade(color, -0.12)
  const dark = shade(color, -0.42)

  return (
    poly(`${x},${y - h} ${x + w},${y} ${x - w},${y}`, dark) +
    poly(`${x},${y - h} ${x},${y} ${x - w},${y}`, lit) +
    // A block or two off the face, sitting where it fell.
    rect(x + w * 0.45, y - 2.4 * scale, 3.2 * scale, 2.4 * scale, dark) +
    rect(x - w * 0.7, y - 1.8 * scale, 2.4 * scale, 1.8 * scale, dark)
  )
}

/**
 * Plains and grassland: a tuft cut off at the ankle.
 *
 * ONE treatment for the two of them, because the living map already draws them
 * alike -- both grades run the `tufts` props. A distinct dead plains would be
 * inventing a difference the country itself does not have; what tells them
 * apart is the name on the card (Dust Flat against Stubble), which is where the
 * difference actually lives.
 */
function stubble(x: number, y: number, color: string): string {
  const c = shade(color, -0.38)

  return (
    poly(`${x},${y - 2.4} ${x + 1.1},${y} ${x - 1.1},${y}`, c) +
    poly(`${x - 3},${y - 1.6} ${x - 2.1},${y} ${x - 3.9},${y}`, c) +
    poly(`${x + 3.2},${y - 2} ${x + 4.1},${y} ${x + 2.3},${y}`, c)
  )
}

/**
 * §5.2 -- dead ground. A crack in the pan, lying flat.
 *
 * Every other treatment in this file stands something UP: trees, columns,
 * shards, stalks. This one deliberately stands nothing, because the absence is
 * half the message -- a hex with nothing on its surface reads as nothing to
 * work before the grey has finished saying it. Flat marks only, and squashed
 * the way the tile itself is (§13.2), so they lie ON the ground rather than
 * across it.
 */
function crack(x: number, y: number, scale: number, color: string): string {
  const w = 14 * scale
  const line = shade(color, -0.38)

  return (
    `<path d="M${(x - w / 2).toFixed(1)},${y.toFixed(1)} ` +
    `l${(w * 0.28).toFixed(1)},${(-1.6 * scale).toFixed(1)} ` +
    `l${(w * 0.24).toFixed(1)},${(2.1 * scale).toFixed(1)} ` +
    `l${(w * 0.48).toFixed(1)},${(-1.1 * scale).toFixed(1)}" ` +
    `fill="none" stroke="${line}" stroke-width="${(1.1 * scale).toFixed(2)}" ` +
    `stroke-linecap="round" stroke-linejoin="round"/>`
  )
}

/** A flat stone sitting in the pan -- the only thing dead ground carries. */
function pebble(x: number, y: number, scale: number, color: string): string {
  const w = 5 * scale
  const h = 2.4 * scale

  return (
    `<path d="M${(x - w).toFixed(1)},${y.toFixed(1)} ` +
    `L${(x - w * 0.45).toFixed(1)},${(y - h).toFixed(1)} ` +
    `L${(x + w * 0.5).toFixed(1)},${(y - h * 0.85).toFixed(1)} ` +
    `L${(x + w).toFixed(1)},${(y + h * 0.15).toFixed(1)} ` +
    `L${(x + w * 0.3).toFixed(1)},${(y + h).toFixed(1)} ` +
    `L${(x - w * 0.5).toFixed(1)},${(y + h * 0.9).toFixed(1)}Z" ` +
    `fill="${shade(color, -0.2)}"/>`
  )
}

function shelf(x: number, y: number, scale: number, color: string): string {
  const w = 15 * scale
  const h = 5 * scale
  return (
    poly(`${x - w},${y} ${x - w * 0.78},${y - h} ${x + w * 0.86},${y - h} ${x + w},${y}`, shade(color, -0.2)) +
    poly(`${x - w * 0.78},${y - h} ${x + w * 0.86},${y - h} ${x + w * 0.7},${y - h - 2.4 * scale} ${x - w * 0.6},${y - h - 2.4 * scale}`, shade(color, 0.18))
  )
}

/** Obsidian: glass, so it gets a highlight nothing else on the map has. */
function glassShard(x: number, y: number, scale: number, color: string): string {
  const h = 13 * scale
  const w = 5 * scale
  return (
    poly(`${x},${y - h} ${x + w},${y} ${x - w},${y}`, shade(color, -0.45)) +
    poly(`${x},${y - h} ${x - w * 0.35},${y} ${x - w},${y}`, shade(color, 0.3)) +
    poly(`${x + w * 0.1},${y - h * 0.8} ${x + w * 0.42},${y - h * 0.2} ${x + w * 0.16},${y - h * 0.2}`, '#c9c2d6')
  )
}

/** Ribs in the grass. The herds keep off this stretch for a reason. */
function bones(x: number, y: number, color: string): string {
  const bone = '#d8d2c0'
  return (
    tuft(x - 6, y, color) +
    `<path d="M ${x - 4} ${y} q 4 -7 9 -1" stroke="${bone}" stroke-width="1.4" fill="none"/>` +
    `<path d="M ${x - 1} ${y} q 4 -6 8 -1" stroke="${shade(bone, -0.18)}" stroke-width="1.2" fill="none"/>`
  )
}

/** Tusks, for the ground the beastfang herds hold. */
function fangs(x: number, y: number, color: string): string {
  const ivory = '#e2dac4'
  return (
    tuft(x + 5, y, color) +
    `<path d="M ${x - 5} ${y} q 1 -8 6 -9" stroke="${ivory}" stroke-width="2" fill="none" stroke-linecap="round"/>` +
    `<path d="M ${x} ${y} q 1 -6 5 -7" stroke="${shade(ivory, -0.2)}" stroke-width="1.6" fill="none" stroke-linecap="round"/>`
  )
}

/** Flax in flower: a week a year, and it is how you find the meadow. */
function flowering(x: number, y: number, color: string): string {
  return (
    tuft(x, y, color) +
    `<circle cx="${x}" cy="${y - 7}" r="1.5" fill="#7d90c4"/>` +
    `<circle cx="${x - 3.6}" cy="${y - 5}" r="1.2" fill="#8fa0cf"/>`
  )
}

/** Hemp: over head height, which is why sight stops in it. */
function tallStalk(x: number, y: number, color: string): string {
  const c = shade(color, -0.26)
  return (
    rect(x, y - 13, 1.3, 13, c) +
    rect(x - 4, y - 10, 1.1, 10, shade(color, -0.14)) +
    rect(x + 4, y - 11, 1.1, 11, shade(color, -0.2)) +
    poly(`${x + 0.6},${y - 16} ${x + 3},${y - 11} ${x - 2},${y - 11}`, c)
  )
}

/** Silkweave: a strand between the stems, and nobody asks what spun it. */
function silk(x: number, y: number, color: string): string {
  return (
    tuft(x, y, color) +
    `<path d="M ${x - 7} ${y - 8} q 7 4 14 -2" stroke="#e6e2d2" stroke-width="0.9" fill="none"/>` +
    `<path d="M ${x - 4} ${y - 10} q 4 6 8 1" stroke="#cfc9bb" stroke-width="0.7" fill="none"/>`
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

/*
 * §5.7 -- rich ground, and the animals that found it first.
 *
 * A pocket is marked by the BIOME'S OWN CRITTER (§4: "the herbs say what grows
 * on a kind of ground; these say what lives on it"), gathered on the hex.
 * Animals know where the good ground is, which is the whole of the tell: you
 * learn to read a hex by what is standing on it rather than by decoding a
 * symbol somebody drew on it.
 *
 * It reads against the herd, which is the only other living marker: a herd is a
 * BIG pale-brown body side-on with a second beast behind it, and every one of
 * these is small, single and shaped like nothing else on the map. On plains
 * both can stand on one hex, which is exactly right -- a grazing herd and a
 * hare are two different pieces of news.
 *
 * Solid fills and two tones each (§13.2), because a critter drawn in one colour
 * on its own biome would be the thing §5.2 says about dead ground: invisible on
 * the country it belongs to.
 */
function glimmermoth(x: number, y: number): string {
  const wing = '#e4d7ac'
  const body = '#4a4335'

  return (
    poly(`${x},${y - 4} ${x - 8},${y - 8} ${x - 6},${y}`, wing) +
    poly(`${x},${y - 4} ${x + 8},${y - 8} ${x + 6},${y}`, shade(wing, -0.18)) +
    rect(x - 1, y - 7, 2, 7, body) +
    `<path d="M${x - 1} ${y - 7} L${x - 4} ${y - 11} M${x + 1} ${y - 7} L${x + 4} ${y - 11}" stroke="${body}" stroke-width="0.9" fill="none"/>`
  )
}

function rockmite(x: number, y: number): string {
  const shell = '#2f3a42'
  const sheen = '#8fa6b4'

  // Banded across the shell rather than a lit wedge: a bright shape on a dark
  // body is the PACK's whole tell (§9.5.1 -- two eyes looking at you), and a
  // beetle borrowing it would say the wrong thing at a glance.
  return (
    `<ellipse cx="${x}" cy="${y - 4}" rx="6" ry="4.2" fill="${shell}"/>` +
    `<path d="M${x - 2} ${y - 7.6} v 7.2 M${x + 1.6} ${y - 7.4} v 6.8" stroke="${sheen}" stroke-width="1.1" fill="none"/>` +
    `<path d="M${x - 5} ${y - 7} L${x - 9} ${y - 10} M${x - 5} ${y - 5} L${x - 9} ${y - 3}" stroke="${shell}" stroke-width="1" fill="none"/>`
  )
}

function dustleveret(x: number, y: number): string {
  const back = '#7a5c3c'
  const belly = '#d8c7a6'

  return (
    `<ellipse cx="${x}" cy="${y - 4}" rx="6" ry="3.6" fill="${back}"/>` +
    `<ellipse cx="${x - 4}" cy="${y - 2.4}" rx="3.4" ry="1.8" fill="${belly}"/>` +
    `<ellipse cx="${x + 5}" cy="${y - 7}" rx="2.6" ry="2.2" fill="${back}"/>` +
    // The ears, which are the whole of what tells a hare from a herd.
    `<path d="M${x + 5} ${y - 9} L${x + 3.5} ${y - 15} M${x + 6.5} ${y - 9} L${x + 7} ${y - 15}" stroke="${back}" stroke-width="1.6" stroke-linecap="round" fill="none"/>`
  )
}

function ashnewt(x: number, y: number): string {
  const skin = '#cfc7b4'
  const spots = '#5d4a42'

  return (
    `<ellipse cx="${x}" cy="${y - 4}" rx="6" ry="3" fill="${skin}"/>` +
    `<ellipse cx="${x - 6}" cy="${y - 4}" rx="2.4" ry="2.2" fill="${skin}"/>` +
    `<path d="M${x + 5} ${y - 4} q 5 -1 6 -5" stroke="${skin}" stroke-width="1.8" fill="none" stroke-linecap="round"/>` +
    `<path d="M${x - 2} ${y - 2} l -2 3 M${x + 2} ${y - 2} l 2 3" stroke="${skin}" stroke-width="1.4" fill="none" stroke-linecap="round"/>` +
    rect(x - 1.5, y - 6, 1.6, 1.6, spots) +
    rect(x + 2, y - 5.6, 1.6, 1.6, spots)
  )
}

function fenlark(x: number, y: number): string {
  const back = '#4c4436'
  const breast = '#ddd0ad'

  return (
    `<ellipse cx="${x}" cy="${y - 5.5}" rx="5.8" ry="4.2" fill="${back}"/>` +
    `<ellipse cx="${x - 2.6}" cy="${y - 4.4}" rx="3.4" ry="2.8" fill="${breast}"/>` +
    `<ellipse cx="${x - 5.6}" cy="${y - 9}" rx="2.9" ry="2.6" fill="${back}"/>` +
    poly(`${x - 7.8},${y - 9} ${x - 11.5},${y - 8.2} ${x - 7.8},${y - 7.4}`, '#c98f3f') +
    poly(`${x + 4.6},${y - 7} ${x + 11},${y - 9.5} ${x + 4.6},${y - 4}`, shade(back, 0.18)) +
    `<path d="M${x - 1} ${y - 1.8} v 2.4 M${x + 2.2} ${y - 1.8} v 2.4" stroke="${back}" stroke-width="1.1" fill="none"/>`
  )
}

const POCKET_CRITTER: Record<Biome, (x: number, y: number) => string> = {
  forest: glimmermoth,
  mountain: rockmite,
  plains: dustleveret,
  badlands: ashnewt,
  grassland: fenlark,
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

/**
 * §5.6 -- the same masonry, twice. Outside sight a settlement keeps its
 * silhouette and loses its light: same shapes, same geometry, a solid
 * desaturated palette instead (§13.2 -- never opacity). Scouting the hex is
 * what lights it up, so nothing on the map changes SIZE when the fog lifts.
 */
type Masonry = {
  roof: string
  wall: string
  stone: string
  slate: string
  pennant: string
}

const LIT: Masonry = { roof: ROOF, wall: WALL, stone: STONE, slate: SLATE, pennant: PENNANT }

const fogged = (hex: string): string => shade(desaturate(hex, 0.5), -0.45)

const FOG: Masonry = {
  roof: fogged(ROOF),
  wall: fogged(WALL),
  stone: fogged(STONE),
  slate: fogged(SLATE),
  pennant: fogged(PENNANT),
}

function hut(x: number, y: number, scale = 1, pal: Masonry = LIT): string {
  const w = 9 * scale
  const h = 7 * scale
  return (
    rect(x - w / 2, y - h, w, h, pal.wall) +
    poly(
      `${x},${y - h - 6 * scale} ${x + w / 2 + 1.5},${y - h} ${x - w / 2 - 1.5},${y - h}`,
      pal.roof,
    ) +
    rect(x - 1.2 * scale, y - 3.4 * scale, 2.4 * scale, 3.4 * scale, shade(pal.wall, -0.55))
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
function rampart(y: number, halfWidth: number, height: number, pal: Masonry = LIT): string {
  const top = y - height
  let out = rect(-halfWidth, top, halfWidth * 2, height, pal.stone)
  // Lit face along the bottom, so the wall does not read as a flat slab.
  out += rect(-halfWidth, y - height * 0.4, halfWidth * 2, height * 0.4, shade(pal.stone, -0.2))

  // Few and chunky. Seven fine teeth vanished at map scale -- five fat ones
  // survive, and the tooth rhythm is the whole point of the shape.
  const teeth = 5
  const step = (halfWidth * 2) / teeth
  for (let i = 0; i < teeth; i++) {
    out += rect(-halfWidth + i * step + step * 0.1, top - 4.4, step * 0.6, 4.6, pal.stone)
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
function spire(x: number, y: number, height: number, pal: Masonry = LIT): string {
  const w = 9
  const topW = 6
  const top = y - height
  return (
    // Tapered shaft, drawn as a trapezoid so it narrows with height.
    poly(
      `${x - w / 2},${y} ${x + w / 2},${y} ${x + topW / 2},${top} ${x - topW / 2},${top}`,
      pal.stone,
    ) +
    poly(
      `${x - w / 2},${y} ${x},${y} ${x},${top} ${x - topW / 2},${top}`,
      shade(pal.stone, 0.14),
    ) +
    // Spike roof.
    poly(`${x},${top - 11} ${x + topW / 2 + 1.6},${top} ${x - topW / 2 - 1.6},${top}`, pal.slate) +
    // Mast and pennant. The only cloth on the map, and the only gold: §10 makes
    // capitals the thing guilds spend their gold to hold.
    rect(x - 0.6, top - 19, 1.2, 9, shade(pal.stone, -0.3)) +
    poly(`${x + 0.6},${top - 19} ${x + 9},${top - 16.4} ${x + 0.6},${top - 13.8}`, pal.pennant)
  )
}

function settlementProp(tier: SettlementTier, seed: number, pal: Masonry = LIT): string {
  if (tier === 'village') {
    // Scatter: spread wide enough that they read as separate marks. Any closer
    // and three huts become one blob, which is the city's read, not this one.
    return (
      hut(-14, 9, 0.78, pal) +
      hut(12, 6, 0.72, pal) +
      hut(-1, 13, 0.68, pal) +
      (rand01(seed) > 0.5 ? hut(6, 15, 0.55, pal) : '')
    )
  }

  if (tier === 'city') {
    // Enclosure: one steep roof rising behind the wall, in a slate dark enough
    // to separate from the masonry, then the wall cropping its feet.
    return gable(-2, 3, 1.5, shade(pal.slate, -0.3)) + rampart(12, 20, 9, pal)
  }

  // Capital: one mark and its footing. The flanking huts were an accessory --
  // they only muddied the base into the same gray lump a city makes.
  return rect(-15, 6, 30, 6, shade(pal.stone, -0.24)) + spire(0, 6, 34, pal)
}

/**
 * §5.6 -- the settlement's own silhouette with the light taken out of it (§13.2
 * tells the three tiers apart by shape category, so the fog keeps the
 * distinction for free).
 *
 * What the tile does NOT carry, in or out of the fog, is which of the five
 * lines the place runs: a hex is a shape on a map, and a row of billets on it
 * was a legend to decode at a glance nobody asked for. The lines are on the
 * card, where a tap is the question being asked (§6).
 */
export function settlementGlyph(tier: SettlementTier, seed: number): string {
  return settlementProp(tier, seed, FOG)
}

/**
 * The mouth is the one prop the fog cannot simply darken: it is already the
 * blackest thing on the map, and a shaded tile would swallow it whole. So the
 * unscouted version goes the other way -- the frame comes UP to fogged masonry
 * and the opening stays black, which is the same silhouette read as a hole in a
 * wall rather than a smudge on dark ground.
 */
const DUNGEON_LIT = { mouth: '#2a2320', dark: '#0b0d0c', lintel: '#4a3d35', eye: '#d8b34a' }
const DUNGEON_FOG = {
  mouth: shade(FOG.stone, -0.2),
  dark: '#0b0d0c',
  lintel: FOG.stone,
  eye: fogged(PENNANT),
}

/** Dungeon entrance, §9.1 -- sited in the barren capital ring. */
function dungeonProp(fog = false): string {
  const pal = fog ? DUNGEON_FOG : DUNGEON_LIT
  return (
    poly('-13,12 -9,-4 9,-4 13,12', pal.mouth) +
    poly('-7,12 -5,2 5,2 7,12', pal.dark) +
    poly('-13,-4 0,-11 13,-4', pal.lintel) +
    `<circle cx="-9" cy="4" r="1.8" fill="${pal.eye}"/>` +
    `<circle cx="9" cy="4" r="1.8" fill="${pal.eye}"/>`
  )
}

/** The same mouth, unscouted -- §9.1's five are on the map before you walk. */
export function dungeonGlyph(): string {
  return dungeonProp(true)
}


// ----------------------------------------------------------------- water §5.3

/**
 * Ripples: the one mark every stretch of water carries, whatever it crosses.
 *
 * Drawn as short strokes rather than filled shapes because water is the only
 * surface on the map that is flat -- everything else here stands up off its
 * hex and casts a side. A ripple that stood up would read as a ridge.
 */
function ripples(seed: number, count: number, color: string, width = 1.2): string {
  let out = ''
  for (let i = 0; i < count; i++) {
    const x = randInt(hash2(i * 13, seed, seed ^ 0x9a), -21, 6)
    const y = randInt(hash2(i * 29, seed, seed ^ 0x9b), -9, 9)
    const w = randInt(hash2(i * 47, seed, seed ^ 0x9c), 9, 16)
    out +=
      `<path d="M${x},${y} q${w / 2},-2.2 ${w},0" fill="none" ` +
      `stroke="${color}" stroke-width="${width}" stroke-linecap="round"/>`
  }
  return out
}

/** A boulder or a bar breaking the surface. */
function midstream(x: number, y: number, scale: number, color: string): string {
  const w = 5 * scale
  const h = 3.4 * scale
  return (
    poly(`${x - w},${y} ${x - w * 0.4},${y - h} ${x + w * 0.5},${y - h * 0.8} ${x + w},${y}`, color) +
    poly(`${x - w},${y} ${x - w * 0.4},${y - h} ${x},${y}`, shade(color, 0.16))
  )
}

/** Reeds standing out of the shallows, in whatever the bank is made of. */
function reeds(x: number, y: number, color: string, blades = 3): string {
  let out = ''
  for (let i = 0; i < blades; i++) {
    // Near vertical with the bend at the top: a blade, not a tick. Leaning the
    // whole length turned a stand of reeds into a row of little crosses.
    const lean = i % 2 === 0 ? 1.5 : -1.2
    out +=
      `<path d="M${x + i * 2.6},${y} q${lean * 0.3},-6 ${lean},-10" fill="none" ` +
      `stroke="${color}" stroke-width="1.2" stroke-linecap="round"/>`
  }
  return out
}

/** A flat pad on the surface -- forest water, and nothing else. */
function lilyPad(x: number, y: number, color: string): string {
  return (
    `<ellipse cx="${x}" cy="${y}" rx="3.4" ry="1.9" fill="${color}"/>` +
    `<path d="M${x},${y} L${x + 3.2},${y - 0.7}" stroke="${shade(color, -0.35)}" stroke-width="0.9"/>`
  )
}

/**
 * §5.3 / §13.1 -- what a stretch of water looks like on this kind of ground.
 *
 * Four waterways cross the whole map, so a single stream treatment would be
 * the one thing on the board that ignored the biome it ran through and cut a
 * uniform blue line across five kinds of country. Each biome gets its own
 * surface instead: the water is the same rule everywhere and a different
 * *place* everywhere, which is the same argument §5.3 makes for the four
 * grades of ground.
 *
 * The pair per biome is deliberate -- a waterway is moving and a lake is
 * still, and the marks say which without a label.
 */
function waterProp(tile: Tile): string {
  const biome = tile.biome
  const seed = tile.propSeed
  const bank = shade(variantColor(biome as VariantKey), -0.12)
  const foam = '#dbe7ec'
  const river = tile.water === 'river'

  switch (biome) {
    // A brook under cover: still, dark, and half-roofed. The log across it is
    // the tell that this is forest water rather than open water.
    case 'forest': {
      const green = shade(bank, 0.08)
      let out = ripples(seed, river ? 3 : 2, shade(foam, -0.28), 1)
      out += reeds(-21, 6, green, 3) + reeds(14, 7, green, 3)
      if (river) {
        out +=
          `<path d="M-22,3 L21,-5" stroke="${shade(green, -0.5)}" ` +
          `stroke-width="3" stroke-linecap="round"/>`
      } else {
        out +=
          lilyPad(-11, 4, green) +
          lilyPad(7, 7, green) +
          lilyPad(-2, -4, shade(green, 0.1)) +
          lilyPad(13, -1, green)
      }
      return out
    }

    // Rapids over scree, or a tarn that never warms up. Both are rock and cold
    // light -- the most foam of the five, and the only white on the water.
    case 'mountain': {
      const rock = shade(bank, -0.24)
      let out = midstream(-16, 5, 1.1, rock) + midstream(9, 8, 0.95, rock)
      out += midstream(-2, -3, 0.85, shade(rock, 0.12)) + midstream(18, 1, 0.8, rock)
      // Foam last and heaviest: it is the only white on the water, and what
      // separates a run of rapids from a hex with rocks in it.
      out += ripples(seed, river ? 5 : 2, foam, river ? 2 : 1.2)
      return out
    }

    // A slow meander with a sandbar in it, or a stock pool. Widest and
    // laziest of the five, which is what plains water is.
    case 'plains': {
      const sand = shade(bank, 0.22)
      let out = river
        ? `<ellipse cx="3" cy="6" rx="14" ry="3.4" fill="${sand}"/>` + midstream(-17, 3, 0.8, sand)
        : reeds(-21, 7, shade(bank, -0.05), 3) +
          reeds(16, 6, shade(bank, -0.05), 3) +
          `<ellipse cx="-2" cy="8" rx="8" ry="2.2" fill="${sand}"/>`
      out += ripples(seed, 3, shade(foam, -0.2), 1.2)
      return out
    }

    // A wash running over silt between cracked shelves, or an alkali pan with
    // a pale crust round it. The only water here that looks like it might not
    // last the season.
    case 'badlands': {
      const crust = shade(desaturate(bank, 0.45), 0.34)
      let out =
        `<path d="M-24,-4 q9,4 17,0 q8,-4 15,2" fill="none" stroke="${crust}" ` +
        'stroke-width="2.2" stroke-linecap="round"/>'
      out += river
        ? midstream(-7, 7, 0.9, crust) + midstream(12, 4, 0.7, crust)
        : `<path d="M-21,9 q19,5 40,-2" fill="none" stroke="${crust}" ` +
          'stroke-width="2.6" stroke-linecap="round"/>'
      out += ripples(seed, 2, shade(foam, -0.34), 1.1)
      return out
    }

    // Rushes to the waterline. Grassland water is the hardest of the five to
    // see, which is exactly right: the crop comes right up to it.
    default: {
      const rush = shade(bank, -0.16)
      let out = ripples(seed, river ? 3 : 2, shade(foam, -0.26), 1.2)
      out += reeds(-22, 7, rush, 4) + reeds(-6, 9, rush, 3) + reeds(15, 6, rush, 4)
      out += reeds(4, -3, shade(rush, 0.12), river ? 2 : 3)
      return out
    }
  }
}

// ------------------------------------------------------------------- public

/**
 * Everything that stands on top of a tile. `y` values are negative-up from the
 * tile center, matching the hex geometry origin.
 */
/**
 * §5.2 -- what stands on dead ground, per biome.
 *
 * Five, because a dead forest and a dead mountain are not the same picture and
 * flattening them into one grey treatment made every waste on the map the same
 * place. Each is its biome's own silhouette with nothing alive in it, drawn in
 * the biome's own colour drained -- so the hex still reads as the country it is
 * in, which is what keeps it hidden until you are standing near it.
 *
 * Cracks are the common thread: every one of the five shows the pan through,
 * because that is the one thing all dead ground has in common.
 */
function deadProps(
  tile: Tile,
  base: string,
  seed: number,
  spot: (index: number) => { x: number; y: number },
): string {
  let out = ''

  // The ground itself, under whatever is left standing on it.
  for (let i = 0; i < 2; i++) {
    const p = spot(i)
    out += crack(p.x * 0.8, p.y + 4, 0.7 + rand01(hash2(i, tile.col, seed)) * 0.4, base)
  }

  switch (tile.biome) {
    case 'forest': {
      const count = 1 + randInt(hash2(tile.col, tile.row, seed ^ 0x91), 0, 1)
      for (let i = 0; i < count; i++) {
        const p = spot(i + 2)
        out += snag(p.x, p.y + 2, 0.8 + rand01(hash2(i, tile.row, seed)) * 0.35, base)
      }
      break
    }
    case 'mountain': {
      const p = spot(2)
      out += scree(p.x * 0.6, p.y + 3, 0.85 + rand01(hash2(tile.col, tile.row, seed)) * 0.3, base)
      break
    }
    case 'badlands': {
      // The one biome whose living props are already bare rock, so what marks
      // it dead is that the shards are down: a flat stone in the pan instead.
      const p = spot(2)
      out += pebble(p.x * 0.7, p.y + 2, 0.8, base)
      if (rand01(hash2(tile.row, tile.col, seed ^ 0x77)) > 0.55) {
        const q = spot(3)
        out += pebble(q.x * 0.5, q.y - 1, 0.6, base)
      }
      break
    }
    default: {
      // Plains and grassland, drawn alike because they are drawn alike alive.
      const count = 2 + randInt(hash2(tile.col, tile.row, seed ^ 0xa3), 0, 1)
      for (let i = 0; i < count; i++) {
        const p = spot(i + 2)
        out += stubble(p.x, p.y + 3, base)
      }
      break
    }
  }

  return out
}

export function tileProps(tile: Tile, depleted: boolean): string {
  if (tile.dungeon) return dungeonProp()
  if (tile.settlement) return settlementProp(tile.settlement.tier, tile.propSeed)
  if (tile.water) return waterProp(tile)

  const base = variantColor(tile.variant)
  const seed = tile.propSeed
  let out = ''

  const spot = (index: number) => {
    const hx = hash2(tile.col + index * 71, tile.row + index * 131, seed)
    const hy = hash2(tile.col + index * 191, tile.row + index * 37, seed)
    return { x: randInt(hx, -17, 17), y: randInt(hy, -4, 9) }
  }

  // §5.2 -- dead ground answers first, and it answers by BIOME rather than by
  // variant. It has no variant of its own on purpose: the fill under it is the
  // country's own colour, so at a distance -- where §13.2 draws the fill and
  // nothing else -- a waste is indistinguishable from the ground around it, and
  // the only way to find workable hexes is to walk close enough to see what is
  // standing on them. That is the whole point of it blending.
  if (tile.dead) return deadProps(tile, base, seed, spot)

  // §5.3 -- the treatment comes off the variant, not the biome. Four grades of
  // forest are four different stands of trees; the base grade is the one that
  // still draws what it always drew.
  const treatment = VARIANT_PROPS[tile.variant] ?? 'conifers'

  switch (treatment) {
    case 'conifers': {
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
    case 'broadleaf': {
      const count = depleted ? 2 : 3
      for (let i = 0; i < count; i++) {
        const p = spot(i)
        out += depleted
          ? stump(p.x, p.y, base)
          : broadleaf(p.x, p.y, 0.7 + rand01(hash2(i, tile.col, seed)) * 0.35, base)
      }
      break
    }
    case 'giants': {
      const count = depleted ? 1 : 2
      for (let i = 0; i < count; i++) {
        const p = spot(i)
        out += depleted
          ? stump(p.x, p.y, base)
          : giant(p.x * 0.7, p.y, 0.72 + rand01(hash2(i, tile.row, seed)) * 0.22, base)
      }
      break
    }
    case 'ironwood': {
      const p = spot(0)
      out += depleted
        ? stump(p.x, p.y, base)
        : ironwoodTree(p.x * 0.6, p.y, 0.78, base)
      break
    }
    case 'peaks': {
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
    case 'banded': {
      const p = spot(0)
      out += depleted
        ? rockShard(p.x, p.y, 0.7, base)
        : bandedPeak(p.x * 0.4, p.y, 0.66 + rand01(seed) * 0.36, base, '#8c3f34')
      break
    }
    case 'mythril': {
      const p = spot(0)
      out += depleted
        ? rockShard(p.x, p.y, 0.7, base)
        : bandedPeak(p.x * 0.4, p.y, 0.7 + rand01(seed) * 0.3, base, '#cfe2ea')
      break
    }
    case 'crater': {
      const p = spot(0)
      out += crater(p.x * 0.5, p.y + 2, depleted ? 0.7 : 0.95, base)
      break
    }
    case 'shards': {
      const count = depleted ? 1 : 3
      for (let i = 0; i < count; i++) {
        const p = spot(i)
        out += rockShard(p.x, p.y, depleted ? 0.5 : 0.6 + rand01(hash2(i, tile.row, seed)) * 0.5, base)
      }
      break
    }
    case 'columns': {
      const count = depleted ? 2 : 3
      for (let i = 0; i < count; i++) {
        const p = spot(i)
        out += column(p.x * 0.8, p.y, depleted ? 0.55 : 0.7 + rand01(hash2(i, tile.col, seed)) * 0.3, base)
      }
      break
    }
    case 'shelf': {
      const p = spot(0)
      out += shelf(p.x * 0.35, p.y + 2, depleted ? 0.7 : 0.95, base)
      break
    }

    case 'glass': {
      const count = depleted ? 1 : 2
      for (let i = 0; i < count; i++) {
        const p = spot(i)
        out += glassShard(p.x, p.y, depleted ? 0.6 : 0.85 + rand01(hash2(i, tile.row, seed)) * 0.3, base)
      }
      break
    }
    case 'grazed': {
      const count = depleted ? 1 : 4
      for (let i = 0; i < count; i++) {
        const p = spot(i)
        out += tuft(p.x, p.y, shade(base, -0.08))
      }
      break
    }
    case 'bones': {
      out += bones(spot(0).x, spot(0).y, base)
      if (!depleted) out += tuft(spot(1).x, spot(1).y, base)
      break
    }
    case 'fangs': {
      out += fangs(spot(0).x, spot(0).y, base)
      if (!depleted) out += tuft(spot(2).x, spot(2).y, base)
      break
    }
    case 'flowering': {
      const count = depleted ? 1 : 3
      for (let i = 0; i < count; i++) {
        const p = spot(i)
        out += depleted ? tuft(p.x, p.y, base) : flowering(p.x, p.y, base)
      }
      break
    }
    case 'tall': {
      const count = depleted ? 1 : 3
      for (let i = 0; i < count; i++) {
        const p = spot(i)
        out += depleted ? tuft(p.x, p.y, base) : tallStalk(p.x, p.y, base)
      }
      break
    }
    case 'silk': {
      out += silk(spot(0).x, spot(0).y, base)
      if (!depleted) out += tuft(spot(2).x, spot(2).y, base)
      break
    }
    case 'tufts':
    default: {
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


/**
 * One tile, drawn on its own, for the almanac's ground tab.
 *
 * §5.3 gave every biome four variants and §13.2 tells them apart by tint AND by
 * prop treatment -- so a flat color chip shows half the difference. Four
 * greens next to each other are hard work; four greens with conifers, broadleaf
 * crowns, two buttressed giants and a banded trunk on them are not.
 *
 * The same `tileProps` the map calls, on the same squashed hex with the same
 * extruded slab, so what the reference page shows is what the ground looks
 * like. The seed is fixed rather than per-tile: this is one specimen of a kind
 * of ground, not a place, and a specimen that reshuffled every render would be
 * harder to compare against the card beside it.
 */
const SPECIMEN_SEED = 0x51ec

export function variantSpecimen(variant: VariantKey, size = 66): string {
  const tile = {
    col: 0,
    row: 0,
    variant,
    propSeed: SPECIMEN_SEED,
    settlement: undefined,
    dungeon: undefined,
    herdUntil: undefined,
  } as unknown as Tile

  const top = variantColor(variant)
  const side = shade(top, -0.4)
  const edge = shade(top, -0.2)

  // A prop is filled with its own ground's color, so on the map a tree only
  // reads because it stands in front of the DARKER slab of the tile behind it
  // (§13.2's painter's sort is what guarantees that). Draw one specimen alone
  // and there is nothing behind it, so the silhouette disappears into the fill.
  // The neighbor directly upslope is put back for exactly that reason -- it is
  // what the hex actually looks like in place, not a vignette behind it.
  const backTop = shade(top, -0.3)
  const back =
    `<g transform="translate(0,${-ROW_STEP})">` +
    `<path d="${HEX_SIDE_PATH}" fill="${shade(backTop, -0.4)}"/>` +
    `<path d="${HEX_TOP_PATH}" fill="${backTop}"/>` +
    '</g>'

  // Tall props stand well above their own tile -- a giant reaches ~26px up --
  // so the box is far deeper above the center than below it.
  const w = 62
  const boxH = 82
  const h = Math.round((size * boxH) / w)

  return (
    `<svg viewBox="-31 -54 ${w} ${boxH}" width="${size}" height="${h}" aria-hidden="true">` +
    back +
    `<path d="${HEX_SIDE_PATH}" fill="${side}"/>` +
    `<path d="${HEX_TOP_PATH}" fill="${top}" stroke="${edge}" stroke-width="0.5"/>` +
    tileProps(tile, false) +
    '</svg>'
  )
}

/** Rendered separately so a herd can sit on top of whatever terrain is there. */
/**
 * §5.3 -- one stretch of water, off the map, for the almanac.
 *
 * Same argument as variantSpecimen(): the neighbor upslope goes back in,
 * because a surface drawn against nothing has no edge to read against. Water
 * needs it more than the land does -- its marks are strokes rather than
 * silhouettes, and a stroke on an empty field is a scratch.
 */
/**
 * §5.3 -- one hex of water, and nothing behind it.
 *
 * The specimen above puts the upslope neighbor back so a prop has a darker
 * slab to read against, which is right on an almanac plate and wrong in a card
 * where every other portrait is a single object: two stacked hexagons read as
 * two things, and the tile card is answering "what is this hex".
 *
 * Water needs no backing anyway -- its fill is already unlike anything the land
 * is drawn in, which is the whole reason §5.3 tints it by the ground it crosses
 * rather than letting it cut one blue line across five kinds of country.
 */
export function waterGlyph(biome: Biome, kind: WaterKind, size = 34): string {
  const tile = {
    col: 0,
    row: 0,
    biome,
    variant: biome as VariantKey,
    water: kind,
    propSeed: SPECIMEN_SEED,
    settlement: undefined,
    dungeon: undefined,
    herdUntil: undefined,
  } as unknown as Tile

  const top = waterColor(biome, kind)

  // Tight to the slab: half a hex above the center, half plus its depth below.
  const w = HEX_W
  const boxH = HEX_H + 12

  return (
    `<svg viewBox="${-w / 2} ${-HEX_H / 2 - 2} ${w} ${boxH}" width="${size}" ` +
    `height="${Math.round((size * boxH) / w)}" aria-hidden="true">` +
    `<path d="${HEX_SIDE_PATH}" fill="${shade(top, -0.4)}"/>` +
    `<path d="${HEX_TOP_PATH}" fill="${top}" stroke="${shade(top, -0.2)}" stroke-width="0.5"/>` +
    waterProp(tile) +
    '</svg>'
  )
}

/**
 * §5.2 -- one hex of dead ground, for the tile card's portrait.
 *
 * The same argument as waterGlyph beside it: a single object, no backing slab,
 * drawn by the very function that draws it on the map. The portrait slot is
 * answering "what is this hex", and for dead ground the honest answer is the
 * ground itself -- the biome's own colour with a snag or a scree slope on it,
 * exactly what you are looking at underfoot.
 *
 * A seam fills this slot with its material and a settlement with its lines, so
 * a waste filling it with a blank pin was the one kind of ground whose portrait
 * said nothing at all. That is the hex a player most needs to recognise, since
 * it is the one the map is deliberately not telling them about at a distance.
 */
export function deadGlyph(biome: Biome, size = 34): string {
  const tile = {
    col: 0,
    row: 0,
    biome,
    variant: biome as VariantKey,
    dead: true,
    propSeed: SPECIMEN_SEED,
    settlement: undefined,
    dungeon: undefined,
    water: undefined,
    herdUntil: undefined,
  } as unknown as Tile

  return groundHex(biome, tileProps(tile, false), size)
}

/**
 * §5.6 -- one hex of unscouted country: the biome's colour, and nothing on it.
 *
 * The same drawing the MAP makes out there, which is the whole argument for it.
 * Beyond the sight ring §13.2 paints terrain and no props at all, so a portrait
 * with props would be the card claiming to know something the map does not --
 * live conifers would assert a seam, snags would assert a waste, and the two
 * have to look alike or the fog is decoration.
 *
 * Bare ground is what "I have not been there" looks like, and it is a great
 * deal more honest than the blank pin this replaced: that was a hole where the
 * tile should be, on a card whose whole left-hand column is the tile.
 */
export function unscoutedGlyph(biome: Biome, size = 34): string {
  return groundHex(biome, '', size)
}

/**
 * The slab both of them are drawn on: a tile seen from the map's own angle.
 *
 * Tight to the slab -- half a hex above the center, half plus its depth below.
 */
function groundHex(biome: Biome, inner: string, size: number): string {
  const top = variantColor(biome as VariantKey)
  const w = HEX_W
  const boxH = HEX_H + 12

  return (
    `<svg viewBox="${-w / 2} ${-HEX_H / 2 - 2} ${w} ${boxH}" width="${size}" ` +
    `height="${Math.round((size * boxH) / w)}" aria-hidden="true">` +
    `<path d="${HEX_SIDE_PATH}" fill="${shade(top, -0.4)}"/>` +
    `<path d="${HEX_TOP_PATH}" fill="${top}" stroke="${shade(top, -0.2)}" stroke-width="0.5"/>` +
    inner +
    '</svg>'
  )
}

export function waterSpecimen(biome: Biome, kind: WaterKind, size = 66): string {
  const tile = {
    col: 0,
    row: 0,
    biome,
    variant: biome as VariantKey,
    water: kind,
    propSeed: SPECIMEN_SEED,
    settlement: undefined,
    dungeon: undefined,
    herdUntil: undefined,
  } as unknown as Tile

  const top = waterColor(biome, kind)
  const backTop = shade(variantColor(biome as VariantKey), -0.3)

  const w = 62
  const boxH = 82
  const h = Math.round((size * boxH) / w)

  return (
    `<svg viewBox="-31 -54 ${w} ${boxH}" width="${size}" height="${h}" aria-hidden="true">` +
    `<g transform="translate(0,${-ROW_STEP})">` +
    `<path d="${HEX_SIDE_PATH}" fill="${shade(backTop, -0.4)}"/>` +
    `<path d="${HEX_TOP_PATH}" fill="${backTop}"/>` +
    '</g>' +
    `<path d="${HEX_SIDE_PATH}" fill="${shade(top, -0.4)}"/>` +
    `<path d="${HEX_TOP_PATH}" fill="${top}" stroke="${shade(top, -0.2)}" stroke-width="0.5"/>` +
    tileProps(tile, false) +
    '</svg>'
  )
}

export function herdProp(tile: Tile): string {
  return tile.herdUntil ? herd(0, 6) : ''
}

/**
 * §5.7 -- the critter that found the rich ground, if there is any.
 *
 * Off to the left of centre, because the herd and the pack own the middle of a
 * hex and all three can stand on one: a pocket is a fact about the ground and
 * they are visitors on top of it.
 */
export function pocketProp(tile: Tile): string {
  return tile.pocketUntil ? POCKET_CRITTER[tile.biome](-13, 9) : ''
}

/**
 * §5.7 -- one critter, off the map, for the almanac.
 *
 * Its own ground behind it for the reason variantSpecimen() gives: a silhouette
 * drawn against nothing has no edge to read against, and the whole point of
 * these five is that each one reads on the country it lives on.
 */
export function pocketSpecimen(biome: Biome, size = 54): string {
  const top = variantColor(biome as VariantKey)
  const w = 62
  // The top face runs y -17..17 and the slab another 11 below it, so the box
  // has to reach 28 or the ground the critter is standing on is cut off at the
  // ankles. Shallow above, because none of the five stands more than 15px tall.
  const boxH = 52
  const h = Math.round((size * boxH) / w)

  return (
    `<svg viewBox="-31 -22 ${w} ${boxH}" width="${size}" height="${h}" aria-hidden="true">` +
    `<path d="${HEX_SIDE_PATH}" fill="${shade(top, -0.4)}"/>` +
    `<path d="${HEX_TOP_PATH}" fill="${top}" stroke="${shade(top, -0.2)}" stroke-width="0.5"/>` +
    POCKET_CRITTER[biome](0, 8) +
    '</svg>'
  )
}

/**
 * §9.5.1 -- a pack on the hex.
 *
 * Read against the herd, which is the only other marker that comes and goes: a
 * herd is a pale brown animal side-on, this is a dark crouching mass with two
 * lit eyes. Eyes are the tell -- nothing else on the map has any, and at a
 * 58x34 hex two bright points on a dark shape read as "something is looking at
 * you" before the silhouette resolves into anything.
 *
 * Solid fills only (§13.2). Ember rather than a biome color, because a pack
 * belongs to no ground: it walked here.
 */
function pack(x: number, y: number): string {
  const hide = '#4a2b30'
  const eye = '#e0a24a'

  return (
    `<path d="M${x - 8} ${y + 3} Q${x - 7} ${y - 6} ${x} ${y - 7}` +
    ` Q${x + 7} ${y - 6} ${x + 8} ${y + 3} Z" fill="${hide}"/>` +
    `<path d="M${x - 6} ${y - 5} L${x - 4} ${y - 9} L${x - 2} ${y - 5} Z" fill="${hide}"/>` +
    `<path d="M${x + 6} ${y - 5} L${x + 4} ${y - 9} L${x + 2} ${y - 5} Z" fill="${hide}"/>` +
    `<circle cx="${x - 3}" cy="${y - 3}" r="1.3" fill="${eye}"/>` +
    `<circle cx="${x + 3}" cy="${y - 3}" r="1.3" fill="${eye}"/>` +
    rect(x - 7, y + 2, 3, 2.4, shade(hide, -0.3)) +
    rect(x + 4, y + 2, 3, 2.4, shade(hide, -0.3))
  )
}

export function packProp(tile: Tile): string {
  return tile.pack ? pack(0, 4) : ''
}

/**
 * §9.5.7 -- a corpse, and whatever is standing over it.
 *
 * The one marker on the map deliberately outside the fog: it is drawn for
 * everybody at any distance, because a recovery you cannot find is not one.
 * That makes it the sharpest case of §13.2's rule -- you may always see THAT
 * something is there, and never what is happening there.
 *
 * It reads as the pack's shape gone still: the same dark mass, no lit eyes, and
 * a pale marker planted beside it. Eyes are the pack's whole tell, so taking
 * them away is what says this one is not looking at you.
 *
 * `mine` puts the marker in ember, which is §13.3's color for a state to deal
 * with -- and a row of yours sitting on a hex four days out is exactly that.
 * Somebody else's is bone, which is a fact rather than a task.
 */
function corpse(x: number, y: number, mine: boolean): string {
  const hide = '#3b2429'
  const bone = mine ? '#b8453f' : '#c9bd9e'

  return (
    `<path d="M${x - 8} ${y + 3} Q${x - 6} ${y - 3} ${x} ${y - 4}` +
    ` Q${x + 6} ${y - 3} ${x + 8} ${y + 3} Z" fill="${hide}"/>` +
    rect(x - 0.9, y - 12, 1.8, 9, bone) +
    rect(x - 4, y - 10, 8, 1.8, bone) +
    `<circle cx="${x}" cy="${y - 13.5}" r="2.1" fill="${bone}"/>`
  )
}

export function corpseProp(mine: boolean): string {
  return corpse(0, 4, mine)
}

export { dungeonProp }
