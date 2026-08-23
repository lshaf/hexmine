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

    if (def.stat === stat) contributions.push({ def, value: def.value })
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
  skillReduction: number
  equipReduction: number
  /** After clamp. This is the number that actually runs. */
  total: number
  /** True when the clamp bound the result -- surfaced in the UI so the player
   *  can see that more gear would be wasted here. */
  clamped: boolean
}

/**
 * §7.3
 *   trip_time = clamp(base - skill_reduction - equipment_reduction, 30min, 60min)
 *
 * The floor clamp is mandatory and is in the formula from day one: without it
 * any future buff or equipment tier creates a sub-30-minute or zero-time
 * exploit. Do not remove it, and do not apply bonuses after it.
 */
export function tripTime(
  baseSeconds: number,
  skillLevel: number,
  equipTripReduction: number,
): TripBreakdown {
  const skillProgress = Math.min(1, skillLevel / SKILLS.maxLevel)
  const skillReduction = Math.round(MINING.maxSkillReductionSeconds * skillProgress)

  const equipProgress = Math.min(1, equipTripReduction / EQUIPMENT.statCeiling)
  const equipReduction = Math.round(MINING.maxEquipReductionSeconds * equipProgress)

  const raw = baseSeconds - skillReduction - equipReduction
  const total = Math.min(MINING.ceilingSeconds, Math.max(MINING.floorSeconds, raw))

  return { base: baseSeconds, skillReduction, equipReduction, total, clamped: total !== raw }
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

/** What an item is for, §8. A gathering tool names its line; worn gear has none. */
export const itemStatLine = (def: ItemDef): string =>
  statLine(def.stat, def.value, def.slot ? skillForSlot(def.slot) : null)

/** §8.0.1 -- a rolled line, which may name a line of its own or take its item's. */
export const optionStatLine = (option: ItemOption, def: ItemDef): string =>
  statLine(
    option.stat,
    option.value,
    option.scope ?? (def.slot ? skillForSlot(def.slot) : null),
  )
