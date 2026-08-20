import { onScopeDispose, readonly, ref } from 'vue'

/**
 * One breakpoint, used to decide *placement* rather than layout.
 *
 * The HUD is the same on both: floating plates over a full-bleed map. What
 * changes is where the tutorial prompt lives -- top-right on a wide screen,
 * above the dock on a phone, where there is no room beside the gauges.
 *
 * matchMedia rather than a resize listener: it fires once per crossing.
 */
const WIDE = '(min-width: 561px)'

export function useBreakpoint() {
  const query = window.matchMedia(WIDE)
  const isWide = ref(query.matches)

  const update = (event: MediaQueryListEvent) => {
    isWide.value = event.matches
  }

  query.addEventListener('change', update)
  onScopeDispose(() => query.removeEventListener('change', update))

  return { isWide: readonly(isWide) }
}
