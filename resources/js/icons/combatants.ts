/**
 * §9.5 -- the two faces of a fight, drawn rather than named.
 *
 * The exchange used to be two labelled bars: "Barrow Knight" over one and "Your
 * kit" over the other. That is a table of numbers with a heading, and it asks a
 * player to read their way into a fight they did not choose. A face is read
 * before a word is.
 *
 * Built on §13.1's rule and nothing new: one axis owns the SHAPE and another
 * owns the COLOR, so the set grows by picking rather than by drawing.
 *
 *   monster:  profile -> silhouette      tier -> palette
 *   fighter:  weapon family -> stance    pool -> palette
 *
 * Three monster shapes and four palettes cover all eight of §9.5.2, the same
 * way nine slots and three treatments covered two hundred items. Adding a
 * monster costs a row in the roster.
 *
 * The eyes are the pack glyph's own amber (map/props.ts). That is deliberate
 * rather than a coincidence: the thing you met on the map has to be the thing
 * in the modal, or the modal is about somebody else's fight.
 */
import { shade } from '@/theme/palette'

const VIEW = 40
const C = VIEW / 2

let crestSeq = 0
const nextId = () => `c${++crestSeq}`

/** The hexagon's own points, shared by the frame and the clip it defines. */
function hexPoints(r: number): string {
  return Array.from({ length: 6 }, (_, i) => {
    const angle = (Math.PI / 3) * i
    return `${(C + r * Math.cos(angle)).toFixed(2)},${(C + r * Math.sin(angle)).toFixed(2)}`
  }).join(' ')
}

/** The pack glyph's eye. Nothing else in the game has eyes; keep it that way. */
const EYE = '#e0a24a'

/**
 * §9.5.2 -- how far in this thing lives, as a color.
 *
 * Bark on the safe rim, ember in the barren center. A monster belongs to no
 * ground -- it walked here -- so this is not a biome ramp: it climbs toward
 * §13.3's alarm color, and tier 4 lands exactly on it. Meeting an Ash Revenant
 * should look like the game's warning color wearing a body.
 */
const TIER_HIDE: Record<number, string> = {
  1: '#6b5a3e',
  2: '#7d4a38',
  3: '#9a4436',
  4: '#b8453f',
}

const hideFor = (tier: number): string => TIER_HIDE[Math.min(4, Math.max(1, tier))]!

/**
 * The hexagon every crest sits in, matching the map's flat-top tiling (§13.2)
 * and the HUD's own `--hex-clip`. A fight happens on a hex; the frame says so.
 */
function crest(stroke: string, body: string, size: number): string {
  const id = nextId()
  const points = hexPoints(18.6)

  // The body is CLIPPED to the frame rather than merely drawn behind it. A
  // shoulder hanging outside the hexagon reads as a mistake, and the frame is
  // the one thing tying a fight to the hex it happens on (§13.2).
  return `<svg viewBox="0 0 ${VIEW} ${VIEW}" width="${size}" height="${size}" role="img" aria-hidden="true">
    <defs><clipPath id="${id}"><polygon points="${points}"/></clipPath></defs>
    <polygon points="${points}" fill="#191f1c"/>
    <g clip-path="url(#${id})">${body}</g>
    <polygon points="${points}" fill="none" stroke="${stroke}" stroke-width="1.4"/>
  </svg>`
}

/**
 * §9.5.2 -- the profile, which is what a player actually reads.
 *
 * Not a level number and not a tier badge: a brute is high attack and low
 * guard, a carapace the reverse, a swift one is middling and blunts what hits
 * it. Each has to be told apart at 34px, so each owns a different read --
 * a forward wedge, a low dome, a raised lean.
 */
