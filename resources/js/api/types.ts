/**
 * Wire contract between the client and the backend.
 *
 * These DTOs are the spec the Laravel API implements. Two rules shape all of it,
 * both from §16:
 *
 *  1. Server-authoritative everything. The client never sends a duration, an
 *     elapsed time, a yield or a drop -- it sends an intent ("mine this tile")
 *     and is told what happened.
 *  2. Every mutating call returns the full fresh PlayerState. Partial patches
 *     invite desync; an idle game with long timers cannot afford it.
 */
import type {
  ActiveBuff,
  ItemOption,
  Job,
  MaterialKey,
  OwnedItem,
  Rarity,
  Settlement,
  SkillKey,
  StatKey,
  TravelState,
} from '@/game/types'
import type { WorldConfig } from '@/game/worldgen'

export interface CharacterDto {
  id: string
  name: string
  /**
   * §7 -- whether `name` is a name this character claimed or the label every
   * unnamed one is drawn with. The string alone cannot say: somebody could have
   * been called Prospector, except that the one name nobody may claim is that.
   */
  named: boolean
  /** Truncated for display; the backend keys everything off the full address. */
  wallet: string
  level: number
  xp: number
  xpToNext: number
  gold: number
  col: number
  row: number
  /** §7.6 -- what is in the pack, against the two limits on it. */
  bag: BagDto
  /**
   * §5.6 -- how far the character can see, in hexes. One standing still, zero
   * on the road. Not a reach: every hex on the map is walkable.
   */
  sight: number
  /** §8.3 -- wall-clock ms to cross one hex at this character's pace. */
  travelPerHexMs: number
}

/**
 * §7.6 -- the bag.
 *
 * Two limits, not one. `units` is the weight of everything carried -- materials,
 * potions, and every piece of gear not being worn -- and `rows` is how many
 * separate things that is. Over either one and the character cannot travel,
 * which is what `over` is for: the map reads it rather than comparing the pairs
 * itself, so the client and the server never disagree about whether a walk is
 * allowed.
 */
export interface BagDto {
  units: number
  unitCap: number
  rows: number
  rowCap: number
  over: boolean
}

export interface SkillDto {
  key: SkillKey
  level: number
  xp: number
  xpToNext: number
}

/** Where a stopped journey actually left you, and how far that was. */
export interface TravelStop {
  col: number
  row: number
  hexes: number
  settlement: Settlement | null
}

