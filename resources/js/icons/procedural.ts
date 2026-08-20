/**
 * Procedural icon system, §13.1. No artist required.
 *
 * The whole equipment set is 5 base silhouettes x 3 tier treatments x 5 material
 * palettes, produced by fill/stroke swaps. Adding an item never means drawing
 * anything -- it means picking a slot, a tier and a palette.
 *
 *   slot     -> base silhouette
 *   tier     -> fill treatment (flat grey / solid / gradient + glow)
 *   material -> accent colour
 *   rarity   -> hex frame, ornamentation grows per tier
 */
import { GOLD, MATERIAL_PALETTE, TIER_TREATMENT, VELLUM_DIM, shade } from '@/theme/palette'
import type { EquipSlot, EquipTier, Material, MaterialTier } from '@/game/types'

const VIEW = 40
const C = VIEW / 2

let gradientSeq = 0
const nextId = () => `g${++gradientSeq}`

// --------------------------------------------------------- base silhouettes

const SILHOUETTE: Record<EquipSlot, (fill: string, edge: string) => string> = {
  tool: (fill, edge) => `
    <rect x="18.4" y="12" width="3.2" height="20" rx="1.2" fill="${edge}"/>
    <path d="M8 15 Q20 6 32 15 Q20 12.5 8 15 Z" fill="${fill}" stroke="${edge}" stroke-width="1.1"/>
    <path d="M18.4 12 h3.2 v4 h-3.2 Z" fill="${edge}"/>`,

  armor: (fill, edge) => `
    <path d="M13 10 L20 13 L27 10 L30 15 L28.5 30 Q20 34 11.5 30 L10 15 Z"
          fill="${fill}" stroke="${edge}" stroke-width="1.2" stroke-linejoin="round"/>
    <path d="M20 13 L20 32" stroke="${edge}" stroke-width="1.1"/>
    <path d="M13 19 h14" stroke="${edge}" stroke-width="1"/>`,

  boots: (fill, edge) => `
    <path d="M14 8 L21 8 L21 22 L30 25 L30 31 L14 31 Z"
          fill="${fill}" stroke="${edge}" stroke-width="1.2" stroke-linejoin="round"/>
    <path d="M14 27 h16" stroke="${edge}" stroke-width="1.2"/>
    <path d="M16 12 h4 M16 16 h4" stroke="${edge}" stroke-width="0.9"/>`,

  gloves: (fill, edge) => `
    <path d="M13 16 L13 11 Q13 9 15 9 Q17 9 17 11 L17 15
             L19 15 L19 10 Q19 8 21 8 Q23 8 23 10 L23 15
             L25 15 L25 12 Q25 10 27 10 Q29 10 29 12 L29 24
             Q29 31 22 31 L19 31 Q13 31 13 25 Z"
          fill="${fill}" stroke="${edge}" stroke-width="1.2" stroke-linejoin="round"/>
    <path d="M13 21 Q10 20 10 17 Q10 15 12 15 L13 16 Z" fill="${fill}" stroke="${edge}" stroke-width="1"/>`,

  weapon: (fill, edge) => `
    <path d="M20 5 L23 12 L23 26 L17 26 L17 12 Z" fill="${fill}" stroke="${edge}" stroke-width="1.1"/>
    <rect x="12.5" y="26" width="15" height="2.6" rx="1.2" fill="${edge}"/>
    <rect x="18.6" y="28.6" width="2.8" height="6" rx="1.2" fill="${edge}"/>
    <circle cx="20" cy="34" r="1.9" fill="${edge}"/>`,
}

// -------------------------------------------------------------- hex framing

/** Rarity frame, §13.1 -- a hex border whose ornamentation grows with tier. */
function hexFrame(stroke: string, ornate: boolean): string {
  const r = 18.6
  const points = Array.from({ length: 6 }, (_, i) => {
    const angle = (Math.PI / 3) * i
    return `${(C + r * Math.cos(angle)).toFixed(2)},${(C + r * Math.sin(angle)).toFixed(2)}`
  }).join(' ')

  let out = `<polygon points="${points}" fill="none" stroke="${stroke}" stroke-width="1.4"/>`
  if (ornate) {
    const inner = Array.from({ length: 6 }, (_, i) => {
      const angle = (Math.PI / 3) * i
      return `${(C + (r - 3) * Math.cos(angle)).toFixed(2)},${(C + (r - 3) * Math.sin(angle)).toFixed(2)}`
    }).join(' ')
    out += `<polygon points="${inner}" fill="none" stroke="${stroke}" stroke-width="0.7"/>`
    // Corner pips, the top-tier tell.
    for (let i = 0; i < 6; i++) {
      const angle = (Math.PI / 3) * i
      const x = C + r * Math.cos(angle)
      const y = C + r * Math.sin(angle)
      out += `<circle cx="${x.toFixed(2)}" cy="${y.toFixed(2)}" r="1.5" fill="${stroke}"/>`
    }
  }
  return out
}

// ------------------------------------------------------------------ public

export interface IconOptions {
  slot: EquipSlot
  tier: EquipTier
  palette: keyof typeof MATERIAL_PALETTE
  size?: number
}