const MONSTER: Record<string, (hide: string, dark: string, lit: string) => string> = {
  // High attack, low guard. Read by PROPORTION first: widest at the shoulders
  // and narrowing to the ground, with the head sunk between them and two fists
  // already down. Everything about it is carried forward, and nothing about it
  // is covering anything.
  brute: (hide, dark, lit) => `
    <path d="M6.5 16 L14 12.5 L26 12.5 L33.5 16 L30 32 L10 32 Z" fill="${hide}"/>
    <path d="M10.5 8 L15 14.5 L8.5 15.5 Z" fill="${dark}"/>
    <path d="M29.5 8 L31.5 15.5 L25 14.5 Z" fill="${dark}"/>
    <path d="M14.5 15 Q20 12.5 25.5 15 L24 22 Q20 24 16 22 Z" fill="${dark}"/>
    <path d="M6.5 16 L14 12.5 L26 12.5 L33.5 16 L33 18.4 L26 15 L14 15 L7 18.4 Z" fill="${lit}"/>
    <circle cx="17.2" cy="17.6" r="1.7" fill="${EYE}"/>
    <circle cx="22.8" cy="17.6" r="1.7" fill="${EYE}"/>
    <rect x="7.5" y="25" width="5.5" height="6" fill="${dark}"/>
    <rect x="27" y="25" width="5.5" height="6" fill="${dark}"/>`,

  // Low attack, high guard. The inverse proportion: widest at the GROUND and
  // closing to a dome, banded across so it reads as plate rather than as a
  // body. The eyes are down under the rim, which is the whole sentence -- there
  // is no way in from the front.
  carapace: (hide, dark, lit) => `
    <path d="M5.5 31 Q5.5 10.5 20 10.5 Q34.5 10.5 34.5 31 Z" fill="${hide}"/>
    <path d="M8 16.5 Q20 12 32 16.5" fill="none" stroke="${lit}" stroke-width="1.7"/>
    <path d="M6.5 22 Q20 17 33.5 22" fill="none" stroke="${lit}" stroke-width="1.7"/>
    <path d="M9.5 12.5 Q20 9 30.5 12.5" fill="none" stroke="${lit}" stroke-width="1.4"/>
    <path d="M5.5 31 Q5.5 25.5 20 25.5 Q34.5 25.5 34.5 31 Z" fill="${dark}"/>
    <circle cx="16" cy="28.4" r="1.5" fill="${EYE}"/>
    <circle cx="24" cy="28.4" r="1.5" fill="${EYE}"/>`,

  // Middling both, and it blunts what it is hit with. The only NARROW one: a
  // tall lean body under a head carried high and forward on a long neck, with
  // the spines that do the blunting standing off its back. Nothing here is
  // wide, which is what tells it from the other two at 34px.
  swift: (hide, dark, lit) => `
    <path d="M9 22 L15.5 18 L16.5 23 Z" fill="${dark}"/>
    <path d="M8.5 27.5 L14.5 23.5 L15.5 28 Z" fill="${dark}"/>
    <path d="M15 33 Q13.8 22 20 19 Q26.2 22 25 33 Z" fill="${hide}"/>
    <path d="M17.5 19.5 L18.6 10.5 L29 12.6 L27.6 17 L22.2 16 L22 19.5 Z" fill="${hide}"/>
    <path d="M18.6 10.5 L23 6.5 L23.6 11.4 Z" fill="${lit}"/>
    <path d="M22.2 16 L27.6 17 L29 12.6 Z" fill="${lit}"/>
    <circle cx="25.6" cy="14.2" r="1.6" fill="${EYE}"/>`,
}

/**
 * A monster's crest: what it is, and how far in it lives.
 *
 * `profile` decides the body and `tier` the hide, so the roster is covered by
 * three shapes rather than eight. An unknown profile falls back to the brute
 * rather than drawing nothing -- an empty frame in a fight reads as a bug.
 */
export function monsterCrest(profile: string, tier: number, size = 40, dead = false): string {
  const hide = dead ? shade(hideFor(tier), -0.45) : hideFor(tier)
  const dark = shade(hide, -0.45)
  const lit = shade(hide, 0.26)
  const body = (MONSTER[profile] ?? MONSTER.brute!)(hide, dark, lit)

  // §9.5.7 -- a carrier is the pack's shape gone still. Eyes are the pack's
  // whole tell on the map, so taking them away here is the same sentence said
  // on the plate: this one is not looking at you.
  const still = dead ? body.replace(new RegExp(EYE, 'g'), shade(hide, -0.55)) : body

  return crest(hide, still, size)
}

/**
 * §9.5.4 -- you, as the thing in your hand.
 *
 * The family in the weapon slot IS your class, so it is what the figure is
 * doing rather than a badge beside it: a shieldbearer is behind the shield, a
 * swordhand has the blade up, a runecaster is holding a mark off the ground.
 *
 * Bare hands get their own stance rather than an empty frame. §9.5.3 makes
 * fighting with nothing a legitimate way out of a pin -- an expensive one, but
 * never a locked door -- so the one kit the game guarantees you can always
 * bring has to be drawable.
 */