export interface PlayerState {
  /** Authoritative clock. The client renders countdowns against this, offset by
   *  its own drift measurement -- it never trusts Date.now() alone. */
  serverTime: number
  /**
   * How much the server compresses game timers (1 = real 30-60 minute mines).
   * Published rather than configured on both sides, so predicted durations and
   * real countdowns cannot drift apart.
   */
  timeScale: number
  character: CharacterDto
  skills: Record<SkillKey, SkillDto>
  inventory: Partial<Record<MaterialKey, number>>
  equipment: OwnedItem[]
  jobs: Job[]
  /** Settlement the player is currently present at, §6.2. */
  presenceAt: string | null
  /**
   * §10 -- the guild this character belongs to, or null.
   *
   * On the state rather than fetched, because membership decides what a bench
   * will make (§8.0's legendary rung) and the two must never disagree.
   */
  /**
   * §9.5.4 -- the flat pair, and the durability pool that is the health bar.
   *
   * Solid numbers, not the `power`/`defense` percentages on `bonuses`.
   */
  combat: {
    attack: number
    defense: number
    pool: number
    job: string | null
    jobLevel: number
  }
  guild: GuildStateSummary | null
  /** §10.0 -- standing in your own hall, which is the one question the bench asks. */
  atGuildHall: boolean
  /**
   * §9.5.7 -- YOUR corpses, at any distance and through any fog.
   *
   * Here rather than on the map, and that split is what makes the two
   * endpoints mean one thing each: this is what is yours and is bounded by
   * nothing, the map is what is around you and is bounded by sight (§5.6).
   */
  carriers: Carrier[]
  /** The journey under way, or null when standing still. */
  travel: TravelState | null
  /**
   * The settlement the character is standing on, or null out in the field.
   * Trading, crafting and processing all require one -- there is no trader in
   * the middle of a forest. Sent by the server rather than derived locally so
   * the UI and the rules cannot disagree.
   */
  standingAt: Settlement | null
  /**
   * The hex under the character's feet, already costed. The dock acts on this
   * rather than on the selection, because you work the ground you stand on.
   */
  underfoot: TilePreview
  /** Item keys this settlement stocks. Bigger settlements carry more, §3.2. */
  shopStock: string[]
  /**
   * Aggregated equipment bonuses, already capped and diminished server-side.
   * Gear that works everywhere only -- §8 gathering tools are line-locked and
   * are reported per line in `toolYield`.
   */
  bonuses: Record<StatKey, number>
  /** What each line's equipped tool is worth on its own mines, §8. */
  toolYield: Record<SkillKey, number>
  /** §8.5 -- potions on the shelf, keyed by item. */
  consumables: Record<string, number>
  /** §8.5 -- effects running right now. Expiry is a server-clock deadline. */
  buffs: ActiveBuff[]
  /** §7.4.1 -- one point per character level, spent on tree nodes. */
  skillPoints: SkillPoints
  /** §7.4 -- one row per job. A level here gates nodes and grants nothing. */
  jobLevels: JobLevel[]
  /** §7.4.2 -- node keys bought. Bought, never refunded. */
  nodes: string[]
  /**
   * §12.1 -- where every *visible* quest stands. A quest whose prerequisite is
   * unclaimed is not in this list at all: what is next should be legible, and
   * what comes after that is not yet the player's problem.
   */
  quests: QuestState[]
}

/** §12.1 -- one quest's standing. The catalog behind it comes from GET /quests. */
export interface QuestState {
  key: string
  /** Held at the target rather than run past it. */
  progress: number
  /** Whether the goal is met. Server-decided: it is a rule, and §16 owns rules. */
  complete: boolean
  /** Claimed pays once and never comes back. */
  claimed: boolean
  claimedAt: number | null
}

/**
 * §12.1 -- a quest, as written down. Static and identical for everyone, so it is
 * fetched once when the panel first opens rather than riding every refresh.
 */
export interface QuestDef {
  name: string
  description: string
  goal: {
    kind: 'gather' | 'process' | 'craft' | 'travel' | 'sell' | 'level' | 'job'
    /** Narrows the counter: a material, a line, a category, a job. Null is any. */
    subject: string | null
    target: number
  }
  /** §3.2 -- gold, and only ever gold. */
  gold: number
  /** The quest that must be *claimed* before this one is offered. */
  requires: string | null
}

/** What a claim paid, and what it opened. Rendered as a receipt, never a toast. */
export interface QuestReward {
  quest: string
  name: string
  gold: number
  goldAfter: number
  unlocked: Array<{ key: string; name: string }>
}

export interface SkillPoints {
  total: number
  spent: number
  available: number
}

export interface JobLevel {
  key: string
  level: number
  xp: number
  xpToNext: number
}

