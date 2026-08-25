/**
 * The battle bench's own entry point, §9.5.
 *
 * A separate page rather than a panel over the map, for the same reason the
 * almanac is one: it takes no character and makes no map request, so it boots
 * with no session and is correct without one. What it does make is a single
 * POST to /api/battle-sim, which runs the REAL exchange server-side -- the
 * arithmetic lives in one place (§16) and a bench that reimplemented it would
 * be a second opinion that drifts.
 *
 * Served at /battle (routes/web.php).
 */
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import BattleSimApp from './BattleSimApp.vue'

// Pinia only because BattleLive is the GAME's plate and reaches for the store
// to read your pair. The bench hands it that as a prop instead, so the store
// stays empty and is never asked anything -- but a composable cannot be called
// conditionally, so it still has to exist.
createApp(BattleSimApp).use(createPinia()).mount('#app')
