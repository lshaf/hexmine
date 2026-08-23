/**
 * Laravel-backed driver.
 *
 * Route map the backend needs to expose (all under the `api` middleware group,
 * authenticated with Sanctum). Every mutating route returns:
 *
 *     { "data": <action result>, "state": <PlayerState>, "message": "..." }
 *
 * which is a plain JsonResource wrapping the action result plus a
 * PlayerStateResource. Returning full state on every write is deliberate --
 * see the note in ./types.ts.
 *
 *   GET    /api/state
 *   GET    /api/world
 *   GET    /api/map                              mutations within sight
 *   POST   /api/mining                        {col,row}
 *   POST   /api/jobs/{job}/collect
 *   DELETE /api/jobs/{job}
 *   GET    /api/settlements/{settlement}
 *   POST   /api/settlements/{settlement}/processing   {recipe,batches}
 *   POST   /api/travel                        {col,row}
 *   DELETE /api/travel
 *   POST   /api/inventory/discards            {material,quantity}
 *   POST   /api/inventory/drinks              {item}
 *   POST   /api/shop/purchases                {item}
 *   POST   /api/shop/sales                    {material,quantity}
 *   POST   /api/crafting                      {item}
 *   POST   /api/equipment/{item}/equip
 *   POST   /api/equipment/{item}/unequip
 *   POST   /api/equipment/{item}/repair
 *   DELETE /api/equipment/{item}
 */
import type {
  ActionResult,
  CollectResult,
  GameApi,
  MapMutations,
  PlayerState,
  QuestDef,
  QuestReward,
  SkillPoints,
  SkillTree,
  StationState,
  BattlePreview,
  BattleResult,
  TilePreview,
  TravelStop,
} from './types'
import type { WorldConfig } from '@/game/worldgen'
import { ApiError } from './types'
import type { ActiveBuff, Job, MaterialKey, OwnedItem, TravelState } from '@/game/types'

const BASE = '/api'

function csrfToken(): string {
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]!) : ''
}

/**
 * Sanctum issues the XSRF-TOKEN cookie from this endpoint. The client and API
 * are the same origin, so auth is the session cookie and every mutating request
 * has to echo that token back. Done once, lazily, and shared by concurrent
 * callers so a burst of actions cannot fire several handshakes.
 */
let csrfHandshake: Promise<void> | null = null

function ensureCsrf(): Promise<void> {
  if (csrfToken()) return Promise.resolve()
  csrfHandshake ??= fetch('/sanctum/csrf-cookie', { credentials: 'include' })
    .then(() => undefined)
    .catch(() => undefined)
  return csrfHandshake
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  if (init.method && init.method !== 'GET') await ensureCsrf()

  const response = await fetch(`${BASE}${path}`, {
    ...init,
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-XSRF-TOKEN': csrfToken(),
      ...(init.headers ?? {}),
    },
  })

  if (!response.ok) {
    // Laravel puts validation and abort() messages on `message`.
    const body = await response.json().catch(() => ({}) as Record<string, unknown>)
    throw new ApiError(
      (body.message as string) ?? `Request failed (${response.status})`,
      String(body.code ?? response.status),
    )
  }
  return (await response.json()) as T
}

const post = <T>(path: string, body?: unknown) =>
  request<T>(path, { method: 'POST', body: body === undefined ? undefined : JSON.stringify(body) })

const del = <T>(path: string) => request<T>(path, { method: 'DELETE' })

export class HttpDriver implements GameApi {
  getState(): Promise<PlayerState> {
    return request<PlayerState>('/state')
  }

  getWorld(): Promise<WorldConfig> {
    return request<WorldConfig>('/world')
  }

  getMap(): Promise<MapMutations> {
    return request<MapMutations>('/map')
  }

  previewTile(col: number, row: number): Promise<TilePreview> {
    return request<TilePreview>(`/tiles/${col}/${row}/preview`)
  }

