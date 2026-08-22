/**
 * Material silhouettes, §13.1 — the specimen plate.
 *
 * Until now every material of a tier shared one shape, so Wood, Hardwood,
 * Heartoak, Toadstool and Heartknot were the same lump in five colours. That
 * works while a tier holds five things. It stopped working at seventy-four.
 *
 * The set is drawn as a WOODCUT SPECIMEN PLATE, which is the vernacular the
 * page is already named after: heavy outline, one flat fill, one lighter facet,
 * and no gradient anywhere below tier 3. The lit treatment is reserved for the
 * rare and raid tiers, so "this one glows" separates the top of the ladder from
 * sixty matte neighbours; the exact rung is on the rarity belt drawn under the
 * specimen (§13.1).
 *
 * A form is DERIVED, never stored — the same argument §8.4 makes about craft
 * category. A second field would only be somewhere for the catalog and the
 * truth to disagree, and the truth here is the material's own key.
 *
 * Shapes are shared where the things genuinely are alike: three grades of hide
 * are one hide with more on it. What separates them is the accent, the facet,
 * and a per-key detail — never a wholly different drawing, because thirty-five
 * shapes that each mean something beat seventy-four that nobody can tell apart.
 */
import type { Material } from '@/game/types'

export interface Ink {
  /** The material accent, §13.1: what the thing is made of. */
  fill: string
  /** Outline and shadow. Every specimen is cut, so every specimen has one. */
  dark: string
  /** The single lit facet. One per shape — two reads as a gradient. */
  light: string
}

/*
 * The vocabulary. Grouped by what the thing IS rather than by tier, because a
 * mushroom and a root are further apart than two grades of ore.
 */
export type Form =
  // tier 0 — worthless, scattered, nothing about it reads as a resource
  | 'twig' | 'flake' | 'tatter' | 'splinter' | 'pebbles' | 'husk'
  // tier 1 — what the ground gives up
  | 'log' | 'ore' | 'hide' | 'rubble' | 'column' | 'slab' | 'sheaf'
  // tier 1 — the herbalist's shelf, and the only green things in the game
  | 'mushroom' | 'sprig' | 'trefoil' | 'root' | 'rosette' | 'sap' | 'umbel'
  // tier 1 — the alchemist's other stock, and the only things with eyes
  | 'moth' | 'mite' | 'hare' | 'newt' | 'bird'
  // tier 1 — the smith's and the armorer's stock
  | 'knot' | 'resin' | 'salt' | 'scale' | 'horn' | 'cord' | 'grit' | 'seep'
  | 'reed' | 'wax'
  // tier 2 — worked
  | 'planks' | 'ingot' | 'leather' | 'cutstone' | 'bolt' | 'frame'
  // tier 3 / 4 — the lit ones
  | 'crystal' | 'mote' | 'shard' | 'relic' | 'core'

const FORM_BY_KEY: Record<string, Form> = {
  // ---- tier 0
  branch: 'twig', deadfall: 'twig',
  ore_chips: 'flake', slag: 'flake',
  torn_hide: 'tatter', bone_splinter: 'splinter',
  gravel: 'pebbles', cinder: 'pebbles',
  chaff: 'husk', thistle: 'husk',

  // ---- tier 1, the ground
  wood: 'log', hardwood: 'log', heartoak: 'log',
  iron_ore: 'ore', hematite: 'ore', meteoric_iron: 'ore',
  pelt: 'hide', thick_pelt: 'hide', dire_pelt: 'hide',
  stone: 'rubble', basalt: 'column', granite: 'slab',
  fiber: 'sheaf', flax: 'sheaf', hemp: 'sheaf',

  // ---- tier 1, the herbalist
  toadstool: 'mushroom', ashcap: 'mushroom',
  blue_nettle: 'sprig', sagebrush: 'sprig',
  clover: 'trefoil',
  bitterroot: 'root',
  lichen: 'rosette', stonewort: 'rosette',
  birch_sap: 'sap',
  yarrow: 'umbel',

  // ---- tier 1, the alchemist's second stock
  glimmermoth: 'moth',
  rockmite: 'mite',
  dustleveret: 'hare',
  ashnewt: 'newt',
  fenlark: 'bird',

  // ---- tier 1, the smith and the armorer
  heartknot: 'knot', pine_pitch: 'resin',
  flux_salt: 'salt', slate_scale: 'scale',
  horn: 'horn', sinew: 'cord',
  whetgrit: 'grit', tar_seep: 'seep',
  quench_reed: 'reed', beeswax: 'wax',

  // ---- tier 2
  planks: 'planks', beams: 'planks', bentwood: 'planks',
  ingots: 'ingot', steel_ingots: 'ingot', skysteel: 'ingot',
  leather: 'leather', boiled_leather: 'leather', lacquered_hide: 'leather',
  cut_stone: 'cutstone', dressed_basalt: 'cutstone', polished_granite: 'cutstone',
  cloth: 'bolt', linen: 'bolt', canvas: 'bolt',
  reinforced_frame: 'frame',

  // ---- tier 4
  essence: 'mote', relic: 'relic', core: 'core',
}

