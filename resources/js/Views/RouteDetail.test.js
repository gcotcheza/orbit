// @vitest-environment jsdom
// =============================================================================
// The route detail's two sentences and one button
// =============================================================================
// The screen is mostly its children — the gauge, the chart, the callout have
// their own reasons to exist — and what is genuinely THIS file's is the prose
// under the price and how loudly the hand-off at the bottom speaks. Both were
// wrong in ways a screenshot makes obvious and no test noticed:
//
//   - a headline fare with NO DATE ON IT. €75 could be this Friday or eleven
//     weeks out, and those are different answers to "should I book";
//   - "36% below its usual €99" printed in full confidence beside a gauge
//     drawing no arc at all, because `confident: false` — the percentage was
//     computed from statistics made of the one fare it is being compared to;
//   - "Above usual — wait" in the callout with a glowing accent Book button
//     under it, which is the app arguing with itself and losing.
//
// docs/API.md is the fixture, as it is for Home.test.js.
// =============================================================================
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { flushPromises, mount } from '@vue/test-utils'

const get = vi.fn()
const post = vi.fn()

vi.mock('@/lib/http', () => ({
    http: {
        get: (...args) => get(...args),
        post: (...args) => post(...args),
    },
}))
// The screen resolves a router only for its Back button, which is not what any
// of this is about.
vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn(), back: vi.fn() }) }))

import RouteDetail from './RouteDetail.vue'

const DETAIL = {
    code: 'AMS-LIS',
    origin: { iata: 'AMS', city: 'Amsterdam', country: 'Netherlands', countryCode: 'NL', lat: 52.3105, lng: 4.7683 },
    destination: { iata: 'LIS', city: 'Lisbon', country: 'Portugal', countryCode: 'PT', lat: 38.7742, lng: -9.1342 },
    price: { current: 75, usual: 111, pctBelow: 32 },
    score: 65,
    tier: 'great',
    confident: true,
    verdict: { label: 'Good price — book', short: 'Good', tone: 'good' },
    sparkline: [80, 78, 76, 75],
    trackingDays: 60,
    /*
     * `foundAt` NULL BY DEFAULT — the ordinary state of a route whose fares
     * predate the column, and the state in which this screen says nothing about
     * age at all. The tests that are about freshness set it.
     */
    cheapest: { date: '2026-09-09', price: 75, foundAt: null },
    history: [
        { date: '2026-08-12', price: 80 },
        { date: '2026-08-13', price: 78 },
        { date: '2026-08-14', price: 75 },
    ],
    stats: { min: 46, p25: 79, median: 111, p75: 130, max: 149 },
    advice: { title: 'Good price — book', body: '€75 is 32% under its usual €111 — a solid time to lock it in.', tone: 'good' },
    booking: {
        aviasales: 'https://www.aviasales.com/search/AMS0909LIS1?marker=123456',
        skyscanner: 'https://www.skyscanner.nl/transport/flights/ams/lis/260909/',
    },
}

/**
 * The `meta` half of the answer (docs/API.md): whether this account watches the
 * route, and how old its fares are. A route that is watched and fresh is the
 * one this screen has always drawn, so it is the default here — the states that
 * are new are the ones that say otherwise.
 */
const META = { watched: true, fares: { fetchedAt: '2026-08-14T06:12:00+02:00', fresh: true } }

async function detail(overrides = {}, meta = META) {
    get.mockResolvedValue({ data: { data: { ...DETAIL, ...overrides }, meta } })

    // The screen shares stores/watchlist.js with the watch screen and the
    // globe, so that a route added from here is a route they both know about.
    const wrapper = mount(RouteDetail, {
        props: { id: 'AMS-LIS' },
        global: { plugins: [createPinia()] },
    })

    await flushPromises()

    return wrapper
}

/** A 404 from the read, which is what a pair Orbit has never priced answers. */
function neverPriced() {
    get.mockRejectedValue({ response: { status: 404 } })
}

beforeEach(() => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
})

