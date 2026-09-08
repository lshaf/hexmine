/**
 * §9.5 -- what the client works out about a fight, in one place.
 *
 * Nothing here decides anything. The exchange is settled the instant you close
 * and stored round by round (§9.5.5), so every function below is a pure read of
 * a log the server already wrote -- which is exactly what makes it safe to
 * share between the plate a player sees and the bench the numbers are tuned on.
 *
 * IT EXISTS BECAUSE THE BENCH IS NOT A FORK. /battle draws the same band, the
 * same cooldown rail and the same skill rows the game does, off the same
 * derivations. A simulator that re-derived any of this would be a second
 * opinion, and the first thing a second opinion does is drift.
 */
import { BATTLE_SKILLS, type BattleSkillDef } from '@/game/battleSkills'
import { FAMILY_FOR_BATTLE_JOB } from '@/icons/combatants'
import { skillGlyph } from '@/icons/skills'
import type { BattleJob, BattleRound } from '@/game/types'

/** §9.5.4 -- the family in the slot is the class, so the job follows from it. */
export const BATTLE_JOB_FOR_FAMILY: Record<string, string> = Object.fromEntries(
  Object.entries(FAMILY_FOR_BATTLE_JOB).map(([job, family]) => [family, job]),
)

/** What a skill row carries once a fight has armed it: every figure included. */
export type ArmedSkill = BattleJob['skills'][number]

/**
 * The half of a skill that is enough to DRAW one.
 *
 * The mirrored table (`battleSkills.ts`) carries the name, the mark and the
 * cooldown and deliberately carries no multiplier -- those are the server's
 * (§16) -- so the preview and the bench hold this shape until a fight hands
 * them the whole of it. Everything that draws a skill takes this, and the
 * armed row satisfies it.
 */
export interface SkillLike {
  key: string
  name: string
  glyph: string
  cooldown: number
  effect?: string
}

/**
 * §9.5.9 -- the three a weapon knows, WITHOUT a fight to read them off.
 *
 * The preview and the bench both have to name them before anything has
 * happened, and neither has a stored round to look at. What they get is the
 * mirrored table (`battleSkills.ts`), which carries the name, the mark and the
 * cooldown and deliberately carries no multiplier: those are the server's
 * (§16). So the shape here is a subset of the armed row rather than the whole
 * of it, and the missing half arrives with the fight.
 */
export function skillsOfFamily(family: string | null): BattleSkillDef[] {
  if (!family) return []

  return Object.values(BATTLE_SKILLS).filter((s) => s.family === family)
}

/** One skill's state partway through a replay. */
export interface SkillTurn {
  key: string
  name: string
  cooldown: number
  effect?: string
  svg: string
  /** Off cooldown as of this round, so the next one could see it go. */
  ready: boolean
  /** It went off on the round being drawn. */
  firing: boolean
  /** 0-1, how far round it has come. A ring fills; a numeral does not read. */
  turn: number
  /** Rounds still to wait. */
  left: number
}

/**
 * §9.5.9 -- where each of the three is, as of `round`.
 *
 * Read off the log rather than re-simulated: the round each skill last went is
 * already written down, so tracking a countdown here would be keeping a second
 * copy of something the server already said. Everything starts on cooldown, so
 * at round 0 nothing is ready and a rout never gets there.
 *
 * `round` at the log's full length is the whole fight, which is what the
 * preview and the receipt both want.
 */
export function skillTurns(
  skills: SkillLike[] | undefined,
  log: BattleRound[],
  round: number,
): SkillTurn[] {
  return (skills ?? []).map((art) => {
    let last = 0
    for (let i = 0; i < round && i < log.length; i++) {
      if (log[i]!.skill === art.key) last = i + 1
    }

    const since = round - last
    const ready = round > 0 && since >= art.cooldown

    return {
      key: art.key,
      name: art.name,
      cooldown: art.cooldown,
      effect: art.effect,
      svg: skillGlyph(art.glyph, 16),
      ready,
      firing: log[round - 1]?.skill === art.key,
      turn: ready ? 1 : Math.min(1, since / Math.max(1, art.cooldown)),
      left: Math.max(0, art.cooldown - since),
    }
  })
}

