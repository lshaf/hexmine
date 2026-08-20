/**
 * How a wallet address is shown, and how it is handed over.
 *
 * These are two different jobs and they want two different values: the screen
 * gets an abbreviation, because nobody reads forty characters of hex, and the
 * clipboard gets the whole thing, because a truncated address is not an
 * address. Masking is a display decision, so it lives here rather than in the
 * API -- the server sends the player their own full address.
 */

/** `0x71c9…3f8a`. Enough of both ends to recognise, short enough to sit in a HUD. */
export function shortWallet(wallet: string): string {
  return wallet.length <= 13 ? wallet : `${wallet.slice(0, 6)}…${wallet.slice(-4)}`
}

/**
 * Copy to the clipboard, with a fallback for the insecure contexts the
 * Clipboard API refuses to run in (plain http, which is how this is developed).
 */
export async function copyText(text: string): Promise<boolean> {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text)
      return true
    }
  } catch {
    // Fall through to the textarea route rather than failing the interaction.
  }

  try {
    const scratch = document.createElement('textarea')
    scratch.value = text
    scratch.setAttribute('readonly', '')
    scratch.style.position = 'fixed'
    scratch.style.opacity = '0'
    document.body.appendChild(scratch)
    scratch.select()
    const ok = document.execCommand('copy')
    document.body.removeChild(scratch)
    return ok
  } catch {
    return false
  }
}