/** §7.4.3 -- what a node does. One of these kinds, and nothing else. */
export type NodeEffect =
  | { kind: 'stat'; stat: StatKey; value: number }
  /**
   * §9.5.4 -- the SOLID pair. Whole points of attack or defense, added to the
   * gear before the percentages multiply, and locked to the weapon family the
   * job is fought with. Not a percentage and not under §8.1's ceiling.
   */
  | { kind: 'pair'; stat: 'attack' | 'defense'; value: number }
  /**
   * §9.5.6 -- the two wear streams, spared. What hit you comes off the armor
   * and what you hit comes off the blade, so a tree that wants both buys both.
   */
  | { kind: 'battleWear'; value: number }
  | { kind: 'weaponWear'; value: number }
  /** §9.5.8 -- what a pack pays: coin, and what its kit was carrying. */
  | { kind: 'goldFind'; value: number }
  | { kind: 'lootOption'; value: number }
  | { kind: 'craftOption'; value: number }
  | { kind: 'craftDurability'; value: number }
  /** §8.0.1 -- a deeper reach into the bag a rolled line is drawn from. */
  | { kind: 'optionTier'; value: number }
  /** §8.4 -- the consumable bench: a potion has no durability and no line. */
  | { kind: 'brewExtra'; value: number }
  | { kind: 'stackCap'; value: number }
  | { kind: 'costReduction'; value: number }
  | { kind: 'batch'; value: number }
  /** §6.2 -- worth more for standing there, and §6.1's second run. */
  | { kind: 'presence'; value: number }
  | { kind: 'runSlot'; value: number }
  /** §7.3 -- the line's tool spared a mine's wear. */
  | { kind: 'toolWear'; value: number }
  /** §7.3 -- whole points of mining attack on this line. A count, not a stat. */
  | { kind: 'bite'; value: number }
  /** §9.5.9 -- what the family's three battle skills are worth, and how often. */
  | { kind: 'skillPower'; value: number }
  | { kind: 'skillCooldown'; value: number }
  | { kind: 'skillStun'; value: number }
  /** §5.1 -- a mine that comes up one grade better than the tool can take. */
  | { kind: 'seamGrade'; value: number }
  /** §7.5 -- whole hexes of sight, on top of the base one. Not a percentage. */
  | { kind: 'sight'; value: number }
  /** §7.6 -- units and rows of bag, on top of the flat base. Counts, not stats. */
  | { kind: 'bagUnits'; value: number }
  | { kind: 'bagRows'; value: number }

export interface JobDef {
  name: string
  kind: 'craft' | 'battle'
  source: string
  palette: string
  description: string
}

export interface NodeDef {
  job: string
  tier: number
  jobLevel: number
  name: string
  effect: NodeEffect
  requires: string[]
  description: string
}

/**
 * §7.4 -- the trees, served rather than mirrored into catalog.ts. Static and
 * identical for everyone, so it is fetched once when the panel first opens
 * instead of riding along with every state refresh.
 */
export interface SkillTree {
  jobs: Record<string, JobDef>
  nodes: Record<string, NodeDef>
  tierJobLevel: Record<number, number>
  tierSize: Record<number, number>
  /** §7.5 -- jobs whose nodes are granted by job level, never bought. */
  automatic: string[]
  jobMaxLevel: number
  /**
   * §7.4.3 -- what each non-stat kind stops at, keyed as the effects are.
   *
   * Served rather than mirrored: these caps are what protect the §11 sinks, and
   * a second copy here would be a second opinion about where a tree ends.
   * `stat` is absent on purpose — those have no cap of their own, they join
   * gear's aggregate and stop at STAT_CEILING (§8.1 rule 1).
   */
  caps: Partial<Record<NodeEffect['kind'], number>>
}

/** Server-computed preview of what a mine on this tile would cost and give. */
/**
 * What one verb on one hex would cost and give, §7.3.
 *
 * Mining and gathering are the same shape because they are the same mine on
 * the same ground -- a tile slot, a bag row, a clock and a haul. What differs
 * is the table the haul comes off and whether a tool is required, and both of
 * those are already answered by the fields below.
 */
