// @vitest-environment jsdom
// A link that leaves the app says so, inside its own accessible name, and asks
// first (docs/DECISIONS.md: an-interstitial-sits-on-top-of-the-new-tab-link).
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import NewTabLink from './NewTabLink.vue'

const HREF = 'https://www.aviasales.com/search/AMS1509OPO1?marker=123456'

let wrapper = null

const link = (attrs = {}) => {
    wrapper = mount(NewTabLink, {
        attachTo: document.body,
        attrs: { href: HREF, ...attrs },
        slots: { default: '<span>See this fare on Aviasales</span>' },
    })

    return wrapper
}

function clickOn(element, init = {}) {
    const event = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init })

    element.dispatchEvent(event)

    return event
}

const dialog = () => document.body.querySelector('[role="dialog"]')

afterEach(() => {
    wrapper?.unmount()
    wrapper = null
})

describe('a link that opens a new tab', () => {
    it('is a real anchor, wearing what its caller gave it', () => {
        const a = link({ class: 'booking__link' })

        expect(a.element.tagName).toBe('A')
        expect(a.attributes('href')).toBe(HREF)
        expect(a.classes()).toContain('booking__link')
    })

    // No `noreferrer`: the affiliate attribution rides on the referrer.
    it('opens away from the app without handing over the opener', () => {
        expect(link().attributes('target')).toBe('_blank')
        expect(link().attributes('rel')).toBe('noopener')
    })

    it('says it in the name, after the caller\'s own words', () => {
        expect(link().text()).toBe('See this fare on Aviasales (opens in a new tab)')
    })

    it('says it to screen readers only', () => {
        const note = link().get('span.sr-only')

        expect(note.text()).toBe('(opens in a new tab)')
        expect(link().element.lastElementChild.className).toBe('sr-only')
    })
})

describe('the confirmation it opens first', () => {
    it('holds a plain click back and asks instead', async () => {
        const a = link()
        const event = clickOn(a.element)
        await nextTick()

        expect(event.defaultPrevented).toBe(true)
        expect(dialog()).not.toBeNull()
        expect(dialog().textContent).toContain('You\'re leaving Orbit')
    })

    it.each([
        ['ctrl', { ctrlKey: true }],
        ['meta', { metaKey: true }],
        ['shift', { shiftKey: true }],
        ['alt', { altKey: true }],
        ['the middle button', { button: 1 }],
    ])('lets a click with %s leave the app untouched', async (_name, init) => {
        const event = clickOn(link().element, init)
        await nextTick()

        expect(event.defaultPrevented).toBe(false)
        expect(dialog()).toBeNull()
    })

    it('hands the same destination on, marker and all', async () => {
        clickOn(link().element)
        await nextTick()

        const onward = dialog().querySelector('.leaving__continue')

        expect(onward.tagName).toBe('A')
        expect(onward.getAttribute('href')).toBe(HREF)
        expect(onward.getAttribute('target')).toBe('_blank')
        expect(onward.getAttribute('rel')).toBe('noopener')
    })

    it('closes on Escape and gives the focus back to the link', async () => {
        const a = link()
        a.element.focus()

        clickOn(a.element)
        await nextTick()
        expect(document.activeElement).not.toBe(a.element)

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
        await nextTick()

        expect(dialog()).toBeNull()
        expect(document.activeElement).toBe(a.element)
    })

    it('closes on the scrim and gives the focus back to the link', async () => {
        const a = link()
        a.element.focus()

        clickOn(a.element)
        await nextTick()

        clickOn(document.body.querySelector('.leaving__scrim'))
        await nextTick()

        expect(dialog()).toBeNull()
        expect(document.activeElement).toBe(a.element)
    })

    it('closes on Stay in Orbit and gives the focus back to the link', async () => {
        const a = link()
        a.element.focus()

        clickOn(a.element)
        await nextTick()

        clickOn(dialog().querySelector('.leaving__stay'))
        await nextTick()

        expect(dialog()).toBeNull()
        expect(document.activeElement).toBe(a.element)
    })

    // Closing during the click would cancel the navigation it started.
    it('stands still until the onward click has been taken', async () => {
        vi.useFakeTimers()

        clickOn(link().element)
        await nextTick()

        const event = clickOn(dialog().querySelector('.leaving__continue'))
        await nextTick()

        expect(event.defaultPrevented).toBe(false)
        expect(dialog()).not.toBeNull()

        vi.runAllTimers()
        await nextTick()

        expect(dialog()).toBeNull()
        vi.useRealTimers()
    })
})
