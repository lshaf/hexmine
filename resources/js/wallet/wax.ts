/**
 * WAX wallet login.
 *
 * §2 -- naming a wallet costs nothing, so a login is a *payment*: 0.0001 WAX
 * signed out of the wallet being claimed, carrying a memo the server issued a
 * moment earlier. Three round trips, and the middle one is the only one that
 * touches a key:
 *
 *   1. the server says what to pay        POST /api/auth/wax/challenge
 *   2. the wallet signs and broadcasts    eosio.token::transfer
 *   3. the server reads it off the chain  POST /api/auth/wax
 *
 * There is no step where anything is asked who it is. The transfer is signed by
 * an account and says so, so the identity request that used to come first was a
 * popup asking for an answer the next step hands over anyway.
 *
 * Everything about the payment -- the account, the amount, the memo -- comes
 * from step 2. Nothing here decides what a login costs, which is the same rule
 * the rest of the client plays by: the server is the authority and this renders
 * what it is told (§16).
 *
 * WHICH wallet is WharfKit's question, not ours. The kit is handed every plugin
 * we support and `login()` is called with none of them named, so its own
 * renderer draws the picker -- which is why there is one button on the door
 * rather than one per wallet.
 *
 * That is worth stating as a rule, because the old shape looked harmless. Two
 * buttons meant the door carried its own copy of the wallet list, and a copy is
 * a thing that drifts: adding Wombat meant adding a plugin, a `WalletKind`, a
 * button, a label and a busy state, and forgetting any one of them left a wallet
 * that was installed and unreachable. **The plugin array is the list now.**
 * Adding a wallet is a line in it and nothing in the view.
 *
 * Past the picker they are the same session object making the same transfer:
 * Anchor signs over a link, the Cloud Wallet is a popup, Wombat and TokenPocket
 * are extensions, and the server cannot tell and does not ask.
 */
import type { Session, SessionKit } from '@wharfkit/session'

/** What the server tells us about itself before anything is signed. */
export interface WaxSettings {
  wallet: string | null
  /** Whether the game is reachable without one. Off while dev provisioning is on. */
  required: boolean
  account: string
  fee: string
  contract: string
  chain_id: string
  endpoint: string
}

interface Challenge {
  nonce: string
  memo: string
  account: string
  fee: string
  contract: string
  expires_in: number
}

/**
 * A login failure the player can act on, as opposed to one they can only
 * report. `code` is the server's when the server refused, and one of the two
 * below when the wallet did.
 */
export class WalletError extends Error {
  constructor(
    message: string,
    readonly code: string,
  ) {
    super(message)
  }
}

let kit: SessionKit | null = null
let settings: WaxSettings | null = null

function csrfToken(): string {
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]!) : ''
}

async function post<T>(url: string, body: unknown): Promise<T> {
  // Same-origin cookie auth, same as the game API: Sanctum needs the XSRF
  // cookie echoed back on anything that writes.
  if (!csrfToken()) {
    await fetch('/sanctum/csrf-cookie', { credentials: 'include' }).catch(() => undefined)
  }

  const response = await fetch(url, {
    method: 'POST',
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-XSRF-TOKEN': csrfToken(),
    },
    body: JSON.stringify(body),
  })

  const payload = await response.json().catch(() => ({}))

  if (!response.ok) {
    throw new WalletError(
      payload.message ?? 'The server refused the login.',
      payload.code ?? `http_${response.status}`,
    )
  }

  return payload as T
}

/** Whoever this browser already is, plus what a login would cost. */
export async function loadSettings(): Promise<WaxSettings> {
  const response = await fetch('/api/auth/wax', {
    credentials: 'include',
    headers: { Accept: 'application/json' },
  })

  settings = (await response.json()) as WaxSettings

  return settings
}

/**
 * The chain the wallet signs for is the server's to name, so the kit cannot be
 * built until the settings have landed.
 *
 * The signing libraries are a quarter of a megabyte and are imported HERE, at
 * the moment somebody picks a wallet, rather than at the top of the file. A
 * player who is already logged in never touches this path, and the door itself
 * paints without waiting on any of it.
 */
