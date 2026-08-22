/**
 * The single client-side store.
 *
 * It holds a cache of server state and nothing the server does not already know:
 * no local timers driving rewards, no predicted inventory. Actions call the API
 * and replace state wholesale.
 *
 * The map is the one exception, and deliberately so. Terrain is a pure function
 * of (col, row, seed) (§5), so tiles are generated here from the parameters the
 * server publishes, and the server is asked only for what it alone knows --
 * depletion timers and miner occupancy. Panning therefore costs no network.
 */
import { defineStore } from 'pinia'
import { computed, ref, shallowRef, watch } from 'vue'
import { api } from '@/api/client'
import { ApiError } from '@/api/types'
import type {
  CollectResult,
  MapMutations,
  PlayerState,
  SkillTree,
  StationState,
  TilePreview,
} from '@/api/types'
import type {
  Job,
  MaterialKey,
  FieldJob,
  ProcessingJob,
  Settlement,
  Tile,
  TravelState,
} from '@/game/types'
import { TUTORIAL, TUTORIAL_OUTRO, tutorialStep } from '@/game/tutorial'
import { configureWorld, generateTile, inBounds, worldParams } from '@/game/worldgen'
import { hexDistance, visibleTiles } from '@/map/hexGeometry'

/** Which overlay is open over the map, if any. */
export type PanelKey = 'bag' | 'craft' | 'shop' | 'hero' | 'atlas' | 'skills'

export interface LogEntry {
  id: number
  text: string
  tone: 'info' | 'good' | 'bad'
  at: number
}

const key = (col: number, row: number) => `${col},${row}`

