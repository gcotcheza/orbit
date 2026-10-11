// @vitest-environment jsdom
import { afterEach, describe, expect, it } from 'vitest'
import { cycleFocus } from './focus'

function modal() {
    const box = document.createElement('div')
    box.tabIndex = -1
    box.innerHTML = '<p>text</p><a href="/one">one</a><a>no href</a><button>two</button>'
    document.body.append(box)

    return box
}

const stop = (box, name) => [...box.querySelectorAll('a, button')].find((el) => el.textContent === name)

afterEach(() => {
    document.body.replaceChildren()
})

describe('cycleFocus', () => {
    it('walks the links and buttons forward and wraps at the end', () => {
        const box = modal()
        box.focus()

        cycleFocus(box, false)
        expect(document.activeElement).toBe(stop(box, 'one'))

        cycleFocus(box, false)
        expect(document.activeElement).toBe(stop(box, 'two'))

        cycleFocus(box, false)
        expect(document.activeElement).toBe(stop(box, 'one'))
    })

    it('walks backwards from the container to the last stop', () => {
        const box = modal()
        box.focus()

        cycleFocus(box, true)
        expect(document.activeElement).toBe(stop(box, 'two'))

        cycleFocus(box, true)
        expect(document.activeElement).toBe(stop(box, 'one'))
    })

    it('pulls a focus that escaped back inside', () => {
        const box = modal()
        const outside = document.createElement('button')
        document.body.append(outside)
        outside.focus()

        cycleFocus(box, false)
        expect(document.activeElement).toBe(stop(box, 'one'))
    })

    it('leaves the focus where it is when there is nowhere to go', () => {
        const box = document.createElement('div')
        box.tabIndex = -1
        document.body.append(box)
        box.focus()

        cycleFocus(box, false)
        expect(document.activeElement).toBe(box)
    })
})
