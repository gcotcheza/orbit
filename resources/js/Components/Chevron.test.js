// @vitest-environment jsdom
// The one chevron three screens draw (design/README.md): same path, same
// stroke, only the size differs.
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import Chevron from './Chevron.vue'

describe('the chevron', () => {
    it('draws at the row size unless it is told otherwise', () => {
        const svg = mount(Chevron)

        expect(svg.attributes('width')).toBe('15')
        expect(svg.attributes('height')).toBe('15')
    })

    it('takes the size it is given, on both axes', () => {
        const svg = mount(Chevron, { props: { size: 17 } })

        expect(svg.attributes('width')).toBe('17')
        expect(svg.attributes('height')).toBe('17')
    })

    it('is decoration, so a screen reader never meets it', () => {
        expect(mount(Chevron).attributes('aria-hidden')).toBe('true')
    })

    it('is the same stroked path wherever it is drawn', () => {
        const path = mount(Chevron).get('path')

        expect(path.attributes('d')).toBe('M6 4l5 5-5 5')
        expect(path.attributes('stroke')).toBe('var(--muted)')
    })

    // The callers position it from their own scoped stylesheet, which can only
    // reach the root element.
    it('wears the class its caller puts on it', () => {
        const svg = mount(Chevron, { attrs: { class: 'ret__chevron' } })

        expect(svg.element.tagName).toBe('svg')
        expect(svg.classes()).toContain('ret__chevron')
    })
})
