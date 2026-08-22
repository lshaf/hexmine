/**
 * Procedural icon system, §13.1. No artist required.
 *
 * The whole equipment set is one base silhouette per slot x 3 tier treatments x
 * 5 material palettes, produced by fill/stroke swaps. Adding an item never means
 * drawing anything -- it means picking a slot, a tier and a palette. That is why
 * §8 giving every gathering line its own tool cost five silhouettes and nothing
 * else: 25 tools came out of it.
 *
 *   slot     -> base silhouette
 *   tier     -> fill treatment (flat grey / solid / gradient + glow)
 *   material -> accent colour
 *   rarity   -> hex frame, ornamentation grows per tier
 */
import { HERB_ACCENT, MATERIAL_PALETTE, RARITY_TREATMENT, shade } from '@/theme/palette'
import { HERB_FORMS, formFor, formShape, gradeRank, keySeed, type Ink } from './forms'
import type { EquipSlot, Material, Rarity } from '@/game/types'

const VIEW = 40
const C = VIEW / 2

let gradientSeq = 0
const nextId = () => `g${++gradientSeq}`

// --------------------------------------------------------- base silhouettes

/*
 * The five gathering tools have to be told apart at 26px in a shop list, so each
 * one owns a different read: crescent off a centre line (axe), wide symmetric
 * points (pickaxe), open arc (bow), solid block (hammer), diagonal hook
 * (sickle). Within that, one convention holds the family together -- the haft is
 * always drawn in `edge`, the working head in `fill`, so the material accent
 * always lands on the part that meets the ground.
 */
type IconShape = EquipSlot | 'potion'

const SILHOUETTE: Record<IconShape, (fill: string, edge: string) => string> = {
  // Woodcutting. A bearded bit hung off one side of the haft: the beard hooking
  // low is what stops it reading as a symmetrical lens.
  axe: (fill, edge) => `
    <rect x="16.4" y="7" width="3.2" height="27" rx="1.4" fill="${edge}"/>
    <path d="M19.4 11 L28 9 Q31.5 16 26 23.5 Q22.8 26.5 19.4 19.5 Z"
          fill="${fill}" stroke="${edge}" stroke-width="1.1" stroke-linejoin="round"/>
    <path d="M21.4 12.8 Q27.2 16.4 25.4 21.4" fill="none" stroke="${edge}" stroke-width="0.8"/>`,

  // Mining. Wide and thin, sharp at both tips -- read against the hammer, which
  // is narrow and thick. Width plus taper is the only thing telling them apart
  // at 26px, so do not soften either.
  pickaxe: (fill, edge) => `
    <rect x="18.4" y="13" width="3.2" height="21" rx="1.4" fill="${edge}"/>
    <path d="M6.5 17.5 Q20 3.5 33.5 17.5 Q20 11.5 6.5 17.5 Z"
          fill="${fill}" stroke="${edge}" stroke-width="1.1" stroke-linejoin="round"/>
    <path d="M18.4 12.6 h3.2 v4.6 h-3.2 Z" fill="${edge}"/>`,

  // Hunting. Mostly negative space -- nothing else in the set is an open curve.
  bow: (fill, edge) => `
    <path d="M16.5 6 Q30.4 20 16.5 34" fill="none" stroke="${edge}" stroke-width="4.6" stroke-linecap="round"/>
    <path d="M16.5 6 Q30.4 20 16.5 34" fill="none" stroke="${fill}" stroke-width="2.6" stroke-linecap="round"/>
    <path d="M16.5 6 L16.5 34" stroke="${edge}" stroke-width="1"/>
    <path d="M13.6 20 H28.6" stroke="${edge}" stroke-width="1.3"/>
    <path d="M32.4 20 L27.6 17.4 L27.6 22.6 Z" fill="${edge}"/>
    <path d="M13.4 20 L16.4 17.8 L16.4 22.2 Z" fill="${edge}"/>`,

  // Quarrying. Narrow and thick where the pickaxe is wide and thin, with a peen
  // wedge on one side so it never resolves into the same symmetrical T.
  hammer: (fill, edge) => `
    <path d="M11.5 7.6 L25.5 7.6 L29 13.05 L25.5 18.5 L11.5 18.5 Z"
          fill="${fill}" stroke="${edge}" stroke-width="1.2" stroke-linejoin="round"/>
    <path d="M14.8 7.6 V18.5" stroke="${edge}" stroke-width="0.9"/>
    <rect x="16.9" y="7" width="3.2" height="27" rx="1.4" fill="${edge}"/>`,

  // Harvesting. The only tool with no vertical axis at all. The handle gets the
  // bow's two-tone treatment or it disappears under the blade.
  sickle: (fill, edge) => `
    <path d="M23.5 23.5 L30.5 30.5" stroke="${edge}" stroke-width="4.4" stroke-linecap="round"/>
    <path d="M23.5 23.5 L30.5 30.5" stroke="${fill}" stroke-width="2.2" stroke-linecap="round"/>
    <path d="M25.5 26.5 C14.5 26.5 8 19.5 10.5 10 C15 16.5 19 20.5 25.5 22.5 Z"
          fill="${fill}" stroke="${edge}" stroke-width="1.1" stroke-linejoin="round"/>`,

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

  // §8.5 consumables. A round bulb -- the only closed curve in the set, and the
  // only shape with no handle, because a potion is the one thing you do not hold
  // to work with.
  potion: (fill, edge) => `
    <rect x="17.2" y="4.4" width="5.6" height="3.6" rx="1.2" fill="${edge}"/>
    <rect x="18" y="7.6" width="4" height="8" fill="${edge}"/>
    <path d="M18 15.4 Q9.6 19.6 9.6 25.6 Q9.6 33.4 20 33.4 Q30.4 33.4 30.4 25.6 Q30.4 19.6 22 15.4 Z"
          fill="${fill}" stroke="${edge}" stroke-width="1.2" stroke-linejoin="round"/>
    <path d="M11.4 23.6 Q20 26.4 28.6 23.6" fill="none" stroke="${edge}" stroke-width="1"/>`,
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
  /** Absent for consumables (§8.5), which have no slot and draw as a flask. */
  slot?: EquipSlot
  rarity: Rarity
  palette: keyof typeof MATERIAL_PALETTE
  size?: number
}