/** Full SVG markup for an equipment icon. */
export function itemIcon({ slot, tier, palette, size = 40 }: IconOptions): string {
  const treatment = TIER_TREATMENT[tier]
  const accent = MATERIAL_PALETTE[palette]
  const id = nextId()

  let fill: string
  let defs = ''

  if (tier === 'basic') {
    // Flat grey: basic gear should look like what it is.
    fill = treatment.fill
  } else if (tier === 'crafted') {
    fill = accent
  } else {
    // NFT: gradient plus a border glow, §13.1.
    defs = `<defs>
      <linearGradient id="${id}" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stop-color="${shade(accent, 0.34)}"/>
        <stop offset="55%" stop-color="${accent}"/>
        <stop offset="100%" stop-color="${shade(accent, -0.34)}"/>
      </linearGradient>
      <filter id="${id}f" x="-40%" y="-40%" width="180%" height="180%">
        <feGaussianBlur stdDeviation="1.6" result="b"/>
        <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>
      </filter>
    </defs>`
    fill = `url(#${id})`
  }

  const edge = tier === 'nft' ? GOLD : shade(tier === 'basic' ? treatment.fill : accent, -0.42)
  const frame = hexFrame(tier === 'nft' ? GOLD : tier === 'crafted' ? shade(accent, 0.1) : '#5a625c', tier !== 'basic')
  const body = SILHOUETTE[slot](fill, edge)

  return `<svg viewBox="0 0 ${VIEW} ${VIEW}" width="${size}" height="${size}" role="img" aria-hidden="true">
    ${defs}${frame}
    <g${tier === 'nft' ? ` filter="url(#${id}f)"` : ''}>${body}</g>
  </svg>`
}

// --------------------------------------------------------- material icons

/**
 * Materials get their own small set: a shape per tier, tinted by the material's
 * palette. Same principle -- generated, never drawn.
 */
export function materialIcon(mat: Material, size = 32): string {
  const accent = MATERIAL_PALETTE[mat.palette]
  const dark = shade(accent, -0.32)
  const light = shade(accent, 0.24)
  const id = nextId()

  const shapes: Record<MaterialTier, string> = {
    // Raw: a rough, irregular lump.
    1: `<path d="M9 26 L7 15 L15 8 L27 10 L32 20 L26 30 L14 31 Z" fill="${accent}" stroke="${dark}" stroke-width="1.2" stroke-linejoin="round"/>
        <path d="M15 8 L18 19 L9 26" fill="none" stroke="${light}" stroke-width="1.1"/>`,
    // Refined: stacked, squared-off bars.
    2: `<rect x="7" y="21" width="26" height="8" rx="1.6" fill="${accent}" stroke="${dark}" stroke-width="1.1"/>
        <rect x="10" y="13" width="20" height="8" rx="1.6" fill="${light}" stroke="${dark}" stroke-width="1.1"/>
        <rect x="14" y="7" width="12" height="7" rx="1.6" fill="${accent}" stroke="${dark}" stroke-width="1.1"/>`,
    // Rare: a faceted crystal.
    3: `<defs><linearGradient id="${id}" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" stop-color="${light}"/><stop offset="100%" stop-color="${dark}"/>
        </linearGradient></defs>
        <path d="M20 4 L31 15 L26 33 L14 33 L9 15 Z" fill="url(#${id})" stroke="${GOLD}" stroke-width="1.2" stroke-linejoin="round"/>
        <path d="M20 4 L20 33 M9 15 L31 15" stroke="${GOLD}" stroke-width="0.7"/>`,
    // Raid: a sealed relic core.
    4: `<defs><radialGradient id="${id}">
          <stop offset="0%" stop-color="${light}"/><stop offset="100%" stop-color="${dark}"/>
        </radialGradient></defs>
        <circle cx="20" cy="20" r="12" fill="url(#${id})" stroke="${GOLD}" stroke-width="1.2"/>
        <path d="M20 8 L20 32 M8 20 L32 20" stroke="${GOLD}" stroke-width="0.8"/>
        <circle cx="20" cy="20" r="4.5" fill="${VELLUM_DIM}"/>`,
  }

  return `<svg viewBox="0 0 40 40" width="${size}" height="${size}" role="img" aria-hidden="true">
    ${shapes[mat.tier]}
  </svg>`
}

/** Small glyph for the five skill lines. */
export function skillIcon(key: string, size = 24): string {
  const paths: Record<string, string> = {
    woodcutting: '<path d="M8 30 L24 12 M22 8 L32 18 L27 23 L17 13 Z"/>',
    mining: '<path d="M9 29 L23 15 M11 11 Q20 6 29 11 Q20 10 11 11 Z M19 13 L23 17"/>',
    hunting: '<path d="M20 6 L20 30 M13 12 L20 6 L27 12 M10 20 h20"/>',
    quarrying: '<path d="M8 28 h24 M12 28 V18 h7 v10 M21 28 V13 h7 v15"/>',
    harvesting: '<path d="M20 32 V14 M20 14 Q12 12 11 6 Q19 6 20 14 Q21 6 29 6 Q28 12 20 14"/>',
  }
  return `<svg viewBox="0 0 40 40" width="${size}" height="${size}" fill="none"
    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
    role="img" aria-hidden="true">${paths[key] ?? ''}</svg>`
}
