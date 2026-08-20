// The whole client entry point — #app is empty in the Blade, so a fault here is a white screen
// with a 200 behind it. Order is deliberate: pinia, theme, router, mount.
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
