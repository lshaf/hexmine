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
 *   tier     -> fill treatment (flat gray / solid / gradient + glow)
 *   material -> accent color
 *   rarity   -> hex frame, ornamentation grows per tier -- on a material as
 *               much as on a piece of gear
 */
import {
  CRITTER_ACCENT,
  HERB_ACCENT,
  ICHOR_ACCENT,
  LINE,
  PLATE_ACCENT,
  MATERIAL_PALETTE,
  RARITY_TREATMENT,
  shade,
} from '@/theme/palette'
import {
  CRITTER_FORMS,
  HERB_FORMS,
  ICHOR_FORMS,
  PLATE_FORMS,
  formFor,
  formShape,
  gradeRank,
  keySeed,
  type Ink,
} from './forms'
import { materialRarity } from '@/game/catalog'
import type { EquipSlot, Material, Rarity } from '@/game/types'

const VIEW = 40
const C = VIEW / 2

let gradientSeq = 0
const nextId = () => `g${++gradientSeq}`

// --------------------------------------------------------- base silhouettes

/*
 * The five gathering tools have to be told apart at 26px in a shop list, so each
 * one owns a different read: crescent off a center line (axe), wide symmetric
 * points (pickaxe), open arc (bow), solid block (hammer), diagonal hook
 * (sickle). Within that, one convention holds the family together -- the haft is
 * always drawn in `edge`, the working head in `fill`, so the material accent
 * always lands on the part that meets the ground.
 */
/**
 * §9.5.4 -- the three weapon families are one slot and three silhouettes.
 *
 * Everything else in the set is told apart by its slot, because a slot holds
 * one kind of thing. The `weapon` slot holds three, and which one you carry is
 * your class -- so the family has to own the shape, or a shieldbearer's shield
 * is drawn as a sword.
 */
type IconShape = EquipSlot | 'potion' | 'shield' | 'focus'

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

  // §9.5.4 Shieldbearer. A heater shield: the only thing in the set that is
  // wider at the top than the bottom, which is what stops it reading as a
  // blade at 26px.
  shield: (fill, edge) => `
    <path d="M9 7 H31 V17 Q31 28 20 34 Q9 28 9 17 Z"
          fill="${fill}" stroke="${edge}" stroke-width="1.3" stroke-linejoin="round"/>
    <path d="M20 7 V34" stroke="${edge}" stroke-width="1"/>
    <path d="M9 15 H31" stroke="${edge}" stroke-width="1"/>`,

  // §9.5.4 Runecaster. A rod under a cut stone -- no edge anywhere on it, which
  // is the whole read: the other two families are things you swing.
  focus: (fill, edge) => `
    <rect x="18.6" y="17" width="2.8" height="17" rx="1.3" fill="${edge}"/>
    <path d="M20 5 L27 11.5 L20 20 L13 11.5 Z"
          fill="${fill}" stroke="${edge}" stroke-width="1.2" stroke-linejoin="round"/>
    <path d="M13 11.5 H27 M20 5 V20" stroke="${edge}" stroke-width="0.8"/>
    <circle cx="20" cy="24.5" r="2.2" fill="${fill}" stroke="${edge}" stroke-width="1"/>`,

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

/**
 * Rarity plate, §13.1 -- a hex FILLED with the rarity color, not outlined in it.
 *
 * It was a hairline round nothing, which made rarity the thinnest mark on a
 * screen full of them: at 32px a gray ring and a green ring are the same ring
 * until you look. Filled, the rung is the loudest thing about an icon and is
 * read across a whole list without reading anything -- which is what §13.1 says
 * rarity is FOR, and a hairline was never going to carry it.
 *
 * The stroke stays and stays the same color, so the plate keeps a crisp edge
 * rather than an antialiased one. It is not a second mark.
 *
 * **Everything drawn ON the plate is cut in one ink** (§13.3's `line`), because
 * the plate is now the thing a mark has to survive: ornament in the rarity
 * color would be the plate's own color on the plate, which is nothing at all.
 * That one ink is what makes the icon read as a stamped tile rather than as a
 * shape sitting on a colored square.
 */
