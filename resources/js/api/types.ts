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
  Job,
  MaterialKey,
  OwnedItem,
  Settlement,
  SkillKey,
  StatKey,
  TravelState,
} from '@/game/types'
import type { WorldConfig } from '@/game/worldgen'

export interface CharacterDto {
  id: string
  name: string
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
  /** §12 -- index into the tutorial script; -1 once finished. */
  tutorialStep: number
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
   * How much the server compresses game timers (1 = real 30-60 minute trips).
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
  /** What each line's equipped tool is worth on its own trips, §8. */
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
  | { kind: 'unlock'; target: string }
  | { kind: 'craftOption'; value: number }
  | { kind: 'craftDurability'; value: number }
  | { kind: 'costReduction'; value: number }
  | { kind: 'batch'; value: number }
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
}

/** Server-computed preview of what a trip on this tile would cost and give. */
export interface TilePreview {
  canMine: boolean
  reason?: string
  seconds: number
  baseSeconds: number
  skillReduction: number
  equipReduction: number
  clamped: boolean
  yield: number
  /** The line this hex belongs to, even when the haul comes back as scrap. */
  skill: SkillKey | null
  /** §4.0 -- true when there is no tool for the line and `material` is scrap. */
  scrap: boolean
  /** Why the haul is scrap, naming the tool that would fix it. */
  note: string | null
  material: MaterialKey | null
  /**
   * §5.6 -- true when the hex is outside sight, and everything above it is
   * therefore blank rather than zero. The server will not cost an unscouted
   * hex, so the card reports the walk instead of the seam.
   */
  unseen: boolean
  /** §5.5 -- the other verb on this hex, costed in the same request. */
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
  /** Server-clock deadline the herd wanders off at, or null if there is none. */
  herdUntil: number | null
  yield: number
  material: MaterialKey | null
  /** §4.0 -- true when there is no bow and the haul comes back as Torn Hide. */
  scrap: boolean
  /**
   * §5.5 -- odds of essence on top. Zero without a bow, and that is a rule
   * rather than a tuning value: bare hands must not reach a Tier 4 material.
   */
  essenceChance: number
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
}

/** Standard envelope: the result of the action plus the new authoritative state. */
export interface ActionResult<T = unknown> {
  data: T
  state: PlayerState
  /** Human-readable line for the activity log. */
  message?: string
}

export interface CollectResult {
  gained: Partial<Record<MaterialKey, number>>
  /** Units that did not fit: the §2 per-wallet cap, or a full bag (§7.6). */
  lostToOverflow: number
  xp: { skill: SkillKey; amount: number }
  characterXp: number
  levelsGained: number
  durabilityLost: number
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
  /** True when the player is standing here and processing runs faster, §6.2. */
  presence: boolean
}

/** The one interface both drivers implement. Swapping local -> http is a
 *  one-line change in client.ts once the Laravel routes exist. */
export interface GameApi {
  getState(): Promise<PlayerState>
  /** Generation parameters. Fetched once at boot. */
  getWorld(): Promise<WorldConfig>
  getMap(): Promise<MapMutations>
  previewTile(col: number, row: number): Promise<TilePreview>

  startMining(col: number, row: number): Promise<ActionResult<Job>>
  /** §5.5 -- work a herd marker. Its own verb, not a mode of mining. */
  startHunt(col: number, row: number): Promise<ActionResult<Job>>
  collectJob(jobId: string): Promise<ActionResult<CollectResult>>
  abandonJob(jobId: string): Promise<ActionResult<null>>

  getStation(settlementId: string): Promise<StationState>
  startProcessing(settlementId: string, recipeKey: string, batches: number): Promise<ActionResult<Job>>

  travelTo(col: number, row: number): Promise<ActionResult<TravelState>>
  cancelTravel(): Promise<ActionResult<TravelStop>>

  buyItem(itemKey: string): Promise<ActionResult<OwnedItem>>
  sellMaterial(material: MaterialKey, quantity: number): Promise<ActionResult<{ gold: number }>>
  craftItem(itemKey: string): Promise<ActionResult<OwnedItem>>

  equipItem(ownedId: string): Promise<ActionResult<null>>
  unequipItem(ownedId: string): Promise<ActionResult<null>>
  repairItem(ownedId: string): Promise<ActionResult<null>>
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