describe('the day the price is for', () => {
    it('prints it under the fare, named as a departure', async () => {
        const wrapper = await detail()

        expect(wrapper.get('.price__value').text()).toBe('€75')
        /*
         * "Cheapest departure", spelled out. This screen's OTHER dates — the
         * chart's — are the days we looked, and a line reading "Wed, Sep 9"
         * with nothing in front of it would be read as either.
         */
        expect(wrapper.get('.price__when').text()).toBe('Cheapest departure · Wed, Sep 9')
    })

    it('prints no date at all when there is no fare to date', async () => {
        // docs/API.md: `cheapest` is null before the first poll, and null is
        // not today.
        const wrapper = await detail({ cheapest: null, price: { current: null, usual: null, pctBelow: null } })

        expect(wrapper.find('.price__when').exists()).toBe(false)
        expect(wrapper.get('.price__value').text()).toBe('—')
    })
})

describe('the caption under the price', () => {
    it('states the comparison when Orbit means it', async () => {
        const wrapper = await detail()

        expect(wrapper.get('.price__caption').text()).toBe('32% below its usual €111')
    })

    /*
     * THE HONESTY RULE, and it is the same one the gauge beside it already
     * follows. `confident: false` means Orbit is not expressing an opinion
     * (docs/API.md) — under a week of this route's own prices, so the
     * statistics are computed from the handful of fares it collected itself and
     * the current price IS the median. A bold "36% below its usual €99" next to
     * a ring drawing no arc is that placeholder arithmetic read out as a
     * finding.
     *
     * The usual price SURVIVES, because it is a fact. What goes is the
     * comparison drawn from it.
     */
    it('drops the percentage while it is still learning the route', async () => {
        const wrapper = await detail({
            confident: false,
            score: 0,
            tier: 'none',
            trackingDays: 1,
            price: { current: 63, usual: 99, pctBelow: 36 },
            verdict: { label: 'Not enough data yet', short: 'New', tone: 'normal' },
        })

        expect(wrapper.get('.price__caption').text()).toBe('Usual €99 · still learning')
        expect(wrapper.text()).not.toContain('36%')

        // And the ring says the same thing in its own language.
        expect(wrapper.get('.gauge__value').text()).toBe('—')
        expect(wrapper.get('.gauge__caption').text()).toBe('Deal score')

        /*
         * SO DOES THE CALLOUT'S GLYPH, and it is the third reader of the same
         * flag. It was a white TICK — the identical mark "Good price — book"
         * carries — beside the sentence "Not enough data yet", on the screen a
         * route reaches on the day it is added, above a booking button. A tick
         * means "checked, and fine"; putting it on an admission that nothing
         * has been checked is the callout contradicting its own words.
         *
         * The path data is the assertion because that is what a reader sees:
         * the hourglass is drawn as two paths, the tick as one.
         */
        const glyph = wrapper.findAll('.callout__icon path')

        expect(glyph).toHaveLength(2)
        expect(glyph[0].attributes('d')).not.toContain('M4 9.5l3 3 7-8')
    })

    /* The tick survives everywhere Orbit does have an opinion. */
    it('keeps the tick on advice Orbit stands behind', async () => {
        const wrapper = await detail()

        const glyph = wrapper.findAll('.callout__icon path')

        expect(glyph).toHaveLength(1)
        expect(glyph[0].attributes('d')).toBe('M4 9.5l3 3 7-8')
    })

    it('says the scale out loud when there is a score to scale', async () => {
        const wrapper = await detail()

        expect(wrapper.get('.gauge__caption').text()).toBe('Deal score /100')
    })

    it('says so plainly when there is no fare and no usual price', async () => {
        const wrapper = await detail({ price: { current: null, usual: null, pctBelow: null } })

        expect(wrapper.get('.price__caption').text()).toBe('No fare seen for this route yet.')
    })
})

/*
 * =============================================================================
 * LOOK BEFORE YOU WATCH
 * =============================================================================
 * This screen can now be opened for a pair Orbit has no route row for at all —
 * the watch form's "Look up" navigates straight here — so it owns the fetch and
 * everything that can go wrong with one. What is asserted below is the part
 * that would otherwise be invisible: WHEN it asks, when it deliberately does
 * not, and what it says while it waits.
 */

/** Mounted by hand, so a test can hold the lookup open and look at the screen. */
function mountDetail() {
    return mount(RouteDetail, { props: { id: 'AMS-LIS' }, global: { plugins: [createPinia()] } })
}

const FRESH = { watched: false, fares: { fetchedAt: '2026-08-14T09:00:00+02:00', fresh: true } }