async function sessionKit(): Promise<SessionKit> {
  if (!settings) {
    throw new WalletError('The wallet settings have not loaded yet.', 'not_ready')
  }

  if (!kit) {
    const [
      { SessionKit },
      { default: WebRenderer },
      { WalletPluginAnchor },
      { WalletPluginCloudWallet },
      { WalletPluginWombat },
      { WalletPluginScatter },
      { WalletPluginTokenPocket },
    ] = await Promise.all([
      import('@wharfkit/session'),
      import('@wharfkit/web-renderer'),
      import('@wharfkit/wallet-plugin-anchor'),
      import('@wharfkit/wallet-plugin-cloudwallet'),
      import('@wharfkit/wallet-plugin-wombat'),
      import('@wharfkit/wallet-plugin-scatter'),
      import('@wharfkit/wallet-plugin-tokenpocket'),
    ])

    kit = new SessionKit({
      appName: 'hexmine',
      chains: [{ id: settings.chain_id, url: settings.endpoint }],
      ui: new WebRenderer(),

      // The Cloud Wallet first because it is what most WAX players already have
      // and needs nothing installed; the rest in the order somebody is likely to
      // recognise them.
      //
      // `wallet-plugin-privatekey` is deliberately NOT here and must not be
      // added. It works by asking a player to paste a private key into a web
      // page, which is the exact thing every wallet in the list above exists to
      // avoid having to do -- and putting it on the picker would teach the habit
      // that phishes this game's players later. It is a test fixture, not a
      // wallet.
      walletPlugins: [
        new WalletPluginCloudWallet(),
        new WalletPluginAnchor(),
        new WalletPluginWombat(),
        new WalletPluginScatter(),
        new WalletPluginTokenPocket(),
      ],
    })
  }

  return kit
}

/**
 * Connect, pay, and come back logged in.
 *
 * The whole flow is one call because it is one decision by the player: there is
 * no useful halfway state between "connected a wallet" and "logged in", and a
 * wallet connected but unpaid can do nothing at all.
 *
 * `onWallet` fires the moment a session exists, and it is what the door locks
 * on. **It must not lock before that**, because the wallet step is WharfKit's
 * and WharfKit does not report a dismissal: closing its picker leaves
 * `kit.login()` pending forever. Its `cancelRequest()` only cancels registered
 * *prompt* promises, and the wallet-selection step registers none -- so a door
 * that disabled itself on the click would be dead until a reload, which is
 * exactly what it did when this was first written.
 *
 * Nothing is needed to guard the gap. The picker is a modal that covers the
 * page, so there is no second click to be had while it is up, and a player who
 * closes it is looking at the door exactly as they left it.
 */
export async function login(onWallet?: (wallet: string) => void): Promise<string> {
  const wallets = await sessionKit()

  // §2 -- restore before asking. A wallet that has signed in here before is
  // already known to the kit, and putting it through the identity dance again
  // is a popup that answers a question nobody asked: the transfer below names
  // its own signer.
  //
  // `login()` is called with NOTHING named, which is what puts WharfKit's own
  // picker on the screen. One chain is configured, so the only thing it asks is
  // which wallet -- and a returning player never sees it at all, because the
  // restore above has already answered.
  let session: Session
  try {
    session = (await wallets.restore()) ?? (await wallets.login()).session
  } catch (error) {
    throw new WalletError(reason(error, 'The wallet did not connect.'), 'wallet_cancelled')
  }

  const wallet = String(session.actor)

  // Past the picker, and everything from here is ours: two round trips and a
  // signature, none of which a player can dismiss.
  onWallet?.(wallet)

  // Nothing about the wallet goes up with this. The server issues a memo for
  // this browser and finds out who paid by reading the payment.
  const challenge = await post<Challenge>('/api/auth/wax/challenge', {})

  let transactionId: string
  try {
    const result = await session.transact(
      {
        action: {
          account: challenge.contract,
          name: 'transfer',
          authorization: [session.permissionLevel],
          data: {
            from: wallet,
            to: challenge.account,
            quantity: challenge.fee,
            memo: challenge.memo,
          },
        },
      },
      // Broadcast, obviously -- the server proves the login by reading the
      // transaction off the chain, so a signature that never left the browser
      // proves nothing.
      { broadcast: true },
    )

    transactionId = String(result.resolved?.transaction.id ?? result.response?.transaction_id ?? '')
  } catch (error) {
    throw new WalletError(reason(error, 'The payment was not signed.'), 'payment_cancelled')
  }

  if (!transactionId) {
    throw new WalletError('The wallet signed but returned no transaction id.', 'no_transaction_id')
  }

  const { wallet: connected } = await post<{ wallet: string }>('/api/auth/wax', {
    transaction_id: transactionId,
  })

  return connected
}

export async function logout(): Promise<void> {
  await fetch('/api/auth/wax', {
    method: 'DELETE',
    credentials: 'include',
    headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
  })

  await kit?.logout().catch(() => undefined)
}

function reason(error: unknown, fallback: string): string {
  const message = error instanceof Error ? error.message : ''

  return message.trim() === '' ? fallback : message
}