export const useGame = defineStore('game', () => {
  // ------------------------------------------------------------ raw state
  const state = ref<PlayerState | null>(null)
  const station = ref<StationState | null>(null)
  const preview = ref<TilePreview | null>(null)

  const panel = ref<PanelKey | null>(null)
  const selected = ref<{ col: number; row: number } | null>(null)

  /**
   * §4 -- the last haul, held until the player dismisses its receipt.
   *
   * Kept in the store rather than in the component that claimed it, because
   * the claim button lives in the trip stack and the receipt belongs over the
   * whole map. Null when there is nothing to show.
   */
  const haul = ref<CollectResult | null>(null)
  const busy = ref(false)
  const booted = ref(false)

  const log = ref<LogEntry[]>([])
  let logId = 0

  /**
   * Difference between the server clock and this device's. Countdowns render
   * against server time so a wrong system clock cannot fake a finished job --
   * and cannot make a real one look stuck either.
   */
  const clockOffset = ref(0)
  /** Bumped once a second purely to re-run countdown computeds. */
  const tick = ref(0)

  const now = computed(() => {
    void tick.value
    return Date.now() + clockOffset.value
  })

  // ---------------------------------------------------------------- map

  /**
   * The camera. It pans freely and costs nothing to move, because terrain is a
   * pure function of (col, row, seed) (§5) -- dragging generates tiles locally
   * and asks the server for nothing.
   *
   * What it does NOT move is sight. Live state -- depletion, who is mining
   * where -- is scoped server-side to a two-hex disc around the character
   * (§5.6), so the camera can wander and still learn nothing it should not.
   * Out there the map shows what the seed says and no more: the lie of the
   * land, and whether anybody lives on it.
   */
  const view = ref({ col: 0, row: 0, w: 900, h: 620 })

  const mutations = ref<MapMutations>({ depleted: [], occupied: [], cleared: [] })

  /**
   * Generated tiles for the current view. shallowRef because this is a few
   * hundred plain objects replaced wholesale -- deep reactivity on it would
   * cost far more than it could ever save.
   */
  const tiles = shallowRef<Tile[]>([])

  function rebuildTiles(): void {
    const { col, row, w, h } = view.value
    const depleted = new Map(mutations.value.depleted.map(([c, r, at]) => [key(c, r), at]))
    const occupied = new Map(mutations.value.occupied.map(([c, r, n]) => [key(c, r), n]))
    // §9.5.1 -- the pack itself is derived; whether it is still standing is not.
    const cleared = new Set((mutations.value.cleared ?? []).map(([c, r]) => key(c, r)))

    const built: Tile[] = []
    for (const coord of visibleTiles(col, row, w, h)) {
      // §5.1 -- the map ends, and the render must end with it. visibleTiles()
      // returns a rectangle around the camera without knowing where the edge
      // is, so tiles past it used to be generated and drawn: terrain outside
      // the world, on ground travelTo() would refuse to walk to.
      if (!inBounds(coord.col, coord.row)) continue

      const k = key(coord.col, coord.row)
      built.push(
        generateTile(coord.col, coord.row, now.value, {
          regrowsAt: depleted.get(k) ?? 0,
          slotsUsed: occupied.get(k) ?? 0,
          packCleared: cleared.has(k),
        }),
      )
    }
    tiles.value = built
  }

  /**
   * Move the camera. Local only -- tiles are generated, never fetched.
   *
   * §5.1 -- the centre is held on the map. Without this a pan can carry the
   * camera off the edge and leave the viewport empty, which reads as a broken
   * render rather than as an edge.
   */
  function setView(col: number, row: number): void {
    const { radius } = worldParams()
    view.value = {
      ...view.value,
      col: Math.max(-radius, Math.min(radius, col)),
      row: Math.max(-radius, Math.min(radius, row)),
    }
    rebuildTiles()
  }

  /** The map reports how much room it has. Also local. */
  function setViewport(w: number, h: number): void {
    if (Math.abs(w - view.value.w) < 1 && Math.abs(h - view.value.h) < 1) return
    view.value = { ...view.value, w, h }
    rebuildTiles()
  }

  function centreOnCharacter(): void {
    const char = state.value?.character
    if (char) setView(char.col, char.row)
  }

  /*
   * Sight belongs to the character, not the camera, so the camera never
   * recentres itself. Watching the position rather than patching travelTo means
   * the two can never disagree about where you are.
   */
  watch(
    // Reads the raw state rather than the `character` computed: this getter runs
    // during setup, and that computed is declared further down the file.
    () => {
      const char = state.value?.character
      return char ? `${char.col},${char.row}` : ''
    },
    (key, previous) => {
      // An empty previous is the first state landing, which boot() already
      // followed with a fetch of its own.
      if (!key || !previous) return
      centreOnCharacter()
    },
  )

  /*
   * Two things change what is knowable: where you stand, and whether you are
   * standing at all. Setting off drops sight to zero (§5.6) without moving you
   * a hex, so the position watcher above would miss it and the map would keep
   * drawing a scouting report the server has stopped vouching for.
   *
   * Fetching on the edge rather than on a timer is what makes the walk free:
   * one call when the road starts, one when it ends, and nothing in between
   * however far it is.
   */
  watch(
    () => {
      const char = state.value?.character
      if (!char) return ''
      return `${char.col},${char.row},${state.value?.travel ? 'road' : 'still'}`
    },
    async (key, previous) => {
      if (!key || !previous) return
      await refreshMutations()

      // What was in sight a moment ago may not be now, and the other way round.
      // Re-asking about the selection is what stops the card sitting on a
      // scouting report the character has walked away from -- or showing
      // "unscouted" for a hex they are now standing next to.
      if (selected.value) await select(selected.value.col, selected.value.row)
    },
  )

  /**
   * Live state for the handful of tiles in sight (§5.6) -- seven of them at
   * the base radius. Panning never calls
   * this, and neither does walking -- only arriving and setting off do.
   */
  async function refreshMutations(): Promise<void> {
    mutations.value = await api.getMap()
    rebuildTiles()
  }

  const tileAt = (col: number, row: number): Tile | undefined =>
    tiles.value.find((t) => t.col === col && t.row === row)

  // -------------------------------------------------------------- derived

  const character = computed(() => state.value?.character ?? null)

  /**
   * Server-published clock compression (1 = production timings). Any duration
   * the client predicts locally has to be divided by this to become a real
   * countdown.
   */
  const timeScale = computed(() => state.value?.timeScale ?? 1)

  /**
   * §7.6 -- the bag, and the one thing about it that has to be visible from
   * anywhere: it is full.
   *
   * Full is not the same as over. Rows can never go over -- a full bag turns a
   * new kind away at the door -- while units can, and being over on units is
   * what stops the road. Both are worth an ember cell in the corner, because
   * both mean the next thing you pick up will not go the way you expect.
   */
  const bag = computed(() => state.value?.character.bag ?? null)
  const bagFull = computed(
    () => (bag.value ? bag.value.rows >= bag.value.rowCap || bag.value.units >= bag.value.unitCap : false),
  )

  const inventory = computed(() => state.value?.inventory ?? {})
  const equipment = computed(() => state.value?.equipment ?? [])
  const skills = computed(() => state.value?.skills ?? null)
  const bonuses = computed(() => state.value?.bonuses ?? null)
  /** §8 -- yield is per gathering line now, never one number. */
  const toolYield = computed(() => state.value?.toolYield ?? null)
  /** §8.5 -- the shelf and what is running off it. */
  const consumables = computed(() => state.value?.consumables ?? {})
  const buffs = computed(() => state.value?.buffs ?? [])

  /**
   * §7.4 -- the trades.
   *
   * `tree` is the static catalog: the same 180 rows for every player, so it is
   * fetched once and lazily the first time the panel opens rather than riding
   * along with every state refresh. What is per-character -- points, job levels,
   * owned nodes -- arrives in the state like everything else.
   */
  const tree = shallowRef<SkillTree | null>(null)
  const skillPoints = computed(
    () => state.value?.skillPoints ?? { total: 0, spent: 0, available: 0 },
  )
  const jobLevels = computed(() => state.value?.jobLevels ?? [])
  const ownedNodes = computed(() => new Set(state.value?.nodes ?? []))

  async function loadTree(): Promise<void> {
    if (tree.value) return
    tree.value = await api.getSkillTree()
  }

  async function buyNode(nodeKey: string): Promise<void> {
    await act(() => api.buyNode(nodeKey))
  }
  const jobs = computed<Job[]>(() => state.value?.jobs ?? [])

  /**
    * One trip out and one processing job at a time, so both of these are a
    * single job or nothing. A trip pins the character to its hex, whether it is
    * a seam or a herd (§5.5); processing is the NPC's work, which the player
    * only helps along by being there (§6.2).
    */
  const fieldJob = computed<FieldJob | null>(
    () =>
      jobs.value.find((j): j is FieldJob => j.kind === 'mining' || j.kind === 'hunting') ?? null,
  )

  const processingJob = computed<ProcessingJob | null>(
    () => jobs.value.find((j): j is ProcessingJob => j.kind === 'processing') ?? null,
  )

  /** The hex underfoot, costed by the server. What the dock offers. */
  const underfoot = computed<TilePreview | null>(() => state.value?.underfoot ?? null)

  const readyJobs = computed(() => jobs.value.filter((j) => j.endsAt <= now.value))
  const activeJobs = computed(() => jobs.value.filter((j) => j.endsAt > now.value))

  const selectedTile = computed(() =>
    selected.value ? tileAt(selected.value.col, selected.value.row) : undefined,
  )

  /**
   * The settlement the player is standing on, straight from the server.
   * Trading, crafting and processing are gated on this, so it is not derived
   * client-side -- the rules and the buttons read the same value.
   */
  const currentSettlement = computed<Settlement | null>(() => state.value?.standingAt ?? null)

  /** What the settlement underfoot stocks. Empty out in the field. */
  const shopStock = computed<string[]>(() => state.value?.shopStock ?? [])

  /**
   * §5.6 -- how far the character can see. Zero while walking, which is what
   * darkens the map for the length of a journey.
   *
   * There is no companion "how far can I go": every hex on the map is walkable
   * and the only cost is the clock, so the map has a fog boundary where it used
   * to have a reach boundary.
   */
  const sight = computed(() => character.value?.sight ?? 0)

  /** §8.3 -- wall-clock ms per hex at this character's pace. */
  const travelPerHexMs = computed(() => character.value?.travelPerHexMs ?? 0)

  /** What a walk to this hex would cost in real time, before taking it. */
  const travelEta = (col: number, row: number): number => {
    const char = character.value
    if (!char) return 0

    return hexDistance(char.col, char.row, col, row) * travelPerHexMs.value
  }

  /** The journey under way, §5. Null whenever the character is standing still. */
  const travel = computed<TravelState | null>(() => state.value?.travel ?? null)

  /**
   * How far along the road the walker is, in hexes, as a fraction.
   *
   * The map interpolates the marker against this, so it is deliberately not
   * rounded: the whole-hex figure that a stop would keep is the floor of it,
   * which is the same arithmetic the server does when it lands you.
   */
  const travelProgress = computed(() => {
    const journey = travel.value
    if (!journey) return 0

    return Math.max(0, Math.min(journey.hexes, (now.value - journey.startedAt) / journey.perHexMs))
  })

  /** Whole hexes already banked -- what stopping right now would keep. */
  const travelHexesWalked = computed(() => Math.floor(travelProgress.value))

  const travelRemainingMs = computed(() =>
    travel.value ? Math.max(0, travel.value.endsAt - now.value) : 0,
  )

  /**
   * A journey lands on the server's clock, not on a request, and nothing pushes
   * that news down. So when the countdown runs out the client asks -- once, on
   * the edge, because the answer clears `travel` and the edge cannot repeat.
   */
  watch(
    () => travel.value !== null && travelRemainingMs.value === 0,
    async (landed) => {
      if (!landed) return
      await refreshState()
      await refreshMutations()
    },
  )

  const currentStep = computed(() =>
    character.value ? tutorialStep(character.value.tutorialStep) : null,
  )
  const tutorialDone = computed(() => character.value?.tutorialStep === -1)
  const tutorialProgress = computed(() =>
    character.value && character.value.tutorialStep >= 0
      ? `${character.value.tutorialStep + 1} / ${TUTORIAL.length}`
      : '',
  )

  const held = (material: MaterialKey): number => inventory.value[material] ?? 0

  // -------------------------------------------------------------- plumbing

  function note(text: string, tone: LogEntry['tone'] = 'info'): void {
    log.value.unshift({ id: ++logId, text, tone, at: now.value })
    if (log.value.length > 40) log.value.pop()
  }

  function absorb(next: PlayerState): void {
    clockOffset.value = next.serverTime - Date.now()
    state.value = next
  }

  /** Wrap an API action: single-flight, state absorption, error surfacing. */
  async function act<T>(
    run: () => Promise<{ data: T; state: PlayerState; message?: string }>,
    tone: LogEntry['tone'] = 'good',
  ): Promise<T | null> {
    if (busy.value) return null
    busy.value = true
    try {
      const result = await run()
      absorb(result.state)
      if (result.message) note(result.message, tone)
      return result.data
    } catch (error) {
      note(error instanceof ApiError ? error.message : 'Something went wrong.', 'bad')
      return null
    } finally {
      busy.value = false
    }
  }

  // --------------------------------------------------------------- actions

  async function boot(): Promise<void> {
    // World parameters first: nothing can be drawn until the generator knows
    // what world it is generating.
    configureWorld(await api.getWorld())

    absorb(await api.getState())
    centreOnCharacter()
    await refreshMutations()

    booted.value = true
    setInterval(() => {
      tick.value++
    }, 1000)
  }

  async function refreshState(): Promise<void> {
    absorb(await api.getState())
  }

  /**
   * Point at a hex. Any hex -- you can walk to all of them (§5.6), so a tap
   * across the map is a destination, not a mistake to be swallowed.
   *
   * What it does not do is ask about one it cannot see. The server would refuse
   * to cost it anyway, and skipping the round trip is most of why sight shrank:
   * dragging the camera and tapping around it now costs nothing at all. Out
   * there the card reads the seed and the distance, both of which are already
   * on this device.
   */
  async function select(col: number, row: number): Promise<void> {
    selected.value = { col, row }

    const char = state.value?.character
    if (char && hexDistance(char.col, char.row, col, row) > sight.value) {
      preview.value = null
      return
    }

    preview.value = await api.previewTile(col, row)
  }

  /** §6.1 -- the shared processing queue, for the settlement underfoot. */
  async function openStation(): Promise<void> {
    const here = currentSettlement.value
    if (!here) return
    station.value = await api.getStation(here.id)
  }

  function closeStation(): void {
    station.value = null
  }

  function clearSelection(): void {
    selected.value = null
    preview.value = null
  }

  async function startMining(col: number, row: number): Promise<void> {
    const job = await act(() => api.startMining(col, row))
    if (job) {
      await refreshMutations()
      await select(col, row)
    }
  }

  /**
   * §5.5 -- a hunt takes no tile slot, so the occupancy map cannot have changed
   * and refreshMutations() would be a wasted request. Only the hex re-reads.
   */
  async function startHunt(col: number, row: number): Promise<void> {
    const job = await act(() => api.startHunt(col, row))
    if (job) await select(col, row)
  }

  /**
   * §4.0 -- the same hex, by hand.
   *
   * Never gated client-side. The button is always live and the server is what
   * says no, because every reason it could say no is a fact only the server
   * holds -- and a cell greyed out for a reason the player cannot read is
   * worse than a cell that answers.
   */
  async function startGathering(col: number, row: number): Promise<void> {
    const job = await act(() => api.startGathering(col, row))
    if (job) await select(col, row)
  }

  /**
   * §4 -- the haul, and the one moment in an idle game where something happened.
   *
   * A trip now comes back as several stacks off the hex's own table, which a
   * toast cannot carry: it would either truncate the haul or stack five
   * notifications up the screen. The result is held for the modal instead, and
   * everything the player is owed -- what dropped, both XP ladders, tool wear,
   * what would not fit -- is read off the server's own response.
   */
  async function collect(jobId: string): Promise<void> {
    const result = await act(() => api.collectJob(jobId))
    if (result) haul.value = result

    await refreshMutations()
    if (selected.value) await select(selected.value.col, selected.value.row)
  }

  /** Dismiss the haul receipt. Nothing depends on it having been read. */
  function clearHaul(): void {
    haul.value = null
  }

  async function abandon(jobId: string): Promise<void> {
    await act(() => api.abandonJob(jobId), 'bad')
    await refreshMutations()
  }

  async function travelTo(col: number, row: number): Promise<void> {
    const ok = await act(() => api.travelTo(col, row))
    // The map recentres itself: the position watcher above is driven by the
    // state this call just absorbed.
    if (ok !== null) await select(col, row)
  }

  /**
   * Stop where you stand. The server floors the journey to whole hexes, so
   * this reports back where it actually left you rather than assuming.
   */
  async function cancelTravel(): Promise<void> {
    const stop = await act(() => api.cancelTravel(), 'bad')
    if (stop !== null) {
      await refreshMutations()
      await select(stop.col, stop.row)
    }
  }

  async function startProcessing(
    settlementId: string,
    recipeKey: string,
    batches: number,
  ): Promise<void> {
    const job = await act(() => api.startProcessing(settlementId, recipeKey, batches))
    if (job) station.value = await api.getStation(settlementId)
  }

  async function buy(itemKey: string): Promise<void> {
    await act(() => api.buyItem(itemKey))
  }

  async function sell(material: MaterialKey, quantity: number): Promise<void> {
    await act(() => api.sellMaterial(material, quantity))
  }

  async function craft(itemKey: string): Promise<void> {
    await act(() => api.craftItem(itemKey))
  }

  async function equip(ownedId: string): Promise<void> {
    await act(() => api.equipItem(ownedId))
  }

  async function unequip(ownedId: string): Promise<void> {
    await act(() => api.unequipItem(ownedId))
  }

  async function repair(ownedId: string): Promise<void> {
    await act(() => api.repairItem(ownedId))
  }

  async function discard(ownedId: string): Promise<void> {
    await act(() => api.discardItem(ownedId), 'info')
  }

  /** §11.1 -- tip materials out to make room. Nothing comes back for them. */
  async function discardMaterial(material: MaterialKey, quantity: number): Promise<void> {
    await act(() => api.discardMaterial(material, quantity), 'info')
  }

  /** §8.5 -- drink a potion, starting a timed buff. */
  async function drink(item: string): Promise<void> {
    await act(() => api.useConsumable(item))
  }

  function openPanel(next: PanelKey): void {
    panel.value = next
  }

  function closePanel(): void {
    panel.value = null
  }

  return {
    // state
    state, station, preview, panel, selected, busy, booted, log, now, view, tiles,
    // derived
    character, timeScale, bag, bagFull, inventory, equipment, skills, bonuses, toolYield, jobs, readyJobs,
    consumables, buffs,
    tree, skillPoints, jobLevels, ownedNodes,
    activeJobs, fieldJob, processingJob, underfoot, selectedTile,
    currentSettlement, shopStock, sight, travelPerHexMs, travelEta,
    travel, travelProgress, travelHexesWalked, travelRemainingMs,
    currentStep, tutorialDone, tutorialProgress, TUTORIAL_OUTRO,
    // helpers
    tileAt, held, note,
    // actions
    boot, setView, setViewport, centreOnCharacter, refreshMutations, refreshState,
    select, clearSelection,
    haul, clearHaul,
    startMining, startGathering, startHunt, collect, abandon, travelTo, cancelTravel, startProcessing, buy,
    sell, craft, equip, unequip, repair, discard, discardMaterial, drink, openPanel, closePanel,
    loadTree, buyNode,
    openStation, closeStation,
  }
})
