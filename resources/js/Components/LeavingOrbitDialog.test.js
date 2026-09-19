// @vitest-environment jsdom
// Orbit's own way out of Orbit (docs/DECISIONS.md:
// an-interstitial-sits-on-top-of-the-new-tab-link).
import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { h, KeepAlive, nextTick, ref } from 'vue'
import LeavingOrbitDialog from './LeavingOrbitDialog.vue'

const AVIASALES = 'https://www.aviasales.com/search/AMS1509OPO1?marker=123456'

let wrapper = null

function dialog(href = AVIASALES) {
    wrapper = mount(LeavingOrbitDialog, { attachTo: document.body, props: { href } })

    return document.body.querySelector('.leaving')
}

/** Home's <KeepAlive> (App.vue), as far as this dialog can tell. */
function cached(href = AVIASALES) {
    const shown = ref(true)
    const closed = vi.fn()

    wrapper = mount(
        {
            setup: () => () => h(KeepAlive, null, {
                default: () => (shown.value ? h(LeavingOrbitDialog, { href, onClose: closed }) : null),
            }),
        },
        { attachTo: document.body },
    )

    return { closed, shown }
}

function escape() {
    const event = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true })

    document.body.dispatchEvent(event)

    return event
}

const stay = () => document.body.querySelector('.leaving__stay')
const onward = () => document.body.querySelector('.leaving__continue')

function press(key, init = {}) {
    const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init })

    document.activeElement.dispatchEvent(event)

    return event
}

afterEach(() => {
    wrapper?.unmount()
    wrapper = null
})

describe('the leaving confirmation', () => {
    it('names the site it is about to hand over to', () => {
        const panel = dialog()

        expect(panel.querySelector('.leaving__title').textContent).toBe('You\'re leaving Orbit')
        expect(panel.querySelector('.leaving__body').textContent).toBe(
            'Aviasales opens in a new tab, so Orbit stays where it is. The price and availability'
            + ' there are theirs, and can differ from what we recorded this morning.',
        )
        expect(onward().textContent).toBe('Continue to Aviasales')
        expect(stay().textContent).toBe('Stay in Orbit')
    })

    it.each([
        [AVIASALES, 'Aviasales'],
        ['https://www.skyscanner.nl/transport/flights/ams/opo/260915/', 'Skyscanner'],
        // The third party nobody has added yet is the point of the fallback.
        ['https://www.kiwi.com/en/search/AMS/OPO', 'kiwi.com'],
        ['https://flights.example.org/AMS-OPO', 'flights.example.org'],
        // No hostname at all would otherwise read "Continue to " and stop.
        ['mailto:fares@example.org', 'the booking site'],
    ])('reads %s as %s', (href, site) => {
        dialog(href)

        expect(onward().textContent).toBe(`Continue to ${site}`)
    })

    it('hands the destination on unedited, in a new tab, keeping the referrer', () => {
        dialog()

        expect(onward().tagName).toBe('A')
        expect(onward().getAttribute('href')).toBe(AVIASALES)
        expect(onward().getAttribute('target')).toBe('_blank')
        expect(onward().getAttribute('rel')).toBe('noopener')
    })

    it('is a real dialog, named by its own title', () => {
        const panel = dialog()

        expect(panel.getAttribute('role')).toBe('dialog')
        expect(panel.getAttribute('aria-modal')).toBe('true')
        expect(document.getElementById(panel.getAttribute('aria-labelledby')).textContent).toBe(
            'You\'re leaving Orbit',
        )
        expect(stay().tagName).toBe('BUTTON')
    })

    it('takes the focus when it opens', () => {
        const panel = dialog()

        expect(document.activeElement).toBe(panel)
    })

    it('keeps Tab inside itself, in both directions', () => {
        const panel = dialog()

        press('Tab')
        expect(document.activeElement).toBe(stay())

        press('Tab')
        expect(document.activeElement).toBe(onward())

        press('Tab')
        expect(document.activeElement).toBe(stay())

        press('Tab', { shiftKey: true })
        expect(document.activeElement).toBe(onward())

        panel.focus()
        press('Tab', { shiftKey: true })
        expect(document.activeElement).toBe(onward())
    })

    it('closes on Escape', () => {
        dialog()
        press('Escape')

        expect(wrapper.emitted('close')).toHaveLength(1)
    })

    it('closes on the scrim', () => {
        dialog()
        document.body.querySelector('.leaving__scrim').dispatchEvent(new MouseEvent('click'))

        expect(wrapper.emitted('close')).toHaveLength(1)
    })

    it('closes on Stay in Orbit', () => {
        dialog()
        stay().dispatchEvent(new MouseEvent('click'))

        expect(wrapper.emitted('close')).toHaveLength(1)
    })

    // A modal over the day sheet must not let one Escape close both.
    it('swallows the keys it handles before anything underneath sees them', () => {
        const underneath = vi.fn()
        window.addEventListener('keydown', underneath)

        dialog()
        const escape = press('Escape')
        const tab = press('Tab')

        window.removeEventListener('keydown', underneath)

        expect(underneath).not.toHaveBeenCalled()
        expect(escape.defaultPrevented).toBe(true)
        expect(tab.defaultPrevented).toBe(true)
    })

    it('lets go of the keyboard once it is gone', () => {
        dialog()
        wrapper.unmount()
        wrapper = null

        expect(escape().defaultPrevented).toBe(false)
        expect(document.body.querySelector('.leaving')).toBeNull()
    })

    /*
     * The one screen that is kept alive never unmounts, so `onUnmounted` is not
     * the hook that runs on a Back navigation (RouteDetailPanel.test.js).
     */
    it('lets go of the keyboard when a cached screen goes away', async () => {
        const { shown } = cached()

        expect(escape().defaultPrevented).toBe(true)

        shown.value = false
        await nextTick()

        expect(escape().defaultPrevented).toBe(false)
    })

    it('closes itself when a cached screen goes away', async () => {
        const { closed, shown } = cached()

        shown.value = false
        await nextTick()

        expect(closed).toHaveBeenCalledTimes(1)
    })

    it('takes the keyboard back when that screen returns', async () => {
        const { shown } = cached()

        shown.value = false
        await nextTick()

        shown.value = true
        await nextTick()

        expect(escape().defaultPrevented).toBe(true)
    })
})
