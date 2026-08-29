/**
 * Pure game maths. No state, no I/O -- every function here takes its inputs
 * explicitly so the same logic can be ported to the Laravel side verbatim and
 * diffed against this file.
 *
 * IMPORTANT: the server owns these numbers. This module exists so the client
 * can *predict* and display them; it must never be the authority.
 */
import { EQUIPMENT, MINING, PROCESSING, SKILLS } from './balance'
import { ITEM_BY_KEY, LINE_STAT_LABEL, MATERIALS, SKILL_BY_KEY, STAT_LABEL, skillForSlot } from './catalog'
import type {
  BuffScope,
  Rarity,
  ItemDef,
  ItemOption,
  MaterialKey,
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

    // §8 -- a tool has no percentage at all; its base is a solid attack. And a
    // rolled line is not a contributor here either: every option is a solid
    // number on the pair now (§8.0.1), added where the solid number is read.
    if (def.stat === stat && def.value !== undefined) contributions.push({ def, value: def.value })
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

/**
 * §8 -- one item per slot, so picking a piece out of the pack is a question
 * about the piece already on the belt: what does the swap actually move.
 *
 * Projected through aggregateStat() rather than subtracted, because a stat is
 * not the item's own number. §8.1's falloff reorders every contributor and its
 * ceiling clamps the sum, so a piece worth twice as much on paper can be worth
 * nothing at all once it is on -- which is exactly the thing worth saying
 * before a swap rather than after it.
 */
export function aggregateAfterSwap(
  items: OwnedItem[],
  incoming: OwnedItem,
  outgoing: OwnedItem | null,
  stat: StatKey,
  line: SkillKey | null = null,
): number {
  const swapped = items.map((item) => {
    if (item.id === incoming.id) return { ...item, equipped: true }
    if (outgoing && item.id === outgoing.id) return { ...item, equipped: false }

    return item
  })

  return aggregateStat(swapped, stat, line)
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
/**
 * §8.2 -- what the trader gives for a piece of gear, as it stands.
 *
 * The basis is the shelf price where there is one and what the PARTS cost where
 * there is not -- the same pair §8.3 prices the shelf from -- then half of it,
 * then scaled by what is left of the durability.
 */
export function resaleValue(def: ItemDef, durability: number, max?: number): number {
  // §7.4.3 -- the PIECE's ceiling where the caller knows it. The catalog's is
  // the shelf's figure, right only where there is no piece yet.
  const ceiling = max || (def.maxDurability ?? 0)
  if (ceiling <= 0 || durability <= 0) return 0

  const price = resaleBasis(def)
  if (price <= 0) return 0

  return Math.floor(price * EQUIPMENT.resaleRate * (Math.min(durability, ceiling) / ceiling))
}

/**
 * What an undamaged piece is worth before wear comes off. Zero means the trader
 * does not deal in it at all, which is a different thing from a piece worn down
 * to nothing: the shop lists the second and refuses it.
 */
export function resaleBasis(def: ItemDef): number {
  // §3.2 -- gold reaches the bottom two rungs and stops.
  if (def.rarity !== 'common' && def.rarity !== 'uncommon') return 0

  // §8.2 -- what it is MADE OF wherever that is knowable. A shelf price is
  // make-cost marked up plus bench time (§8.3), and neither is yours to sell
  // back. Only shop stock with no recipe falls through to the tag.
  const parts = makeCost(def)

  return parts > 0 ? parts : (def.goldPrice ?? 0)
}

/** §8.3 -- what a thing's parts fetch at the NPC's own poor rate. */
export function makeCost(def: ItemDef): number {
  let worth = 0
  for (const [key, qty] of Object.entries(def.inputs ?? {})) {
    worth += (MATERIALS[key as MaterialKey]?.npcPrice ?? 0) * (qty as number)
  }

  return worth
}

/**
 * §8.2 -- what the trader gives for a potion, per flask.
 *
 * The mirror of Formulas::consumableResale(). A draft has no shelf price to
 * halve, because nothing stocks consumables -- so the price comes off what its
 * reagents fetch at the NPC's own poor rate, at the same resale rate gear uses,
 * and with no wear term because a potion has no durability to have spent.
 */
export function consumableResale(def: ItemDef): number {
  return Math.floor(makeCost(def) * EQUIPMENT.resaleRate)
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
export function repairCost(def: ItemDef, missingDurability: number, max?: number): Record<string, number> {
  const inputs = Object.entries(def.inputs ?? {}) as Array<[string, number]>
  if (inputs.length === 0 || missingDurability <= 0) return {}

  // §7.4.3 -- against the PIECE's ceiling where the caller knows it. A recipe's
  // materials buy one full piece, so a full mend costs one recipe's worth
  // however many points that is: a well-made piece is cheaper to keep.
  const fraction = missingDurability / Math.max(1, max || (def.maxDurability ?? 1))

  // §8.2 -- a repair costs a SHARE OF THE RECIPE, and the share is how much is
  // missing. The total first, the materials dealt out of it after: ceiling each
  // material on its own flattened the curve, because any quantity above zero
  // rounded to a whole unit and a scratch cost one of everything.
  const sum = inputs.reduce((n, [, qty]) => n + qty, 0)
  const total = Math.ceil(sum * fraction * EQUIPMENT.repairCostRate)
  if (total <= 0) return {}

  // Largest remainder, so the split keeps the recipe's proportions and the
  // pieces sum to exactly the total.
  const out: Record<string, number> = {}
  const remainders: Array<[string, number]> = []
  let dealt = 0

  for (const [key, qty] of inputs) {
    const exact = total * (qty / sum)
    const whole = Math.floor(exact)
    out[key] = whole
    remainders.push([key, exact - whole])
    dealt += whole
  }

  remainders.sort((a, b) => b[1] - a[1])
  for (const [key] of remainders) {
    if (dealt >= total) break
    out[key]++
    dealt++
  }

  for (const key of Object.keys(out)) {
    if (out[key] === 0) delete out[key]
  }

  return out
}

// -------------------------------------------------------------------- mining

export interface TripBreakdown {
  /** §7.3 -- how much work the hex is. The world rolls this and nothing else. */
  hp: number
  toolAttack: number
  skillAttack: number
  /** §7.4.3 -- whole points of attack off the line's own tree. */
  skillBite: number
  /** Work taken out of the hex per second, all in. */
  rate: number
  /** After clamp. This is the number that actually runs, or 0 if you cannot. */
  total: number
  /** True when the guard bound the result. It should never be, on real gear. */
  clamped: boolean
  /** §8.0 rule 1 -- false when nothing in your hands and nothing learned. */
  able: boolean
}

/**
 * §7.3 -- a hex is an amount of WORK, and a mine is how long you take over it.
 *
 *   rate      = (attack + skill_attack + skill_bite) * (1 + trip_reduction)
 *   trip_time = clamp(hp / rate, guard, ceiling)
 *
 * `attack` is the WHOLE base rate rather than a bonus on top of one: a pick is
 * what mines and a bow is what hunts, and neither verb has a bare-handed mode
 * to add to. Gathering passes MINING.bareHandAttack, because for that one verb
 * your hands are the tool.
 *
 * At zero attack there is no mine at all -- `able` is false and the caller says
 * so, rather than printing a clock nobody can reach.
 */
export function mineTime(
  hp: number,
  skillLevel: number,
  equipTripReduction: number,
  toolAttack = 0,
  skillBite = 0,
): TripBreakdown {
  const skill = skillAttack(skillLevel)
  const bite = Math.max(0, Math.min(skillBite, SKILLS.biteCap))
  const attack = Math.max(0, toolAttack) + skill + bite

  const rate = attack * (1 + Math.max(0, equipTripReduction))

  const raw = attack > 0 ? Math.round(hp / rate) : 0
  const total = attack > 0 ? Math.min(MINING.ceilingSeconds, Math.max(MINING.floorSeconds, raw)) : 0

  return {
    hp,
    toolAttack,
    skillAttack: skill,
    skillBite: bite,
    rate: Math.round(rate * 100) / 100,
    total,
    clamped: attack > 0 && total !== raw,
    able: attack > 0,
  }
}

/**
 * §7.3 -- what the line skill is worth per second. The one term every verb
 * shares. Floored, because ceil handed the first level of a line a free point.
 */
export function skillAttack(skillLevel: number): number {
  const level = Math.max(0, Math.min(skillLevel, SKILLS.maxLevel))

  return Math.floor(level / MINING.skillLevelsPerAttack)
}

/**
 * §4.0 -- what a pair of hands manages per second, all in.
 *
 * GATHERING's rate and nothing else's. Mining and hunting are refused without
 * their tool rather than downgraded, so no mine mixes hands and a tool.
 */
export function gatherAttack(skillLevel: number): number {
  return MINING.bareHandAttack + skillAttack(skillLevel)
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

/** Yield for one mine. Skill and equipment both add; ring adds the risk premium. */
export function mineYield(
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
  presenceBonus?: number,
): number {
  const tierSpeed = PROCESSING.speed[tier]
  const presenceSpeed = presence ? 1 - (presenceBonus ?? PROCESSING.presenceSpeedBonus) : 1
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
 * The stats stored as a reduction, and there are none left.
 *
 * There was one: `tripReduction`, a percentage off a mine's clock, which the
 * screen had to turn over because "+3%" beside the word "time" says the
 * opposite of what it does. §7.3 made a tool's attack the whole rate of a mine
 * and the stat became the tool's own ladder said twice, so it is gone -- but
 * the SET stays, because the day another stat is stored as a share of what it
 * removes, this is the one place that has to know.
 */
const REDUCTION_STATS = new Set<StatKey>()

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
 * §8.0.1 -- a rolled line, which is always a solid number on the pair.
 *
 * A tool's rolled `attack` is §7.3's mining attack and it is still said plainly
 * as attack: the piece's own chip says ATK, a tool has no other attack for it
 * to be confused with (§8 rule 5 keeps combat off a tool entirely), and a
 * second word for the one number is what made it hard to read.
 */
export function optionStatLine(option: ItemOption): string {
  return `+${option.value} ${FLAT_LABEL[option.stat] ?? option.stat}`
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

export const PAIR_STATS = new Set<StatKey>(['power', 'defense'])

/**
 * §8.5 -- the gathering line a buff scope names, or null.
 *
 * `travel`, `processing` and `battle` are actions without a line, so they get
 * the plain stat label: there is no "travel travel speed" to print.
 */
function lineScope(scope: BuffScope | undefined): SkillKey | null {
  return scope !== undefined && scope in SKILL_BY_KEY ? (scope as SkillKey) : null
}

export function statChips(def: ItemDef, options: ItemOption[] = []): StatChip[] {
  const chips: StatChip[] = []
  // A tool reads its line off its slot (§8.0.1); a potion has no slot and reads
  // it off the action it is armed for (§8.5). Both are line-locked and both
  // have to say so -- a Forest Draft that printed "+3% yield" would be claiming
  // to work five lines when it works one, and the whole reason seventy potions
  // are safe to exist is that each is bought for one thing you do.
  const line = def.slot ? skillForSlot(def.slot) : lineScope(def.scope)

  // The work stat, when the piece has one worth saying. A tool has none at all
  // (its base is the attack below), and a weapon's `power` is not one worth
  // saying either: the pair is what that stat means, and it is drawn below.
  //
  // A DRAFT is the exception, because it has no pair to be said in. §8.5's
  // battle drafts move `power` and `defense` and nothing else, so suppressing
  // the percentage there left the chip row empty -- a flask that told you
  // nothing at all about what it did.
  const pairSaidBelow = PAIR_STATS.has(def.stat as StatKey) && !def.consumable
  if (def.stat !== undefined && def.value !== undefined && !pairSaidBelow) {
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

/** §8.0.1 -- what an item's rolled lines add to one solid number. */
export function flatOption(options: ItemOption[], stat: string): number {
  return options.filter((o) => o.stat === stat).reduce((sum, o) => sum + o.value, 0)
}

// ------------------------------------------------------------------ the swap

/** One fact about a swap, and whether it moves the way the player wants. */
export interface SwapChange {
  text: string
  better: boolean
}

/** The solid pair (§9.5.4), rolled lines included -- what `statChips` prints. */
function solid(item: OwnedItem, def: ItemDef, stat: 'attack' | 'defense'): number {
  return (def[stat] ?? 0) + flatOption(item.options ?? [], stat)
}

/**
 * One piece's own percentage contribution to a stat, before any kit maths.
 *
 * Its base stat and nothing else: a rolled line is a solid number now (§8.0.1)
 * and is compared as one, in `solid()` above.
 */
function ownPercent(_item: OwnedItem, def: ItemDef, stat: StatKey): number {
  return def.stat === stat ? def.value ?? 0 : 0
}

/** Every percentage either piece touches. The pair is solid and is said apart. */
function percentStats(pieces: Array<{ item: OwnedItem; def: ItemDef }>): StatKey[] {
  const stats = new Set<StatKey>()

  for (const { def } of pieces) {
    if (def.stat && !PAIR_STATS.has(def.stat)) stats.add(def.stat)
  }

  return [...stats]
}

/** Both sides of one swap, or null where either piece is not in the catalog. */
function swapPieces(
  incoming: OwnedItem,
  outgoing: OwnedItem | null,
): { into: ItemDef; off: ItemDef | null; line: SkillKey | null } | null {
  const into = ITEM_BY_KEY[incoming.key]
  if (!into) return null

  const off = outgoing ? ITEM_BY_KEY[outgoing.key] ?? null : null
  if (outgoing && !off) return null

  return { into, off, line: into.slot ? skillForSlot(into.slot) : null }
}

/**
 * What the swap moves, said once per fact.
 *
 * §8 puts one item in a slot, so a piece in the pack is never a question on its
 * own: it is a question about the one already on the belt. Two rows of absolute
 * numbers is that question handed back to the player as arithmetic.
 *
 * The pair subtracts, because §9.5.4's numbers are solid. A percentage does
 * not: it is projected through the whole kit both ways (§8.1's falloff and
 * ceiling), so what is printed is what the swap is actually worth rather than
 * the difference between two labels.
 *
 * Against an empty slot the difference IS the piece, so the same call carries
 * the bare case without a second shape.
 */
export function swapChanges(
  items: OwnedItem[],
  incoming: OwnedItem,
  outgoing: OwnedItem | null,
): SwapChange[] {
  const pieces = swapPieces(incoming, outgoing)
  if (!pieces) return []

  const { into, off, line } = pieces
  const out: SwapChange[] = []

  for (const [stat, word] of [['attack', 'atk'], ['defense', 'def']] as const) {
    const delta =
      solid(incoming, into, stat) - (outgoing && off ? solid(outgoing, off, stat) : 0)

    if (delta !== 0) out.push({ text: `${delta > 0 ? '+' : ''}${delta} ${word}`, better: delta > 0 })
  }

  const both = [{ item: incoming, def: into }]
  if (outgoing && off) both.push({ item: outgoing, def: off })

  for (const stat of percentStats(both)) {
    const before = aggregateStat(items, stat, line)
    const after = aggregateAfterSwap(items, incoming, outgoing, stat, line)
    if (Math.abs(after - before) < 1e-9) continue

    out.push({ text: statLine(stat, after - before, line), better: after > before })
  }

  return out
}

/**
 * §8.1 rule 1 -- the swap that buys nothing.
 *
 * A better number on the label and no movement in the total means the kit is
 * already at the ceiling for that stat. Worth saying before the tap: it is the
 * one case where the obvious upgrade is not one.
 */
export function swapCeilingNote(
  items: OwnedItem[],
  incoming: OwnedItem,
  outgoing: OwnedItem | null,
): string {
  const pieces = swapPieces(incoming, outgoing)
  if (!pieces || !outgoing || !pieces.off) return ''

  const { into, off, line } = pieces

  for (const stat of percentStats([{ item: incoming, def: into }, { item: outgoing, def: off }])) {
    const before = aggregateStat(items, stat, line)
    const after = aggregateAfterSwap(items, incoming, outgoing, stat, line)
    if (Math.abs(after - before) > 1e-9) continue
    if (ownPercent(incoming, into, stat) <= ownPercent(outgoing, off, stat)) continue

    // The labels are stored mid-sentence ("mine time"), and this one opens one.
    const words = STAT_LABEL[stat]

    return `${words[0]!.toUpperCase()}${words.slice(1)} is capped on your kit — the surplus is wasted.`
  }

  return ''
}
