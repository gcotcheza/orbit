// Which frame the window is big enough for. The literals are the plan's breakpoints — a custom
// property cannot be read by matchMedia any more than by @media (docs/BUSINESS-LOGIC.md §36).
import { computed, getCurrentScope, onScopeDispose, ref } from 'vue'

// The height is the phone-on-its-side guard: a 844x390 handset is wider than 768px and is still a
// phone, and the frame's panes have no room to be panes in 390px (docs/BUSINESS-LOGIC.md §36).
const TABLET = '(min-width: 768px) and (min-height: 600px)'
const DESKTOP = '(min-width: 1024px) and (min-height: 600px)'

export function useLayout() {
    // No window and no matchMedia both mean "assume the phone", which is the layout that needs no
    // media query to be correct.
    const usable = typeof window !== 'undefined' && typeof window.matchMedia === 'function'
    const queries = usable ? [window.matchMedia(TABLET), window.matchMedia(DESKTOP)] : []

    function read() {
        if (!usable) {
            return 'phone'
        }

        if (queries[1].matches) {
            return 'desktop'
        }

        return queries[0].matches ? 'tablet' : 'phone'
    }

    const layout = ref(read())

    function update() {
        layout.value = read()
    }

    queries.forEach((query) => query.addEventListener('change', update))

    if (getCurrentScope()) {
        onScopeDispose(() => queries.forEach((query) => query.removeEventListener('change', update)))
    }

    return {
        layout,
        isPhone: computed(() => layout.value === 'phone'),
        isDesktop: computed(() => layout.value === 'desktop'),
    }
}