  previewBattle(): Promise<BattlePreview> {
    return request<BattlePreview>('/battle/preview')
  }

  fight(): Promise<ActionResult<BattleResult>> {
    return post<ActionResult<BattleResult>>('/battle')
  }

  startMining(col: number, row: number): Promise<ActionResult<Job>> {
    return post<ActionResult<Job>>('/mining', { col, row })
  }

  startGathering(col: number, row: number): Promise<ActionResult<Job>> {
    return post<ActionResult<Job>>('/gathering', { col, row })
  }

  startHunt(col: number, row: number): Promise<ActionResult<Job>> {
    return post<ActionResult<Job>>('/hunting', { col, row })
  }

  collectJob(jobId: string): Promise<ActionResult<CollectResult>> {
    return post<ActionResult<CollectResult>>(`/jobs/${jobId}/collect`)
  }

  abandonJob(jobId: string): Promise<ActionResult<null>> {
    return del<ActionResult<null>>(`/jobs/${jobId}`)
  }

  getStation(settlementId: string): Promise<StationState> {
    return request<StationState>(`/settlements/${settlementId}`)
  }

  startProcessing(settlementId: string, recipeKey: string, batches: number) {
    return post<ActionResult<Job>>(`/settlements/${settlementId}/processing`, {
      recipe: recipeKey,
      batches,
    })
  }

  travelTo(col: number, row: number): Promise<ActionResult<TravelState>> {
    return post<ActionResult<TravelState>>('/travel', { col, row })
  }

  cancelTravel(): Promise<ActionResult<TravelStop>> {
    return del<ActionResult<TravelStop>>('/travel')
  }

  buyItem(itemKey: string): Promise<ActionResult<OwnedItem>> {
    return post<ActionResult<OwnedItem>>('/shop/purchases', { item: itemKey })
  }

  sellMaterial(material: MaterialKey, quantity: number) {
    return post<ActionResult<{ gold: number }>>('/shop/sales', { material, quantity })
  }

  getSkillTree(): Promise<SkillTree> {
    return request<SkillTree>('/jobs-tree')
  }

  buyNode(nodeKey: string): Promise<ActionResult<{ node: string; points: SkillPoints }>> {
    return post<ActionResult<{ node: string; points: SkillPoints }>>('/jobs-tree/nodes', {
      node: nodeKey,
    })
  }

  sellEquipment(ownedId: string): Promise<ActionResult<{ gold: number }>> {
    return post<ActionResult<{ gold: number }>>(`/shop/equipment-sales/${ownedId}`, {})
  }

  getQuests(): Promise<Record<string, QuestDef>> {
    return request<{ quests: Record<string, QuestDef> }>('/quests').then((r) => r.quests)
  }

  claimQuest(quest: string): Promise<ActionResult<QuestReward>> {
    return post<ActionResult<QuestReward>>(`/quests/${quest}/claim`, {})
  }

  craftItem(itemKey: string): Promise<ActionResult<Job>> {
    return post<ActionResult<Job>>('/crafting', { item: itemKey })
  }

  equipItem(ownedId: string): Promise<ActionResult<null>> {
    return post<ActionResult<null>>(`/equipment/${ownedId}/equip`)
  }

  unequipItem(ownedId: string): Promise<ActionResult<null>> {
    return post<ActionResult<null>>(`/equipment/${ownedId}/unequip`)
  }

  repairItem(ownedId: string): Promise<ActionResult<null>> {
    return post<ActionResult<null>>(`/equipment/${ownedId}/repair`)
  }

  discardItem(ownedId: string): Promise<ActionResult<null>> {
    return del<ActionResult<null>>(`/equipment/${ownedId}`)
  }

  discardMaterial(material: MaterialKey, quantity: number) {
    return post<ActionResult<{ dropped: number }>>('/inventory/discards', { material, quantity })
  }

  useConsumable(item: string) {
    return post<ActionResult<ActiveBuff>>('/inventory/drinks', { item })
  }
}