describe('a route Orbit has never priced', () => {
    it('says what it is doing, and draws the fares it gets back', async () => {
        neverPriced()

        let answer
        post.mockReturnValue(new Promise((resolve) => { answer = resolve }))

        const wrapper = mountDetail()
        await flushPromises()

        // Not the skeleton: a sentence, because a fare provider is being asked
        // six or seven questions and that is a second or three of somebody's
        // evening.
        expect(wrapper.get('.checking__title').text()).toBe('Checking current fares…')
        expect(post).toHaveBeenCalledWith(
            '/api/routes/lookup',
            { origin: 'AMS', destination: 'LIS' },
            expect.objectContaining({ timeout: expect.any(Number) }),
        )

        answer({ data: { data: DETAIL, meta: FRESH } })
        await flushPromises()

        expect(wrapper.find('.checking').exists()).toBe(false)
        expect(wrapper.get('.price__value').text()).toBe('€75')
    })

    /*
     * A PAIR THAT IS NOT A ROUTE — an origin Orbit does not fly from, an
     * airport it has never heard of — is refused by the lookup with a sentence
     * naming the half that is wrong, and that sentence is worth more than the
     * generic apology above it.
     */
    it('shows the server’s own reason when the pair cannot be priced', async () => {
        neverPriced()
        post.mockRejectedValue({
            response: { status: 422, data: { errors: { destination: ['Orbit does not know an airport with that code.'] } } },
        })

        const wrapper = mountDetail()
        await flushPromises()

        expect(wrapper.get('.empty__title').text()).toBe('No such route')
        expect(wrapper.get('.empty__code').text()).toBe('AMS-LIS')
        expect(wrapper.get('.empty__why').text()).toBe('Orbit does not know an airport with that code.')
    })

    it('says so honestly when the fetch fails, rather than spinning', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {})

        neverPriced()
        post.mockRejectedValue({ response: { status: 500 } })

        const wrapper = mountDetail()
        await flushPromises()

        expect(wrapper.find('.checking').exists()).toBe(false)
        expect(wrapper.get('.empty__title').text()).toBe('Could not load this route')
    })

    it('names the throttle instead of blaming the connection', async () => {
        neverPriced()
        post.mockRejectedValue({ response: { status: 429 } })

        const wrapper = mountDetail()
        await flushPromises()

        expect(wrapper.get('.empty__body').text()).toContain('Give it a minute')
    })
})

describe('fares that have gone stale', () => {
    const STALE = { watched: false, fares: { fetchedAt: '2026-07-02T06:12:00+02:00', fresh: false } }

    it('are refreshed when nothing else is going to refresh them', async () => {
        post.mockResolvedValue({ data: { data: { ...DETAIL, price: { current: 61, usual: 111, pctBelow: 45 } }, meta: FRESH } })

        const wrapper = await detail({}, STALE)

        expect(post).toHaveBeenCalledTimes(1)
        expect(wrapper.get('.price__value').text()).toBe('€61')
    })

    /*
     * A WATCHED ROUTE IS LEFT ALONE. It is polled every morning; stale fares on
     * one are a poll to fix rather than provider calls to spend from somebody's
     * phone — and the screen a watched route gets is the screen it always got.
     */
    it('are left alone on a route the morning poll owns', async () => {
        const wrapper = await detail({}, { ...STALE, watched: true })

        expect(post).not.toHaveBeenCalled()
        expect(wrapper.find('.watch').exists()).toBe(false)
    })

    /*
     * AND A REFRESH THAT FAILS DOES NOT TAKE THE PRICES WITH IT. What is on
     * screen was real when it was fetched; replacing it with an error page
     * would be the app punishing somebody for coming back to a route.
     */
    it('keep the last prices on screen when the refresh does not work', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {})
        post.mockRejectedValue({ response: { status: 500 } })

        const wrapper = await detail({}, STALE)

        expect(wrapper.get('.price__value').text()).toBe('€75')
        expect(wrapper.get('.detail__notice--quiet').text()).toContain('Could not check today’s fares')
        // And it says WHEN these are from, because that is the whole of what is
        // wrong with them.
        expect(wrapper.get('.detail__notice--quiet').text()).toContain('Jul 2')
    })
})

