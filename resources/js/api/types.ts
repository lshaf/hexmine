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
import type { Job, MaterialKey, OwnedItem, Settlement, SkillKey, StatKey } from '@/game/types'
import type { WorldConfig } from '@/game/worldgen'

export interface CharacterDto {
  id: string
  name: string
  /** Truncated for display; the backend keys everything off the full address. */
  wallet: string
  level: number
  xp: number
  xpToNext: number
  ap: number
  apMax: number
  /** Millis per AP point, so the client can render a live regen bar. */
  apRegenMs: number
  /** When the current AP value was last settled, server clock. */
  apUpdatedAt: number
  gold: number
  col: number
  row: number
  storageUsed: number
  storageCap: number
  /** Hexes the character can reach in one move, §7.1. */
  travelRange: number
  /** §12 -- index into the tutorial script; -1 once finished. */
  tutorialStep: number
}

export interface SkillDto {
  key: SkillKey
  level: number
  xp: number
  xpToNext: number
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
  /** Aggregated equipment bonuses, already capped and diminished server-side. */
  bonuses: Record<StatKey, number>
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
  material: MaterialKey | null
  apCost: number
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
  /** Units lost because storage was over cap, §11.1. */
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
  collectJob(jobId: string): Promise<ActionResult<CollectResult>>
  abandonJob(jobId: string): Promise<ActionResult<null>>

  getStation(settlementId: string): Promise<StationState>
  startProcessing(settlementId: string, recipeKey: string, batches: number): Promise<ActionResult<Job>>

  travelTo(col: number, row: number): Promise<ActionResult<null>>

  buyItem(itemKey: string): Promise<ActionResult<OwnedItem>>
  sellMaterial(material: MaterialKey, quantity: number): Promise<ActionResult<{ gold: number }>>
  craftItem(itemKey: string): Promise<ActionResult<OwnedItem>>

  equipItem(ownedId: string): Promise<ActionResult<null>>
  unequipItem(ownedId: string): Promise<ActionResult<null>>
  repairItem(ownedId: string): Promise<ActionResult<null>>
  discardItem(ownedId: string): Promise<ActionResult<null>>
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
