/**
 * §5.5 -- the hunt. What mining a plains hex used to be.
 *
 * Animals stand on forest and grassland, on **a share** of it, and move on the
 * pack's own two-hour bucket. Killing one clears it exactly as fighting a pack
 * clears that.
 *
 * **A share rather than all of it.** One on every workable hex made the hunt a
 * property of the ground -- walk onto forest, hunt -- which is the plains biome
 * again under another name. A chance is what makes finding game a thing you do
 * rather than a thing that is true. It is a higher share than a pack's, because a
 * pack is a hazard the map is better for being sparse with and this is a whole
 * gathering line's faucet.
 *
 * **It does not pin.** A pack owns the hex it stands on (§9.5.3), which is a
 * hazard a player walks around; an animal doing the same would fence off the
 * country its line is worked in. It is a hook on a hex that otherwise works
 * normally -- the standing §9.5.7 gives a corpse.
 *
 * **The GRADE is the ladder, and it is the plains variant table moved onto the
 * creature.** A hex used to carry Plains / Herd Range / Dire Range / Beastfang
 * Reach; the animal carries it now, on the same weights -- which is what keeps
 * Beastfang Hide contested-only without a rule of its own (§2: a Tier 3 on the
 * safe rim is the grind->NFT path the threat model exists to close).
 *
 * **Two countries of four**, one animal per grade. A roster shared between them
 * would make forest and grassland the same walk, and §9.5.2 has just finished
 * making that argument about monsters.
 */
import type { Animal, Material, Recipe } from './types'

export const HUNT_BIOMES = ['forest', 'grassland'] as const

export const HUNT_GRADES: Array<{ grade: string; material: string; weights: Record<string, number> }> = [
  { grade: 'common', material: 'pelt', weights: { outer: 0.975, mid: 0.68, inner: 0.42 } },
  { grade: 'uncommon', material: 'thick_pelt', weights: { outer: 0.02, mid: 0.3, inner: 0.25 } },
  { grade: 'rare', material: 'dire_pelt', weights: { outer: 0.005, mid: 0.02, inner: 0.15 } },
  { grade: 'contested', material: 'beastfang_hide', weights: { outer: 0.0, mid: 0.0, inner: 0.18 } },
]

export const ANIMALS: Record<string, Animal> = {
  roe_deer: { key: 'roe_deer', name: 'Roe Deer', biome: 'forest', grade: 'common', material: 'pelt', description: 'Feeding at the edge of the trees, and gone into them the moment you are seen.' },
  wood_boar: { key: 'wood_boar', name: 'Wood Boar', biome: 'forest', grade: 'uncommon', material: 'thick_pelt', description: 'Rooting under the mast. It has no reason to run and knows it.' },
  bracken_elk: { key: 'bracken_elk', name: 'Bracken Elk', biome: 'forest', grade: 'rare', material: 'dire_pelt', description: 'Shoulder-high in the fern with a rack it has to turn sideways to walk.' },
  ironhide_stag: { key: 'ironhide_stag', name: 'Ironhide Stag', biome: 'forest', grade: 'contested', material: 'beastfang_hide', description: 'Nothing in the wood has taken one down in living memory. You may try.' },
  field_doe: { key: 'field_doe', name: 'Field Doe', biome: 'grassland', grade: 'common', material: 'pelt', description: 'Standing in the seed heads with its ears up, which is how you find it.' },
  horned_ram: { key: 'horned_ram', name: 'Horned Ram', biome: 'grassland', grade: 'uncommon', material: 'thick_pelt', description: 'Comes down the slope at you rather than away, every time.' },
  sedge_auroch: { key: 'sedge_auroch', name: 'Sedge Auroch', biome: 'grassland', grade: 'rare', material: 'dire_pelt', description: 'Older than the settlement it grazes past, and heavier than the gate.' },
  beastfang_sire: { key: 'beastfang_sire', name: 'Beastfang Sire', biome: 'grassland', grade: 'contested', material: 'beastfang_hide', description: 'The hide is named for it. So is most of what it has eaten.' },
}