/**
 * Full SVG markup for an equipment icon.
 *
 * Two colours are doing two different jobs and must not be confused: the
 * **material accent** says what the thing is made of, the **rarity colour** says
 * how good it is. Rarity owns the frame and the glow, because that is what a
 * player scans a list for; the material keeps the body.
 *
 * `common` is the exception -- it takes the rarity grey for its body too, so the
 * cheapest gear looks like what it is no matter what it is made of.
 */
export function itemIcon({ slot, rarity, palette, size = 40 }: IconOptions): string {
  const treatment = RARITY_TREATMENT[rarity]
  const accent = MATERIAL_PALETTE[palette]
  const id = nextId()

  let fill: string
  let defs = ''

  if (rarity === 'common') {
    fill = treatment.fill
  } else if (!treatment.glow) {
    fill = accent
  } else {
    // Epic and up: gradient body plus a border glow, §13.1.
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

  const edge = shade(rarity === 'common' ? treatment.fill : accent, -0.42)
  const frame = hexFrame(treatment.color, treatment.ornate)
  const body = SILHOUETTE[slot ?? 'potion'](fill, edge)

  return `<svg viewBox="0 0 ${VIEW} ${VIEW}" width="${size}" height="${size}" role="img" aria-hidden="true">
    ${defs}${frame}
    <g${treatment.glow ? ` filter="url(#${id}f)"` : ''}>${body}</g>
  </svg>`
}

// --------------------------------------------------------- material icons

/**
 * Materials get their own small set: a shape per tier, tinted by the material's
 * palette. Same principle -- generated, never drawn.
 */
export function materialIcon(mat: Material, size = 32): string {
  // §4 -- the herbalist's shelf is green, whatever ground it grew on.
  const form = formFor(mat)
  const accent = HERB_FORMS.has(form) ? HERB_ACCENT : MATERIAL_PALETTE[mat.palette]

  const ink: Ink = {
    fill: accent,
    dark: shade(accent, -0.42),
    light: shade(accent, 0.26),
  }

  return `<svg viewBox="0 0 40 40" width="${size}" height="${size}" role="img" aria-hidden="true">
    ${formShape(form, ink, keySeed(mat.key), gradeRank(mat.key))}
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