const FIGHTER: Record<string, (ink: string, dark: string) => string> = {
  // The figure is the mass and the weapon is the bright thing, so what you are
  // carrying is what is read first. Everything is pulled inside the frame: a
  // blade tip or a fist cut off by the hexagon reads as a rendering fault
  // rather than as a crop.
  shield: (ink, dark) => `
    <circle cx="24" cy="12" r="4" fill="${dark}"/>
    <path d="M19.5 33 Q19.5 19 24 18.2 Q28.5 19 28.5 33 Z" fill="${dark}"/>
    <path d="M7.5 12 L22 16 L22 28.5 Q12.5 26 7.5 20 Z" fill="${ink}"/>
    <path d="M11 15.5 L19 17.8 L19 24.6 Q13 22.6 11 19.2 Z" fill="${dark}"/>`,

  sword: (ink, dark) => `
    <circle cx="15" cy="13" r="4" fill="${dark}"/>
    <path d="M10.5 33 Q10.5 20 15 19.2 Q19.5 20 19.5 33 Z" fill="${dark}"/>
    <path d="M18 21.5 L26 24" stroke="${dark}" stroke-width="2.6" stroke-linecap="round"/>
    <path d="M26.4 23.5 L26.4 10 L29.6 10 L29.6 23.5 Z" fill="${ink}"/>
    <path d="M28 7.2 L30 11 L26 11 Z" fill="${ink}"/>
    <rect x="22.6" y="23.4" width="10.8" height="2.6" fill="${ink}"/>
    <rect x="26.2" y="26" width="3.6" height="4.4" fill="${ink}"/>`,

  focus: (ink, dark) => `
    <circle cx="14.5" cy="14" r="4" fill="${dark}"/>
    <path d="M10 33 Q10 20.5 14.5 19.8 Q19 20.5 19 33 Z" fill="${dark}"/>
    <path d="M17.5 22 L24 16.5" stroke="${dark}" stroke-width="2.6" stroke-linecap="round"/>
    <circle cx="26.5" cy="13.5" r="5.6" fill="none" stroke="${ink}" stroke-width="1.8"/>
    <circle cx="26.5" cy="13.5" r="2.6" fill="${ink}"/>`,

  bare: (ink, dark) => `
    <circle cx="20" cy="12.5" r="4" fill="${dark}"/>
    <path d="M15 33 Q15 19.5 20 18.7 Q25 19.5 25 33 Z" fill="${dark}"/>
    <path d="M16 20.5 L12 25" stroke="${dark}" stroke-width="2.8" stroke-linecap="round"/>
    <path d="M24 20.5 L28 25" stroke="${dark}" stroke-width="2.8" stroke-linecap="round"/>
    <circle cx="11" cy="25.8" r="3" fill="${ink}"/>
    <circle cx="29" cy="25.8" r="3" fill="${ink}"/>`,
}

/**
 * Your crest, colored by how the pool is holding.
 *
 * §13.3's pair, doing the job it is for: sap is the state worth crossing the
 * screen for and ember is the one to deal with, so the figure turns ember at
 * the same quarter-pool the bar does. The portrait carries the state rather
 * than sitting inertly above a bar that has it.
 */
export function fighterCrest(family: string | null, failing = false, size = 40): string {
  const ink = failing ? '#b8453f' : '#8fbf7f'
  const dark = shade(ink, -0.24)
  const body = (FIGHTER[family ?? 'bare'] ?? FIGHTER.bare!)(ink, dark)

  return crest(ink, body, size)
}

/**
 * §7.4 / §9.5.4 -- which family a battle job is fought with.
 *
 * The reverse of Catalog::BATTLE_JOB_FOR_FAMILY, and the only thing either
 * battle screen needs to draw you: a receipt already names the job that earned
 * the XP, so the family it was fought with is derivable and never has to ride
 * the payload as a second copy.
 */
export const FAMILY_FOR_BATTLE_JOB: Record<string, string> = {
  shieldbearer: 'shield',
  swordhand: 'sword',
  runecaster: 'focus',
}
