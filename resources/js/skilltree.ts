/**
 * The skill-tree proposal's own entry point.
 *
 * A separate page rather than a panel over the map, for the same reason as
 * /oddity: it is a PITCH, not the rules. Four candidate shapes for §7.4 drawn
 * side by side so the choice can be made by looking rather than by reading a
 * paragraph describing each one.
 *
 * Served at /skilltree (routes/web.php).
 */
import { createApp } from 'vue'
import SkillTreeApp from './SkillTreeApp.vue'

createApp(SkillTreeApp).mount('#app')
