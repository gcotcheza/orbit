// =============================================================================
// A fare that may already be gone, and the button that goes and asks
// =============================================================================
// DUS→VCE was on the route detail at €36 — "Seen 3 days ago", usual €62 — and
// the live direct was about $150 with nothing anywhere near it. Orbit's fares
// are Travelpayouts' cache of other people's searches, ultra-cheap ones die in
// hours, and the app had faithfully reprinted one that was already gone. Two
// things came of it and both are in this file: the headline stops being drawn
// at full confidence, and there is a way to go and check.
//
// =============================================================================
// ⚠ WHY TWO OF THESE THREE TESTS INTERCEPT THE APP'S OWN API, WHEN NOTHING ELSE
//   IN THIS SUITE DOES
// =============================================================================
// This harness drives the real app against a real database on purpose, and that
// is exactly what these two states cannot be reached through:
//
//   - THE DEMOTION needs a fare over 48 hours old. The sandbox's fares are all
//     stamped by the seeder's final pass (App\Infrastructure\Pricing\
//     FakePriceProvider stamps `foundAt` with the clock it was run at), so the
//     newest thing in the database is minutes old and specs/detail.spec.js
//     ASSERTS that no freshness line is drawn at all. There is no fare here
//     that can be stale, and seeding one would mean a seeder whose job is to
//     write a deliberately misleading row into any database it is pointed at.
//   - THE SUCCESSFUL CHECK needs SerpAPI to answer, and this sandbox has no
//     key — deliberately, because a browser suite must never spend a metered
//     third-party budget, and .env.e2e pins the fakes for exactly that reason.
//
// So the SERVER's behaviour is proven where it can be: tests/Feature/
// LivePriceCheckTest drives the whole endpoint against recorded SerpAPI
// fixtures, thirteen tests of the guardrails and the storage. What is left to
// prove is that a real browser DRAWS these two documents correctly, and that is
// what these tests do — the intercepted body is the exact shape docs/API.md
// publishes, taken from the sandbox's own live response and given the one field
// the sandbox cannot produce.
//
// THE THIRD TEST IS NOT INTERCEPTED AT ALL. The refusal path — no key, so no
// search — is the whole round trip, from a real tap to the app's own 503 and the
// sentence a person reads, and it is the state this box is really in.
// =============================================================================
import { expect, shot, test } from '../fixtures.js'

/** Seeded by database/seeders/WatchlistSeeder — six routes, this is the first. */
const CODE = 'AMS-LIS'

/**
 * The document the screen is drawing, with the one fact this sandbox cannot
 * hold: a cheapest fare found three days ago that the SERVER has judged
 * `mayBeGone`.
 *
 * ONLY THOSE TWO FIELDS ARE TOUCHED. The price, the statistics, the chart and
 * the gauge are the sandbox's own, so what is photographed is a real screen
 * with a real fare on it — not a mock of one.
 */
function ageTheCheapestFare(body) {
    const threeDaysAgo = new Date(Date.now() - 3 * 24 * 3_600_000).toISOString()

    body.data.cheapest = { ...body.data.cheapest, foundAt: threeDaysAgo, mayBeGone: true }

    return body
}

async function pretendTheFareIsThreeDaysOld(page) {
    await page.route(`**/api/routes/${CODE}`, async (route) => {
        const response = await route.fetch()

        await route.fulfill({ response, json: ageTheCheapestFare(await response.json()) })
    })
}

test('a stale, far-below-usual fare is demoted instead of shouted', async ({ page }) => {
    await pretendTheFareIsThreeDaysOld(page)

    await page.goto(`/route/${CODE}`)
    await expect(page.locator('.detail__code')).toHaveText('AMS → LIS')

    /*
     * THE LABEL, IN WORDS, NEXT TO THE NUMBER IT IS ABOUT. A reader standing in
     * front of a fare deciding whether to go and buy it needs the two facts that
     * decide it: how old the price is, and that it might not be there.
     */
    await expect(page.locator('.price__gone')).toHaveText('Seen 3 days ago — may be gone')

    /* And the plain "Seen 3 days ago" line is REPLACED rather than joined: two
       grey lines about one fact is how a page teaches people to skip both. */
    await expect(page.locator('.price__seen')).toHaveCount(0)

    /*
     * AND THE DEMOTION IS REAL AT THE PIXEL LEVEL, which is the half a class
     * assertion cannot make. The headline is 42 px in `--ink` normally; a fare
     * that may be gone is 32 px in `--muted`. A build that added the class and
     * no style would pass `toHaveClass` and change nothing a person sees.
     */
    const headline = await page.evaluate(() => {
        const style = getComputedStyle(document.querySelector('.price__value'))

        return { size: style.fontSize, colour: style.color }
    })

    expect(Number.parseFloat(headline.size)).toBeLessThan(42)

    const ordinary = await page.evaluate(() => getComputedStyle(document.body).getPropertyValue('--ink').trim())
    expect(headline.colour, 'the demoted headline is still drawn in the confident ink').not.toBe(ordinary)

    /*
     * AND THE HAND-OFF GOES QUIET WITH IT. The page has just said this price may
     * not exist; a glowing button under that would be the loudest thing on the
     * screen sending somebody off to buy it. Same link, same tap target, no
     * fill — the same treatment advice that says "wait" already gets.
     */
    await expect(page.getByRole('link', { name: /see this fare on aviasales/i })).toHaveClass(
        /booking__cta--secondary/,
    )

    await shot(page, 'route-detail-may-be-gone')
})