export interface WorkPreview {
  canMine: boolean
  reason?: string
  /** 0 when the verb cannot be done at all -- see `able`. */
  seconds: number
  /** §7.3 -- how much work the hex is, and how fast you get through it. */
  hp: number
  toolAttack: number
  skillAttack: number
  /** §7.4.3 -- whole points of attack off the line's own tree. */
  skillBite: number
  rate: number
  clamped: boolean
  /** §8.0 rule 1 -- false with nothing in your hands and nothing learned. */
  able: boolean
  yield: number
  /** The line this hex belongs to, even when the haul comes back as scrap. */
  skill: SkillKey | null
  /** §4.0 -- true when this costing is the bare-handed verb and pays scrap. */
  scrap: boolean
  /** §8.0 -- no working tool for this hex's line, so mining has nothing behind it. */
  bare: boolean
  /** What the haul will really be, naming the tool that would change it. */
  note: string | null
  material: MaterialKey | null
  /**
   * §4 -- everything this ground can give up, most likely first.
   *
   * The ODDS are deliberately absent. Naming them would turn a hex into a
   * spreadsheet and the decision into arithmetic; what a prospector is owed is
   * what is here, which is a fact about the place, and how often is what the
   * mine is for.
   */
  drops: MaterialKey[]
  /** Which of the three verbs this costing is for. */
  activity: 'gathering' | 'mining' | 'hunting'
  /**
   * §9.5.3 -- something is standing on the hex under your feet.
   *
   * Set on every costing in sight, not only this hex's: the pin is about the
   * ground you are on, so while it holds there is no work anywhere.
   */
  pinned: boolean
  /**
   * §8.2 -- gear this mine would wear out entirely, named before it happens.
   * Line-locked like the wear: the axe on your back is not at risk in a mine.
   */
  warnings: string[]
  /**
   * §5.6 -- true when the hex is outside sight, and everything above it is
   * therefore blank rather than zero. The server will not cost an unscouted
   * hex, so the card reports the walk instead of the seam.
   */
  unseen: boolean
}

/** The hex as the dock reads it: the tool's verb, with the other two hung off it. */
export interface TilePreview extends WorkPreview {
  /**
   * §4.0 -- the same hex worked by hand, costed in the same request.
   *
   * Always present and almost always available: there is no tool to lack, which
   * is the whole of what makes it the floor under the §8.0 ladder.
   */
  gather: WorkPreview
  /** §5.5 -- the third verb on this hex, costed in the same request. */
  hunt: HuntPreview
}

/**
 * §5.5 -- what working a herd here would cost and give.
 *
 * Separate from TilePreview rather than folded into it, because a hunt is a
 * different verb and not a mode of mining: it takes no tile slot, depletes
 * nothing, and pays a Tier 4 material the seam never does.
 */
export interface HuntPreview {
  canHunt: boolean
  reason?: string | null
  seconds: number
  /** §7.3 -- a herd is a pile of work read exactly as a hex is, and the bow is
   *  what gets through it. Same four numbers the seam reports. */
  hp: number
  toolAttack: number
  skillAttack: number
  /** §7.4.3 -- whole points of attack off the line's own tree. */
  skillBite: number
  rate: number
  clamped: boolean
  able: boolean
  /** Server-clock deadline the herd wanders off at, or null if there is none. */
  herdUntil: number | null
  yield: number
  material: MaterialKey | null
  /** Always false: a hunt is refused outright without a bow, never downgraded. */
  scrap: boolean
  /** §4 -- what a herd on this ground can give up, most likely first. */
  drops?: MaterialKey[]
  note: string | null
  unseen: boolean
}

/**
 * What the server sends for a viewport. Terrain is NOT in here -- the client
 * generates it from the world config (§5). These are the two facts it cannot
 * derive, as compact tuples because this fires on every pan.
 *
 *   depleted: [col, row, regrowsAt]
 *   occupied: [col, row, slotsUsed]
 */
export interface MapMutations {
  depleted: Array<[number, number, number]>
  occupied: Array<[number, number, number]>
  /** §9.5.1 -- packs in sight that somebody has already fought, win or lose. */
  cleared: Array<[number, number]>
  /** §9.5.7 -- other people's corpses, inside sight like everything else here. */
  carriers: Carrier[]
}

