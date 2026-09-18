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
  DungeonFight,
  DungeonState,
  DungeonTile,
  GuildDirectory,
  GuildDoor,
  GuildRole,
  MapMutations,
  PlayerState,
  QueueSlot,
  DailyDef,
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
import { ITEM_BY_KEY } from '@/game/catalog'
import {
  configureWorld,
  generateTile,
  inBounds,
  isWorldConfigured,
  worldParams,
} from '@/game/worldgen'
import {
  COL_STEP,
  ROW_STEP,
  MAP_PX_CHART,
  MAP_PX_DEFAULT,
  MAP_PX_MAX,
  hexDistance,
  mapPxMin,
  visibleTiles,
} from '@/map/hexGeometry'

/** Which overlay is open over the map, if any. */
export type PanelKey =
  | 'bag'
  | 'craft'
  | 'shop'
  | 'hero'
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

  /**
   * §5.6 -- the floor under a scheduled refresh, and nothing else.
   *
   * The server says WHEN to come back (`nextChangeAt`), so there is no interval
   * to pick. This only stops a moment that has already passed -- a stale clock,
   * a tab woken from sleep -- from turning into a tight loop of requests.
   */
  const LIVE_MIN_GAP_MS = 5_000

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
  /**
   * The camera: where it is, how much room it has, and how close it is.
   *
   * `px` is PIXELS PER HEX COLUMN, which is the one unit that means the same
   * thing to both renderers (§13.2). COL_STEP is what the board is drawn at, so
   * `px === COL_STEP` is the scale the map has always had and everything else
   * is read against it.
   */
  const view = ref({ col: 0, row: 0, w: 900, h: 620, px: MAP_PX_DEFAULT })

  const mutations = ref<MapMutations>({
    depleted: [],
    occupied: [],
    cleared: [],
    hunted: [],
    carriers: [],
    roaming: [],
    guildLands: [],
    nextChangeAt: null,
  })

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

  /**
   * §5.6 -- the next moment anything in view stops being true.
   *
   * A pack's bucket ending, an animal's, a pocket closing, a worked-out hex
   * regrowing: all of them are derived from `(col, row, now)` and the held
   * mutations, so none of them needs a request -- what they need is for the
   * tiles to be built again on the far side of the moment.
   *
   * Kept as one timestamp rather than a poll because it is exactly knowable:
   * rebuilding on a timer would redraw several hundred tiles a second to catch
   * something that happens twice an hour.
   */
  const nextTileChange = ref(Number.POSITIVE_INFINITY)

  /**
   * The server's half of the map, indexed by hex.
   *
   * Hoisted out of the sweep so ONE tile can be built without building the
   * whole window around it -- see buildTile below.
   */
  const mutationIndex = computed(() => {
    const depleted = new Map(mutations.value.depleted.map(([c, r, at]) => [key(c, r), at]))
    const occupied = new Map(
      mutations.value.occupied.map(([c, r, bodies, seats]) => [key(c, r), { bodies, seats }]),
    )
    // §9.5.1 -- the pack itself is derived; whether it is still standing is not.
    const cleared = new Set((mutations.value.cleared ?? []).map(([c, r]) => key(c, r)))
    // §5.5 -- and the animal's own, which is the same subtraction: the seed
    // says where one stands and only the server knows it has been taken.
    const hunted = new Set((mutations.value.hunted ?? []).map(([c, r]) => key(c, r)))
    // §5.5 -- and where one walked to, which is a value rather than a
    // subtraction: the seed says the hex is empty and the server is the only
    // thing that knows something is standing on it.
    const roaming = new Map(
      (mutations.value.roaming ?? []).map(([c, r, k, grade]) => [key(c, r), { key: k, grade }]),
    )
    // §10.6 -- and the one PLACE the seed knows nothing about. A value rather
    // than a subtraction for the same reason a roamer is.
    const guildLands = new Map(
      (mutations.value.guildLands ?? []).map((land) => [key(land.col, land.row), land]),
    )

    return { depleted, occupied, cleared, hunted, roaming, guildLands }
  })

  /**
   * One hex, generated rather than looked up.
   *
   * §5 -- terrain is a pure function of (col, row, seed), so a hex does not
   * have to be on screen to be described. That matters at the far end of the
   * zoom (§13.2): the board is not drawn out there, so the visible list is
   * empty, and a tap on the chart would otherwise select a hex the store could
   * say nothing about -- the card read "Unsurveyed" over ordinary forest,
   * which is the one thing §5.6 says the fog does not withhold.
   */
  function buildTile(col: number, row: number): Tile | undefined {
    // §5.1 -- the map ends, and the render must end with it. visibleTiles()
    // returns a rectangle around the camera without knowing where the edge is,
    // so tiles past it used to be generated and drawn: terrain outside the
    // world, on ground travelTo() would refuse to walk to.
    if (!inBounds(col, row)) return undefined

    const m = mutationIndex.value
    const k = key(col, row)

    return generateTile(col, row, now.value, {
      regrowsAt: m.depleted.get(k) ?? 0,
      slotsUsed: m.occupied.get(k)?.seats ?? 0,
      workers: m.occupied.get(k)?.bodies ?? 0,
      packCleared: m.cleared.has(k),
      huntCleared: m.hunted.has(k),
      roaming: m.roaming.get(k),
      guildLand: m.guildLands.get(k),
    })
  }

  function rebuildTiles(): void {
    const { col, row, w, h, px } = view.value

    // §13.2 -- below the handover the board is not drawn at all, so generating
    // its tiles would be several thousand objects a frame for a renderer that
    // never looks at them. The chart reads the seed directly.
    if (px < MAP_PX_CHART) {
      tiles.value = []

      return
    }

    const scale = px / COL_STEP
    const built: Tile[] = []
    for (const coord of visibleTiles(col, row, w / scale, h / scale)) {
      const tile = buildTile(coord.col, coord.row)
      if (tile) built.push(tile)
    }
    tiles.value = built

    // The soonest of everything in view that is on a clock. Read off the tiles
    // that were just built, so it can never disagree with what is drawn.
    let soonest = Number.POSITIVE_INFINITY
    for (const tile of built) {
      for (const at of [tile.pack?.until, tile.hunt?.until, tile.pocketUntil, tile.regrowsAt]) {
        if (at !== undefined && at > now.value && at < soonest) soonest = at
      }
    }
    nextTileChange.value = soonest
  }

  /*
   * §5.6 -- and the moment arrives on the clock the rest of the app already
   * runs on. Nothing here asks the server: what expired was derived in the
   * first place, so re-deriving it is the whole of the update.
   */
  watch(now, (at) => {
    if (at >= nextTileChange.value) rebuildTiles()
  })

  /**
   * Move the camera. Local only -- tiles are generated, never fetched.
   *
   * §5.1 -- the center is held on the map. Without this a pan can carry the
   * camera off the edge and leave the viewport empty, which reads as a broken
   * render rather than as an edge.
   */
  function setView(col: number, row: number): void {
    view.value = { ...view.value, ...clampView(col, row, view.value.px) }
    rebuildTiles()
  }

  /**
   * Keep the window full of world.
   *
   * §5.1 clamps the camera to the map, which is enough while the window is a
   * few hexes across -- but the zoom goes out until the whole world fits, and a
   * camera merely on the map can still sit at a corner with three quarters of
   * the screen showing nothing. A sheet framed against a void is a sheet you
   * have to fight to read.
   *
   * So the clamp is the edge less HALF A WINDOW, in whatever the window is
   * worth at this scale -- and once the world is narrower than the window there
   * is no choice left to make, so it simply sits centerd. At the scale the
   * board is played at this is a couple of hexes of slack and changes nothing;
   * it only bites where it is needed.
   */
  function clampView(col: number, row: number, px: number): { col: number; row: number } {
    const { radius, size } = worldParams()
    const halfCols = view.value.w / 2 / px
    const halfRows = view.value.h / 2 / (px * (ROW_STEP / COL_STEP))
    const hold = (v: number, half: number) =>
      half * 2 >= size ? 0 : Math.round(Math.max(half - radius, Math.min(radius - half, v)))

    return { col: hold(col, halfCols), row: hold(row, halfRows) }
  }

  /** The map reports how much room it has. Also local. */
  function setViewport(w: number, h: number): void {
    if (Math.abs(w - view.value.w) < 1 && Math.abs(h - view.value.h) < 1) return

    // The far end of the zoom is "the whole world across this viewport", so a
    // narrower window moves the floor and can leave the camera standing under
    // it -- a phone rotating to portrait, or a panel opening beside the map.
    const px = Math.max(mapPxMin(worldParams().size, w), view.value.px)

    view.value = { ...view.value, w, h, px }
    view.value = { ...view.value, ...clampView(view.value.col, view.value.row, px) }
    rebuildTiles()
  }

  /**
   * How close the camera is, in pixels per hex column.
   *
   * Clamped at both ends and nowhere else: the near end is a board you could
   * count the grain on, and the far end is the whole world edge to edge, which
   * is what replaced the atlas. Everything between is continuous, because a
   * viewBox scales continuously and a ladder of named steps would be a second
   * vocabulary for a thing the map already does smoothly.
   */
  function setScale(px: number): void {
    const floor = mapPxMin(worldParams().size, view.value.w)
    const next = Math.max(floor, Math.min(MAP_PX_MAX, px))
    if (Math.abs(next - view.value.px) < 1e-4) return

    // Pulling back at the edge of the world has to pull the camera in with it,
    // or the last notch of the zoom frames half a screen of nothing.
    view.value = { ...view.value, px: next, ...clampView(view.value.col, view.value.row, next) }
    rebuildTiles()
  }

  /**
   * One press of the zoom, and there are two of them.
   *
   * The ladder runs from the scale the board is drawn at to the whole world in
   * one window -- on a phone a factor of about three hundred. No single ratio
   * serves that: fine enough for the board and it takes twenty-odd presses to
   * cross, coarse enough to cross and the board is one press wide.
   *
   * So the step is the regime's. The board is a short stretch and a press there
   * is "a bit further"; the chart is hundreds and a press is "much further".
   * Three presses across the board, seven across the chart. Both are coarser
   * than a wheel notch, which is the other half of the same argument: a wheel
   * is a nudge at a view you are already looking at, a press is a decision to
   * go somewhere else.
   */
  const ZOOM_NOTCH_BOARD = 1.5

  const ZOOM_NOTCH_CHART = 2.5

  function zoomBy(steps: number): void {
    const px = view.value.px
    const charted = px < MAP_PX_CHART
    const next = px * (charted ? ZOOM_NOTCH_CHART : ZOOM_NOTCH_BOARD) ** steps

    // The handover is a DETENT: a press that would cross it stops on it
    // instead, so the board's widest view is somewhere you land rather than
    // somewhere you pass through -- and a press out followed by a press back
    // returns you where you were rather than overshooting by the difference
    // between the two ratios.
    //
    // It catches you ONCE. Standing on the detent already, the next press goes
    // through: a stop that re-arms itself every time is not a detent, it is a
    // wall, and the camera simply could not reach the chart.
    const crossing = charted !== next < MAP_PX_CHART
    setScale(crossing && px !== MAP_PX_CHART ? MAP_PX_CHART : next)
  }

  /*
   * Whether there is anywhere left to go, so a cell that cannot do anything
   * says so rather than swallowing the press. The far end depends on the
   * viewport, since it is "the whole world across this window".
   */
  const canZoomIn = computed(() => view.value.px < MAP_PX_MAX - 1e-4)
  const canZoomOut = computed(
    () => view.value.px > mapPxMin(worldParams().size, view.value.w) + 1e-4,
  )

  /** §13.2 -- which renderer the camera is over: the board, or the chart. */
  const charting = computed(() => view.value.px < MAP_PX_CHART)

  /**
   * §5.1 -- how far the world runs from the middle out.
   *
   * Published because the map is a deployment setting rather than a constant
   * (config/game.php), so anything that has to say where the edge is -- the
   * coordinate jump, for one -- has to ask rather than assume.
   */
  const mapRadius = computed(() => (isWorldConfigured() ? worldParams().radius : 0))

  function centerOnCharacter(): void {
    // §5.6 -- where the walker IS, not where they set off from. `here` is
    // declared further down, so this reads the same derivation directly rather
    // than reaching forward to it.
    const char = state.value?.character
    if (!char) return

    const journey = state.value?.travel
    if (!journey || journey.path.length === 0) {
      setView(char.col, char.row)

      return
    }

    const walked = Math.max(
      0,
      Math.min(journey.stopHex, (now.value - journey.startedAt) / journey.perHexMs),
    )
    const at = journey.path[Math.min(journey.path.length - 1, Math.floor(walked))]!
    setView(at[0], at[1])
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
   * §5.6 -- what is knowable changes when the ground under you does, and on
   * the road that is every hex rather than only at the ends of it.
   *
   * Keyed on `here` rather than on the character's column: the column sits on
   * the departure hex for the whole journey, so a walk used to look motionless
   * from in here. Setting off and arriving are still edges worth catching, and
   * the road/still flag is what catches them -- a journey of one hex begins and
   * ends without the position ever changing.
   */
  watch(
    () => {
      const char = state.value?.character
      if (!char) return ''
      return `${hereCol.value},${hereRow.value},${state.value?.travel ? 'road' : 'still'}`
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
  /**
   * §5.6 -- and the answer says when to ask for the next one.
   *
   * A fixed poll was a guess in both directions: too often for a two-hour
   * bucket, and still late for one about to turn. Every moment worth coming
   * back for is exactly knowable on the server -- the buckets, the pockets and
   * the regrowths in this very disc -- so it hands one over and this schedules
   * a single call for it.
   */
  let liveTimer: ReturnType<typeof setTimeout> | null = null

  async function refreshMutations(): Promise<void> {
    mutations.value = await api.getMap()
    rebuildTiles()
    await reconcileUnderfoot()
    scheduleLiveRefresh()
  }

  /**
   * §5.5/§5.7/§9.5.3 -- what is STANDING on your hex is map state, and what you
   * may DO about it is player state. They have to agree.
   *
   * The dock offers Hunt when `state.underfoot.hunt.animal` is there, and that
   * field only moves when the whole state payload is refetched -- which happens
   * on arriving, on acting, and on nothing else. So an animal that walked in
   * while you stood still (§5.5) or a bucket that simply rolled one onto your
   * hex was DRAWN on the map and had no verb under it: the map knew and the
   * dock did not, and a reload was the only way to tell it.
   *
   * Asked as a comparison rather than fixed with an unconditional refetch. The
   * state payload is the big one -- bag, jobs, quests, skills, the lot -- and
   * the map is re-asked at every `nextChangeAt` and, on the road, at every hex.
   * Fetching it on that schedule would be paying for the whole character to
   * find out about a deer. So: three things can appear on or leave the hex you
   * are standing on, and if the drawing and the payload disagree about any of
   * them, the payload is the stale one.
   */
  async function reconcileUnderfoot(): Promise<void> {
    const said = state.value?.underfoot
    const tile = tileAt(hereCol.value, hereRow.value)
    if (!said || !tile) return

    const stale =
      Boolean(tile.hunt) !== Boolean(said.hunt?.animal)
      || Boolean(tile.pack) !== Boolean(said.pinned)
      || Boolean(tile.pocketUntil) !== Boolean(said.pocketUntil)

    if (stale) await refreshState()
  }

  function scheduleLiveRefresh(): void {
    if (liveTimer !== null) {
      clearTimeout(liveTimer)
      liveTimer = null
    }

    const at = mutations.value.nextChangeAt
    if (at === null || at === undefined) return

    liveTimer = setTimeout(
      () => {
        liveTimer = null
        if (busy.value) return
        void refreshMutations()
      },
      Math.max(LIVE_MIN_GAP_MS, at - now.value),
    )
  }

  /**
   * The tile at a hex: the drawn one if it is on screen, generated if it is not.
   *
   * The list is what the board is rendering, so a lookup answers fastest and
   * keeps one object per hex for the common case. The fallback is what makes
   * "any hex can be pointed at" (§5.6) true at every scale rather than only
   * where the board happens to be drawn.
   */
  const tileAt = (col: number, row: number): Tile | undefined =>
    tiles.value.find((t) => t.col === col && t.row === row) ?? buildTile(col, row)

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
   * Straps can never go *over* -- there is nowhere to put a thing that has no
   * strap, so the refusal comes at the door rather than at the gate -- which
   * makes "every strap is taken" the whole of the warning. It is worth an ember
   * cell in the corner because it means the next thing you pick up will not go
   * the way you expect: a haul that outgrows the stack it is joining now needs
   * a strap that is not there.
   */
  const bag = computed(() => state.value?.character.bag ?? null)
  const bagFull = computed(
    () => (bag.value ? bag.value.slots >= bag.value.slotCap : false),
  )

  const inventory = computed(() => state.value?.inventory ?? {})
  const equipment = computed(() => state.value?.equipment ?? [])

  /**
   * §13.1 -- the two slots the map's own marker wears.
   *
   * The coat you are standing in and the thing in your hand, as RUNGS: rarity
   * is what a piece is read by at a glance, and a marker has room for one
   * reading per piece. The five gathering tools are deliberately not here --
   * all five are worn at once (§8 rule 3), so a figure carrying them carries
   * every one, and which is in use is picked off the ground rather than chosen.
   *
   * Nulls are honest: no coat and no weapon are both ordinary states, and §9.5.9
   * makes an empty hand a real one -- no family, so no class and no skills.
   */
  const worn = computed(() => {
    const at = (slot: string) => {
      const held = equipment.value.find((i) => i.equipped && ITEM_BY_KEY[i.key]?.slot === slot)

      return held ? ITEM_BY_KEY[held.key]! : null
    }

    const weapon = at('weapon')

    return {
      armor: at('armor')?.rarity ?? null,
      weapon:
        weapon && weapon.family
          ? { family: weapon.family, rarity: weapon.rarity }
          : null,
    }
  })
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

  /**
   * §7.1 -- the same list keyed by job, for the one thing that asks about a
   * single job rather than reading the sheet: an equip gate. A tool and a
   * weapon are gated on the job that swings them, and the chip that draws it
   * has to be able to look one up without scanning an array per row.
   */
  const jobLevelMap = computed(() =>
    Object.fromEntries(jobLevels.value.map((j) => [j.key, j.level])),
  )
  const ownedNodes = computed(() => new Set(state.value?.nodes ?? []))

  /**
   * §7.4 -- what this character holds, keyed by skill.
   *
   * The one thing the panel reads. `ownedNodes` is still here because the
   * battle-skill list and the fight bench speak in nodes (a node is a rank),
   * and it is the same fact seen from the other end.
   */
  const skillRanks = computed<Record<string, number>>(() => state.value?.skillRanks ?? {})
  const rankOf = (key: string) => skillRanks.value[key] ?? 0

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

  async function buySkillRank(skillKey: string): Promise<void> {
    // Quiet: buying one already says so through act(), and the rank landing in
    // the holding is the same event rather than a second one.
    await act(() => api.buySkillRank(skillKey), 'good', true)

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
   * §12.2 -- the day's three.
   *
   * Same split again, and they ride the same fetch: the pool is static, and
   * which three are yours today is derived per character and lives in the state.
   */
  const dailyDefs = shallowRef<Record<string, DailyDef> | null>(null)
  const dailies = computed(() => state.value?.dailies?.tasks ?? [])
  const dailiesResetAt = computed(() => state.value?.dailies?.resetsAt ?? 0)

  /**
   * How many are payable right now. The one number the HUD needs, so the button
   * can say there is gold waiting without the panel being open.
   */
  const questsReady = computed(
    () =>
      quests.value.filter((q) => q.complete && !q.claimed).length +
      dailies.value.filter((d) => d.complete && !d.claimed).length,
  )

  async function loadQuests(): Promise<void> {
    if (questDefs.value) return
    const catalog = await api.getQuests()
    questDefs.value = catalog.quests
    dailyDefs.value = catalog.dailies
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

  /** §12.2 -- the same receipt, off a different ledger. */
  async function claimDaily(task: string): Promise<void> {
    const result = await act(() => api.claimDaily(task))
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
    * a seam, an animal (§5.5) or a fight (§9.5.5); processing is the NPC's
    * work, which the player only helps along by being there (§6.2).
    *
    * §5.5 -- `hunting` is on this list because a hunt IS a mine: same hex, same
    * clock, same claim. It was on neither list while it had a kind of its own,
    * which made a started hunt invisible from the moment it began.
    */
  const fieldJob = computed<FieldJob | BattleJob | null>(
    () =>
      jobs.value.find(
        (j): j is FieldJob | BattleJob =>
          j.kind === 'mining' || j.kind === 'hunting' || j.kind === 'battle',
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
   * is news, the second is a thing to tap. A cell that lit for work half a map
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
  const underfoot = computed(() => state.value?.underfoot ?? null)

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
   * §5.6 -- how far the character can see, and the road no longer closes it.
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

    return hexDistance(hereCol.value, hereRow.value, col, row) * travelPerHexMs.value
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
   * §5.6 -- the hex the prospector is actually standing on, right now.
   *
   * The server keeps `character.col/row` at the DEPARTURE hex for the whole
   * journey and only writes a new one when you land or stop, so every readout
   * that asked the character where it was got the place it set off from --
   * three days into a walk the dock still named the forest you left, and the
   * distance to a bench was measured from there.
   *
   * This is derived rather than asserted, and it is the same arithmetic the
   * server itself uses: cancelTravel() lands you on
   * `floor(elapsed / perHex)` steps along the same derived path, which is what
   * `travelHexesWalked` already is. So this is not the client having an opinion
   * about where it is -- it is the number the server will agree with the
   * instant you stop, drawn a few minutes early.
   *
   * It is what the SERVER derives too, and that is what makes it safe to key
   * on. §5.6's eye no longer closes on the road, so the disc has to follow the
   * walker -- and both sides run the identical arithmetic to decide where the
   * walker is, so the client can never ask about ground the server would
   * refuse. Every verb is still refused out there; this decides what is
   * *scouted*, not what may be done.
   */
  const here = computed(() => {
    const char = state.value?.character
    if (!char) return null

    const journey = travel.value
    if (!journey || journey.path.length === 0) return { col: char.col, row: char.row }

    const step = Math.min(journey.path.length - 1, travelHexesWalked.value)
    const at = journey.path[step]!

    return { col: at[0], row: at[1] }
  })

  const hereCol = computed(() => here.value?.col ?? 0)
  const hereRow = computed(() => here.value?.row ?? 0)

  /**
   * Time to the DESTINATION, which is the journey the player asked for.
   *
   * Not to `stopAt`. A pack ahead cuts the road short (§9.5.3), and counting to
   * the cut announces the ambush the moment the road starts -- a whole journey
   * early, from any distance. The eye is open on the road now (§5.6), so one IS
   * discovered ahead of walking into it: by coming within sight of it, a hex or
   * three out. That is a warning you earned by getting close, which is the
   * opposite of a clock that knew all along.
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

    // §7.4 -- named for the SKILL and its new rank, because a node's own name
    // is not on any screen any more: eleven of Explorer's were different words
    // for the same +2, and the panel says "Straps".
    //
    // Named where the tree happens to be loaded, counted where it is not: the
    // catalog is fetched lazily when the skills panel first opens, and a toast
    // is not worth a round trip the player did not ask for.
    const named: string[] = []
    for (const [key, skill] of Object.entries(tree.value?.skills ?? {})) {
      const now = next.skillRanks?.[key] ?? 0
      if (now > 0 && fresh.some((node) => skill.ranks.some((r) => r.node === node))) {
        named.push(skill.ranks.length > 1 ? `${skill.name} ${now}` : skill.name)
      }
    }

    note(
      named.length > 0
        ? `The road paid: ${named.join(', ')}.`
        : `The road paid: ${fresh.length} new Explorer ${fresh.length === 1 ? 'rank' : 'ranks'}.`,
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

    // §9.6 -- a session survives a reload, and a floor is not the map. Asked
    // once at boot rather than polled: what changes it is this client's own
    // presses, and every one of them answers with the new state.
    await loadDungeon()

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
    // A costing belongs to ONE hex, so pointing somewhere new drops the old
    // one rather than leaving it on screen until the next answer lands. It is
    // what made the plate show the last hex's refusal over this hex's name for
    // as long as a request takes.
    const moved = selected.value?.col !== col || selected.value?.row !== row
    selected.value = { col, row }
    if (moved) preview.value = null

    // §5.6 -- from where the walker IS, and this guard exists to ask exactly
    // the question the server will ask, so a hex it would refuse costs no
    // round trip.
    //
    // It read the character's own column deliberately for a while, because
    // that is what the server costed against and the column sits on the
    // departure hex for the whole journey. The server derives the walking
    // position now -- it has to, since the eye no longer closes on the road --
    // so pointing this anywhere else would skip hexes the server would answer
    // about.
    if (hexDistance(hereCol.value, hereRow.value, col, row) > sight.value) {
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

  /** §5.5 -- the animal on this hex, worked the way the seam under it is. */
  async function startHunt(col: number, row: number): Promise<void> {
    const job = await act(() => api.startHunt(col, row))
    if (job) {
      await refreshMutations()
      await select(col, row)
    }
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
  /**
   * §6.1 / §8.4 -- re-read whichever bank of slots this job was sitting in.
   *
   * Both banks are their own fetch rather than riding the player state, so a
   * job leaving one is invisible to them: the queue bar went on drawing the
   * slot that had just emptied, and went on refusing the next run because of
   * it. `collect` learned that for the craft bench and never for the
   * processing line, and `abandon` never learned it at all.
   *
   * The station is only re-read when it is already OPEN. Filling it otherwise
   * would open a settlement panel nobody asked for, which is the same reason
   * `loadBench` is a separate call in the first place.
   */
  async function refreshBankFor(job: Job | undefined): Promise<void> {
    if (job?.kind === 'craft') await loadBench()
    if (job?.kind === 'processing' && station.value !== null) await openStation()
  }

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

    await refreshBankFor(job)
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

  // ------------------------------------------------------------ §9.6 dungeons

  /**
   * Where this prospector is in a dungeon, or null for "not in one".
   *
   * Held apart from `state` on purpose: a session is its own coordinate space
   * (§9.6) and folding it into the player state would make every map read carry
   * a floor it has no use for. The map does not know dungeons exist.
   */
  const dungeon = ref<DungeonState | null>(null)

  /**
   * Whether the mouth panel is open.
   *
   * Driven by a dock press, the same as a settlement's -- it used to open by
   * itself on arrival and be dismissible, which left no way back to it: a
   * player who closed it once could not find the dungeon again. A place you
   * walked five thousand hexes to needs a verb, not an interruption.
   */
  const mouthOpen = ref(false)

  function openMouth(): void {
    mouthOpen.value = true
  }

  function closeMouth(): void {
    mouthOpen.value = false
  }

  /** The last fight, kept until the plate is dismissed. */
  const dungeonFight = ref<DungeonFight | null>(null)

  /** Inside a dungeon the world map is not what you are looking at. */
  const underground = computed(() => dungeon.value?.inside === true)

  /** §9.6.2 -- what is on a hex within sight, by key. */
  const dungeonTiles = computed(() => {
    const out = new Map<string, DungeonTile>()
    for (const tile of dungeon.value?.tiles ?? []) {
      out.set(`${tile.col},${tile.row}`, tile)
    }
    return out
  })

  /** §9.5.3 -- what is standing on the hex under your feet, if anything. */
  const dungeonUnderfoot = computed(() => {
    const d = dungeon.value
    if (!d?.inside) return null
    return dungeonTiles.value.get(`${d.col},${d.row}`)?.monster ?? null
  })

  async function loadDungeon(): Promise<void> {
    try {
      const result = await api.getDungeon()
      absorb(result.state, true)
      dungeon.value = result.data
    } catch {
      dungeon.value = null
    }
  }

  async function openDungeon(key: string, category: string, difficulty: string): Promise<void> {
    dungeon.value = await act(() => api.openDungeon(key, category, difficulty))
  }

  async function joinDungeon(code: string): Promise<void> {
    dungeon.value = await act(() => api.joinDungeon(code))
  }

  async function enterDungeon(): Promise<void> {
    dungeon.value = await act(() => api.enterDungeon())
  }

  async function stepDungeon(col: number, row: number): Promise<void> {
    // Quiet: a step is not news, and a line of log per hex would bury the
    // things that are (§13.3's own argument about what earns a colour).
    const next = await act(() => api.stepDungeon(col, row), 'good', true)
    if (next) dungeon.value = next
  }

  async function fightDungeon(): Promise<void> {
    const result = await act(() => api.fightDungeon())
    if (!result) return
    dungeonFight.value = result.fight
    dungeon.value = result.dungeon
  }

  async function descendDungeon(): Promise<void> {
    const next = await act(() => api.descendDungeon())
    if (next) dungeon.value = next
  }

  async function leaveDungeon(): Promise<void> {
    await act(() => api.leaveDungeon())
    dungeon.value = null
    dungeonFight.value = null
    await refreshState()
  }

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

  async function upgradeGuildFacility(facility: 'hall' | 'processing' | 'craft'): Promise<void> {
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

  /** §8.0/§10.6 -- standing on your own guild's land, which opens the top rung. */
  const atGuildHall = computed(() => Boolean(state.value?.atGuildHall))

  /** §10.0.2 -- and whether you are the one who may spend the treasury. */
  const guildOwner = computed(() => guild.value?.role === 'owner')

  /**
   * §10.6 -- may this guild build on the hex under your feet?
   *
   * Derived here rather than asked for, because every term is already on the
   * client: the tile is a pure function of the seed (§5), and whether the guild
   * holds land is on the player state. The server refuses for the same reasons
   * and says which -- this only decides whether to OFFER, and a control that
   * appears where it cannot work is worse than one that is simply absent.
   */
  const canClaimHere = computed(() => {
    const g = guild.value
    const char = character.value
    if (!g || !guildOwner.value || g.land || !char || travel.value) return false

    const tile = tileAt(char.col, char.row)

    return Boolean(
      tile && tile.dead && !tile.water && !tile.settlement && !tile.dungeon && !tile.guildLand,
    )
  })

  /** §10.6 -- the claim plate, opened from the dock and closed by pressing it. */
  const claiming = ref(false)

  function openClaim(): void {
    claiming.value = true
  }

  function closeClaim(): void {
    claiming.value = false
  }

  async function claimLand(): Promise<void> {
    const done = await act(() => api.claimGuildLand())
    if (done) {
      claiming.value = false
      await refreshMutations()
    }
  }

  async function nameLand(name: string): Promise<void> {
    await act(() => api.nameGuildLand(name))
    await refreshMutations()
  }

  async function abandon(jobId: string): Promise<void> {
    // Read before the call: the job is gone from the state afterwards, so
    // there would be nothing left to ask which bank it was in.
    const job = jobs.value.find((j) => j.id === jobId)

    await act(() => api.abandonJob(jobId), 'bad')
    await refreshMutations()
    await refreshBankFor(job)
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

  /** §8.2 -- `coin` buys the parts over the counter as far as the tier reaches. */
  async function repair(ownedId: string, coin = false): Promise<void> {
    await act(() => api.repairItem(ownedId, coin))
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
    character, timeScale, bag, bagFull, inventory, equipment, worn, skills, bonuses, toolYield, jobs, readyJobs,
    consumables, buffs,
    tree, skillPoints, jobLevels, jobLevelMap, ownedNodes, skillRanks, rankOf,
    rename,
    questDefs, quests, questsReady, questReward,
    dailyDefs, dailies, dailiesResetAt,
    slate, saved,
    activeJobs, fieldJob, workFull, benchJobs, benchReady, benchHere, underfoot, selectedTile,
    currentSettlement, shopStock, sight, travelPerHexMs, travelEta,
    dungeon, dungeonFight, underground, dungeonTiles, dungeonUnderfoot,
    mouthOpen, openMouth, closeMouth,
    loadDungeon, openDungeon, joinDungeon, enterDungeon, stepDungeon,
    fightDungeon, descendDungeon, leaveDungeon,
    here, hereCol, hereRow,
    travel, travelProgress, travelHexesWalked, travelRemainingMs,
    // helpers
    tileAt, held, note,
    // actions
    boot, setView, setViewport, setScale, zoomBy, canZoomIn, canZoomOut, charting, mapRadius,
    centerOnCharacter, refreshMutations, refreshState,
    select, clearSelection,
    haul, clearHaul, battle, fight, clearBattle, carriers,
    liveBattle, finishLiveBattle,
    guilds, guild, atGuildHall, guildOwner, halls, openHalls, closeHalls,
    canClaimHere, claiming, openClaim, closeClaim, claimLand, nameLand,
    loadGuilds, foundGuild, joinGuild, leaveGuild,
    updateGuild, removeGuildMember, setGuildRole, withdrawApplication, decideApplication,
    donateToGuild, upgradeGuildFacility,
    startMining, startGathering, startHunt, collect, abandon, travelTo, cancelTravel, startProcessing, buy,
    sell, sellAllScrap, sellItem, sellPotion, craft, equip, unequip, repair, discard, discardMaterial, drink, openPanel, closePanel,
    battleSkills, loadTree, loadBattleSkills, buySkillRank,
    loadQuests, claimQuest, claimDaily, clearQuestReward,
    toggleSlate,
    openStation, closeStation, loadBench,
  }
})
