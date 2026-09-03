/**
 * §5.3 -- what to call a stretch of water.
 *
 * Client-side only: the server owns whether a hex is water and which of the
 * two it is, and that is all it owns. What the place is *called* is a label,
 * and a label the server sent would be one more string on every tile for no
 * rule to read.
 *
 * Named per biome for the same reason the surface is drawn per biome: a lake
 * in the mountains and a lake in the badlands are not the same body of water,
 * and a map that called both "Lake" would be throwing that away. The names are
 * what a prospector would say, not what a cartographer would write.
 */
import type { Biome, WaterKind } from './types'

export const WATER_LABEL: Record<WaterKind, Record<Biome, string>> = {
  river: {
    forest: 'Brook',
    mountain: 'Rapids',
    badlands: 'Wash',
    grassland: 'Stream',
  },
  lake: {
    forest: 'Forest Pool',
    mountain: 'Tarn',
    badlands: 'Alkali Pan',
    grassland: 'Mere',
  },
}

export const waterLabel = (biome: Biome, kind: WaterKind): string =>
  WATER_LABEL[kind][biome]