/**
 * §9.5.7 -- a marked enemy holding somebody's row, standing where they fell.
 *
 * Your own is the one thing in the game outside the fog, because a debt you
 * cannot find is a fine with extra steps. Somebody else's obeys the fog like
 * everything else -- a live map of every death on the server would be a
 * scanner. What it holds is named; what it would cost to take is not, until you
 * are standing there.
 */
export interface Carrier {
  col: number
  row: number
  monster: string
  /** What it took, in words: "9 × Wood", "Iron Pickaxe". */
  label: string
  /** Unix ms it crumbles, and the row with it. */
  until: number
  /** §2 -- only the owner may take it back. Anybody else kills the row. */
  mine: boolean
  owner: string
}

/**
 * §9.5.5 -- what the fight standing on your hex would cost, before you take it.
 *
 * Priced server-side like every other outcome. The odds are the point: a forced
 * encounter you can see the odds of is a decision, and one you cannot is a
 * gamble.
 */
export interface BattlePreview {
  canFight: boolean
  reason: string | null
  /** Unix ms the pack wanders off, which is the other way out of the pin. */
  until?: number
  /** §9.5.5 -- how long the swing takes. A fight is work, not a button. */
  seconds?: number
  monster?: {
    key: string
    name: string
    tier: number
    profile: 'brute' | 'carapace' | 'swift'
    attack: number
    defense: number
    /** §9.5.5 -- what it has to be worked through. Its half of the exchange. */
    hp: number
    description: string
  }
  /** Your flat pair, gear plus battle job, §9.5.4. */
  attack?: number
  defense?: number
  /** §9.5.5 -- your HP, which is the durability of the kit you are wearing. */
  pool?: number
  /** §9.5.5 -- the exchange with the swing taken out. A promise, not a guess. */
  expected?: {
    won: boolean
    rounds: number
    damageTaken: number
    damageDealt: number
    left: number
    foeLeft: number
  }
  odds?: number
  /** §9.5.4 -- the battle job the equipped weapon levels, and where it stands. */
  job?: string | null
  jobLevel?: number
  /** §9.5.6 -- what the exchange would take off the kit, and off the blade. */
  wear?: { pool: number; taken: number; weapon: number }
  /** §8.2 -- gear this fight could destroy outright, named before it happens. */
  warnings?: string[]
  /** §9.5.7 -- set when the thing standing here is a corpse rather than a pack. */
  corpse?: { mine: boolean; label: string; owner: string } | null
}

/**
 * §9.5.5 -- how it went, and what it cost.
 *
 * The whole of a fight, because there is no health on either side and so
 * nothing to watch tick down: one roll, two wear rolls, and a pack that is gone
 * whichever way it landed. Everything here is the server's (§16) -- the client
 * rolled nothing and may not re-derive any of it.
 */
export interface BattleResult {
  won: boolean
  /** What the preview promised, kept alongside the outcome it produced. */
  odds: number
  monster: {
    key: string
    name: string
    tier: number
    profile: 'brute' | 'carapace' | 'swift'
    attack: number
    defense: number
    hp: number
  }
  attack: number
  defense: number
  /** §9.5.5 -- the exchange, as it actually went. */
  rounds: number
  pool: number
  damageTaken: number
  damageDealt: number
  /** §9.5.8 -- gold needs no bag row, which is why a fight can always pay it. */
  gold: number
  job: string | null
  jobXp: number
  /** §9.5.6 -- the weapon, and the one worn piece that took the hit. */
  wear: BattleWear[]
  /** §8.2 -- named here because nothing may be destroyed quietly. */
  destroyed: string[]
  /** §9.5.8 -- monster materials, keyed by material. Combat feeds combat. */
  spoils: Record<string, number>
  /** Units that had no strap to land on (§7.6). */
  spoilsLost: number
  /** §9.5.8 -- the kit it was using, at 5-50% and never past rare. */
  looted: {
    key: string
    name: string
    rarity: string
    durability: number
    maxDurability: number
    options: ItemOption[]
  } | null
  /** Loot the bag had no room for, named rather than silently dropped. */
  leftBehind: string | null
  /** §9.5.7 -- what was standing here, when it was a corpse rather than a pack. */
  corpse: { mine: boolean; label: string; owner: string | null } | null
  /** The row that came home, when its owner was the one standing over it. */
  recovered: string | null
  /** §2 -- the row a stranger's kill destroyed instead of moving. */
  burned: string | null
  /** §9.5.7 -- a loss that nothing absorbed. */
  died: boolean
  stolen: { label: string; kind: string } | null
  wokeAt: { name: string; col: number; row: number } | null
}

