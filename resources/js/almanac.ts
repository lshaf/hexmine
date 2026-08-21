/**
 * The almanac's own entry point.
 *
 * A separate page rather than a panel over the map: this is a developer
 * reference, not something a player opens mid-trip. It reads the two static
 * catalogs and nothing else -- no store, no pinia, no request -- so it boots
 * instantly and is correct with no character and no session.
 *
 * Served at /almanac (routes/web.php).
 */
import { createApp } from 'vue'
import AlmanacApp from './AlmanacApp.vue'

createApp(AlmanacApp).mount('#app')