describe('the watchlist strip', () => {
    it('is not on a route that is already watched', async () => {
        const wrapper = await detail()

        expect(wrapper.find('.watch__action').exists()).toBe(false)
    })

    it('offers the route, and says so once it is taken', async () => {
        const wrapper = await detail({}, FRESH)

        const button = wrapper.get('.watch__action')
        expect(button.text()).toBe('Watch this route')

        post.mockResolvedValue({ data: { data: { code: 'AMS-LIS', active: true } } })

        await button.trigger('click')
        await flushPromises()

        // The same write the add form makes (docs/API.md), through the store
        // the globe and the watch list both read.
        expect(post).toHaveBeenCalledWith('/api/watchlist', { origin: 'AMS', destination: 'LIS' })
        expect(wrapper.find('.watch__action').exists()).toBe(false)
        expect(wrapper.get('.watch--on').text()).toContain('On your watch list')
    })

    it('says a failed add failed, and keeps the offer', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {})

        const wrapper = await detail({}, FRESH)

        post.mockRejectedValue({ response: { status: 500 } })

        await wrapper.get('.watch__action').trigger('click')
        await flushPromises()

        expect(wrapper.get('[role="alert"]').text()).toContain('Could not add this route')
        expect(wrapper.get('.watch__action').text()).toBe('Watch this route')
    })

    /*
     * AN ANSWER WITH NO `meta` AT ALL — an older server, a proxy that ate it —
     * must not produce a button that offers to add a route this screen cannot
     * tell is already there. Both things it drives fail closed.
     */
    it('stays away when the server did not say whether the route is watched', async () => {
        const wrapper = await detail({}, null)

        expect(wrapper.find('.watch').exists()).toBe(false)
        expect(post).not.toHaveBeenCalled()
    })
})

/*
 * ============================================================================
 * HOW OLD THE HEADLINE FARE IS
 * ============================================================================
 * A third date axis on the screen that is already careful about the other two:
 * `history[].date` is when we LOOKED, `cheapest.date` is when you FLY, and
 * `cheapest.foundAt` is when the PRICE WAS FOUND. Orbit's fares come from a
 * cache of other people's searches, so the big number at the top of this screen
 * can be days old — it showed €36 against a live €56 — and this is the screen
 * with a hand-off button under it.
 *
 * UNLIKE THE DAY SHEET, THIS ONE HAS A THRESHOLD. The sheet is one day and one
 * number and prints the age always; this page is a fare, a gauge, a chart, a
 * callout and a button, and a "Seen 2 hours ago" on a route polled this morning
 * would be one more grey line teaching the reader to skip the place the
 * important version appears.
 */
describe('how old the cheapest fare is', () => {
    /** The clock the assertions below are written against. */
    const NOW = new Date('2026-08-15T12:00:00+02:00')

    beforeEach(() => {
        vi.useFakeTimers()
        vi.setSystemTime(NOW)
    })

    afterEach(() => {
        vi.useRealTimers()
    })

    it('says how old a fare is once it has survived a morning it should have been repriced in', async () => {
        const wrapper = await detail({
            cheapest: { date: '2026-09-09', price: 75, foundAt: '2026-08-11T12:00:00+02:00' },
        })

        expect(wrapper.get('.price__seen').text()).toBe('Seen 4 days ago')
    })

    /*
     * THE THRESHOLD, FROM BOTH SIDES. A day is the poll's own period: under it
     * the fare is in the ordinary state of a watched route and its age says
     * nothing; past it, it has outlived a morning that should have replaced it.
     */
    it.each([
        ['2026-08-14T13:00:00+02:00', false, 'a fare from this time yesterday'],
        ['2026-08-14T11:00:00+02:00', true, 'a fare older than a day'],
    ])('shows the line only past a day (%s)', async (foundAt, shown) => {
        const wrapper = await detail({
            cheapest: { date: '2026-09-09', price: 75, foundAt },
        })

        expect(wrapper.find('.price__seen').exists()).toBe(shown)
    })

    /*
     * AND SILENCE WHERE THE AGE IS NOT KNOWN — a row written before the column
     * existed. The screen must not fill that in from anything else: rendering
     * "Seen just now", or borrowing `meta.fares.fetchedAt` (which is when ORBIT
     * ASKED, not when the price was found), would state exactly the false thing
     * this whole feature was built to stop stating.
     */
    it('says nothing at all when the age is unknown', async () => {
        const wrapper = await detail()

        expect(wrapper.find('.price__seen').exists()).toBe(false)
        expect(wrapper.text()).not.toContain('Seen ')
    })

    it('says nothing when there is no fare to be old', async () => {
        const wrapper = await detail({ cheapest: null, price: { current: null, usual: null, pctBelow: null } })

        expect(wrapper.find('.price__seen').exists()).toBe(false)
    })
})

