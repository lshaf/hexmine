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

/*
 * §9.5.2 -- the third axis, and the one the ROSTER owns.
 *
 * Profile says how a thing fights and tier says how far in it lives, which is
 * everything a player has to ACT on -- but with twelve monsters it leaves two
 * of them looking like one animal in two colours, and a bestiary you cannot
 * tell apart is a list of numbers with pictures on it.
 *
 * So each monster gets exactly ONE mark, and the mark is whatever its own
 * description already says it is: the Slag Ogre's girder, the Thornback's
 * quills, the Iron Shrike's wings. Nothing invented -- if the sentence in the
 * roster names a thing, that is the thing drawn, and if it names none the
 * monster keeps the bare profile.
 *
 * One rather than several, and always a silhouette-level shape. These are read
 * at 24px on a map tile before they are read at 44 on a plate, so a mark that
 * needs the big size is a mark that is not there when it matters.
 */
const MONSTER_MARK: Record<string, (hide: string, dark: string, lit: string) => string> = {
  /* "Runs the treeline in threes." Moss over the shoulders, in the one colour
     on the plate that is not the hide -- it grew there, it is not part of it. */
  moss_hound: () => `
    <path d="M9.5 13.6 Q13 7.6 17 12 Q20 7.2 23.5 11.8 Q27.5 7.4 30.5 13.4 Z" fill="#5f8058"/>
    <path d="M6.6 17.4 Q9 13.4 12 17 Z" fill="#5f8058"/>
    <path d="M28 17.4 Q31 13.4 33.6 17.2 Z" fill="#5f8058"/>`,

  /* "Its back plated over." A single rut line across the dome -- the wheel it
     sits in, and the reason you step on it. */
  ditch_crawler: (_hide, dark) => `
    <path d="M0 29.5 Q10 26.5 20 29.5 Q30 32.5 40 29.5 L40 33 L0 33 Z" fill="${dark}"/>
    <path d="M0 26.5 Q10 23.5 20 26.5" fill="none" stroke="${dark}" stroke-width="1.8"/>`,

  /* "Crosses the road in one blur." The streaks it left, behind and low. */
  rill_skitter: (_hide, _dark, lit) => `
    <path d="M4 28.5 H13 M2.5 32 H10.5 M5.5 25 H11" stroke="${lit}" stroke-width="1.5" stroke-linecap="round"/>`,

  /* "Swinging the same girder since." An I-beam across the fists. */
  slag_ogre: (_hide, _dark, lit) => `
    <path d="M4 32.5 L31 20 L33 24.4 L6 36.9 Z" fill="${lit}"/>
    <path d="M28.6 18.4 L35.4 21.6 L33.4 26 L26.6 22.8 Z" fill="${lit}"/>`,

  /* "Every quill points out." Along the top of the dome and nowhere else. */
  thornback: (_hide, _dark, lit) => `
    <path d="M8 15 L5 9.5 M13 11.6 L11.5 5.4 M20 10.4 L20 4 M27 11.6 L28.5 5.4 M32 15 L35 9.5"
      stroke="${lit}" stroke-width="1.8" stroke-linecap="round"/>`,

  /* "Whips out of a vent." One lash, curling away from the body. */
  cinder_lash: (_hide, _dark, lit) => `
    <path d="M14 30 Q4 28 6 20 Q7.5 14 13 15" fill="none" stroke="${lit}"
      stroke-width="2.2" stroke-linecap="round"/>`,

  /* "Fast over broken ground." The ridge it is named for, standing off the
     back -- the swift profile's own spines, said louder. */
  ridge_wyrm: (_hide, _dark, lit) => `
    <path d="M14.6 30 L7.5 30.5 L13.8 26 L6.5 24 L13.6 20.6 L8.8 16.6 L15.4 16"
      fill="none" stroke="${lit}" stroke-width="1.9" stroke-linejoin="round" stroke-linecap="round"/>`,

  /* "Drops out of the sun." Wings, and they are the whole silhouette change. */
  iron_shrike: (_hide, dark, lit) => `
    <path d="M13.5 12.6 L3.6 16.6 L5.6 24.4 L13 18.6 Z" fill="${lit}"/>
    <path d="M26.5 12.6 L36.4 16.6 L34.4 24.4 L27 18.6 Z" fill="${lit}"/>
    <path d="M13.5 12.6 L3.6 16.6 L9 17.4 Z" fill="${dark}"/>
    <path d="M26.5 12.6 L36.4 16.6 L31 17.4 Z" fill="${dark}"/>`,

  /* "Bakes itself hard in the vents." The heat still in the seams: the one
     mark drawn in the eye's amber, because it is the same thing being said --
     something in there is still going. */
  kiln_tortoise: () => `
    <path d="M12 20.5 L15 14.5 M20 19.5 L20 13 M28 20.5 L25 14.5"
      stroke="${EYE}" stroke-width="1.5" stroke-linecap="round"/>`,

  /* "Whatever it was buried in, it is still wearing." A helm slit across the
     front, which is also the sentence: there is no way in. */
  barrow_knight: (_hide, _dark, lit) => `
    <rect x="11" y="26.6" width="18" height="2.6" fill="${lit}"/>
    <path d="M20 24.5 L20 33" stroke="${lit}" stroke-width="1.6"/>`,

  /* "With the fire still on it." Embers going up off the shoulders. */
  ash_revenant: () => `
    <path d="M12 11 Q10.5 7 13 4.5 Q13.6 7.4 15.6 8.6 Q15 10.4 13.6 11 Z" fill="${EYE}"/>
    <path d="M26 11.5 Q25 8.5 27 6.5 Q27.4 8.6 28.8 9.4 Q28.4 10.8 27.4 11.4 Z" fill="${EYE}"/>`,

  /* "Keeps pace a ring behind you for a day." Bleached: the one monster whose
     mark is the absence of colour, drawn over the hide it should have had. */
  pale_stalker: (_hide, _dark, _lit) => `
    <path d="M15 33 Q13.8 22 20 19 Q26.2 22 25 33 Z" fill="#cfc9bb"/>
    <path d="M17.5 19.5 L18.6 10.5 L29 12.6 L27.6 17 L22.2 16 L22 19.5 Z" fill="#cfc9bb"/>
    <path d="M22.2 16 L27.6 17 L29 12.6 Z" fill="#9d998e"/>
    <circle cx="25.6" cy="14.2" r="1.6" fill="${EYE}"/>`,
}

