// @vitest-environment jsdom
// =============================================================================
// The choreography, driven by a fake clock
// =============================================================================
// lib/geo.test.js proves the flight's arithmetic and lib/tour.test.js proves
// its timetable. What is left — and what those two cannot see — is whether this
// component actually CALLS them in the right order, and whether it stops when
// it is told to. That is the part that breaks in the field: a timer that
// survives a route change and flies two arcs at once, a paused screen that
// keeps a GPU busy in a background tab, a sequence that never emits `advance`
// and leaves the tour stuck on Lisbon forever.
//
// So globe.gl is replaced by a scene that only remembers what it was asked to
// do, and the browser's clock by vitest's. Every assertion below is about
// ORDER and CANCELLATION; nothing here re-checks a number that has its own test
// next door.
//
// jsdom does not implement matchMedia or ResizeObserver — both are browser APIs
// this component legitimately uses — so they are stubbed in beforeEach rather
// than worked around in the component.
// =============================================================================
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const scene = {
    resize: vi.fn(),
    applyTheme: vi.fn(),
    showRoute: vi.fn(),
    showAllRoutes: vi.fn(),
    pointOfView: vi.fn(),
    pause: vi.fn(),
    resume: vi.fn(),
    destroy: vi.fn(),
}

const createGlobeScene = vi.fn(async () => scene)

// vi.mock is hoisted above every import in this file, so this factory runs
// before the two consts above are initialised. Both references are deferred
// inside functions for exactly that reason.
vi.mock('./globeScene', () => ({
    hasWebgl: () => true,
    createGlobeScene: (...args) => createGlobeScene(...args),
}))

// Written below the mock rather than above it so the two read as one thing;
// ESM hoists it either way.
import GlobeStage from './GlobeStage.vue'

const AMS_LIS = {
    code: 'AMS-LIS',
    origin: { iata: 'AMS', city: 'Amsterdam', lat: 52.3105, lng: 4.7683 },
    destination: { iata: 'LIS', city: 'Lisbon', lat: 38.7742, lng: -9.1342 },
}

const AMS_OPO = {
    code: 'AMS-OPO',
    origin: { iata: 'AMS', city: 'Amsterdam', lat: 52.3105, lng: 4.7683 },
    destination: { iata: 'OPO', city: 'Porto', lat: 41.2481, lng: -8.6814 },
}

let reduceMotion = false

function stubBrowserApis() {
    window.matchMedia = (media) => ({
        media,
        matches: reduceMotion,
        addEventListener: () => {},
        removeEventListener: () => {},
    })

    globalThis.ResizeObserver = class {
        observe() {}
        disconnect() {}
    }
}

/**
 * Mount the stage and let its asynchronous globe.gl import settle.
 *
 * The wrapper is remembered so that afterEach can unmount it. That is not
 * tidiness: this component listens on `document`, jsdom's document outlives the
 * test, and a stage left mounted goes on answering visibilitychange for every
 * test after it.
 */
let stage = null

async function mountStage(routes = [AMS_LIS, AMS_OPO]) {
    stage = mount(GlobeStage, {
        props: { routes, activeCode: routes[0].code },
    })

    await flushPromises()

    return stage
}

/** Every altitude the camera was sent to, in order. */
const altitudes = () => scene.pointOfView.mock.calls.map(([pov]) => pov.altitude)

beforeEach(() => {
    reduceMotion = false
    setActivePinia(createPinia())
    stubBrowserApis()
    vi.useFakeTimers()
    vi.clearAllMocks()
})

afterEach(() => {
    stage?.unmount()
    stage = null
    vi.useRealTimers()
})

describe('the sequence', () => {
    it('fits the whole route the moment it has a globe, without a transition', async () => {
        await mountStage()

        expect(scene.showRoute).toHaveBeenCalledWith(AMS_LIS)
        // The first route cuts to the fitted view rather than animating from
        // globe.gl's default camera position.
        expect(scene.pointOfView).toHaveBeenCalledTimes(1)
        expect(scene.pointOfView.mock.calls[0][0].altitude).toBe(2.4)
        expect(scene.pointOfView.mock.calls[0][1]).toBe(0)
    })

    it('dives to the origin airport, then flies, then asks for the next route', async () => {
        const wrapper = await mountStage()

        vi.advanceTimersByTime(1100)

        const [dive, diveMs] = scene.pointOfView.mock.calls[1]
        expect(dive).toEqual({ lat: AMS_LIS.origin.lat, lng: AMS_LIS.origin.lng, altitude: 0.42 })
        expect(diveMs).toBe(1700)

        // Still holding over Amsterdam, and not yet flying.
        vi.advanceTimersByTime(2400)
        expect(scene.pointOfView).toHaveBeenCalledTimes(2)
        expect(wrapper.emitted('advance')).toBeUndefined()

        // 3 600 ms in: the flight starts and drives the camera per frame.
        vi.advanceTimersByTime(200)
        expect(scene.pointOfView.mock.calls.length).toBeGreaterThan(2)

        vi.advanceTimersByTime(3600)

        const [landed] = scene.pointOfView.mock.calls.at(-1)
        expect(landed.lat).toBeCloseTo(AMS_LIS.destination.lat, 3)
        expect(landed.lng).toBeCloseTo(AMS_LIS.destination.lng, 3)
        // The end of the altitude curve — it lands deeper than it took off.
        expect(landed.altitude).toBeCloseTo(0.2, 6)

        // The dwell, and only then the hand-over.
        expect(wrapper.emitted('advance')).toBeUndefined()
        vi.advanceTimersByTime(4400)
        expect(wrapper.emitted('advance')).toHaveLength(1)
    })

    it('shows the plane only while it is in the air, pointed where it is going', async () => {
        const wrapper = await mountStage()

        // v-show, read off the element: VTU's isVisible() has opinions about
        // SVG in a detached tree, and `display: none` is the actual thing being
        // asserted.
        const planeStyle = () => wrapper.find('.plane').attributes('style')

        expect(planeStyle()).toContain('display: none')

        vi.advanceTimersByTime(3700)
        await wrapper.vm.$nextTick()
        expect(planeStyle()).not.toContain('display: none')
        // South-west out of Amsterdam — lib/geo.test.js owns the number.
        expect(planeStyle()).toContain('rotate(220.9deg)')

        vi.advanceTimersByTime(3600)
        await wrapper.vm.$nextTick()
        expect(planeStyle()).toContain('display: none')
    })
})