describe('the booking hand-off', () => {
    it('is the loud one when the advice is not a warning', async () => {
        const wrapper = await detail()

        expect(wrapper.get('.booking__cta').classes()).toContain('booking__cta--primary')
    })

    /*
     * AVIASALES IS THE LOUD ONE, AND THAT IS A CORRECTNESS MATTER. Orbit's
     * prices come out of Aviasales' cache; this screen used to send people to
     * Skyscanner, which had often never had the fare — €29 here against €68
     * there. Skyscanner survives as a text link.
     */
    it('leads to the search the price came from, and offers the other as a comparison', async () => {
        const wrapper = await detail()

        expect(wrapper.get('.booking__cta').attributes('href')).toBe(DETAIL.booking.aviasales)
        expect(wrapper.get('.booking__cta').text()).toContain('Aviasales')

        const compare = wrapper.get('.booking__compare')

        expect(compare.attributes('href')).toBe(DETAIL.booking.skyscanner)
        expect(compare.text()).toBe('Compare on Skyscanner')
    })

    /*
     * ONE EXPECTATION LINE, word for word the day sheet's — the two are
     * duplicated rather than shared, so nothing but a test stops them drifting.
     * It replaced the old "we don't sell tickets" disclaimer rather than
     * stacking under it.
     */
    it('sets one expectation about the price rather than two lines of small print', async () => {
        const wrapper = await detail()

        expect(wrapper.get('.booking__disclaimer').text()).toBe(
            'Prices come from recent searches — the booking site shows live availability.',
        )
        expect(wrapper.text()).not.toContain("We don't sell tickets")
    })

    /*
     * A callout reading "Above usual — wait" over a glowing accent button is
     * the app contradicting itself, and the button wins because it is the
     * loudest thing on the screen. Same link, same tap target, quieter.
     */
    it('goes quiet under advice that says to wait', async () => {
        const wrapper = await detail({
            verdict: { label: 'Above usual — wait', short: 'Wait', tone: 'warn' },
            advice: {
                title: 'Above usual — wait',
                body: '€75 is 10% above its usual €68. Hold off — fares this far up tend to settle back.',
                tone: 'warn',
            },
        })

        expect(wrapper.get('.booking__cta').classes()).toContain('booking__cta--secondary')
        // Still a link, still to the same place.
        expect(wrapper.get('.booking__cta').attributes('href')).toBe(DETAIL.booking.aviasales)
    })
})

// =============================================================================
// The fare that may already be gone, and the way to find out
// =============================================================================
// DUS→VCE was on this screen at €36 — "Seen 3 days ago", usual €62 — against a
// live market of about $150. Every number was true and the headline was a fare
// nobody could buy.
// =============================================================================

/**
 * The state that started it: €36 against a usual €62, found three days ago.
 * ⚠ The advice is the SERVER's qualified one — a document with `mayBeGone`
 * true and "a solid time to lock it in" is not one this API produces.
 */
const GONE = {
    price: { current: 36, usual: 62, pctBelow: 42 },
    cheapest: { date: '2026-09-09', price: 36, foundAt: '2026-08-12T12:00:00+02:00', mayBeGone: true },
    advice: {
        title: 'Cheap, but it may be gone',
        body: '€36 is 42% under this route’s usual price, and old enough that fares like it have usually sold. Check the live price before counting on it.',
        tone: 'warn',
    },
}

/** What the server answers a live check with (docs/API.md, `meta.liveCheck`). */
const LIVE = {
    date: '2026-09-09',
    lowest: 150,
    typicalLow: 90,
    typicalHigh: 260,
    level: 'typical',
    checkedAt: '2026-08-15T12:00:00+02:00',
}