export interface BattleWear {
  name: string
  slot: string | null
  lost: number
  left: number
  destroyed: boolean
}

/** Standard envelope: the result of the action plus the new authoritative state. */
export interface ActionResult<T = unknown> {
  data: T
  state: PlayerState
  /** Human-readable line for the activity log. */
  message?: string
}

/** §9.5.9 -- a skill as the panel draws it: what it does, and its figures. */
export interface BattleSkillRow {
  key: string
  /** The key it is stored under once learned, and the one buyNode takes. */
  node: string
  name: string
  glyph: string
  cooldown: number
  /** §9.5.9 -- the battle job level it opens at. */
  jobLevel: number
  known: boolean
  canLearn: boolean
  effect: string
  stats: Array<{ label: string; value: string }>
}

export interface CollectResult {
  gained: Partial<Record<MaterialKey, number>>
  /**
   * §8.4 -- what a craft handed over. A bench makes one THING, not a haul, so
   * this is set exactly when `gained` is empty for a reason rather than for
   * want of a strap.
   */
  made?: {
    key: string
    name: string
    consumable: boolean
    quantity?: number
    durability?: number
  } | null
  /** Units that did not fit: the §2 per-wallet cap, or a full bag (§7.6). */
  lostToOverflow: number
  xp: { skill: SkillKey; amount: number }
  /**
   * §7.4 -- the bench trade this taught, if any. A craft teaches no §7.2 skill
   * and no character XP, so this is the ONLY figure on a craft receipt; a
   * processing run teaches both its line and its job.
   */
  job: string | null
  jobXp: number
  characterXp: number
  levelsGained: number
  durabilityLost: number
  /** §8.2 -- gear the mine finished off. Named, because nothing goes quietly. */
  destroyed: string[]
}

/**
 * §10 -- a guild, as everybody else sees it.
 *
 * A guild is a PLACE before it is a roster (§10.0), so the hall is on the
 * summary rather than looked up: where it stands is most of what deciding to
 * join one is about.
 */
export interface GuildSummary {
  id: string
  name: string
  /** A short tag, shown wherever the name will not fit. */
  code: string
  description: string
  /** §10.0.3 -- 1024 colors, base64 of 3072 raw RGB bytes. Null until drawn. */
  flag: string | null
  settlementId: string
  settlementName: string | null
  col: number
  row: number
  /**
   * §10.0.1 -- the door, in one setting with three positions.
   *
   *   closed    not listed, nobody gets in
   *   open      listed, and walking in is enough
   *   approval  listed, and the owner decides
   */
  recruitment: GuildDoor
  members: number
  /** §10.5 -- seats bought over the flat base. */
  hallLevel: number
  /** §10.5 -- rungs bought over what the settlement underneath already reached. */
  benchLevel: number
  /** §10.5 -- how far up §8.0's ladder this guild's own bench reaches. */
  benchReach: Rarity
  /** §10.5 -- how many the hall seats, all in. */
  rosterCap: number
}

/**
 * §10.4 -- the treasury and its prices, which only your own guild tells you.
 *
 * A pot whose size a rival can read is a pot that can be outbid to the coin,
 * and that is the same argument that makes a donation non-retractable.
 */
