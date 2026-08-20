// @vitest-environment jsdom
// Unit test, not browser: scripts/e2e.sh can't stage two builds served from
// one origin with a worker installing between them while a page outlives both.
// Why: docs/BUSINESS-LOGIC.md §36.
//
// Tests the CONTRACT with navigator.serviceWorker (which events mean a newer
// build); the registration is a fake made of real EventTargets.
// Why: docs/BUSINESS-LOGIC.md §36.
//
// Core assertion is negative: nothing reloads unless the button was pressed,
// even though `controllerchange` fires on its own as the new worker activates.
// Why: docs/BUSINESS-LOGIC.md §36.
import { beforeEach, describe, expect, it, vi } from 'vitest'

/** A worker, as far as this module is concerned: a state and a postMessage. */
class FakeWorker extends EventTarget {
    constructor(state = 'installing') {
        super()
        this.state = state
        this.postMessage = vi.fn()
    }

    /** Move to a new state and tell whoever is listening, as the browser does. */
    become(state) {
        this.state = state
        this.dispatchEvent(new Event('statechange'))
    }
}

class FakeRegistration extends EventTarget {
    constructor({ waiting = null } = {}) {
        super()
        this.waiting = waiting
        this.installing = null
        this.update = vi.fn().mockResolvedValue(undefined)
    }

    /** A new worker starts downloading — the browser's `updatefound`. */
    findUpdate(worker) {
        this.installing = worker
        this.dispatchEvent(new Event('updatefound'))

        return worker
    }
}

/**
 * Fresh copy of the module per test: it holds module-level state on purpose
 * (one registration, one "asked", one "already reloading") that must not leak.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
async function load({ controller = {} } = {}) {
    vi.resetModules()

    const serviceWorker = new EventTarget()
    serviceWorker.controller = controller
    serviceWorker.register = vi.fn()

    Object.defineProperty(navigator, 'serviceWorker', { value: serviceWorker, configurable: true })

    return { serviceWorker, pwa: await import('./pwa.js') }
}

/* jsdom has no navigation, so `location.reload` is the one thing that has to be
   replaced rather than observed. */
let reload

beforeEach(() => {
    reload = vi.fn()
    vi.stubGlobal('location', { ...window.location, reload })
})

describe('finding out that a newer build has arrived', () => {
    it('says nothing on a page that has just been given its first worker', async () => {
        // No controller = this worker IS the first one; offering to reload
        // would be reloading a page somebody just opened.
        // Why: docs/BUSINESS-LOGIC.md §36.
        const { pwa } = await load({ controller: null })
        const registration = new FakeRegistration()

        pwa.watchForUpdate(registration)

        const arriving = registration.findUpdate(new FakeWorker())
        arriving.become('installed')

        expect(pwa.updateReady.value).toBe(false)
    })

    it('announces a worker that installs while an older one is in charge', async () => {
        const { pwa } = await load()
        const registration = new FakeRegistration()

        pwa.watchForUpdate(registration)
        expect(pwa.updateReady.value).toBe(false)

        const arriving = registration.findUpdate(new FakeWorker())

        // Still downloading: there is no newer build ON THIS DEVICE yet, and an
        // offer made now is an offer to reload into the same bundle.
        expect(pwa.updateReady.value).toBe(false)

        arriving.become('installed')

        expect(pwa.updateReady.value).toBe(true)
    })

    it('announces a worker that was already parked when the page loaded', async () => {
        // Installed while app closed. Orbit's worker skips waiting so it
        // doesn't actually park — tested here as a property of pwa.js regardless.
        // Why: docs/BUSINESS-LOGIC.md §36.
        const { pwa } = await load()

        pwa.watchForUpdate(new FakeRegistration({ waiting: new FakeWorker('installed') }))

        expect(pwa.updateReady.value).toBe(true)
    })
})

describe('taking the new version, and not taking it', () => {
    it('does not reload when the worker changes on its own', async () => {
        const { pwa, serviceWorker } = await load()

        pwa.watchForUpdate(new FakeRegistration())

        // What actually happens on every deploy: the new worker activates and
        // claims the page without anybody being asked.
        serviceWorker.dispatchEvent(new Event('controllerchange'))

        expect(reload).not.toHaveBeenCalled()
    })

    it('asks a waiting worker to stand down, and reloads once it has', async () => {
        const { pwa, serviceWorker } = await load()
        const waiting = new FakeWorker('installed')

        pwa.watchForUpdate(new FakeRegistration({ waiting }))
        pwa.applyUpdate()

        // The reload waits for the handover, so the page that comes back is
        // served by the new worker rather than by the one it replaces.
        expect(waiting.postMessage).toHaveBeenCalledWith({ type: 'SKIP_WAITING' })
        expect(reload).not.toHaveBeenCalled()

        serviceWorker.dispatchEvent(new Event('controllerchange'))

        expect(reload).toHaveBeenCalledTimes(1)

        // And exactly once, whatever else the browser fires.
        serviceWorker.dispatchEvent(new Event('controllerchange'))

        expect(reload).toHaveBeenCalledTimes(1)
    })

    it('reloads straight away when the new worker is already in charge', async () => {
        // Orbit's real case: service-worker.js skips waiting on install, so by
        // the time anybody is told, there is nothing left to ask.
        const { pwa } = await load()

        pwa.watchForUpdate(new FakeRegistration())
        pwa.applyUpdate()

        expect(reload).toHaveBeenCalledTimes(1)
        expect(pwa.updateReady.value).toBe(false)
    })

    it('takes the offer away when it is dismissed, and reloads nothing', async () => {
        const { pwa } = await load()
        const registration = new FakeRegistration()

        pwa.watchForUpdate(registration)
        registration.findUpdate(new FakeWorker()).become('installed')

        expect(pwa.updateReady.value).toBe(true)

        pwa.dismissUpdate()

        expect(pwa.updateReady.value).toBe(false)
        expect(reload).not.toHaveBeenCalled()
    })
})

describe('looking for a deploy without being navigated', () => {
    /* Installed app backgrounded/reopened for days without a navigation —
       exactly when the browser is least likely to check for updates itself. */
    const becomeVisible = () => {
        Object.defineProperty(document, 'hidden', { value: false, configurable: true })
        document.dispatchEvent(new Event('visibilitychange'))
    }

    it('checks when the app comes back into view, and not while it is hidden', async () => {
        const { pwa } = await load()
        const registration = new FakeRegistration()

        pwa.watchForUpdate(registration)

        Object.defineProperty(document, 'hidden', { value: true, configurable: true })
        document.dispatchEvent(new Event('visibilitychange'))

        expect(registration.update).not.toHaveBeenCalled()

        vi.useFakeTimers()
        vi.advanceTimersByTime(20 * 60 * 1000)

        becomeVisible()

        expect(registration.update).toHaveBeenCalledTimes(1)

        vi.useRealTimers()
    })

    it('does not ask again on every app switch', async () => {
        // `visibilitychange` fires on every app switch; a request per flick
        // would be rude for news that changes a few times a day.
        // Why: docs/BUSINESS-LOGIC.md §36.
        const { pwa } = await load()
        const registration = new FakeRegistration()

        pwa.watchForUpdate(registration)

        becomeVisible()
        becomeVisible()
        becomeVisible()

        expect(registration.update).not.toHaveBeenCalled()
    })
})
