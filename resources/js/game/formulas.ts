/**
 * Pure game maths. No state, no I/O -- every function here takes its inputs
 * explicitly so the same logic can be ported to the Laravel side verbatim and
 * diffed against this file.
 *
 * IMPORTANT: the server owns these numbers. This module exists so the client
 * can *predict* and display them; it must never be the authority.
 */
import { EQUIPMENT, MINING, PROCESSING, SKILLS } from './balance'
import { ITEM_BY_KEY, LINE_STAT_LABEL, SKILL_BY_KEY, STAT_LABEL, skillForSlot } from './catalog'
import type {
  Rarity,
  ItemDef,
  ItemOption,
  OwnedItem,
  SettlementTier,
  SkillKey,
  StatKey,
} from './types'

// ------------------------------------------------------------------ equipment

/**
 * Aggregate one stat across equipped items.
 *
 * §8.1 rule 2 -- diminishing returns on stacking: sorted strongest-first, the
 * nth contributor is worth `value * falloff^(n-1)`. This is what stops a whale
 * buying three identical bundles for linear scaling.
 *
 * §8.1 rule 1 -- the total is then clamped to the hard per-stat ceiling of the
 * best tier present, so rarity buys durability and reliability, not power.
 *
 * §8 gathering tools are line-locked: a bow does nothing to a tree. A tool
 * counts only when `line` is the skill line it serves, so passing nothing --
 * "no line is being worked" -- leaves every tool out and returns what the player
 * gets from gear that works anywhere.
 */
export function aggregateStat(
  items: OwnedItem[],
  stat: StatKey,
  line: SkillKey | null = null,
): number {
  // §8.0.1 -- a rolled line is just another contributor: same falloff, same cap,
  // and it inherits its item's line-lock, which is what stops five equipped
  // tools stacking five copies of the same bonus.
  const contributions: Array<{ def: ItemDef; value: number }> = []

  for (const owned of items) {
    if (!owned.equipped || owned.durability <= 0) continue
    const def = ITEM_BY_KEY[owned.key]
    if (!def) continue

    const toolLine = def.slot ? skillForSlot(def.slot) : null
    if (toolLine !== null && toolLine !== line) continue

    // §8 -- a tool has no percentage at all; its base is a solid attack.
    if (def.stat === stat && def.value !== undefined) contributions.push({ def, value: def.value })
    for (const option of owned.options ?? []) {
      if (option.stat !== stat) continue
      // §8.0.1 -- a scoped line pays in full on the line it names and nothing
      // anywhere else, and no line being worked is one of those elsewheres.
      if (option.scope && option.scope !== line) continue
      contributions.push({ def, value: option.value })
    }
  }

  if (contributions.length === 0) return 0

  const total = contributions
    .map((c) => c.value)
    .sort((a, b) => b - a)
    .reduce((sum, value, index) => sum + value * EQUIPMENT.stackFalloff ** index, 0)

  const best = contributions.reduce<Rarity>(
    (top, c) => (EQUIPMENT.statCap[c.def.rarity] > EQUIPMENT.statCap[top] ? c.def.rarity : top),
    'common',
  )

  return Math.min(total, EQUIPMENT.statCap[best])
}

/** Salvage returned when an item is discarded, §8.2. */
/**
 * §8.2 -- what a trader pays for a piece of shop gear.
 *
 * Half the shelf price, scaled by what is left of the item. Zero for anything
 * the trader does not stock: gold buys the bottom two rungs and nothing else
 * (§3.2), so a crafted or NFT piece has no shelf price to halve -- salvage is
 * that gear's exit. Zero is also what a broken piece is worth, and the server
 * refuses rather than paying it.
 */
export function resaleValue(def: ItemDef, durability: number): number {
  const price = def.goldPrice ?? 0
  const max = def.maxDurability ?? 0

  if (price <= 0 || max <= 0 || durability <= 0) return 0

  return Math.floor(price * EQUIPMENT.resaleRate * (Math.min(durability, max) / max))
}

