/**
 * The API client.
 *
 * There is exactly one driver: the Laravel backend is the authority (§16), so a
 * client-side simulation of the economy would be a second source of truth and a
 * guaranteed source of drift. The client predicts and renders; it never decides.
 */
import { HttpDriver } from './httpDriver'
import type { GameApi } from './types'

export const api: GameApi = new HttpDriver()

export * from './types'
