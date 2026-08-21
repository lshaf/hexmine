import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'

// Single app: Laravel serves the page and the API, Vite serves the assets.
// There is no proxy and no second origin, so the client's fetch() calls to
// /api are same-origin and Sanctum's cookie auth works without CORS setup.
export default defineConfig({
  plugins: [
    laravel({
      input: [
        'resources/css/app.css',
        'resources/js/main.ts',
        'resources/js/almanac.ts',
        'resources/js/oddity.ts',
      ],
      refresh: true,
    }),
    vue(),
  ],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
  },

  // The dev server has to answer on every interface, not just loopback. Laravel
  // writes the asset host into `public/hot` and the browser fetches from there,
  // so a server bound to 127.0.0.1 tells a phone or a laptop on the LAN to load
  // the app's own JavaScript from *itself* -- which yields a blank #app and no
  // network error to explain it. `host: true` binds 0.0.0.0; DEV_HOST names the
  // address the browser should be told to use when that is not localhost.
  server: {
    host: true,
    port: 5173,
    strictPort: true,
    hmr: process.env.DEV_HOST ? { host: process.env.DEV_HOST } : undefined,
    origin: process.env.DEV_HOST ? `http://${process.env.DEV_HOST}:5173` : undefined,
  },
})