export function salvageYield(def: ItemDef): Partial<Record<string, number>> {
  const out: Record<string, number> = {}
  for (const [key, qty] of Object.entries(def.inputs ?? {})) {
    const amount = Math.floor((qty as number) * EQUIPMENT.salvageRate)
    if (amount > 0) out[key] = amount
  }
  return out
}

/** Repair cost, §8.2: cheaper than crafting new, but not dramatically so. */
export function repairCost(def: ItemDef, missingDurability: number): Record<string, number> {
  const fraction = missingDurability / (def.maxDurability ?? 1)
  const out: Record<string, number> = {}
  for (const [key, qty] of Object.entries(def.inputs ?? {})) {
    const amount = Math.ceil((qty as number) * fraction * EQUIPMENT.repairCostRate)
    if (amount > 0) out[key] = amount
  }
  return out
}

// -------------------------------------------------------------------- mining

export interface TripBreakdown {
  base: number
  /** §7.3 -- how much work the hex is. Base seconds at the bare-handed rate. */
  durability: number
  toolAttack: number
  skillAttack: number
  /** Work taken out of the hex per second, all in. */
  rate: number
  /** After clamp. This is the number that actually runs. */
  total: number
  /** True when the clamp bound the result -- surfaced in the UI so the player
   *  can see that more gear would be wasted here. */
  clamped: boolean
}

/**
 * §7.3 -- a hex is an amount of WORK, and a trip is how long you take over it.
 *
 *   durability = base_seconds * baseAttack
 *   rate       = (base + tool + skill) * (1 + trip_reduction)
 *   trip_time  = clamp(durability / rate, 15min, 60min)
 *
 * The floor clamp is mandatory and is in the formula from day one: without it
 * any future buff or equipment tier creates a sub-floor or zero-time exploit.
 * Do not remove it, and do not apply bonuses after it.
 */
export function tripTime(
  baseSeconds: number,
  skillLevel: number,
  equipTripReduction: number,
  toolAttack = 0,
): TripBreakdown {
  const durability = tileDurability(baseSeconds)

  const skillProgress = Math.min(1, skillLevel / SKILLS.maxLevel)
  const skillAttack = MINING.skillAttack * skillProgress

  const rate = (MINING.baseAttack + toolAttack + skillAttack) * (1 + Math.max(0, equipTripReduction))

  const raw = Math.round(durability / Math.max(1, rate))
  const total = Math.min(MINING.ceilingSeconds, Math.max(MINING.floorSeconds, raw))

  return {
    base: baseSeconds,
    durability,
    toolAttack,
    skillAttack: Math.round(skillAttack),
    rate: Math.round(rate * 100) / 100,
    total,
    clamped: total !== raw,
  }
}

/** §7.3 -- how much work a hex is, which is what a trip actually spends. */
export function tileDurability(baseSeconds: number): number {
  return baseSeconds * MINING.baseAttack
}

/**
 * §8 -- what a gathering tool takes out of a hex each second.
 *
 * A tool's BASE stat, and the only one it has. It used to lead with a yield
 * percentage: attack is how fast you work through a hex (§7.3) and yield is how
 * big the haul is, which are different questions and now different numbers.
 */
export function toolAttack(def: Pick<ItemDef, 'attack'>): number {
  return def.attack ?? 0
}

/** Yield for one trip. Skill and equipment both add; ring adds the risk premium. */
export function tripYield(
  baseYield: number,
  skillLevel: number,
  equipYieldBonus: number,
  ringMultiplier: number,
): number {
  const skillBonus = 1 + (skillLevel / SKILLS.maxLevel) * 0.5
  return Math.max(1, Math.round(baseYield * skillBonus * (1 + equipYieldBonus) * ringMultiplier))
}

// ---------------------------------------------------------------- processing

