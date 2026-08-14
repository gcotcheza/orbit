// =============================================================================
// Theme
// =============================================================================
// Dark or light, chosen by the user and remembered. Dark is the default, both
// here and in resources/css/tokens.css.
//
// The store owns ONE piece of state and two side effects: the `data-theme`
// attribute the stylesheet keys off, and the `theme-color` meta tag the phone's
// browser chrome keys off. Nothing else in the app reads or writes either.
// =============================================================================
import { defineStore } from 'pinia'
import { ref } from 'vue'

const STORAGE_KEY = 'orbit-theme'
const THEMES = ['dark', 'light']

export const useThemeStore = defineStore('theme', () => {
    const theme = ref('dark')

    /**
     * Push the current value at the document.
     *
     * The meta colour is READ BACK OUT OF THE STYLESHEET rather than listed
     * here. tokens.css is the single source of truth for every colour in this
     * app, and a second copy of `#0a0f1e` in a JavaScript file is a copy that
     * will still say `#0a0f1e` the day the background is retuned.
     */
    function apply() {
        const root = document.documentElement
        root.dataset.theme = theme.value

        const meta = document.querySelector('meta[name="theme-color"]')
        const background = getComputedStyle(root).getPropertyValue('--bg').trim()

        // The empty check matters: if this ever runs before the stylesheet has
        // been applied, the custom property resolves to '' — and writing that
        // would replace a correct colour in the markup with nothing at all,
        // which reads to the browser as "no preference" and paints its default
        // white chrome above a dark app.
        if (meta && background !== '') {
            meta.setAttribute('content', background)
        }
    }

    /**
     * Adopt the remembered choice, if there is one, and apply it.
     *
     * Called once from app.js BEFORE the app mounts, so the first frame Vue
     * draws is already in the right theme.
     */
    function load() {
        let stored = null

        try {
            stored = localStorage.getItem(STORAGE_KEY)
        } catch {
            // Safari in private mode throws on localStorage rather than
            // returning null. A theme is not worth failing a boot over: fall
            // through to the default and stop trying to persist.
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
