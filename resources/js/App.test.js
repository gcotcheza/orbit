// @vitest-environment jsdom
// One bar or one rail, never both, and which screens keep the phone column
// (docs/DESKTOP-LAYOUT-PLAN.md, App.vue).
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { RouterLinkStub, mount } from '@vue/test-utils'
import { reactive, ref } from 'vue'

const currentRoute = reactive({ meta: { layout: 'tabs' } })

// Deferred inside the arrow, because vi.mock is hoisted above the const above it.
vi.mock('vue-router', () => ({ useRoute: () => currentRoute }))
vi.mock('@/lib/pwa', () => ({ updateReady: ref(false), applyUpdate: vi.fn(), dismissUpdate: vi.fn() }))

import App from './App.vue'

/** A window this big, for a `useLayout()` that finds a matchMedia to ask. */
function windowOf(width, height = 900) {
    window.matchMedia = (media) => ({
        media,
        matches:
            width >= Number(media.match(/min-width: (\d+)px/)[1]) &&
            height >= Number(media.match(/min-height: (\d+)px/)[1]),
        addEventListener: () => {},
        removeEventListener: () => {},
    })
}

const shell = () =>
    mount(App, { global: { stubs: { RouterView: true, RouterLink: RouterLinkStub } } })

beforeEach(() => {
    currentRoute.meta = { layout: 'tabs' }
})

afterEach(() => {
    delete window.matchMedia
})

describe('the shell', () => {
    it('draws the tab bar on a phone, and no rail', () => {
        windowOf(390)

        const wrapper = shell()

        expect(wrapper.find('.tab-bar').exists()).toBe(true)
        expect(wrapper.find('.rail-nav').exists()).toBe(false)
        expect(wrapper.get('.app-shell').classes()).toContain('app-shell--tabs')
    })

    // The bar and the rail are the same five destinations; two of them on one screen is the bug
    // this pair of assertions exists to catch.
    it.each([
        [820, 'tablet'],
        [1280, 'desktop'],
    ])('swaps it for the rail on a %ipx %s', (width) => {
        windowOf(width)

        const wrapper = shell()

        expect(wrapper.find('.rail-nav').exists()).toBe(true)
        expect(wrapper.find('.tab-bar').exists()).toBe(false)
        expect(wrapper.get('.app-shell').classes()).toContain('app-shell--rail')
        expect(wrapper.get('.app-shell').classes()).not.toContain('app-shell--tabs')
    })

    // A bare screen — the route detail, the login — has neither, at any width.
    it('gives a bare screen neither, however wide the window', () => {
        windowOf(1280)
        currentRoute.meta = { layout: 'bare' }

        const wrapper = shell()

        expect(wrapper.find('.rail-nav').exists()).toBe(false)
        expect(wrapper.find('.tab-bar').exists()).toBe(false)
        expect(wrapper.get('.app-shell').classes()).not.toContain('app-shell--rail')
    })
})

describe('the content area', () => {
    // Phases 2-3 give the other screens panes; until then only the landing owns the frame.
    it('keeps the phone column for a screen with no pane of its own', () => {
        windowOf(1280)

        expect(shell().get('.app-shell__main').classes()).toContain('app-shell__main--column')
    })

    it('hands the whole frame to a screen marked wide', () => {
        windowOf(1280)
        currentRoute.meta = { layout: 'tabs', wide: true }

        expect(shell().get('.app-shell__main').classes()).not.toContain('app-shell__main--column')
    })

    // `wide: 'desktop'` — the calendar and the watch list, whose panes need the 1024px split.
    it('hands the frame to a desktop-wide screen only past 1024px', () => {
        windowOf(1280)
        currentRoute.meta = { layout: 'tabs', wide: 'desktop' }

        expect(shell().get('.app-shell__main').classes()).not.toContain('app-shell__main--column')
    })

    it('keeps that screen in the phone column on a tablet', () => {
        windowOf(820)
        currentRoute.meta = { layout: 'tabs', wide: 'desktop' }

        expect(shell().get('.app-shell__main').classes()).toContain('app-shell__main--column')
    })

    it('never clamps anything on a phone, where there is no rail to leave room for', () => {
        windowOf(390)

        expect(shell().get('.app-shell__main').classes()).not.toContain('app-shell__main--column')
    })

    // A handset on its side is wider than 768px and still a phone: it keeps the bar.
    it('leaves a phone on its side alone', () => {
        windowOf(844, 390)

        const wrapper = shell()

        expect(wrapper.find('.tab-bar').exists()).toBe(true)
        expect(wrapper.find('.rail-nav').exists()).toBe(false)
    })
})
