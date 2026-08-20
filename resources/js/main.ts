import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'

// The stylesheet is a separate Vite entry (see vite.config.ts) so Laravel can
// emit it as a <link> and avoid a flash of unstyled content on first paint.
createApp(App).use(createPinia()).mount('#app')
