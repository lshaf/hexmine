/**
 * The oddity proposal's own entry point.
 *
 * A separate page rather than a panel over the map, for the same reasons as the
 * almanac: it is a document, it takes no character, and it reads nothing but its
 * own local tables and the map's geometry -- no store, no pinia, no request --
 * so it boots instantly and is correct with no session at all.
 *
 * Served at /oddity (routes/web.php).
 */
import { createApp } from 'vue'
import OddityApp from './OddityApp.vue'

createApp(OddityApp).mount('#app')