describe('a fare that may already be gone', () => {
    const NOW = new Date('2026-08-15T12:00:00+02:00')

    beforeEach(() => {
        vi.useFakeTimers()
        vi.setSystemTime(NOW)
    })

    afterEach(() => {
        vi.useRealTimers()
    })

    /* The display face is what a reader takes away, so it steps down a level
       and gains a sentence saying what is wrong with it. */
    it('draws the headline quieter and says why', async () => {
        const wrapper = await detail(GONE)

        expect(wrapper.get('.price__value').classes()).toContain('price__value--gone')
        expect(wrapper.get('.price__gone').text()).toBe('Seen 3 days ago — may be gone')
    })

    /* One line about the fare's age, not two. */
    it('replaces the plain freshness line rather than stacking on it', async () => {
        const wrapper = await detail(GONE)

        expect(wrapper.find('.price__seen').exists()).toBe(false)
    })

    /* The callout is the server's, and the hand-off follows its tone rather
       than composing a second opinion out of `mayBeGone`. */
    it('prints the server’s qualified callout and quiets the hand-off', async () => {
        const wrapper = await detail(GONE)

        expect(wrapper.get('.callout__title').text()).toBe('Cheap, but it may be gone')
        expect(wrapper.get('.booking__cta').classes()).toContain('booking__cta--secondary')
    })

    /* ⚠ A tick means "checked, and it is fine". Over "may be gone" it is the
       callout contradicting the sentence next to it. */
    it('does not put a confident tick on a warning', async () => {
        const tick = 'M4 9.5l3 3 7-8'

        expect((await detail(GONE)).get('.callout__icon').html()).not.toContain(tick)
        expect((await detail()).get('.callout__icon').html()).toContain(tick)
    })

    /*
     * ⚠ THE RULE IS THE SERVER'S. Both facts it is made of are on this page —
     * the age and the percentage — and the screen deliberately does not
     * recompute it. An old fare the server did NOT demote is drawn as it was.
     */
    it('does not decide for itself that an old fare is stale', async () => {
        const wrapper = await detail({
            cheapest: { date: '2026-09-09', price: 75, foundAt: '2026-08-12T12:00:00+02:00', mayBeGone: false },
        })

        expect(wrapper.get('.price__value').classes()).not.toContain('price__value--gone')
        expect(wrapper.get('.price__seen').text()).toBe('Seen 3 days ago')
        expect(wrapper.find('.price__gone').exists()).toBe(false)
    })
})

