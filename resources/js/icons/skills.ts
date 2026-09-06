/**
 * §9.5.9 + §13 -- a mark for each of the nine battle skills.
 *
 * Procedural like everything else, and built the same way §13.1 builds
 * equipment: ONE axis carries the meaning and the rest is shared. Here the axis
 * is the stroke path -- every glyph is the same weight, the same box and the
 * same cap, so a player reads *which* skill went off from the shape alone and
 * never from a colour that is also saying something else.
 *
 * They are drawn as strokes rather than fills because they appear at 18px over
 * a draining pool (BattleLive) where a filled mark would read as a blob. A line
 * survives that; a silhouette does not.
 *
 * Colour is passed in by the caller as `currentColor`, so the modal can tint
 * the mark with whatever the round it landed on is already saying -- §13.3
 * keeps ember for a state to deal with and sap for a thing worth crossing the
 * screen for, and a skill firing is neither. It borrows.
 */

/** The nine paths, keyed by the `glyph` on BattleSkills::SKILLS. */
const PATHS: Record<string, string> = {
  // ------------------------------------------------------------------ shield
  /** Shield Bash -- a shield driven forward, and the shock coming off it. */
  bash: 'M3.5 6 L8.5 4 L13.5 6 v6 c0 3.6 -2.8 5.6 -5 7 c-2.2 -1.4 -5 -3.4 -5 -7 Z M16.5 9 h4.5 M17.5 12.5 h4 M16.5 16 h4.5',
  /** Anvil Stance -- an anvil: horn, waist, foot. Nothing else in the set is squat. */
  anvil: 'M3 9 h12 l5 2.5 -5 1.5 H3 Z M8.5 13 l-1 4 h9 l-1 -4 M6.5 20.5 h11',
  /** Warden's Toll -- a bell, because the toll is what your guard costs it. */
  toll: 'M12 4 a5 5 0 0 1 5 5 v4 l2 3 H5 l2 -3 V9 a5 5 0 0 1 5 -5 Z M10 16 a2 2 0 0 0 4 0',

  // ------------------------------------------------------------------- sword
  /** Onslaught -- two, and nothing reads "twice" faster than a double chevron. */
  onslaught: 'M6 5 l6.5 7 l-6.5 7 M13 5 l6.5 7 l-6.5 7',
  /** Sunder -- driven down through a line, and the halves stay bent away. */
  sunder: 'M12 2.5 v9 M9.5 8 l2.5 3.5 l2.5 -3.5 M2.5 14.5 h6 l-1.5 3 M21.5 14.5 h-6 l1.5 3',
  /** Riposte -- it comes straight back the way it came. */
  riposte: 'M8 7 h6 a5.5 5.5 0 0 1 0 11 H7 M10.5 4 L7 7 l3.5 3',

  // ------------------------------------------------------------------ dagger
  /** Bleeding Cut -- a blade gone in, and what runs off it afterwards. */
  bleed: 'M8 3 L11 6.5 L11 15 L8 18 L5 15 L5 6.5 Z M8 18 v3 M16 8 c2.5 2.5 4 4.3 4 6 a4 4 0 0 1 -8 0 c0 -1.7 1.5 -3.5 4 -6 Z',
  /** Rising Flurry -- three cuts, each longer than the one before it. */
  flurry: 'M4 18.5 L9 13.5 M6.5 19.5 L14.5 11.5 M9 20.5 L20 9.5 M20 9.5 l0.5 -5 l-5 0.5',
  /** Hamstring -- a leg bent at the knee, and the cut taken across the back of it. */
  hamstring: 'M8.5 3 v7 a4 4 0 0 0 4 4 h1.5 M14 14 l-3 6.5 M4 12.5 L19.5 7',
}


/** A skill's mark, sized in pixels. Falls back to a plain spark. */
export function skillGlyph(glyph: string | undefined, size = 18): string {
  const d = (glyph && PATHS[glyph]) ?? 'M12 4 v7 M12 15 v1 M6 8 l3 3 M18 8 l-3 3'

  return `<svg viewBox="0 0 24 24" width="${size}" height="${size}" fill="none"
    stroke="currentColor" stroke-width="1.9" stroke-linecap="round"
    stroke-linejoin="round" aria-hidden="true"><path d="${d}"/></svg>`
}

/**
 * §9.5.9 -- what a round did, in the same words the tooltip used.
 *
 * The genre's rule, applied to a place the genre does not have: a combat log
 * line and the tooltip that promised it should use ONE vocabulary. If the
 * tooltip says `Stun: 2 rounds`, the round it lands says `Stun 2 rounds` --
 * not "held", not "stopped". A player should never have to work out that two
 * words are the same mechanic.
 *
 * Read off the round the server already stored rather than recomputed, because
 * the fight is settled the instant you close (§9.5.5) and the modal is a
 * replay of it. Every branch names a NUMBER: that a blow went missing is
 * already visible, and how long it will keep going is not.
 */
export function skillEffect(entry: {
  stunned?: number
  burn?: number
  extra?: number
  riposte?: number
  sunder?: number
  kept?: number
  released?: number
  toll?: number
}): string | null {
  const rounds = (n: number) => `${n} round${n === 1 ? '' : 's'}`

  if (entry.released !== undefined) return `Returned ${entry.released}`
  if (entry.stunned !== undefined) return `Stun ${rounds(entry.stunned)}`
  if (entry.sunder !== undefined) return `Guard −${entry.sunder}`
  if (entry.riposte !== undefined) return `Answered ${entry.riposte}`
  if (entry.extra !== undefined) return `Second strike ${entry.extra}`
  if (entry.toll !== undefined) return `Damage +${entry.toll}`
  if (entry.kept !== undefined) return `Stored ${entry.kept}`
  if (entry.burn !== undefined) return `Burn ${entry.burn}`

  return null
}