/** The box every silhouette is drawn in, so a caller can scale it. */
export const MONSTER_VIEW = VIEW

/**
 * The silhouette alone, unframed, in a 40x40 box.
 *
 * Exported because the MAP draws the same thing (§13.2): the creature standing
 * on the hex has to be the creature in the modal, or the fight is about
 * somebody else's monster. The frame is what differs -- a crest sits in a
 * hexagon of its own, and on the map the tile is already the hexagon.
 *
 * `profile` decides the body and `tier` the hide, so the roster is covered by
 * three shapes rather than twelve. An unknown profile falls back to the brute
 * rather than drawing nothing -- an empty frame in a fight reads as a bug.
 */
export function monsterBody(
  profile: string,
  tier: number,
  dead = false,
  key: string | null = null,
): string {
  const hide = dead ? shade(hideFor(tier), -0.45) : hideFor(tier)
  const dark = shade(hide, -0.45)
  const lit = shade(hide, 0.26)

  // The mark goes ON the profile, never instead of it: what a player acts on is
  // still the read -- widest at the shoulders, widest at the ground, or narrow.
  const mark = (key && MONSTER_MARK[key]?.(hide, dark, lit)) ?? ''
  const body = (MONSTER[profile] ?? MONSTER.brute!)(hide, dark, lit) + mark

  // §9.5.7 -- a carrier is the pack's shape gone still. Eyes are the pack's
  // whole tell on the map, so taking them away here is the same sentence said
  // on the plate: this one is not looking at you.
  return dead ? body.replace(new RegExp(EYE, 'g'), shade(hide, -0.55)) : body
}

/** The hide a tier wears, for anything drawing beside a monster. */
export const monsterHide = (tier: number, dead = false): string =>
  dead ? shade(hideFor(tier), -0.45) : hideFor(tier)

/**
 * A monster's crest: what it is, how far in it lives, and the hexagon it fights
 * on.
 */
export function monsterCrest(
  profile: string,
  tier: number,
  size = 40,
  dead = false,
  key: string | null = null,
): string {
  /*
   * §9.5.2 -- no country is drawn here, and that is a rule rather than an
   * omission.
   *
   * A crest is a PORTRAIT: it is shown on a fight plate, and a fight happens on
   * the hex you are standing on, so where the thing lives is the one question
   * the reader has already answered by being there. The almanac is the other
   * way round -- you are not there, and which country a monster is of is half
   * of what the entry is for -- so `monsterSpecimen` stands it on its own
   * biome's ground instead.
   *
   * A tuft at the feet was tried. The crest is a hexagon and the corners it
   * would have to sit in are exactly what the shape cuts off.
   */
  return crest(monsterHide(tier, dead), monsterBody(profile, tier, dead, key), size)
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