describe('checking the live price', () => {
    const NOW = new Date('2026-08-15T12:00:00+02:00')

    beforeEach(() => {
        vi.useFakeTimers()
        vi.setSystemTime(NOW)
    })

    afterEach(() => {
        vi.useRealTimers()
    })

    /** The server answers a live check with the whole detail document again. */
    function answers(liveCheck, overrides = GONE) {
        post.mockResolvedValue({
            data: {
                data: { ...DETAIL, ...overrides },
                meta: { ...META, liveCheck },
            },
        })
    }

    it('asks the server once, with no body and its own timeout', async () => {
        const wrapper = await detail(GONE)
        answers(LIVE)

        await wrapper.get('.live__action').trigger('click')
        await flushPromises()

        expect(post).toHaveBeenCalledTimes(1)
        expect(post).toHaveBeenCalledWith('/api/routes/AMS-LIS/live-price', null, { timeout: 30_000 })
    })

    /* The swap, which is the whole feature: "Orbit said €36, Google says €150"
       is what the search was paid for, and both halves stay on the page. */
    it('puts the live price in the headline and Orbit’s beside it', async () => {
        const wrapper = await detail(GONE)
        answers(LIVE)

        await wrapper.get('.live__action').trigger('click')
        await flushPromises()

        expect(wrapper.get('.price__value').text()).toBe('€150')
        expect(wrapper.get('.price__value').classes()).not.toContain('price__value--gone')
        expect(wrapper.get('.price__live').text()).toBe('Live on Google · checked just now')
        expect(wrapper.get('.price__typical').text()).toBe('Google’s typical €90–€260')
        expect(wrapper.get('.price__cached').text()).toBe('Orbit’s cached fare €36, seen 3 days ago')
    })

    /*
     * ⚠ THE PAGE'S LAST CONTROL DOES NOT ENDORSE THE NUMBER JUST DISPROVED —
     * and the sentence saying so is the SERVER's, arriving with the document.
     */
    it('keeps the hand-off quiet while Google says the fare is dearer', async () => {
        const wrapper = await detail(GONE)
        answers(LIVE, {
            ...GONE,
            advice: {
                title: 'Google cannot find this fare',
                body: 'Orbit has €36 cached; the cheapest Google can find for 9 Sep is €150. Treat the cached fare as gone.',
                tone: 'warn',
            },
        })

        await wrapper.get('.live__action').trigger('click')
        await flushPromises()

        expect(wrapper.get('.callout__title').text()).toBe('Google cannot find this fare')
        expect(wrapper.get('.booking__cta').classes()).toContain('booking__cta--secondary')
    })

    /* And it comes back when Google agrees: a live answer at or under Orbit's
       own price is the app being right about a cheap fare. */
    it('says yes again when Google confirms the fare', async () => {
        const wrapper = await detail(GONE)
        answers({ ...LIVE, lowest: 30 }, { ...GONE, advice: DETAIL.advice })

        await wrapper.get('.live__action').trigger('click')
        await flushPromises()

        expect(wrapper.get('.price__value').text()).toBe('€30')
        expect(wrapper.get('.booking__cta').classes()).toContain('booking__cta--primary')
    })

    /* "42% below its usual €62" under Google's €150 would read as an opinion
       about Google's number. */
    it('does not read Orbit’s comparison as if it were about the live price', async () => {
        const wrapper = await detail(GONE)
        answers(LIVE)

        await wrapper.get('.live__action').trigger('click')
        await flushPromises()

        expect(wrapper.find('.price__caption').exists()).toBe(false)
    })

    /* The server serves the same answer free for six hours, so a button
       offering to check again would be a lie about what it costs. */
    it('offers no button once there is an answer', async () => {
        const wrapper = await detail(GONE, { ...META, liveCheck: LIVE })

        expect(wrapper.find('.live__action').exists()).toBe(false)
        expect(wrapper.get('.price__value').text()).toBe('€150')
    })

    /* ⚠ Checked and silent is a real answer and it is not permission. */
    it('says when Google had nothing to say, and leaves the fare demoted', async () => {
        const wrapper = await detail(GONE, { ...META, liveCheck: { ...LIVE, lowest: null, typicalLow: null, typicalHigh: null, level: null } })

        expect(wrapper.get('.price__value').text()).toBe('€36')
        expect(wrapper.get('.price__value').classes()).toContain('price__value--gone')
        expect(wrapper.get('.live__note').text()).toContain('Google had no live price for this date')
    })

    /* A refusal is not an error: the price on screen is untouched and the
       server's own sentence is what the reader gets. */
    it('keeps the cached price and explains when the budget is reserved', async () => {
        const wrapper = await detail(GONE)

        post.mockRejectedValue({
            response: { status: 503, data: { message: 'Orbit is holding its remaining live checks in reserve.' } },
        })

        await wrapper.get('.live__action').trigger('click')
        await flushPromises()

        expect(wrapper.get('.live__error').text()).toBe('Orbit is holding its remaining live checks in reserve.')
        expect(wrapper.get('.price__value').text()).toBe('€36')
        expect(wrapper.get('.price__value').classes()).toContain('price__value--gone')
        expect(wrapper.get('.price__gone').text()).toBe('Seen 3 days ago — may be gone')
    })

    /*
     * ⚠ A CHECK THAT NEVER HAPPENED LEAVES THE BUTTON THERE. Nothing was spent
     * and nothing was recorded, so the offer to try again is honest — which is
     * the difference between this 503 and the reserved one above.
     */
    it('keeps offering the check when Google could not be reached', async () => {
        const wrapper = await detail(GONE)

        post.mockRejectedValue({
            response: {
                status: 503,
                data: { message: 'Orbit could not reach Google just now. Nothing was spent — try again in a moment.' },
            },
        })

        await wrapper.get('.live__action').trigger('click')
        await flushPromises()

        expect(wrapper.get('.live__error').text()).toBe(
            'Orbit could not reach Google just now. Nothing was spent — try again in a moment.',
        )
        expect(wrapper.get('.live__action').attributes('disabled')).toBeUndefined()
        expect(wrapper.get('.price__value').text()).toBe('€36')
    })

    it('offers nothing to check on a route with no fare', async () => {
        const wrapper = await detail({ cheapest: null, price: { current: null, usual: null, pctBelow: null } })

        expect(wrapper.find('.live').exists()).toBe(false)
    })
})