/** Processing duration at a settlement, §6 + §6.2. */
export function processingTime(
  baseSeconds: number,
  tier: SettlementTier,
  presence: boolean,
  equipProcessingBonus: number,
): number {
  const tierSpeed = PROCESSING.speed[tier]
  const presenceSpeed = presence ? 1 - PROCESSING.presenceSpeedBonus : 1
  return Math.max(30, Math.round(baseSeconds * tierSpeed * presenceSpeed * (1 - equipProcessingBonus)))
}

// ----------------------------------------------------------------- character


/** Level-ups can cascade if a large XP grant lands at once. */
export function applyXp(
  level: number,
  xp: number,
  gain: number,
  maxLevel: number,
  curve: (level: number) => number,
): { level: number; xp: number; levelsGained: number } {
  let nextLevel = level
  let nextXp = xp + gain
  let levelsGained = 0

  while (nextLevel < maxLevel && nextXp >= curve(nextLevel)) {
    nextXp -= curve(nextLevel)
    nextLevel += 1
    levelsGained += 1
  }
  if (nextLevel >= maxLevel) nextXp = 0

  return { level: nextLevel, xp: nextXp, levelsGained }
}

// -------------------------------------------------------------------- format

/**
 * Countdown formatter: reads as a state once it hits zero. Use formatSpan for
 * a fixed length of time -- a zero-length reduction is "0s", not "ready".
 */
export function formatDuration(ms: number): string {
  if (ms <= 0) return 'ready'
  const totalSeconds = Math.ceil(ms / 1000)
  const h = Math.floor(totalSeconds / 3600)
  const m = Math.floor((totalSeconds % 3600) / 60)
  const s = totalSeconds % 60
  if (h > 0) return `${h}h ${String(m).padStart(2, '0')}m`
  if (m > 0) return `${m}m ${String(s).padStart(2, '0')}s`
  return `${s}s`
}

/** Fixed duration, never a state word. */
export function formatSpan(ms: number): string {
  const totalSeconds = Math.max(0, Math.round(ms / 1000))
  const h = Math.floor(totalSeconds / 3600)
  const m = Math.floor((totalSeconds % 3600) / 60)
  const s = totalSeconds % 60
  if (h > 0) return `${h}h ${String(m).padStart(2, '0')}m`
  if (m > 0) return `${m}m ${String(s).padStart(2, '0')}s`
  return `${s}s`
}

export const formatPercent = (value: number): string =>
  `${value >= 0 ? '+' : ''}${Math.round(value * 1000) / 10}%`

/**
 * The stats stored as a reduction. There is one, and the screen has to turn it
 * over: a trip cut by three minutes in ten is `-3% trip time`, and printing the
 * stored `+3%` next to the word "time" says the opposite of what it does.
 */
const REDUCTION_STATS = new Set<StatKey>(['tripReduction'])

/**
 * A settlement said with its hex, §6.
 *
 * Names are generated from two word lists, so two villages a day's walk apart
 * can share one -- and now that work is claimed at the bench that holds it
 * (§8.4), "waiting at Redhollow" has to say WHICH Redhollow. The coordinates
 * are also the thing a player types into the map, so they earn their place
 * anywhere the name is a destination rather than a decoration.
 */
export const placeLabel = (
  name: string | null | undefined,
  col: number | null | undefined,
  row: number | null | undefined,
): string => {
  if (!name) return 'somewhere'
  if (col === null || col === undefined || row === null || row === undefined) return name

  return `${name} (${col}, ${row})`
}

/** A stat's percentage with the sign it is read by, never the sign it is stored with. */
export const formatStat = (stat: StatKey, value: number): string =>
  formatPercent(REDUCTION_STATS.has(stat) ? -value : value)

/**
 * One bonus, said in full: `+6% mining yield`, `-3% woodcutting time`.
 *
 * Every screen that prints a bonus goes through here, so the sign and the
 * wording are decided once. `line` names the gathering line the bonus is good
 * on -- a tool's is read off its slot (§8 rule 1), a rolled line carries its
 * own (§8.0.1) -- and naming it is most of what makes the number legible: "+6%
 * yield" leaves a player asking *yield of what*.
 */
