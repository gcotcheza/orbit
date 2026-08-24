// jsdom has no matchMedia, so the real composable would answer 'phone'. The ref arrives as a
// getter: vi.mock factories run before the test's own const exists, so it is read on use.
import { computed } from 'vue'

export function layoutMock(read) {
    return {
        useLayout: () => {
            const desktop = read()

            return {
                layout: computed(() => (desktop.value ? 'desktop' : 'phone')),
                isPhone: computed(() => !desktop.value),
                isDesktop: desktop,
                stop: () => {},
            }
        },
    }
}