/** How many times one skill went off across a whole fight. */
export function timesFired(log: BattleRound[], key: string): number {
  return log.reduce((n, r) => n + (r.skill === key ? 1 : 0), 0)
}

/**
 * A fight that never happened to anybody, dressed as the job the replay reads.
 *
 * The bench's own shape, kept here rather than in the bench: BattleLive takes a
 * job, and if the adapter lived beside the page then adding a field to the
 * replay would silently stop working on /battle. One file changes, both
 * callers move.
 */
export function jobFromFight(fight: {
  family: string | null
  monster: string
  pool: number
  monsterHp: number
  roundMs: number
  log: BattleRound[]
  skills: ArmedSkill[]
}): BattleJob {
  const now = Date.now()

  return {
    id: 'sim',
    kind: 'battle',
    status: 'active',
    col: 0,
    row: 0,
    slot: null,
    quantity: 1,
    startedAt: now,
    endsAt: now + fight.log.length * fight.roundMs,
    skill: BATTLE_JOB_FOR_FAMILY[fight.family ?? ''] ?? 'swordhand',
    monster: fight.monster,
    pool: fight.pool,
    monsterHp: fight.monsterHp,
    roundMs: fight.roundMs,
    log: fight.log,
    skills: fight.skills,
  }
}

// ------------------------------------------------------------- the level

/**
 * §9.5.4 -- the battle job you are actually carrying, and how far up it is.
 *
 * Mirrors GameService::battleJobLevel: the family in the weapon slot is your
 * class, so it is the first equipped, unbroken weapon that decides which of the
 * three answers for you. Null when nothing is in the slot -- which is a real
 * answer rather than a missing one, and the pin reads it as level nothing.
 */
export function carriedBattleJob(
  items: Array<{ key: string; equipped: boolean; durability: number }>,
  familyOf: (key: string) => string | undefined,
  jobLevels: Record<string, number>,
): { job: string; level: number } | null {
  for (const item of items) {
    if (!item.equipped || item.durability <= 0) continue

    const job = BATTLE_JOB_FOR_FAMILY[familyOf(item.key) ?? '']
    if (job) return { job, level: jobLevels[job] ?? 1 }
  }

  return null
}

/**
 * §9.5.2 -- how a monster's level reads against the job you fight with.
 *
 * The comparison is honest only because of Balance::EQUIP_JOB_LEVEL: a battle
 * job level bounds the RUNG you may carry, so it is a real ceiling on how good
 * a kit can be, and the two numbers are on one scale. The BATTLE job and not
 * the career: a weapon is gated on the family in the slot, so a long-dug mine
 * buys nothing against a pack. What it is not is a promise -- §7.1 says a level
 * unlocks access rather than power, so a Swordhand 22 in a work coat does not
 * beat a level 14 pack. **The preview is the answer** (§9.5.5); this is the
 * glance that says whether to read it.
 *
 * Three states rather than two. An even fight is the interesting one and it has
 * to be distinguishable from both edges, or the whole thing collapses into a
 * light saying yes or no about something the preview is already deciding.
 *
 * The band is two rather than five, because the ladder it is read on is the
 * job's: a career runs to 100 and a job stops at 30, and a band that stayed
 * wide would have called every fight on the map even.
 */
export const LEVEL_BAND = 2

export type LevelStanding = 'under' | 'even' | 'over'

export function levelStanding(mine: number, theirs: number): LevelStanding {
  if (mine + LEVEL_BAND < theirs) return 'under'
  if (mine > theirs + LEVEL_BAND) return 'over'

  return 'even'
}

/**
 * §13.3 -- and only one of the three takes a colour.
 *
 * Ember marks a state to deal with, which is exactly what a pack above your
 * rung is. Outclassing one is not a payout and must not wear sap: it is the
 * absence of a problem, and the absence of a problem is what plain text looks
 * like everywhere else in this game.
 */
export const LEVEL_NOTE: Record<LevelStanding, string> = {
  under: 'above your rung',
  even: 'an even rung',
  over: 'under your rung',
}