export function statLine(
  stat: StatKey,
  value: number,
  line: SkillKey | null = null,
): string {
  const scoped = line ? LINE_STAT_LABEL[stat] : undefined
  const words = scoped ? `${SKILL_BY_KEY[line!].name.toLowerCase()} ${scoped}` : STAT_LABEL[stat]

  return `${formatStat(stat, value)} ${words}`
}

/**
 * §8.0.1 -- a rolled line, which may name a line of its own or take its item's.
 *
 * Two kinds, and they are printed differently because they ARE different: a
 * percentage climbs toward §8.1's ceiling, and a solid number just adds. On a
 * gathering tool a flat `attack` is mining attack (§7.3), so it says so.
 */
export function optionStatLine(option: ItemOption, def: ItemDef): string {
  if (option.kind === 'flat') {
    const line = def.slot ? skillForSlot(def.slot) : null
    const what = line ? 'mining attack' : FLAT_LABEL[option.stat] ?? option.stat

    return `+${option.value} ${what}`
  }

  return statLine(
    option.stat,
    option.value,
    option.scope ?? (def.slot ? skillForSlot(def.slot) : null),
  )
}

/**
 * §9.5.4 -- one item's stats, as chips, in the order they should be read.
 *
 * One function so every screen that shows a piece shows the same thing in the
 * same order: the trader, the bench, the almanac, the bag and the gear list.
 *
 * Three rules, and each closes a way the old single line misled:
 *
 * 1. **`power` and `defense` are never printed as percentages.** They are the
 *    percentage twins of the flat pair (§9.5.4), and on a common weapon +3%
 *    power moves 5 attack to 5 — a number that does nothing, sitting where the
 *    number that does everything should be. The pair below IS that stat, said
 *    in the units it is actually felt in.
 * 2. **A zero half is not shown.** "0 attack" is not information; it is a slot
 *    the piece does not fill, and printing it makes every travel cloak look
 *    like it lost a fight.
 * 3. **A gathering tool's attack is its own** (§7.3) — the same word, a
 *    different ladder, and it is never in the pair because it is never in a
 *    fight (§8 rule 5).
 */
const FLAT_LABEL: Partial<Record<string, string>> = {
  attack: 'attack',
  defense: 'defense',
}

export interface StatChip {
  /** Short uppercase word, or null for a work stat that says its own name. */
  label: string | null
  value: string
}

const PAIR_STATS = new Set<StatKey>(['power', 'defense'])

export function statChips(def: ItemDef, options: ItemOption[] = []): StatChip[] {
  const chips: StatChip[] = []
  const line = def.slot ? skillForSlot(def.slot) : null

  // The work stat, when the piece has one worth saying. A tool has none at all
  // (its base is the attack below), and a weapon's `power` is not one worth
  // saying either: the pair is what that stat means.
  if (def.stat !== undefined && def.value !== undefined && !PAIR_STATS.has(def.stat)) {
    chips.push({ label: null, value: statLine(def.stat, def.value, line) })
  }

  if (line !== null) {
    const bite = toolAttack(def) + flatOption(options, 'attack')
    if (bite > 0) chips.push({ label: 'atk', value: String(bite) })

    return chips
  }

  const attack = (def.attack ?? 0) + flatOption(options, 'attack')
  const defense = (def.defense ?? 0) + flatOption(options, 'defense')

  if (attack > 0) chips.push({ label: 'atk', value: String(attack) })
  if (defense > 0) chips.push({ label: 'def', value: String(defense) })

  return chips
}

/** §8.0.1 -- what an item's flat rolled lines add to one solid number. */
export function flatOption(options: ItemOption[], stat: string): number {
  return options
    .filter((o) => o.kind === 'flat' && o.stat === stat)
    .reduce((sum, o) => sum + o.value, 0)
}