describe('cancellation', () => {
    it('abandons the flight in progress when the route changes', async () => {
        const wrapper = await mountStage()

        vi.advanceTimersByTime(3700)
        scene.pointOfView.mockClear()

        await wrapper.setProps({ activeCode: AMS_OPO.code })

        expect(scene.showRoute).toHaveBeenLastCalledWith(AMS_OPO)
        // The new sequence's own opening fit, and nothing from the old flight's
        // frame loop trailing behind it.
        expect(scene.pointOfView).toHaveBeenCalledTimes(1)
        expect(altitudes()).toEqual([2.4])

        vi.advanceTimersByTime(200)
        expect(altitudes()).toEqual([2.4])
    })

    it('emits advance once per sequence, not once per route it has ever shown', async () => {
        const wrapper = await mountStage()

        // Tap another chip midway, then let the second sequence run in full.
        vi.advanceTimersByTime(5000)
        await wrapper.setProps({ activeCode: AMS_OPO.code })
        vi.advanceTimersByTime(20000)

        expect(wrapper.emitted('advance')).toHaveLength(1)
    })

    it('stops dead when the tab is hidden and starts over when it comes back', async () => {
        const wrapper = await mountStage()

        vi.advanceTimersByTime(3700)

        Object.defineProperty(document, 'hidden', { value: true, configurable: true })
        document.dispatchEvent(new Event('visibilitychange'))

        expect(scene.pause).toHaveBeenCalledTimes(1)

        scene.pointOfView.mockClear()
        vi.advanceTimersByTime(30000)
        // Nothing moved, and the tour did not quietly advance behind a
        // backgrounded tab.
        expect(scene.pointOfView).not.toHaveBeenCalled()
        expect(wrapper.emitted('advance')).toBeUndefined()

        Object.defineProperty(document, 'hidden', { value: false, configurable: true })
        document.dispatchEvent(new Event('visibilitychange'))

        expect(scene.resume).toHaveBeenCalledTimes(1)
        // Back to the opening shot rather than mid-flight.
        expect(altitudes()).toEqual([2.4])
    })

    it('is born asleep when the screen was hidden while the globe was loading', async () => {
        // The import takes as long as the connection takes, and the tab can be
        // backgrounded inside that window — at which point pause() runs before
        // there is anything to pause.
        stage = mount(GlobeStage, { props: { routes: [AMS_LIS], activeCode: AMS_LIS.code } })

        Object.defineProperty(document, 'hidden', { value: true, configurable: true })
        document.dispatchEvent(new Event('visibilitychange'))

        await flushPromises()

        expect(scene.pause).toHaveBeenCalledTimes(1)
        expect(scene.pointOfView).not.toHaveBeenCalled()

        Object.defineProperty(document, 'hidden', { value: false, configurable: true })
    })

    it('gives the globe back when it is unmounted', async () => {
        const wrapper = await mountStage()

        wrapper.unmount()

        expect(scene.destroy).toHaveBeenCalledTimes(1)

        scene.pointOfView.mockClear()
        vi.advanceTimersByTime(30000)
        expect(scene.pointOfView).not.toHaveBeenCalled()
    })

    it('tells the screen when there is no globe to be had', async () => {
        createGlobeScene.mockRejectedValueOnce(new Error('chunk failed'))
        vi.spyOn(console, 'error').mockImplementation(() => {})

        const wrapper = await mountStage()

        expect(wrapper.emitted('unavailable')).toHaveLength(1)
    })
})

describe('reduced motion', () => {
    it('draws every route at once, holds still, and never advances', async () => {
        reduceMotion = true

        const wrapper = await mountStage()

        expect(scene.showAllRoutes).toHaveBeenCalledWith([AMS_LIS, AMS_OPO], AMS_LIS.code)
        expect(scene.showRoute).not.toHaveBeenCalled()

        // A cut to the fitted view, and that is the whole film.
        expect(scene.pointOfView).toHaveBeenCalledTimes(1)
        expect(scene.pointOfView.mock.calls[0][1]).toBe(0)

        vi.advanceTimersByTime(30000)

        expect(scene.pointOfView).toHaveBeenCalledTimes(1)
        expect(wrapper.emitted('advance')).toBeUndefined()
        expect(wrapper.find('.plane').attributes('style')).toContain('display: none')
    })
})

describe('the overlays', () => {
    it('says what is on screen, in the words the design uses', async () => {
        const wrapper = await mountStage()

        expect(wrapper.text()).toContain('2 routes orbiting')
        expect(wrapper.text()).toContain('AMS → LIS · Lisbon')
    })

    it('counts one route as a route', async () => {
        const wrapper = await mountStage([AMS_LIS])

        expect(wrapper.text()).toContain('1 route orbiting')
    })
})