export interface GuildTreasury {
  gold: number
  /** The last Bench level worth buying — the one that reaches legendary. */
  benchMaxLevel: number
  /** Gold the next level costs, or null when the facility is finished. */
  hallCost: number | null
  benchCost: number | null
}

export type GuildRole = 'owner' | 'officer' | 'member'
export type GuildDoor = 'closed' | 'open' | 'approval'

/** §10.0.1 -- somebody who has asked to be let in. */
export interface GuildApplicant {
  characterId: string
  name: string
  level: number
  appliedAt: number
}

export interface GuildMemberRow {
  characterId: string
  name: string
  level: number
  role: GuildRole
  joinedAt: number
  /** §10.2 -- by contribution, never equal share. Who carried the hall. */
  donated: number
}

/** Your own guild carries its roster and its post; somebody else's never does. */
export interface GuildStateSummary extends GuildSummary, GuildTreasury {
  /** §10.0.2 -- how many are waiting at the door. Nil unless you are an officer. */
  pending: number
}

export interface GuildDetail extends GuildSummary, GuildTreasury {
  roster: GuildMemberRow[]
  applications: GuildApplicant[]
}

export interface GuildDirectory {
  /** §10.0.1 -- the recruiting ones, and only those. */
  guilds: GuildSummary[]
  mine: GuildDetail | null
  /** §10.0.1 -- guild ids this character is already waiting on an answer from. */
  applied: string[]
  /** §10.0 -- what a hall costs, published rather than compiled in. */
  cost: number
  flagSize: number
}

export interface QueueSlot {
  index: number
  job: Job | null
  /** Whose job occupies the slot -- the public queue is shared, §6.1. */
  owner: string | null
}

export interface StationState {
  settlement: Settlement
  slots: QueueSlot[]
  /** §8.4 -- the benches queue like the lines do, in a bank of their own. */
  bench: QueueSlot[]
  /** True when the player is standing here and processing runs faster, §6.2. */
  presence: boolean
  /**
   * §6.3 -- your own allowance at this settlement, per line it runs.
   *
   * Separate from `slots`, which is everybody's congestion. The two refuse for
   * different reasons and a panel that conflated them would tell a player to
   * wait for a stranger when the thing in the way was their own run.
   */
  runs: Record<string, { going: number; allowed: number }>
  /** §6.1 + §8.4 -- unclaimed work you have out across the whole map, and the cap. */
  outstanding: number
  outstandingCap: number
}

/** The one interface both drivers implement. Swapping local -> http is a
 *  one-line change in client.ts once the Laravel routes exist. */
export interface GameApi {
  getState(): Promise<PlayerState>
  /** Generation parameters. Fetched once at boot. */
  getWorld(): Promise<WorldConfig>
  getMap(): Promise<MapMutations>
  previewTile(col: number, row: number): Promise<TilePreview>
  /** §9.5.5 -- no coordinates: the only fight on offer is the one under your feet. */
  previewBattle(): Promise<BattlePreview>

  // ---------------------------------------------------------------- §10 guilds
  getGuilds(): Promise<GuildDirectory>
  foundGuild(identity: {
    name: string
    code: string
    description: string
    flag: string | null
  }): Promise<ActionResult<GuildDetail>>
  /** §10.0.1 -- joins, or leaves your name, depending on the door. */
  joinGuild(guildId: string): Promise<ActionResult<GuildDetail | null>>
  withdrawApplication(guildId: string): Promise<ActionResult<null>>
  decideApplication(characterId: string, admit: boolean): Promise<ActionResult<GuildDetail | null>>
  leaveGuild(): Promise<ActionResult<null>>
  updateGuild(changes: {
    description?: string
    flag?: string | null
    recruitment?: GuildDoor
  }): Promise<ActionResult<GuildDetail>>
  removeGuildMember(characterId: string): Promise<ActionResult<GuildDetail | null>>
  setGuildRole(characterId: string, role: GuildRole): Promise<ActionResult<GuildDetail | null>>
  /** §10.5 -- gold into the treasury. It does not come back out. */
  donateToGuild(gold: number): Promise<ActionResult<GuildDetail>>
  /** §10.5 -- spend it on a facility level. Owner only. */
  upgradeGuildFacility(facility: 'hall' | 'bench'): Promise<ActionResult<GuildDetail>>
  /** §9.5.5 -- starts a fight and answers with the JOB; the report is on collect. */
  fight(): Promise<ActionResult<Job>>

