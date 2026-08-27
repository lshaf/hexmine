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
 * `unscouted` names the hex for its BIOME and stops there (§5.2). From a
 * distance you can see that there is forest over the hill; you cannot see
 * whether the stand is worth cutting, and you cannot see whether it is dead.
 * So a fogged Ironwood Grove, a fogged plain forest and a fogged Deadwood all
 * read "Forest", and every one of them resolves on arrival.
 *
 * Naming the variant was the loudest possible way to give the game away --
 * "Ironwood Grove" says both that there is a seam and exactly how good it is.
 * Naming it for dead ground hid that just as well and told a small lie to do
 * it: the card asserted Deadwood over ground that turned out to be living.
 * The biome is the one answer that is true at any distance.
 */
export function groundLabel(tile: Tile, unscouted = false): string {
  if (tile.water) return waterLabel(tile.biome, tile.water)
  if (unscouted) return VARIANT_LABEL[tile.biome]
  if (tile.dead) return DEAD_LABEL[tile.biome]

  return VARIANT_LABEL[tile.variant]
}