test('the live price takes the headline and Orbit’s own becomes context', async ({ page }) => {
    await pretendTheFareIsThreeDaysOld(page)

    /*
     * THE GOOGLE CLIENT, FAKED AT THE EDGE OF THE APP. The sandbox has no
     * SERPAPI_KEY and must never have one, so what is served here is the
     * document the endpoint answers when Google HAS replied — assembled from
     * the sandbox's own detail response so that everything except
     * `meta.liveCheck` is real. App\Http\Controllers\RouteController answers the
     * whole detail document exactly like this, which is why the screen needs no
     * special path to adopt it.
     */
    await page.route(`**/api/routes/${CODE}/live-price`, async (route) => {
        const response = await route.fetch({ url: new URL(`/api/routes/${CODE}`, page.url()).href, method: 'GET' })

        /*
         * AGED HERE TOO, AND THAT IS NOT BELT AND BRACES. `route.fetch()` goes
         * to the server directly rather than back through the handler above it,
         * so this body arrives with the sandbox's own minutes-old `foundAt` —
         * and the screen would then print "Orbit's cached fare €76" with no age
         * on it, which is a different state from the one being photographed.
         */
        const body = ageTheCheapestFare(await response.json())

        body.meta.liveCheck = {
            date: body.data.cheapest.date,
            lowest: 150,
            typicalLow: 90,
            typicalHigh: 260,
            level: 'typical',
            checkedAt: new Date().toISOString(),
        }

        await route.fulfill({ json: body, status: 200 })
    })

    await page.goto(`/route/${CODE}`)

    const cached = (await page.locator('.price__value').textContent()).trim()

    await page.getByRole('button', { name: 'Check live price' }).click()

    /*
     * THE SWAP, WHICH IS THE WHOLE FEATURE. Google's number takes the headline
     * because it is the only figure on this screen anybody can act on — and
     * Orbit's does not vanish, it becomes the context that makes the
     * disagreement visible. "Orbit said €X, Google says €150" is what a metered
     * search was spent to find out.
     */
    await expect(page.locator('.price__value')).toHaveText('€150')
    await expect(page.locator('.price__live')).toHaveText('Live on Google · checked just now')
    await expect(page.locator('.price__typical')).toHaveText('Google’s typical €90–€260')
    await expect(page.locator('.price__cached')).toHaveText(
        new RegExp(`^Orbit’s cached fare ${cached}, seen 3 days ago$`),
    )

    /* No longer demoted — the headline is not the doubtful number any more. */
    await expect(page.locator('.price__value')).not.toHaveClass(/price__value--gone/)

    /* AND NO SECOND TAP TO SELL. The server serves the same answer from its row
       for six hours and spends nothing, so a button offering to check again
       would be a lie about what it costs. */
    await expect(page.getByRole('button', { name: 'Check live price' })).toHaveCount(0)

    /* AND THE PAGE DOES NOT THEN ENDORSE THE NUMBER IT JUST DISPROVED: the
       advice under the chart is still reasoning about the cached fare, so the
       hand-off stays in its outline treatment while Google says the market is
       dearer. */
    await expect(page.getByRole('link', { name: /see this fare on aviasales/i })).toHaveClass(
        /booking__cta--secondary/,
    )

    await shot(page, 'route-detail-live-checked')
})

/*
 * ============================================================================
 * THE REAL ROUND TRIP: A BOX WITH NO KEY REFUSES, AND SAYS SO
 * ============================================================================
 * Nothing is intercepted here. This sandbox has no SERPAPI_KEY — which is the
 * DEFAULT state of this app, and the state this browser suite must stay in — so
 * App\Infrastructure\Verify\GoogleFlightsCheck answers "no budget" without
 * making a single request, the endpoint answers 503, and what is asserted is
 * the sentence a person actually reads and the fact that the price they were
 * looking at is untouched.
 */
test('with no budget it refuses, explains, and leaves the price alone', async ({ page, browserConsole }) => {
    /*
     * Chromium logs every 4xx/5xx as a console error and the harness treats
     * those as failures. This one is the assertion: a 503 from this endpoint is
     * the app working exactly as configured, and the waiver is narrow enough to
     * say so.
     */
    browserConsole.allow(/Failed to load resource.*status of 503/)

    await page.goto(`/route/${CODE}`)

    const before = (await page.locator('.price__value').textContent()).trim()

    await page.getByRole('button', { name: 'Check live price' }).click()

    await expect(page.locator('.live__error')).toHaveText(
        'Orbit is holding its remaining live checks in reserve.',
    )

    /* THE PRICE IS EXACTLY WHERE IT WAS. A check that could not be made never
       becomes a price, and a screen that punished somebody for asking would be
       the opposite of the point. */
    await expect(page.locator('.price__value')).toHaveText(before)
    await expect(page.locator('.price__live')).toHaveCount(0)

    await shot(page, 'route-detail-live-reserved')
})
