/**
 * §5.2 / §5.3 -- what to call the ground under a hex.
 *
 * One answer for the three ways a hex can be named after its terrain rather
 * than after a place: water, dead ground, and ordinary country. It exists
 * because the tile card and the dock were each deriving this, and each of them
 * knew about two of the three -- so a waste was called "Forest" in both.
 *
 * Client-side only, like the water names beside it: the server owns whether a
 * hex is dead and the client owns what a prospector calls it.
 */
import { VARIANT_LABEL } from './variants'
import { waterLabel } from './water'
import type { Biome, Tile } from './types'

/**
 * §5.2 -- dead ground, per biome, for the same reason water is per biome: a
 * dead forest and a dead mountain are not the same place, and a map that called
 * both "Dead Ground" would be throwing that away.
 *
 * Each names what you would actually be standing in. None of them says "dead":
 * the card has a sentence for that, and a name doing the sentence's job twice
 * is how a label stops being a name.
 */
export const DEAD_LABEL: Record<Biome, string> = {
  forest: 'Deadwood',
  mountain: 'Scree',
  plains: 'Dust Flat',
  badlands: 'Hardpan',
  grassland: 'Stubble',
}

/**
 * The name of the ground itself -- never a settlement's, never a dungeon's.
 *
 * `asDead` forces the dead name for ground the player has not scouted (§5.2).
 * Out there a live hex and a dead one have to read alike, or the card gives
 * away what the map is holding back -- and naming the variant was the loudest
 * possible way to give it away, since "Ironwood Grove" says both that there is
 * a seam and exactly how good it is.
 */
export function groundLabel(tile: Tile, asDead = false): string {
  if (tile.water) return waterLabel(tile.biome, tile.water)
  if (tile.dead || asDead) return DEAD_LABEL[tile.biome]

  return VARIANT_LABEL[tile.variant]
}