export const HUNT_RAW: Material[] = [
  { key: 'pelt', name: 'Pelt', tier: 1, source: 'hunt', palette: 'pelt', npcPrice: 3, description: 'Rough hide, taken off something that was using it.' },
  { key: 'thick_pelt', name: 'Thick Pelt', tier: 1, source: 'hunt', palette: 'pelt', npcPrice: 5, description: 'Winter coat off a full-grown animal. Heavy, and it keeps its shape.' },
  { key: 'dire_pelt', name: 'Dire Pelt', tier: 1, source: 'hunt', palette: 'pelt', npcPrice: 9, description: 'Off something that had no natural enemies until you turned up.' },
  { key: 'beastfang_hide', name: 'Beastfang Hide', tier: 1, source: 'hunt', palette: 'pelt', npcPrice: 0, description: 'Taken off something that fought back.' },
]

export const HUNT_REFINED: Material[] = [
  { key: 'leather', name: 'Leather', tier: 2, palette: 'pelt', npcPrice: 8, description: 'Scraped, soaked and worked soft. The first thing a tannery is for.' },
  { key: 'boiled_leather', name: 'Boiled Leather', tier: 2, palette: 'pelt', npcPrice: 16, description: 'Boiled hard and molded wet. Sets like a shell and weighs nothing.' },
  { key: 'lacquered_hide', name: 'Lacquered Hide', tier: 2, palette: 'pelt', npcPrice: 30, description: 'Layered, lacquered, and left in the dark to cure. Turns a blade.' },
]

export const HUNT_PROCESSING: Recipe[] = [
  { key: 'tan_leather', name: 'Tan Leather', input: 'pelt', inputQty: 3, output: 'leather', outputQty: 1, baseSeconds: 13 * 60, skill: 'hunting' },
  { key: 'tan_boiled_leather', name: 'Tan Boiled Leather', input: 'thick_pelt', inputQty: 3, output: 'boiled_leather', outputQty: 1, baseSeconds: 17 * 60, skill: 'hunting' },
  { key: 'tan_lacquered_hide', name: 'Tan Lacquered Hide', input: 'dire_pelt', inputQty: 3, output: 'lacquered_hide', outputQty: 1, baseSeconds: 24 * 60, skill: 'hunting' },
]

/** §5.5 -- what a kill gives up beside the hide. */
export const HUNT_PARTS = ['horn', 'sinew', 'bitterroot', 'yarrow', 'dustleveret'] as const

/** §5.5 -- the part only an uncommon animal or better gives up. */
export const HUNT_GRADED_PART = 'braided_sinew'

export const HUNT_GRADED_FROM = 'uncommon'

/** §4 -- the tier-0 rubbish carried out alongside, every time. */
export const HUNT_JUNK = 'bone_splinter'

/** §9.5.8 -- the tier-0 leaving that says where the kill happened. */
export const HUNT_LEAVING = 'matted_turf'

/** The two that are neither hide nor ladder: a graded part and a leaving. */
export const HUNT_EXTRA: Material[] = [
  { key: 'braided_sinew', name: 'Braided Sinew', tier: 1, source: 'hunt', palette: 'pelt', npcPrice: 8, description: 'Laid down in cords by a thing that ran every day of its life. It will not part.' },
  { key: 'matted_turf', name: 'Matted Turf', tier: 0, source: 'hunt', palette: 'stone', npcPrice: 1, description: 'Torn up where it was braced against you. Roots, dirt, and nothing else.' },
]

export const ANIMAL_BY_BIOME_GRADE: Record<string, Record<string, string>> = {
  forest: {
    common: 'roe_deer',
    uncommon: 'wood_boar',
    rare: 'bracken_elk',
    contested: 'ironhide_stag',
  },
  grassland: {
    common: 'field_doe',
    uncommon: 'horned_ram',
    rare: 'sedge_auroch',
    contested: 'beastfang_sire',
  },
}