  startMining(col: number, row: number): Promise<ActionResult<Job>>
  /** §4.0 -- the same hex, by hand. Its own call because it is its own verb. */
  startGathering(col: number, row: number): Promise<ActionResult<Job>>
  /** §5.5 -- work a herd marker. Its own verb, not a mode of mining. */
  startHunt(col: number, row: number): Promise<ActionResult<Job>>
  /** §9.5.5 -- a battle job answers with a BattleResult, everything else a haul. */
  collectJob(jobId: string): Promise<ActionResult<CollectResult | BattleResult>>
  abandonJob(jobId: string): Promise<ActionResult<null>>

  getStation(settlementId: string): Promise<StationState>
  startProcessing(settlementId: string, recipeKey: string, batches: number): Promise<ActionResult<Job>>

  travelTo(col: number, row: number): Promise<ActionResult<TravelState>>
  cancelTravel(): Promise<ActionResult<TravelStop>>

  buyItem(itemKey: string): Promise<ActionResult<OwnedItem>>
  sellMaterial(material: MaterialKey, quantity: number): Promise<ActionResult<{ gold: number }>>
  /** §4.0 -- dump every tier-zero stack at once. The server decides what counts. */
  sellScrap(): Promise<ActionResult<{ gold: number; rows: number }>>
  /** §8.2 -- sell one piece of gear back, priced off its remaining durability. */
  sellEquipment(ownedId: string): Promise<ActionResult<{ gold: number }>>
  /** §8.4 -- returns the bench JOB, because there is no item yet. */
  craftItem(itemKey: string): Promise<ActionResult<Job>>

  equipItem(ownedId: string): Promise<ActionResult<null>>
  unequipItem(ownedId: string): Promise<ActionResult<null>>
  repairItem(ownedId: string): Promise<ActionResult<null>>
  /**
   * §9.5.9 -- the three each battle job knows, keyed by job.
   *
   * Separate from getSkillTree() because those are the same for everybody and
   * these carry the caller's own figures: a bought `skillStun` moves them.
   */
  getBattleSkills(): Promise<Record<string, BattleSkillRow[]>>

  /** §7 -- claim a name. Letters and digits, and nobody else's. */
  renameCharacter(name: string): Promise<ActionResult<{ name: string }>>

  discardItem(ownedId: string): Promise<ActionResult<null>>
  /** §11.1 -- tip materials out. Nothing comes back; you are buying room. */
  discardMaterial(
    material: MaterialKey,
    quantity: number,
  ): Promise<ActionResult<{ dropped: number }>>
  /** §8.5 -- drink one, starting a timed buff. */
  useConsumable(item: string): Promise<ActionResult<ActiveBuff>>

  /** §7.4 -- the static tree. Fetched once, never per action. */
  getSkillTree(): Promise<SkillTree>
  /** §7.4 -- spend one point. Every gate is checked server-side. */
  buyNode(nodeKey: string): Promise<ActionResult<{ node: string; points: SkillPoints }>>

  /** §12.1 -- the static quest catalog. Fetched once, never per action. */
  getQuests(): Promise<Record<string, QuestDef>>
  /** §12.1 -- take the gold, once. Answers with no message: the receipt says it. */
  claimQuest(quest: string): Promise<ActionResult<QuestReward>>
}

export class ApiError extends Error {
  constructor(
    message: string,
    readonly code: string = 'error',
  ) {
    super(message)
    this.name = 'ApiError'
  }
}
