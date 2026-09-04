/**
 * The chamfer proposal's own entry point.
 *
 * A pitch like /oddity and the skill-tree page before it: §13 allows two shapes
 * and the chamfer is one of them, but it has only ever been cut on ONE
 * diagonal — top-left and bottom-right, everywhere, on every plate, chip, tab
 * and button in the game. This page asks what the other diagonal is for.
 *
 * Served at /chamfer (routes/web.php).
 */
import { createApp } from 'vue'
import ChamferApp from './ChamferApp.vue'

createApp(ChamferApp).mount('#app')
