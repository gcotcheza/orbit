// @vitest-environment jsdom
// Which frame the window is wide enough for, and that it keeps up with a resize
// (docs/BUSINESS-LOGIC.md §36, lib/layout.js).
import { afterEach, describe, expect, it } from 'vitest'
import { effectScope } from 'vue'
import { useLayout } from './layout'

const minWidth = (media) => Number(media.match(/min-width: (\d+)px/)[1])
const minHeight = (media) => Number(media.match(/min-height: (\d+)px/)[1])

/** jsdom ships no matchMedia at all, so every test that wants one builds it. */
function windowOf(width, height = 900) {
    const lists = []
    const fits = (media) => width >= minWidth(media) && height >= minHeight(media)

    window.matchMedia = (media) => {
        const list = {
            media,
            matches: fits(media),
            handlers: new Set(),
            addEventListener: (_, handler) => list.handlers.add(handler),
            removeEventListener: (_, handler) => list.handlers.delete(handler),
        }

        lists.push(list)

        return list
    }

    return {
        lists,
        resize(nextWidth, nextHeight = height) {
            width = nextWidth
            height = nextHeight

            lists.forEach((list) => {
                list.matches = fits(list.media)
                list.handlers.forEach((handler) => handler(list))
            })
        },
    }
}

afterEach(() => {
    delete window.matchMedia
})

describe('the frame a window is wide enough for', () => {
    it.each([
        [390, 'phone'],
        [767, 'phone'],
        [768, 'tablet'],
        [1023, 'tablet'],
        [1024, 'desktop'],
        [1280, 'desktop'],
    ])('calls %ipx the %s layout', (width, expected) => {
        windowOf(width)

        const { layout, isPhone, isDesktop } = useLayout()

        expect(layout.value).toBe(expected)
        expect(isPhone.value).toBe(expected === 'phone')
        expect(isDesktop.value).toBe(expected === 'desktop')
    })

    // A phone on its side is 844px wide and is still a phone: the frame's panes have no room
    // to be panes in 390px, and the landscape stage rule is the phone's own.
    it.each([
        [844, 390],
        [926, 428],
        [1280, 500],
    ])('calls a %ix%i window a phone, wide as it is', (width, height) => {
        windowOf(width, height)

        expect(useLayout().layout.value).toBe('phone')
    })

    // A window dragged across a breakpoint, which is the whole reason this is a ref.
    it('follows the window across both breakpoints', () => {
        const fake = windowOf(390)
        const { layout } = useLayout()

        expect(layout.value).toBe('phone')

        fake.resize(820)
        expect(layout.value).toBe('tablet')

        fake.resize(1280)
        expect(layout.value).toBe('desktop')

        fake.resize(400)
        expect(layout.value).toBe('phone')
    })

    // The one safe default: the phone is the layout that needs no media query to be right.
    it('assumes the phone where there is no matchMedia to ask', () => {
        const { layout, isPhone } = useLayout()

        expect(layout.value).toBe('phone')
        expect(isPhone.value).toBe(true)
    })

    // Outside a component there is no scope to dispose it, so the caller is handed the stop.
    it('hands back a stop for a caller with no scope to dispose it', () => {
        const fake = windowOf(390)
        const { stop } = useLayout()

        expect(fake.lists.every((list) => list.handlers.size === 1)).toBe(true)

        stop()

        expect(fake.lists.every((list) => list.handlers.size === 0)).toBe(true)
    })

    it('stops listening when the component using it goes away', () => {
        const fake = windowOf(390)
        const scope = effectScope()

        scope.run(() => useLayout())

        expect(fake.lists.every((list) => list.handlers.size === 1)).toBe(true)

        scope.stop()

        expect(fake.lists.every((list) => list.handlers.size === 0)).toBe(true)
    })
})
