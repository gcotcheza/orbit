// @vitest-environment jsdom
// A link that leaves the app says so, inside its own accessible name
// (docs/DECISIONS.md: a-link-that-opens-a-new-tab-says-so-inside-itself).
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import NewTabLink from './NewTabLink.vue'

const link = (attrs = {}) =>
    mount(NewTabLink, {
        attrs: { href: 'https://www.aviasales.com/search/AMS1509OPO1', ...attrs },
        slots: { default: '<span>See this fare on Aviasales</span>' },
    })

describe('a link that opens a new tab', () => {
    it('is a real anchor, wearing what its caller gave it', () => {
        const a = link({ class: 'booking__link' })

        expect(a.element.tagName).toBe('A')
        expect(a.attributes('href')).toBe('https://www.aviasales.com/search/AMS1509OPO1')
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
