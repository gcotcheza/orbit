// Dark or light, remembered; dark is the default (also resources/css/tokens.css).
// Owns two side effects only: the `data-theme` attribute and `theme-color` meta tag.
import { defineStore } from 'pinia'
import { ref } from 'vue'

const STORAGE_KEY = 'orbit-theme'
const THEMES = ['dark', 'light']

export const useThemeStore = defineStore('theme', () => {
    const theme = ref('dark')

    /**
     * Push the current value at the document. The meta colour is read back
     * out of the stylesheet, not hardcoded — tokens.css is the single source of truth.
     */
    function apply() {
        const root = document.documentElement
        root.dataset.theme = theme.value

        const meta = document.querySelector('meta[name="theme-color"]')
        const background = getComputedStyle(root).getPropertyValue('--bg').trim()

        // Empty check matters: pre-stylesheet, the custom property resolves to
        // '' — writing that reads as "no preference" and paints white chrome.
        if (meta && background !== '') {
            meta.setAttribute('content', background)
        }
    }

    /**
     * Adopt the remembered choice, if there is one, and apply it. Called
     * once from app.js before the app mounts, so the first frame is already right.
     */
    function load() {
        let stored = null

        try {
            stored = localStorage.getItem(STORAGE_KEY)
        } catch {
            // Safari private mode throws instead of returning null; not worth
            // failing a boot over — fall through to the default.
        }

        if (THEMES.includes(stored)) {
            theme.value = stored
        }

        apply()
    }

    function set(next) {
        if (!THEMES.includes(next)) {
            return
        }

        theme.value = next
        apply()

        try {
            localStorage.setItem(STORAGE_KEY, next)
        } catch {
            // As above — the choice still holds for this session.
        }
    }

    function toggle() {
        set(theme.value === 'dark' ? 'light' : 'dark')
    }

    return { theme, load, set, toggle }
})
