/**
 * Palette, §13.3. Solid colours only -- §13.2 is explicit that alpha anywhere on
 * the map causes ghost-hex artefacts through neighbours, so every shade a tile
 * needs is precomputed to an opaque hex string here.
 */
import type { Biome } from '@/game/types'

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
  plains: '#b08a5a',
  badlands: '#96604c',
  grassland: '#a8a05c',
}

export const BIOME_LABEL: Record<Biome, string> = {
  forest: 'Forest',
  mountain: 'Mountain',
  plains: 'Plains',
  badlands: 'Badlands',
  grassland: 'Grassland',
}

// ------------------------------------------------------------ colour helpers

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
  const grey = r * 0.299 + g * 0.587 + b * 0.114
  return rgbToHex(
    r + (grey - r) * amount,
    g + (grey - g) * amount,
    b + (grey - b) * amount,
  )
}

/**
 * §13.3 -- a depleted tile uses a darker, desaturated variant of its OWN biome
 * colour, never grey. The land is drained, not dead, and it will regrow.
 */
export const depletedColor = (biome: Biome): string =>
  shade(desaturate(BIOME_COLOR[biome], 0.45), -0.28)

/** Material accent colours for the procedural icon system, §13.1. */
export const MATERIAL_PALETTE = {
  wood: '#8a5a34',
  iron: '#8d9aa5',
  pelt: '#c2a077',
  stone: '#7b7f86',
  fiber: '#d9cfa8',
  raid: VIOLET,
} as const

/**
 * §8.1 -- rarity treatment for equipment icons. Rarity is the one thing a player
 * reads at a glance across the shop, the bag and the hero sheet, so it gets a
 * colour of its own rather than a shade of the material accent.
 *
 * The ramp is drawn from the §13.3 palette so it never fights the map: stone
 * grey, forest green, mountain slate, violet, gold, ember. Ordered weakest to
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
