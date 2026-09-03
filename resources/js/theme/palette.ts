/**
 * Palette, §13.3. Solid colors only -- §13.2 is explicit that alpha anywhere on
 * the map causes ghost-hex artefacts through neighbors, so every shade a tile
 * needs is precomputed to an opaque hex string here.
 */
import { VARIANT_TINT } from '@/game/variants'
import type { Biome, VariantKey, WaterKind } from '@/game/types'

export const INK = '#141b18'
export const INK_PANEL = '#1d2622'
export const LINE = '#3a463f'
export const VELLUM = '#ece3cd'
export const VELLUM_DIM = '#c9bd9e'

export const COPPER = '#c1793f'
export const EMBER = '#b8453f'
export const GOLD = '#d8b34a'
export const VIOLET = '#7d5fa8'

export const BIOME_COLOR: Record<Biome, string> = {
  forest: '#5f8058',
  mountain: '#6d8399',
  badlands: '#96604c',
  grassland: '#a8a05c',
}

export const BIOME_LABEL: Record<Biome, string> = {
  forest: 'Forest',
  mountain: 'Mountain',
  badlands: 'Badlands',
  grassland: 'Grassland',
}

// ------------------------------------------------------------ color helpers

function hexToRgb(hex: string): [number, number, number] {
  const value = parseInt(hex.slice(1), 16)
  return [(value >> 16) & 255, (value >> 8) & 255, value & 255]
}

function rgbToHex(r: number, g: number, b: number): string {
  const clamp = (n: number) => Math.max(0, Math.min(255, Math.round(n)))
  return `#${((clamp(r) << 16) | (clamp(g) << 8) | clamp(b)).toString(16).padStart(6, '0')}`
}

/** Blend toward black (amount < 0) or white (amount > 0). Always opaque. */
export function shade(hex: string, amount: number): string {
  const [r, g, b] = hexToRgb(hex)
  const target = amount < 0 ? 0 : 255
  const t = Math.abs(amount)
  return rgbToHex(r + (target - r) * t, g + (target - g) * t, b + (target - b) * t)
}

/** Pull saturation out by mixing toward the channel average. */
export function desaturate(hex: string, amount: number): string {
  const [r, g, b] = hexToRgb(hex)
  const gray = r * 0.299 + g * 0.587 + b * 0.114
  return rgbToHex(
    r + (gray - r) * amount,
    g + (gray - g) * amount,
    b + (gray - b) * amount,
  )
}

/** Blend two colors. Always opaque -- §13.2 allows no alpha on the map. */
export function mix(a: string, b: string, amount: number): string {
  const [r1, g1, b1] = hexToRgb(a)
  const [r2, g2, b2] = hexToRgb(b)
  return rgbToHex(r1 + (r2 - r1) * amount, g1 + (g2 - g1) * amount, b1 + (b2 - b1) * amount)
}

/**
 * §5.3 -- open water, tinted by the ground it crosses.
 *
 * One blue everywhere would cut the map into blue and not-blue and read as a
 * layer laid over the terrain rather than part of it. A fifth of the biome's
 * own color mixed in is enough that a river stays recognisably a river while
 * still belonging to the badlands or the forest it runs through -- and that is
 * the same argument §13.3 makes for depleted ground keeping its biome color.
 *
 * A waterway is lighter than a lake because it is shallower. That is the only
 * thing separating the two fills; the shape does the rest.
 */
const WATER_BASE = '#3f6b86'

export const waterColor = (biome: Biome, kind: WaterKind): string => {
  const tinted = mix(WATER_BASE, BIOME_COLOR[biome], 0.22)

  return kind === 'river' ? shade(tinted, 0.12) : shade(tinted, -0.07)
}

/**
 * §5.3 -- the fill for a hex, which is its variant's tint rather than its
 * biome's. Four grades of forest are four greens, or the contested ground would
 * go on looking like the safe ground next to it.
 */
export const variantColor = (variant: VariantKey): string =>
  VARIANT_TINT[variant] ?? BIOME_COLOR[variant as Biome]

/**
 * §13.3 -- a depleted tile uses a darker, desaturated variant of its OWN
 * color, never gray. The land is drained, not dead, and it will regrow.
 */
export const depletedColor = (variant: VariantKey): string =>
  shade(desaturate(variantColor(variant), 0.45), -0.28)

/** Material accent colors for the procedural icon system, §13.1. */
export const MATERIAL_PALETTE = {
  wood: '#8a5a34',
  iron: '#8d9aa5',
  pelt: '#c2a077',
  stone: '#7b7f86',
  fiber: '#d9cfa8',
  raid: VIOLET,
} as const

/**
 * §4 -- the herbalist's ten, and the only green in the material set.
 *
 * A reagent takes this instead of its biome accent. Which ground it grew on is
 * already on the card; what the icon has to say at 15px is which of the three
 * benches wants it, and a shelf that is all one green says that instantly.
 * Deliberately off the biome scale so it never reads as a sixth terrain.
 */
export const HERB_ACCENT = '#7d9464'

/**
 * §4 -- the alchemist's other stock. Warm where the herbs are cool, because
 * the two halves of the shelf are reached by different roads: a herb is
 * gathered by hand, a critter is hunted. Off the biome scale for the same
 * reason the green is.
 */
export const CRITTER_ACCENT = '#c08a52'

/**
 * §9.5.8 -- the two halves of a monster. Bone and blood, and both off the biome
 * scale for the same reason the herbs and the critters are: a spoil comes off a
 * thing that walked onto the hex, not out of it, so it must never read as a
 * sixth terrain. Kept apart from each other because they feed different
 * benches, which is the one thing the icon has to say at 15px.
 */
export const PLATE_ACCENT = '#8f8071'

export const ICHOR_ACCENT = '#8d4a58'

/**
 * §8.1 -- rarity treatment for equipment icons. Rarity is the one thing a player
 * reads at a glance across the shop, the bag and the hero sheet, so it gets a
 * color of its own rather than a shade of the material accent.
 *
 * The ramp is drawn from the §13.3 palette so it never fights the map: stone
 * gray, forest green, mountain slate, violet, gold, ember. Ordered weakest to
 * strongest, and `ornate` is what earns the second hex frame on the icon.
 */
export const RARITY_TREATMENT = {
  common: { color: '#8d948e', fill: '#6f7671', glow: false, ornate: false },
  uncommon: { color: '#6f9a5e', fill: '#5f8058', glow: false, ornate: false },
  rare: { color: '#6f9ec4', fill: '#5d84a6', glow: false, ornate: true },
  epic: { color: VIOLET, fill: VIOLET, glow: true, ornate: true },
  legendary: { color: GOLD, fill: GOLD, glow: true, ornate: true },
  unique: { color: EMBER, fill: EMBER, glow: true, ornate: true },
} as const