function hexFrame(color: string, ornate: boolean): string {
  const r = 18.6
  const points = Array.from({ length: 6 }, (_, i) => {
    const angle = (Math.PI / 3) * i
    return `${(C + r * Math.cos(angle)).toFixed(2)},${(C + r * Math.sin(angle)).toFixed(2)}`
  }).join(' ')

  let out = `<polygon points="${points}" fill="${color}" stroke="${color}" stroke-width="1.4"/>`
  if (ornate) {
    const inner = Array.from({ length: 6 }, (_, i) => {
      const angle = (Math.PI / 3) * i
      return `${(C + (r - 3) * Math.cos(angle)).toFixed(2)},${(C + (r - 3) * Math.sin(angle)).toFixed(2)}`
    }).join(' ')
    out += `<polygon points="${inner}" fill="none" stroke="${LINE}" stroke-width="0.7"/>`
    // Corner pips, the top-tier tell.
    for (let i = 0; i < 6; i++) {
      const angle = (Math.PI / 3) * i
      const x = C + r * Math.cos(angle)
      const y = C + r * Math.sin(angle)
      out += `<circle cx="${x.toFixed(2)}" cy="${y.toFixed(2)}" r="1.5" fill="${LINE}"/>`
    }
  }
  return out
}

// ------------------------------------------------------------------ public

export interface IconOptions {
  /** Absent for consumables (§8.5), which have no slot and draw as a flask. */
  slot?: EquipSlot
  /** §9.5.4 -- one weapon slot, three families, and the family owns the shape. */
  family?: 'shield' | 'sword' | 'focus'
  rarity: Rarity
  palette: keyof typeof MATERIAL_PALETTE
  size?: number
}

/**
 * Full SVG markup for an equipment icon.
 *
 * Two colors are doing two different jobs and must not be confused: the
 * **material accent** says what the thing is made of, the **rarity color** says
 * how good it is. Rarity owns the plate and the glow, because that is what a
 * player scans a list for; the material keeps the body.
 *
 * `common` used to be the exception -- it took the rarity gray for its body as
 * well, so the cheapest gear looked like what it was whatever it was made of.
 * The plate says that now, and says it louder than a gray body ever did, so the
 * body is its material again: gray-on-gray was the one pairing the ink outline
 * could keep legible and could not keep interesting.
 */
export function itemIcon({ slot, family, rarity, palette, size = 40 }: IconOptions): string {
  const treatment = RARITY_TREATMENT[rarity]
  const accent = MATERIAL_PALETTE[palette]
  const id = nextId()

  let fill: string
  let defs = ''

  if (!treatment.glow) {
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

  // §13.1 -- the one ink everything on the plate is cut in. It used to be a
  // darkened copy of the body's own color, which was invisible the moment the
  // plate arrived: a dark violet outline on a violet plate is a violet plate.
  const edge = LINE
  const frame = hexFrame(treatment.color, treatment.ornate)
  // A sword is the `weapon` slot's own shape, so only the other two families
  // displace it -- and a weapon with no family recorded still draws as one.
  const shape: IconShape =
    family && family !== 'sword' ? family : (slot ?? 'potion')
  const body = SILHOUETTE[shape](fill, edge)

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
/**
 * The one color a material answers to, §13.1.
 *
 * Exported because the icon is not the only thing that has to say "this is
 * wood": anything drawing a material as a bare color -- a share of a bar, a
 * pip, a legend -- has to arrive at the same answer, and a second copy of the
 * herb/critter rule would eventually disagree with this one.
 */
export function materialAccent(mat: Material): string {
  // §4 -- the herbalist's shelf is green, whatever ground it grew on.
  const form = formFor(mat)

  if (HERB_FORMS.has(form)) return HERB_ACCENT
  if (CRITTER_FORMS.has(form)) return CRITTER_ACCENT
  // §9.5.8 -- bone and blood, and which of the two is the whole message.
  if (PLATE_FORMS.has(form)) return PLATE_ACCENT
  if (ICHOR_FORMS.has(form)) return ICHOR_ACCENT

  return MATERIAL_PALETTE[mat.palette]
}

export function materialIcon(mat: Material, size = 32): string {
  const form = formFor(mat)
  const accent = materialAccent(mat)

  const ink: Ink = {
    fill: accent,
    // §13.1 -- cut in the panel's own ink rather than in a darkened copy of the
    // accent, because the specimen now sits ON the rarity plate: a dark green
    // outline on a green plate says nothing. One ink for every specimen is also
    // what makes a shelf of them read as one set.
    dark: LINE,
    light: shade(accent, 0.26),
  }

  const { color, ornate } = RARITY_TREATMENT[materialRarity(mat)]

  // Drawn to the edges of the box, so the specimen is pulled in to sit inside
  // the frame rather than through it. Centerd: 20 * 0.86 + 2.8 lands back on 20.
  const specimen = formShape(form, ink, keySeed(mat.key), gradeRank(mat.key))

  return `<svg viewBox="0 0 40 40" width="${size}" height="${size}" role="img" aria-hidden="true">
    ${hexFrame(color, ornate)}
    <g transform="translate(2.8 2.8) scale(0.86)">${specimen}</g>
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
