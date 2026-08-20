// =============================================================================
// Orbit — the whole client entry point
// =============================================================================
// #app is empty in app.blade.php: every pixel this app draws comes from here.
// That is worth stating because it decides the failure mode — a fault in this
// file is not a broken component, it is a white screen with a 200 behind it and
// nothing in any server log.
//
// ORDER IS DELIBERATE:
//   1. pinia, because both the theme and the router's guard read a store;
//   2. the theme, applied to the document BEFORE mount so the first frame Vue
//      paints is already the right one;
//   3. the router, whose beforeEach awaits /api/me;
//   4. mount.
// =============================================================================
import '../css/app.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from '@/App.vue'
import { registerServiceWorker } from '@/lib/pwa'
import { router } from '@/router'
import { useThemeStore } from '@/stores/theme'

const app = createApp(App)

app.use(createPinia())

useThemeStore().load()

app.use(router)

app.mount('#app')

// Last, and deliberately after mount: the PWA is an enhancement, and nothing the user is looking at
// should wait on it. See resources/js/lib/pwa.js.
registerServiceWorker()