/** Tier 3 is one form; the five rares differ by accent and facet count. */
const TIER_FALLBACK: Record<number, Form> = {
  0: 'pebbles', 1: 'rubble', 2: 'ingot', 3: 'crystal', 4: 'shard',
}

export function formFor(mat: Material): Form {
  return FORM_BY_KEY[mat.key] ?? TIER_FALLBACK[mat.tier] ?? 'rubble'
}

/**
 * §4 — the alchemist's ten are the only green things in the catalog.
 *
 * A herb rendered in the iron palette because it grows on a mountain reads as
 * a mineral, which is exactly the confusion the botanical rule exists to end.
 * Which ground it came off is already on the card; what the icon has to say is
 * which bench wants it.
 */
export const HERB_FORMS = new Set<Form>([
  'mushroom', 'sprig', 'trefoil', 'root', 'rosette', 'sap', 'umbel',
])

/**
 * §4 — the alchemist's other stock, and the only things in the set with eyes.
 *
 * Critters take their own accent rather than the herbs' green, because the two
 * halves of the shelf are reached by different roads: a herb is gathered by
 * hand on any hex, a critter needs a bow and a live herd. An icon that could
 * not tell you which is which would hide the only thing worth knowing about an
 * ingredient before you go looking for it.
 */
export const CRITTER_FORMS = new Set<Form>(['moth', 'mite', 'hare', 'newt', 'bird'])


/**
 * §5.3 — where a material sits in its own three-grade ladder.
 *
 * The detail that separates Wood from Hardwood from Heartoak MEANS something,
 * so it cannot come off a hash of the key: that would put Heartoak's dark heart
 * on green timber about a third of the time, and leave Flax and Hemp identical
 * whenever their hashes agreed. Rank drives every difference that carries
 * information; the key seed is left to the differences that carry none.
 */
const GRADE_RANK: Record<string, number> = {
  wood: 0, hardwood: 1, heartoak: 2,
  iron_ore: 0, hematite: 1, meteoric_iron: 2,
  pelt: 0, thick_pelt: 1, dire_pelt: 2,
  fiber: 0, flax: 1, hemp: 2,

  planks: 0, beams: 1, bentwood: 2,
  ingots: 0, steel_ingots: 1, skysteel: 2,
  leather: 0, boiled_leather: 1, lacquered_hide: 2,
  cut_stone: 0, dressed_basalt: 1, polished_granite: 2,
  cloth: 0, linen: 1, canvas: 2,
}

export function gradeRank(key: string): number {
  return GRADE_RANK[key] ?? 0
}

/** Steady across renders: the same material always gets the same variation. */
export function keySeed(key: string): number {
  let h = 0
  for (let i = 0; i < key.length; i++) h = (h * 31 + key.charCodeAt(i)) >>> 0
  return h
}

// ------------------------------------------------------------------- shapes

const cut = (d: string, fill: string, dark: string, w = 1.15) =>
  `<path d="${d}" fill="${fill}" stroke="${dark}" stroke-width="${w}" stroke-linejoin="round"/>`

const line = (d: string, c: string, w = 1) =>
  `<path d="${d}" fill="none" stroke="${c}" stroke-width="${w}" stroke-linecap="round"/>`

