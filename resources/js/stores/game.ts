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
import { PROCESSING } from '@/game/balance'
import type {
  BattleSkillRow,
  BattleResult,
  CollectResult,
  GuildDirectory,
  GuildDoor,
  GuildRole,
  MapMutations,
  PlayerState,
  QueueSlot,
  QuestDef,
  QuestReward,
  SkillTree,
  StationState,
  TilePreview,
} from '@/api/types'
import type {
  BattleJob,
  Job,
  MaterialKey,
  FieldJob,
  CraftJob,
  ProcessingJob,
  Settlement,
  Tile,
  TravelState,
} from '@/game/types'
import { configureWorld, generateTile, inBounds, worldParams } from '@/game/worldgen'
import { hexDistance, visibleTiles } from '@/map/hexGeometry'

/** Which overlay is open over the map, if any. */
export type PanelKey =
  | 'bag'
  | 'craft'
  | 'shop'
  | 'hero'
  | 'atlas'
  | 'skills'
  | 'quests'
  // §8.4 -- what is on a bench somewhere, and which bench.
  | 'bench'
  // §10 -- who you are with, and the hall that makes legendary reachable.
  | 'guild'

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
  const bench = ref<QueueSlot[]>([])
  const preview = ref<TilePreview | null>(null)

  const panel = ref<PanelKey | null>(null)
  const selected = ref<{ col: number; row: number } | null>(null)

  /**
   * §4 -- the last haul, held until the player dismisses its receipt.
   *
   * Kept in the store rather than in the component that claimed it, because
   * the claim button lives in the mine stack and the receipt belongs over the
   * whole map. Null when there is nothing to show.
   */
  const haul = ref<CollectResult | null>(null)

  /**
   * §9.5.5 -- the last fight, held until its receipt is dismissed.
   *
   * Same argument as the haul, and a stronger one: a fight can destroy
   * something (§8.2), and a toast is not where a player should find that out.
   */
  const battle = ref<BattleResult | null>(null)
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

  const mutations = ref<MapMutations>({ depleted: [], occupied: [], cleared: [], carriers: [] })

  /**
   * §9.5.7 -- every corpse this character can see, from both halves.
   *
   * Two sources because they are two different facts. YOURS ride the player
   * state: they are yours, they are on a clock, and the fog does not apply to
   * them. ANYBODY ELSE'S ride the map, inside sight like everything else on it
   * -- a live list of every death on the server would be a scanner.
   *
   * Joined here rather than folded into the tiles: a carrier is not a property
   * of the ground, it is somebody's row standing where they fell.
   */
  const carriers = computed(() => [
    ...(state.value?.carriers ?? []),
    ...(mutations.value.carriers ?? []),
  ])

  /**
   * Generated tiles for the current view. shallowRef because this is a few
   * hundred plain objects replaced wholesale -- deep reactivity on it would
   * cost far more than it could ever save.
   */
  const tiles = shallowRef<Tile[]>([])

  function rebuildTiles(): void {
    const { col, row, w, h } = view.value
    const depleted = new Map(mutations.value.depleted.map(([c, r, at]) => [key(c, r), at]))
    const occupied = new Map(
      mutations.value.occupied.map(([c, r, bodies, seats]) => [key(c, r), { bodies, seats }]),
    )
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
          slotsUsed: occupied.get(k)?.seats ?? 0,
          workers: occupied.get(k)?.bodies ?? 0,
          packCleared: cleared.has(k),
        }),
      )
    }
    tiles.value = built
  }

  /**
   * Move the camera. Local only -- tiles are generated, never fetched.
   *
   * §5.1 -- the center is held on the map. Without this a pan can carry the
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

  function centerOnCharacter(): void {
    const char = state.value?.character
    if (char) setView(char.col, char.row)
  }

  /*
   * Sight belongs to the character, not the camera, so the camera never
   * recenters itself. Watching the position rather than patching travelTo means
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
      centerOnCharacter()
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

  /**
   * §9.5.9 -- what each battle job knows.
   *
   * Re-read rather than cached like the tree: the figures move the moment a
   * `skillPower`, `skillCooldown` or `skillStun` node is bought, and a stale
   * copy would tell a player their point did nothing.
   */
  const battleSkills = ref<Record<string, BattleSkillRow[]>>({})

  async function loadBattleSkills(): Promise<void> {
    battleSkills.value = await api.getBattleSkills()
  }

  async function buyNode(nodeKey: string): Promise<void> {
    // Quiet: buying one already says so through act(), and the node landing in
    // the owned list is the same event rather than a second one.
    await act(() => api.buyNode(nodeKey), 'good', true)

    // §9.5.9 -- the battle skills are their own fetch, and buying a node moves
    // them twice over: learning one flips it to known, and a skillPower or
    // skillCooldown node moves every figure on all three. Re-read here at the
    // mutation rather than from a watcher on the owned set, which is a derived
    // Set and turned out not to fire.
    await loadBattleSkills()
  }

  /**
   * §12.1 -- quests.
   *
   * Same split as the trees: the catalog is static and fetched once, and where
   * this character stands rides in the state with everything else that moves.
   */
  const questDefs = shallowRef<Record<string, QuestDef> | null>(null)
  const quests = computed(() => state.value?.quests ?? [])

  /**
   * How many are payable right now. The one number the HUD needs, so the button
   * can say there is gold waiting without the panel being open.
   */
  const questsReady = computed(() => quests.value.filter((q) => q.complete && !q.claimed).length)

  async function loadQuests(): Promise<void> {
    if (questDefs.value) return
    questDefs.value = await api.getQuests()
  }

  /**
   * §12.1 -- claim, and open the receipt over it.
   *
   * Deliberately NOT a toast. The server answers with no message, so `act`
   * notes nothing, and the modal carries the whole of it: what was earned, what
   * the purse is now, and what the claim just opened up. A toast saying
   * "+40 gold" alongside would be the same news twice, said worse.
   */
  const questReward = ref<QuestReward | null>(null)

  async function claimQuest(quest: string): Promise<void> {
    const result = await act(() => api.claimQuest(quest))
    if (result) questReward.value = result
  }

  function clearQuestReward(): void {
    questReward.value = null
  }

  /**
   * §8.4 -- the slate: ten recipes a prospector means to make.
   *
   * The list rides in the state, because what a player is short of moves with
   * every haul. Two verbs rather than a toggle, so two taps in flight cannot
   * settle on whichever answer arrives last.
   */
  const slate = computed<string[]>(() => state.value?.slate ?? [])

  const saved = (recipe: string) => slate.value.includes(recipe)

  async function toggleSlate(recipe: string): Promise<void> {
    await act(() => (saved(recipe) ? api.dropRecipe(recipe) : api.saveRecipe(recipe)))
  }
  const jobs = computed<Job[]>(() => state.value?.jobs ?? [])

  /**
    * One mine out and one processing job at a time, so both of these are a
    * single job or nothing. A mine pins the character to its hex, whether it is
    * a seam, a herd (§5.5) or a fight (§9.5.5); processing is the NPC's work,
    * which the player only helps along by being there (§6.2).
    */
  const fieldJob = computed<FieldJob | BattleJob | null>(
    () =>
      jobs.value.find(
        (j): j is FieldJob | BattleJob =>
          j.kind === 'mining' || j.kind === 'battle',
      ) ?? null,
  )

  /**
   * §6, §8.4 -- everything left in a building, soonest first.
   *
   * One list because they are claimed by the same rule: at the bench that holds
   * them. A craft at a capital and a run at a village are the same kind of
   * errand, and the ledger's job is to say which walk is worth making.
   */
  const benchJobs = computed(() =>
    jobs.value
      .filter((j): j is ProcessingJob | CraftJob => j.kind === 'processing' || j.kind === 'craft')
      .sort((a, b) => a.endsAt - b.endsAt),
  )

  /** Finished, wherever it is. This is the "your work is done" number. */
  const benchReady = computed(
    () => benchJobs.value.filter((j) => j.endsAt <= now.value).length,
  )

  /**
   * Finished AND under your feet, which is the only kind you can take (§8.4).
   *
   * Two numbers rather than one, because they say different things: the first
   * is news, the second is a thing to tap. A cell that lit for work four days'
   * walk away would be crying wolf every time.
   */
  const benchHere = computed(
    () =>
      benchJobs.value.filter(
        (j) =>
          j.endsAt <= now.value
          && j.col === character.value?.col
          && j.row === character.value?.row,
      ).length,
  )

  /**
   * §6.1 + §8.4 -- the ceiling on work left in buildings anywhere.
   *
   * The per-settlement rules say how much may be left in ONE building and the
   * server owns them; this is the one number the dock can read without asking a
   * station about itself.
   */
  const workFull = computed(() => benchJobs.value.length >= PROCESSING.outstandingWorkCap)

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

    // §9.5.3 -- clamped to where the road actually ends, not to where it was
    // pointed. The walker used to run the full length and land on the village,
    // and the correction only arrived on the refresh after that -- so the
    // marker visibly arrived and then snapped back down the road.
    return Math.max(0, Math.min(journey.stopHex, (now.value - journey.startedAt) / journey.perHexMs))
  })

  /** Whole hexes already banked -- what stopping right now would keep. */
  const travelHexesWalked = computed(() => Math.floor(travelProgress.value))

  /**
   * Time to the DESTINATION, which is the journey the player asked for.
   *
   * Not to `stopAt`. A pack ahead cuts the road short (§9.5.3), and counting to
   * the cut told the walker something §5.6 says they cannot know: sight on the
   * road is zero, because you are between hexes watching your feet. A shortened
   * clock is that fog leaking -- the walk quietly announcing an ambush several
   * minutes before it happens.
   */
  const travelRemainingMs = computed(() =>
    travel.value ? Math.max(0, travel.value.endsAt - now.value) : 0,
  )

  /**
   * Time to where the road ACTUALLY ends, which is not shown to anybody.
   *
   * It exists for the watch below: an interception has to be discovered when it
   * happens rather than a whole road later, and that is a request to make, not
   * a thing to draw.
   */
  const travelStopMs = computed(() =>
    travel.value ? Math.max(0, travel.value.stopAt - now.value) : 0,
  )

  /**
   * A journey lands on the server's clock, not on a request, and nothing pushes
   * that news down. So when the countdown runs out the client asks -- once, on
   * the edge, because the answer clears `travel` and the edge cannot repeat.
   *
   * It counts to `stopAt` rather than `endsAt`, so a journey cut short by a
   * pack asks at the moment it is cut short. Asking at the destination meant
   * every interception was discovered a whole road late.
   */
  watch(
    () => travel.value !== null && travelStopMs.value === 0,
    async (landed) => {
      if (!landed) return
      await refreshState()
      await refreshMutations()
    },
  )

  const held = (material: MaterialKey): number => inventory.value[material] ?? 0

  // -------------------------------------------------------------- plumbing

  function note(text: string, tone: LogEntry['tone'] = 'info'): void {
    log.value.unshift({ id: ++logId, text, tone, at: now.value })
    if (log.value.length > 40) log.value.pop()
  }

  /**
   * §7.5 -- a skill that arrives without being asked for still has to be said.
   *
   * The Explorer tree claims itself the moment the road pays for it: it costs no
   * point, so there is nothing for a press to decide and a button whose only
   * answer is yes is a chore. But the reason it used to need pressing was real
   * -- arriving on its own meant the reward for a thousand hexes was a panel
   * that had quietly changed since you last looked at it, with no moment where
   * it was given to you.
   *
   * So the moment is here. The state carries the owned nodes, so a list that
   * grew on its own is a claim, and nothing on the server has to remember what
   * this client has already been told.
   *
   * `quiet` is for buying one, which announces itself through `act()`. Anything
   * that walks in during a plain refresh is the road paying out.
   */
  function absorb(next: PlayerState, quiet = false): void {
    const before = state.value?.nodes

    clockOffset.value = next.serverTime - Date.now()
    state.value = next

    if (quiet || !before) return

    const known = new Set(before)
    const fresh = next.nodes.filter((key) => !known.has(key))
    if (fresh.length === 0) return

    // Named where the tree happens to be loaded, counted where it is not: the
    // catalog is fetched lazily when the skills panel first opens, and a toast
    // is not worth a round trip the player did not ask for.
    const named = fresh
      .map((key) => tree.value?.nodes[key]?.name)
      .filter((name): name is string => Boolean(name))

    note(
      named.length === fresh.length
        ? `The road paid: ${named.join(', ')}.`
        : `The road paid: ${fresh.length} new Explorer ${fresh.length === 1 ? 'skill' : 'skills'}.`,
      'good',
    )
  }

  /** Wrap an API action: single-flight, state absorption, error surfacing. */
  async function act<T>(
    run: () => Promise<{ data: T; state: PlayerState; message?: string }>,
    tone: LogEntry['tone'] = 'good',
    quiet = false,
  ): Promise<T | null> {
    if (busy.value) return null
    busy.value = true
    try {
      const result = await run()
      absorb(result.state, quiet)
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
    centerOnCharacter()
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

  /**
   * §8.4 -- the bench bank at the settlement underfoot, for the craft panel.
   *
   * Kept apart from `station` on purpose: that ref is what App.vue draws the
   * settlement overlay from, so filling it to read five slot pips would open a
   * panel nobody asked for the moment the craft panel closed.
   */
  async function loadBench(): Promise<void> {
    const here = currentSettlement.value
    if (!here) {
      bench.value = []

      return
    }

    bench.value = (await api.getStation(here.id)).bench
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
   * §4.0 -- the same hex, by hand.
   *
   * Never gated client-side. The button is always live and the server is what
   * says no, because every reason it could say no is a fact only the server
   * holds -- and a cell grayed out for a reason the player cannot read is
   * worse than a cell that answers.
   */
  async function startGathering(col: number, row: number): Promise<void> {
    const job = await act(() => api.startGathering(col, row))
    if (job) await select(col, row)
  }

  /**
   * §4 -- the haul, and the one moment in an idle game where something happened.
   *
   * A mine now comes back as several stacks off the hex's own table, which a
   * toast cannot carry: it would either truncate the haul or stack five
   * notifications up the screen. The result is held for the modal instead, and
   * everything the player is owed -- what dropped, both XP ladders, tool wear,
   * what would not fit -- is read off the server's own response.
   */
  async function collect(jobId: string): Promise<void> {
    const job = jobs.value.find((j) => j.id === jobId)
    const result = await act(() => api.collectJob(jobId))

    // §9.5.5 -- a fight answers with its own report rather than a haul: there
    // is no material and no XP ladder in common, and the plate that reads it is
    // a different plate.
    if (result) {
      if (job?.kind === 'battle') battle.value = result as unknown as BattleResult
      else haul.value = result as CollectResult
    }

    await refreshMutations()
    if (selected.value) await select(selected.value.col, selected.value.row)

    // §8.4 -- the bench bank is its own fetch, so taking something off one has
    // to re-read it. Without this the Workshop kept drawing the slot that was
    // just emptied, and went on refusing the next craft because of it.
    if (job?.kind === 'craft') await loadBench()
  }

  /** Dismiss the haul receipt. Nothing depends on it having been read. */
  function clearHaul(): void {
    haul.value = null
  }

  /**
   * §9.5.5 -- close with whatever is standing on this hex.
   *
   * No coordinates: the only fight on offer is the one under your feet. It
   * takes time now, so this starts a job and answers with nothing -- the report
   * comes off the collect, like every other piece of work.
   */
  async function fight(): Promise<void> {
    const job = await act(() => api.fight())

    // §9.5.5 -- the fight is already settled, and the job carries the whole
    // exchange. Open the plate on it now rather than waiting for a clock: what
    // runs on screen is the replay, and the collect at the end of it is what
    // turns the replay into a receipt.
    if (job && job.kind === 'battle') live.value = job

    // The pack is spent on engagement (§9.5.5), so the map moved even though
    // nothing has been collected yet.
    await refreshMutations()
    if (selected.value) await select(selected.value.col, selected.value.row)
  }

  /**
   * §9.5.5 -- the fight being watched, if one is.
   *
   * A running battle job and nothing else. It is picked up from the state as
   * well as from `fight()`, so closing the tab mid-exchange and coming back
   * finds the plate where it was -- the result was never in the animation.
   */
  const live = ref<BattleJob | null>(null)

  const liveBattle = computed<BattleJob | null>(() => {
    if (live.value) return live.value

    const running = jobs.value.find((j) => j.kind === 'battle')

    return running && running.log?.length ? (running as BattleJob) : null
  })

  /** The replay is over: take the receipt, which is what actually pays out. */
  async function finishLiveBattle(): Promise<void> {
    const job = liveBattle.value
    live.value = null
    if (job) await collect(job.id)
  }

  /** Dismiss the fight receipt. */
  function clearBattle(): void {
    battle.value = null
  }

  // ---------------------------------------------------------------- §10 guilds

  /**
   * The recruiting list and your own guild, fetched together.
   *
   * Two halves of one question -- "am I in one, and if not who is taking
   * people" -- so they ride one request. Your own guild is also on the player
   * state (membership decides what a bench will make, §8.0), and this is the
   * fuller copy with the roster on it.
   */
  const guilds = ref<GuildDirectory | null>(null)

  async function loadGuilds(): Promise<void> {
    guilds.value = await api.getGuilds()
  }

  /** §10.0 -- found one. A city or a capital, and the founder's own gold. */
  async function foundGuild(identity: {
    name: string
    code: string
    description: string
    flag: string | null
  }): Promise<boolean> {
    const made = await act(() => api.foundGuild(identity))
    if (made) await loadGuilds()

    return Boolean(made)
  }

  /** §10.0.1 -- walk in. No application and no approval. */
  async function joinGuild(guildId: string): Promise<void> {
    await act(() => api.joinGuild(guildId))
    await loadGuilds()
  }

  async function leaveGuild(): Promise<void> {
    await act(() => api.leaveGuild(), 'bad')
    await loadGuilds()
  }

  async function updateGuild(changes: {
    description?: string
    flag?: string | null
    recruitment?: GuildDoor
  }): Promise<void> {
    await act(() => api.updateGuild(changes))
    await loadGuilds()
  }

  async function removeGuildMember(characterId: string): Promise<void> {
    await act(() => api.removeGuildMember(characterId), 'bad')
    await loadGuilds()
  }

  async function withdrawApplication(guildId: string): Promise<void> {
    await act(() => api.withdrawApplication(guildId), 'bad')
    await loadGuilds()
  }

  async function decideApplication(characterId: string, admit: boolean): Promise<void> {
    await act(() => api.decideApplication(characterId, admit))
    await loadGuilds()
  }

  async function donateToGuild(gold: number): Promise<void> {
    await act(() => api.donateToGuild(gold))
    await loadGuilds()
  }

  async function upgradeGuildFacility(facility: 'hall' | 'bench'): Promise<void> {
    await act(() => api.upgradeGuildFacility(facility))
    await loadGuilds()
  }

  async function setGuildRole(characterId: string, role: GuildRole): Promise<void> {
    await act(() => api.setGuildRole(characterId, role))
    await loadGuilds()
  }

  /**
   * §10.0 -- the halls overlay, opened from the dock at a city or a capital.
   *
   * Its own flag rather than a PanelKey, for the same reason the station has
   * one: it belongs to WHERE YOU ARE. A corner panel is reachable from any hex
   * and would offer founding everywhere and allow it almost nowhere.
   */
  const halls = ref(false)

  async function openHalls(): Promise<void> {
    halls.value = true
    await loadGuilds()
  }

  function closeHalls(): void {
    halls.value = false
  }

  /** §10 -- the guild on the player state, which every screen reads. */
  const guild = computed(() => state.value?.guild ?? null)

  /** §8.0 -- standing in your own hall, which is what opens the top rung. */
  const atGuildHall = computed(() => Boolean(state.value?.atGuildHall))

  async function abandon(jobId: string): Promise<void> {
    await act(() => api.abandonJob(jobId), 'bad')
    await refreshMutations()
  }

  async function travelTo(col: number, row: number): Promise<void> {
    const ok = await act(() => api.travelTo(col, row))
    // The map recenters itself: the position watcher above is driven by the
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

  /** §4.0 -- one trade for every tier-zero stack. What counts is the server's call. */
  async function sellAllScrap(): Promise<void> {
    await act(() => api.sellScrap())
  }

  async function craft(itemKey: string): Promise<void> {
    await act(() => api.craftItem(itemKey))
    await loadBench()
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

  /** §8.2 -- the third exit: gold back, scaled by what is left of the piece. */
  async function sellItem(ownedId: string): Promise<void> {
    await act(() => api.sellEquipment(ownedId))
  }

  /** §8.2 -- the same exit for a brew, by the flask. */
  async function sellPotion(itemKey: string, quantity: number): Promise<void> {
    await act(() => api.sellPotion(itemKey, quantity))
  }

  /**
   * §7 -- claim a name.
   *
   * Answers with a toast rather than silence: the name is drawn in half a dozen
   * places and none of them is necessarily on screen when it changes, so the
   * confirmation has to travel to the player rather than wait to be found.
   */
  async function rename(name: string): Promise<boolean> {
    // `act` surfaces a refusal as a toast and answers null, which is how every
    // other action in the app reports one. What the caller needs back is only
    // whether to close the form.
    return (await act(() => api.renameCharacter(name), 'good')) !== null
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
    state, station, bench, preview, panel, selected, busy, booted, log, now, view, tiles,
    // derived
    character, timeScale, bag, bagFull, inventory, equipment, skills, bonuses, toolYield, jobs, readyJobs,
    consumables, buffs,
    tree, skillPoints, jobLevels, ownedNodes,
    rename,
    questDefs, quests, questsReady, questReward,
    slate, saved,
    activeJobs, fieldJob, workFull, benchJobs, benchReady, benchHere, underfoot, selectedTile,
    currentSettlement, shopStock, sight, travelPerHexMs, travelEta,
    travel, travelProgress, travelHexesWalked, travelRemainingMs,
    // helpers
    tileAt, held, note,
    // actions
    boot, setView, setViewport, centerOnCharacter, refreshMutations, refreshState,
    select, clearSelection,
    haul, clearHaul, battle, fight, clearBattle, carriers,
    liveBattle, finishLiveBattle,
    guilds, guild, atGuildHall, halls, openHalls, closeHalls,
    loadGuilds, foundGuild, joinGuild, leaveGuild,
    updateGuild, removeGuildMember, setGuildRole, withdrawApplication, decideApplication,
    donateToGuild, upgradeGuildFacility,
    startMining, startGathering, collect, abandon, travelTo, cancelTravel, startProcessing, buy,
    sell, sellAllScrap, sellItem, sellPotion, craft, equip, unequip, repair, discard, discardMaterial, drink, openPanel, closePanel,
    battleSkills, loadTree, loadBattleSkills, buyNode,
    loadQuests, claimQuest, clearQuestReward,
    toggleSlate,
    openStation, closeStation, loadBench,
  }
})
