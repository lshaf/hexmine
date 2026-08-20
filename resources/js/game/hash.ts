/**
 * Deterministic 32-bit hashing.
 *
 * The client no longer generates terrain -- the server owns the world (§5, §16)
 * and sends tiles down. What is still needed here is a stable per-tile random
 * source for *cosmetics*: which trees a forest hex grows, where the rocks sit.
 * Those must not reshuffle on every render, and they must not need a round trip.
 *
 * This mirrors `app/Game/Hash.php`, whose PHP port carries the explicit 32-bit
 * masking needed to reproduce JavaScript's Math.imul / >>> semantics exactly.
 */

/** 32-bit integer hash. Deterministic -- no Math.random anywhere near the map. */
export function hash2(x: number, y: number, seed: number): number {
  let h = seed ^ Math.imul(x | 0, 0x27d4eb2d) ^ Math.imul(y | 0, 0x165667b1)
  h = Math.imul(h ^ (h >>> 15), 0x2c1b3c6d)
  h = Math.imul(h ^ (h >>> 12), 0x297a2d39)
  h ^= h >>> 15
  return h >>> 0
}

/** Hash -> [0,1). */
export const rand01 = (h: number): number => h / 0x1_0000_0000

/** Hash -> integer in [min,max]. */
export const randInt = (h: number, min: number, max: number): number =>
  min + Math.floor(rand01(h) * (max - min + 1))