const SHAPES: Record<Form, (ink: Ink, seed: number, grade: number) => string> = {
  // ---------------------------------------------------------------- tier 0
  twig: ({ fill, dark, light }) =>
    line('M8 31 L27 12', dark, 3.4) +
    line('M8 31 L27 12', fill, 1.8) +
    line('M18 21 L24 22', dark, 2.4) +
    line('M21 18 L20 11', light, 1.8),

  flake: ({ fill, dark, light }) =>
    cut('M9 24 L15 17 L19 22 L14 29 Z', fill, dark) +
    cut('M18 11 L26 14 L23 21 L16 18 Z', light, dark) +
    cut('M25 24 L31 26 L29 31 L24 29 Z', fill, dark),

  tatter: ({ fill, dark, light }) =>
    cut('M10 12 L22 9 L30 15 L27 27 L16 30 L9 23 L13 19 L8 16 Z', fill, dark) +
    line('M14 14 L20 20 L17 26', light, 1.1),

  splinter: ({ fill, dark, light }) =>
    cut('M13 30 L16 10 L20 9 L21 29 Z', light, dark) +
    cut('M22 26 L29 14 L31 16 L26 28 Z', fill, dark),

  pebbles: ({ fill, dark, light }) =>
    cut('M8 26 L12 20 L18 22 L17 29 L10 30 Z', fill, dark) +
    cut('M18 11 L25 10 L27 17 L20 19 Z', light, dark) +
    cut('M25 22 L31 23 L30 30 L24 29 Z', fill, dark),

  husk: ({ fill, dark, light }) =>
    line('M12 31 Q14 20 11 11', dark, 2) +
    line('M20 31 Q20 18 20 9', dark, 2) +
    line('M28 31 Q26 20 29 11', dark, 2) +
    line('M20 31 Q20 18 20 9', light, 0.9) +
    cut('M8 30 H32 L30 33 H10 Z', fill, dark),

  // ---------------------------------------------------- tier 1, the ground
  // A cut log, end on. The heartwood ring is what separates the three grades:
  // green timber has none, hardwood has a tight one, heartoak is dark through.
  log: ({ fill, dark, light }, _seed, grade) => {
    const heart = grade
    return (
      cut('M9 13 L26 9 L32 15 L31 27 L14 31 L8 25 Z', fill, dark) +
      cut('M9 13 L26 9 L32 15 L15 19 Z', light, dark) +
      (heart === 0 ? line('M17 22 Q22 21 27 23', dark, 0.9) : '') +
      (heart === 1
        ? `<ellipse cx="22" cy="24" rx="5" ry="3.4" fill="none" stroke="${dark}" stroke-width="0.9"/>`
        : '') +
      (heart === 2
        ? `<ellipse cx="22" cy="24" rx="4.6" ry="3.2" fill="${dark}"/>`
        : '')
    )
  },

  // Ore, in the rock. The inclusions are the grade: specks, a band, a crater.
  ore: ({ fill, dark, light }, _seed, grade) => {
    const kind = grade
    const body =
      cut('M9 25 L7 15 L15 8 L27 10 L32 20 L26 30 L14 31 Z', fill, dark, 1.2) +
      cut('M15 8 L27 10 L23 18 L12 17 Z', light, dark, 0.9)
    if (kind === 0) {
      return (
        body +
        `<circle cx="16" cy="24" r="1.7" fill="${dark}"/>` +
        `<circle cx="23" cy="26" r="1.2" fill="${dark}"/>` +
        `<circle cx="26" cy="20" r="1.4" fill="${dark}"/>`
      )
    }
    if (kind === 1) return body + line('M10 23 Q19 19 29 24', dark, 2.2)
    return (
      body +
      `<ellipse cx="21" cy="23" rx="6" ry="3.6" fill="${dark}"/>` +
      `<ellipse cx="21" cy="22" rx="3.4" ry="1.9" fill="${fill}"/>`
    )
  },

  // §4 -- a pelt is an animal, skinned and pegged out flat: head, four legs,
  // tail. It is NOT a rectangle with the corners knocked off. Cloth and leather
  // are panels; this is the one Tier 1 material that still has a shape of its
  // own, and drawing it as a panel loses the only thing the icon had to say.
  //
  // The grade rides on the outline: a plain skin, a winter coat, and one that
  // fought back.
  hide: ({ fill, dark, light }, _seed, grade) => {
    const body =
      cut(
        'M20 5 Q23 5 23.5 9 L29 10.5 Q32.5 11.5 31 14.5 L25.5 16 ' +
          'Q27 22 26.5 26 L31 29.5 Q33 32.5 30 33 L23.5 31 ' +
          'Q22 34.5 20 34.5 Q18 34.5 16.5 31 L10 33 ' +
          'Q7 32.5 9 29.5 L13.5 26 Q13 22 14.5 16 L9 14.5 ' +
          'Q7.5 11.5 11 10.5 L16.5 9 Q17 5 20 5 Z',
        fill,
        dark,
      ) +
      // The lit facet is the shoulder, which is where a stretched skin catches
      // the light. One facet only -- two would read as a gradient.
      cut('M16.5 9 Q20 12 23.5 9 L25.5 16 Q20 18 14.5 16 Z', light, dark, 0.85)

    // Winter coat: the pile shows at the edge.
    if (grade === 1) {
      return (
        body +
        line('M15 21 Q20 23.5 25 21 M15.5 25 Q20 27.5 24.5 25', dark, 1.15)
      )
    }
    // It had no natural enemies until you turned up.
    if (grade === 2) {
      return body + line('M16 19 L18.5 28 M20 18.5 L21 28.5 M24 19.5 L22.5 28', dark, 1.2)
    }
    return body
  },

  rubble: ({ fill, dark, light }) =>
    cut('M8 27 L11 16 L21 12 L31 17 L30 28 L19 31 Z', fill, dark, 1.2) +
    cut('M11 16 L21 12 L24 20 L13 23 Z', light, dark, 0.9),

  // Basalt splits in columns, and it splits along them.
  column: ({ fill, dark, light }) =>
    cut('M11 14 L16 11 L21 14 L21 30 L16 32 L11 30 Z', light, dark) +
    cut('M21 14 L26 11 L31 14 L31 27 L26 29 L21 27 Z', fill, dark) +
    line('M16 11 V32', dark, 0.8),

  slab: ({ fill, dark, light }, seed) =>
    cut('M7 22 L11 15 L31 13 L33 21 L29 28 L9 29 Z', fill, dark, 1.2) +
    cut('M11 15 L31 13 L33 21 L13 23 Z', light, dark, 0.9) +
    `<circle cx="${15 + (seed % 3)}" cy="26" r="1.1" fill="${dark}"/>` +
    `<circle cx="23" cy="25" r="0.9" fill="${dark}"/>` +
    `<circle cx="27" cy="19" r="1" fill="${dark}"/>`,

  // A tied bundle. Heads on the stalks say flax; height says hemp.
  sheaf: ({ fill, dark, light }, _seed, grade) => {
    const kind = grade
    const top = kind === 2 ? 6 : 9
    return (
      line(`M13 31 Q14 20 12 ${top + 2}`, dark, 1.9) +
      line(`M20 31 Q20 18 20 ${top}`, dark, 1.9) +
      line(`M27 31 Q26 20 28 ${top + 2}`, dark, 1.9) +
      line(`M20 31 Q20 18 20 ${top}`, light, 0.9) +
      (kind === 1
        ? `<circle cx="12" cy="${top + 1}" r="1.8" fill="${light}"/>` +
          `<circle cx="20" cy="${top - 1}" r="2" fill="${light}"/>` +
          `<circle cx="28" cy="${top + 1}" r="1.8" fill="${light}"/>`
        : '') +
      cut('M10 24 H30 V28 H10 Z', fill, dark, 1)
    )
  },

  // ------------------------------------------------- tier 1, the herbalist
  mushroom: ({ fill, dark, light }, seed) =>
    cut('M18 22 Q17 29 15 32 H25 Q23 29 22 22 Z', light, dark) +
    cut('M7 22 Q9 9 20 9 Q31 9 33 22 Q20 26 7 22 Z', fill, dark) +
    `<circle cx="${14 + (seed % 3)}" cy="16" r="1.6" fill="${light}"/>` +
    `<circle cx="25" cy="18" r="1.2" fill="${light}"/>`,

  sprig: ({ fill, dark, light }) =>
    line('M20 32 Q19 20 21 9', dark, 1.9) +
    cut('M20 24 Q11 23 10 15 Q19 15 20 24 Z', fill, dark) +
    cut('M21 19 Q30 18 31 11 Q22 11 21 19 Z', light, dark) +
    cut('M20 15 Q13 13 13 7 Q20 8 20 15 Z', fill, dark),

  trefoil: ({ fill, dark, light }) =>
    line('M20 32 Q19 24 20 18', dark, 1.8) +
    `<circle cx="14" cy="14" r="6" fill="${fill}" stroke="${dark}" stroke-width="1.1"/>` +
    `<circle cx="26" cy="14" r="6" fill="${fill}" stroke="${dark}" stroke-width="1.1"/>` +
    `<circle cx="20" cy="22" r="6" fill="${light}" stroke="${dark}" stroke-width="1.1"/>`,

  root: ({ fill, dark, light }) =>
    cut('M15 8 Q20 6 25 8 L23 20 Q20 33 17 20 Z', fill, dark) +
    line('M19 22 Q13 24 11 30', dark, 1.5) +
    line('M21 24 Q27 26 29 31', dark, 1.5) +
    line('M17 11 Q20 15 23 11', light, 1),

  rosette: ({ fill, dark, light }) =>
    cut('M20 10 Q27 12 26 20 Q20 24 14 20 Q13 12 20 10 Z', fill, dark) +
    cut('M10 22 Q17 20 20 24 Q16 30 10 28 Z', light, dark) +
    cut('M30 22 Q23 20 20 24 Q24 30 30 28 Z', fill, dark) +
    line('M20 14 V22', dark, 0.9),

  sap: ({ fill, dark, light }) =>
    cut('M11 7 H17 V33 H11 Z', light, dark) +
    line('M14 11 H14.01 M14 17 H14.01 M14 23 H14.01', dark, 2.2) +
    cut('M25 14 Q31 22 25 26 Q19 22 25 14 Z', fill, dark) +
    line('M23 19 Q23 22 25 24', light, 1),

  umbel: ({ fill, dark, light }) =>
    line('M20 32 V20', dark, 1.9) +
    line('M20 22 L12 17 M20 22 L28 17 M20 22 L20 16', dark, 1.1) +
    `<ellipse cx="12" cy="15" rx="4.5" ry="2.6" fill="${fill}" stroke="${dark}" stroke-width="1"/>` +
    `<ellipse cx="28" cy="15" rx="4.5" ry="2.6" fill="${fill}" stroke="${dark}" stroke-width="1"/>` +
    `<ellipse cx="20" cy="12" rx="5.2" ry="3" fill="${light}" stroke="${dark}" stroke-width="1"/>`,


  // ------------------------------------- tier 1, the alchemist's second stock
  // Every one of these is drawn side-on and whole, because a specimen plate
  // shows the animal rather than the part you use. They are also the only
  // shapes in the set with an eye, which is most of what makes them read as
  // alive next to sixty inert lumps.
  moth: ({ fill, dark, light }) =>
    cut('M19 12 Q9 6 6 14 Q5 22 17 21 Z', light, dark) +
    cut('M21 12 Q31 6 34 14 Q35 22 23 21 Z', fill, dark) +
    cut('M19 21 Q9 24 8 30 Q14 33 19 27 Z', fill, dark, 1) +
    cut('M21 21 Q31 24 32 30 Q26 33 21 27 Z', light, dark, 1) +
    cut('M18.5 10 H21.5 L21 28 H19 Z', dark, dark, 0.8) +
    line('M19 10 L15 5 M21 10 L25 5', dark, 1.1),

  mite: ({ fill, dark, light }) =>
    `<ellipse cx="20" cy="21" rx="10" ry="8" fill="${fill}" stroke="${dark}" stroke-width="1.15"/>` +
    `<ellipse cx="20" cy="16" rx="6" ry="4.5" fill="${light}" stroke="${dark}" stroke-width="1"/>` +
    line('M11 17 L5 13 M11 24 L5 27 M29 17 L35 13 M29 24 L35 27', dark, 1.4) +
    line('M17 12 L14 7 M23 12 L26 7', dark, 1.2) +
    `<circle cx="18" cy="16" r="1" fill="${dark}"/>`,

  hare: ({ fill, dark, light }) =>
    cut('M11 15 Q9 6 13 5 Q17 6 15 16 Z', light, dark, 1) +
    cut('M18 15 Q19 6 23 6 Q26 8 22 16 Z', fill, dark, 1) +
    cut('M16 14 Q26 14 27 22 Q28 31 18 31 Q9 31 9 23 Q9 15 16 14 Z', fill, dark) +
    `<circle cx="13" cy="21" r="1.2" fill="${dark}"/>` +
    line('M9 26 Q5 27 4 24', dark, 1.2) +
    cut('M27 24 Q32 23 32 28 Q28 29 26 27 Z', light, dark, 1),

  newt: ({ fill, dark, light }) =>
    cut('M8 20 Q14 13 22 15 Q30 17 31 22 Q30 27 22 27 Q14 28 8 20 Z', fill, dark) +
    cut('M8 20 Q14 13 22 15 Q22 19 20 21 Q13 22 8 20 Z', light, dark, 0.85) +
    line('M31 22 Q36 20 35 15', dark, 2.2) +
    line('M13 26 L11 31 M19 27 L18 32 M14 15 L12 10 M20 15 L20 10', dark, 1.3) +
    `<circle cx="12" cy="19" r="1.1" fill="${dark}"/>`,

  bird: ({ fill, dark, light }) =>
    cut('M14 12 Q23 10 27 17 Q31 25 24 30 Q15 33 11 26 Q8 18 14 12 Z', fill, dark) +
    cut('M16 17 Q24 17 26 24 Q20 27 15 23 Z', light, dark, 0.9) +
    cut('M13 11 Q11 5 16 4 Q20 5 19 11 Z', fill, dark, 1) +
    line('M11 8 L5 9', dark, 2) +
    line('M22 30 L24 34 M17 31 L17 35', dark, 1.3) +
    `<circle cx="15" cy="9" r="1" fill="${dark}"/>`,

  // ------------------------------------ tier 1, the smith and the armorer
  knot: ({ fill, dark, light }) =>
    cut('M8 20 Q8 10 20 10 Q32 10 32 20 Q32 30 20 30 Q8 30 8 20 Z', fill, dark) +
    `<ellipse cx="20" cy="20" rx="6.5" ry="5" fill="${light}" stroke="${dark}" stroke-width="1"/>` +
    `<ellipse cx="20" cy="20" rx="2.4" ry="1.8" fill="${dark}"/>`,

  resin: ({ fill, dark, light }) =>
    cut('M11 8 H16 V32 H11 Z', light, dark) +
    cut('M22 10 Q29 20 22 25 Q15 20 22 10 Z', fill, dark) +
    cut('M26 26 Q30 31 26 33 Q22 31 26 26 Z', fill, dark),

  salt: ({ fill, dark, light }) =>
    cut('M20 8 L28 16 L20 24 L12 16 Z', light, dark) +
    cut('M13 22 L19 28 L13 33 L8 28 Z', fill, dark) +
    cut('M28 22 L33 27 L28 32 L23 27 Z', fill, dark),

  scale: ({ fill, dark, light }) =>
    cut('M20 8 Q28 11 28 18 Q20 22 12 18 Q12 11 20 8 Z', light, dark) +
    cut('M13 20 Q20 23 20 30 Q13 33 7 30 Q7 23 13 20 Z', fill, dark) +
    cut('M27 20 Q33 23 33 30 Q27 33 20 30 Q20 23 27 20 Z', fill, dark),

  horn: ({ fill, dark, light }) =>
    cut('M9 31 Q9 12 26 8 Q31 8 31 12 Q19 16 16 31 Z', fill, dark) +
    line('M13 28 Q15 17 25 12', light, 1.2),

  cord: ({ fill, dark, light }) =>
    line('M9 12 Q20 20 9 28', dark, 3.4) +
    line('M9 12 Q20 20 9 28', fill, 1.8) +
    line('M20 12 Q31 20 20 28', dark, 3.4) +
    line('M20 12 Q31 20 20 28', light, 1.8),

  grit: ({ fill, dark, light }) =>
    cut('M7 27 L13 21 L19 27 L13 32 Z', fill, dark, 1) +
    cut('M17 14 L24 9 L30 15 L23 20 Z', light, dark, 1) +
    cut('M24 24 L30 21 L33 27 L27 30 Z', fill, dark, 1) +
    `<circle cx="10" cy="15" r="1.4" fill="${dark}"/>`,

  seep: ({ fill, dark, light }) =>
    `<ellipse cx="20" cy="27" rx="13" ry="6" fill="${fill}" stroke="${dark}" stroke-width="1.15"/>` +
    `<ellipse cx="17" cy="25" rx="4.5" ry="2" fill="${light}"/>` +
    cut('M22 8 Q27 16 22 20 Q17 16 22 8 Z', fill, dark),

  reed: ({ fill, dark, light }) =>
    line('M11 32 Q13 19 11 8', dark, 2.2) +
    line('M20 32 Q20 18 20 6', dark, 2.2) +
    line('M29 32 Q27 19 29 8', dark, 2.2) +
    line('M20 32 Q20 18 20 6', light, 1) +
    cut('M17 12 Q20 6 23 12 Q20 16 17 12 Z', fill, dark, 1),

  wax: ({ fill, dark, light }) =>
    cut('M20 9 L28 13.5 L28 22.5 L20 27 L12 22.5 L12 13.5 Z', fill, dark) +
    cut('M20 9 L28 13.5 L20 18 L12 13.5 Z', light, dark, 0.9) +
    cut('M20 22 L26 25.5 L26 30 L20 33 L14 30 L14 25.5 Z', fill, dark, 1),

  // ---------------------------------------------------------------- tier 2
  planks: ({ fill, dark, light }, _seed, grade) => {
    // Bentwood is the third grade and the only one that is not straight.
    if (grade === 2) {
      return (
        cut('M8 30 Q8 10 32 12 L32 18 Q14 17 14 30 Z', fill, dark) +
        line('M11 28 Q11 14 29 15', light, 1.1)
      )
    }
    const deep = grade === 1 ? 2 : 0
    return (
      cut(`M6 ${23 - deep} H34 V${29 + deep} H6 Z`, fill, dark, 1.1) +
      cut(`M6 ${14 - deep} H34 V${21 - deep} H6 Z`, light, dark, 1.1) +
      cut(`M10 ${7 - deep} H30 V${12 - deep} H10 Z`, fill, dark, 1.1)
    )
  },

  // Cast bars. A struck mark on the face is the grade: none, one, two.
  ingot: ({ fill, dark, light }, _seed, grade) =>
    cut('M7 24 L11 19 H29 L33 24 L29 29 H11 Z', fill, dark, 1.1) +
    cut('M11 13 L14 9 H26 L29 13 L26 18 H14 Z', light, dark, 1.1) +
    (grade >= 1 ? line('M15 25 H25', dark, 1.2) : '') +
    (grade === 2 ? line('M16 13.5 H24', dark, 1.1) : ''),

  // Tanned and cut to a sheet, but still a skin: one straight cut edge and
  // three that are not. That irregularity is the whole difference from `bolt`,
  // which is a woven roll -- draw both as soft rectangles and leather becomes
  // cloth again one tier up.
  leather: ({ fill, dark, light }, _seed, grade) =>
    cut('M8 13 Q13 8 21 8.5 Q31 9 32 16 Q33 25 26 29 Q17 33 11 28 Q6 23 8 13 Z', fill, dark) +
    cut('M8 13 Q13 8 21 8.5 Q31 9 32 16 Q20 19 9 17 Z', light, dark, 0.85) +
    // Boiled leather sets to a shell; lacquered takes a sheen on top of that.
    (grade >= 1 ? line('M12 23 Q20 27 27 22', dark, 1.2) : '') +
    (grade === 2 ? line('M14 12 Q20 14 26 12', light, 1.2) : ''),

  // Dressed blocks. The finish is the grade: sawn, squared, ground smooth.
  cutstone: ({ fill, dark, light }, _seed, grade) =>
    cut('M8 21 L14 16 H32 L32 26 L26 31 H8 Z', fill, dark, 1.15) +
    cut('M8 21 L14 16 H32 L26 21 Z', light, dark, 0.9) +
    line('M26 21 V31', dark, 0.9) +
    cut('M13 8 H31 V15 H13 Z', light, dark, 1) +
    (grade >= 1 ? line('M11 26 H24', dark, 0.9) : '') +
    (grade === 2 ? line('M16 18.5 H29', light, 1) : ''),

  // A wound bolt, seen end on: the roll and the spiral in its cut face. Cloth
  // is the only one of the three that is manufactured rather than skinned, so
  // it is the only one drawn as a regular solid.
  bolt: ({ fill, dark, light }, _seed, grade) =>
    cut('M13 9 H31 L31 28 H13 Z', fill, dark) +
    `<ellipse cx="13" cy="18.5" rx="5" ry="9.5" fill="${light}" stroke="${dark}" stroke-width="1.15"/>` +
    line('M13 12 Q16.5 15 13 18.5 Q9.5 22 13 25', dark, 1) +
    // The weave tightens with the grade.
    (grade >= 1 ? line('M19 9.5 V27.5', dark, 0.85) : '') +
    (grade === 2 ? line('M25 9.5 V27.5', dark, 0.85) : '') +
    // The loose end hanging off the roll, which is what says bolt and not pipe.
    cut('M31 24 L35 26 L34 31 L31 28 Z', fill, dark, 1),

  frame: ({ fill, dark, light }) =>
    cut('M8 12 H32 V28 H8 Z', fill, dark, 1.2) +
    line('M8 17 H32 M8 23 H32', dark, 1.6) +
    cut('M17 8 H23 V32 H17 Z', light, dark, 1.1),

  // ----------------------------------------------------- tier 3 / 4, lit
  // These keep the gradient. It is the one thing the flat tiers never get, so
  // "lit from within" reads as rarity with no frame doing the work.
  crystal: ({ dark, light }, seed) => {
    const id = `f${seed % 9973}c`
    return (
      `<defs><linearGradient id="${id}" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0%" stop-color="${light}"/><stop offset="100%" stop-color="${dark}"/>
      </linearGradient></defs>` +
      cut('M20 4 L31 15 L26 33 L14 33 L9 15 Z', `url(#${id})`, light, 1.2) +
      line('M20 4 V33 M9 15 H31', light, 0.7)
    )
  },

  mote: ({ dark, light }, seed) => {
    const id = `f${seed % 9973}m`
    return (
      `<defs><radialGradient id="${id}">
        <stop offset="0%" stop-color="${light}"/><stop offset="100%" stop-color="${dark}"/>
      </radialGradient></defs>` +
      `<circle cx="20" cy="20" r="8.5" fill="url(#${id})"/>` +
      line('M20 6 V11 M20 29 V34 M6 20 H11 M29 20 H34', light, 1.3)
    )
  },

  shard: ({ dark, light }, seed) => {
    const id = `f${seed % 9973}s`
    return (
      `<defs><linearGradient id="${id}" x1="0" y1="0" x2="0.6" y2="1">
        <stop offset="0%" stop-color="${light}"/><stop offset="100%" stop-color="${dark}"/>
      </linearGradient></defs>` +
      cut('M22 4 L31 18 L20 35 L11 20 Z', `url(#${id})`, light, 1.1) +
      line('M22 4 L20 35', light, 0.7)
    )
  },

  relic: ({ dark, light }, seed) => {
    const id = `f${seed % 9973}r`
    return (
      `<defs><linearGradient id="${id}" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0%" stop-color="${light}"/><stop offset="100%" stop-color="${dark}"/>
      </linearGradient></defs>` +
      cut('M13 9 H27 L30 20 L20 33 L10 20 Z', `url(#${id})`, light, 1.2) +
      line('M13 9 L20 20 L27 9 M10 20 H30', light, 0.8)
    )
  },

  core: ({ dark, light }, seed) => {
    const id = `f${seed % 9973}k`
    return (
      `<defs><radialGradient id="${id}">
        <stop offset="0%" stop-color="${light}"/><stop offset="100%" stop-color="${dark}"/>
      </radialGradient></defs>` +
      cut('M20 5 L31 12 V27 L20 34 L9 27 V12 Z', `url(#${id})`, light, 1.2) +
      cut('M20 12 L26 15.5 V23 L20 26.5 L14 23 V15.5 Z', light, light, 0.9) +
      line('M20 5 V12 M20 27 V34 M9 12 L14 15.5 M31 12 L26 15.5', light, 0.8)
    )
  },
}

export function formShape(form: Form, ink: Ink, seed: number, grade: number): string {
  return SHAPES[form](ink, seed, grade)
}
