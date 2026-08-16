// =============================================================================
// Route detail (design/README.md §2)
// =============================================================================
// Reached the way a person reaches it — by tapping the spotlight card on the
// globe, not by typing the URL. The route the card was showing is the route the
// detail screen has to be about, and that hand-off across a router navigation
// is the thing worth checking.
// =============================================================================
import { expect, shot, test, waitForGlobe } from '../fixtures.js'

test('the spotlight card opens the route it was showing', async ({ page }) => {
    await page.goto('/')
    await waitForGlobe(page)

    const code = await page.locator('.spotlight__code').textContent()
    const [origin, destination] = code.split('→').map((part) => part.trim())

    await page.locator('.spotlight').click()

    await expect(page).toHaveURL(new RegExp(`/route/${origin}-${destination}$`))
    await expect(page.locator('.detail__code')).toHaveText(`${origin} → ${destination}`)

    // No tab bar on this screen — design/README.md §2, and `meta.layout: 'bare'`
    // in the router. A tab bar here would mean the layout string stopped being
    // read.
    await expect(page.getByRole('navigation', { name: 'Primary' })).toHaveCount(0)
})

test('the price, the gauge, the chart and the booking link are all really there', async ({ page }) => {
    await page.goto('/')
    await waitForGlobe(page)
    await page.locator('.spotlight').click()

    const code = await page.locator('.detail__code').textContent()
    const [origin, destination] = code.split('→').map((part) => part.trim())

    // --- The price -----------------------------------------------------------
    // A euro amount, not the em dash the component shows for a route with no
    // fare yet. Sixty days of seeded history means every route has one.
    await expect(page.locator('.price__value')).toHaveText(/^€\d+$/)
    await expect(page.locator('.price__caption')).not.toBeEmpty()

    /*
     * AND THE DAY IT IS FOR. A headline fare with no date on it is not
     * something anybody can act on — €75 could be this Friday or eleven weeks
     * out — and this screen printed one for months. It is a DEPARTURE date and
     * is labelled as one, because the chart below it is dated by when we
     * LOOKED and the two must never be read for each other.
     */
    await expect(page.locator('.price__when')).toHaveText(
        /^Cheapest departure · (Mon|Tue|Wed|Thu|Fri|Sat|Sun), \w{3} \d{1,2}$/,
    )

    /*
     * AND NO FRESHNESS LINE, WHICH IS THE CORRECT ANSWER HERE AND IS WORTH
     * ASSERTING RATHER THAN SKIPPING.
     *
     * This screen prints "Seen 4 days ago" only once the cheapest fare is over
     * a day old. The sandbox's fares were all found by the seeder's final pass,
     * so they are hours old at most and the line must be ABSENT — which is what
     * proves the threshold exists. A build that printed "Seen just now" on every
     * route would pass a test that only checked the line's format, and would be
     * exactly the grey noise the threshold was put there to avoid. The day sheet
     * is where the always-on version of this line is checked
     * (specs/calendar.spec.js).
     */
    await expect(page.locator('.price__seen')).toHaveCount(0)

    // --- The deal-score gauge ------------------------------------------------
    // The ring is an SVG circle whose dash offset IS the score. Asserting the
    // number is legible AND that the ring was actually swept catches the case
    // where the arithmetic works and the drawing does not.
    const gauge = page.locator('.gauge__value')
    await expect(gauge).toHaveText(/^\d{1,3}$/)

    // Out of WHAT. The ring read 65 with "DEAL SCORE" under it, and 65 out of
    // 100, out of 10 and "65th of the routes on your list" are all readings
    // somebody offered. The scale is on the label now. (Asserted as the text
    // content — the upper case is the stylesheet's.)
    await expect(page.locator('.gauge__caption')).toHaveText('Deal score /100')

    const offset = await page.locator('.gauge__ring').getAttribute('stroke-dashoffset')
    expect(Number(offset)).toBeGreaterThanOrEqual(0)
    expect(Number(offset)).toBeLessThan(157)

    // --- The history chart ---------------------------------------------------
    // `d` on the line path, and a real one: an empty or degenerate path
    // attribute is exactly what a chart drawn from zero points produces, and it
    // renders as nothing at all with no error anywhere.
    const line = page.locator('svg.chart path.chart__line')
    await expect(line).toBeVisible()

    const path = await line.getAttribute('d')
    expect(path).toMatch(/^M[\d.\-\s,]+L/)
    expect(path.match(/L/g).length, 'the price line has almost no points on it').toBeGreaterThan(10)

    /*
     * The dashed "usual price" reference and the end dot the design asks for.
     *
     * `toHaveCount` AND AN ATTRIBUTE FOR THE LINE, NOT `toBeVisible`. An SVG
     * <line> that is horizontal has a bounding box of zero height, and
     * Playwright reads a zero-area box as "hidden" — so the visibility
     * assertion is guaranteed to fail on an element the user can see perfectly
     * well. What is worth asserting is that it was drawn AND placed: a
     * reference at y=0 is a line along the top edge of the chart, which is what
     * a median of null or NaN produces.
     */
    const usual = page.locator('svg.chart line.chart__usual')
    await expect(usual).toHaveCount(1)

    const medianY = Number(await usual.getAttribute('y1'))
    expect(medianY).toBeGreaterThan(0)
    expect(medianY).toBeLessThan(140)

    await expect(page.locator('svg.chart circle.chart__dot')).toBeVisible()

    // --- The booking hand-off ------------------------------------------------
    // TWO LINKS, AND AVIASALES IS THE LOUD ONE. Orbit's fares come from
    // Travelpayouts, which is Aviasales' cache; the app used to quote those
    // fares and hand the reader to Skyscanner, which had often never had them
    // (€29 here against €68 there). These are the links that leave the app, so
    // a malformed one is a dead end at the moment somebody has decided to buy.
    //
    // `{ORIGIN}{DDMM}{DEST}1` — UPPER case, day before month, one adult in
    // economy — App\Application\Routes\BookingLink.
    const booking = page.getByRole('link', { name: /see this fare on aviasales/i })
    await expect(booking).toBeVisible()

    expect(await booking.getAttribute('href')).toMatch(
        new RegExp(
            `^https://www\\.aviasales\\.com/search/${origin}\\d{4}${destination}1(\\?marker=.+)?$`,
        ),
    )

    /*
     * AND THE SECOND OPINION, WHICH IS A BUTTON NOW. It shipped as a 12 px
     * centred text link under the hand-off, in the same grey as the disclaimer
     * beneath it, and the owner reported that on a phone it does not read as
     * something that can be pressed at all — one of only two controls on this
     * screen that do anything. The pair is now side by side: the check on the
     * left, the search Orbit's own number came from on the right with the
     * accent and the wider share.
     */
    const compare = page.getByRole('link', { name: /compare on skyscanner/i })

    const layout = await page.evaluate(() => {
        const quiet = document.querySelector('.booking__compare').getBoundingClientRect()
        const loud = document.querySelector('.booking__cta').getBoundingClientRect()

        return {
            sameRow: Math.abs(quiet.top - loud.top) < 2,
            skyscannerFirst: quiet.right <= loud.left,
            loudIsWider: loud.width > quiet.width,
            /* A finger, not a line of text. */
            tappable: Math.min(quiet.height, loud.height) >= 44,
        }
    })

    expect(layout).toEqual({ sameRow: true, skyscannerFirst: true, loudIsWider: true, tappable: true })

    expect(await compare.getAttribute('href')).toMatch(
        new RegExp(
            `^https://www\\.skyscanner\\.nl/transport/flights/${origin.toLowerCase()}/${destination.toLowerCase()}/\\d{6}/$`,
        ),
    )

    // Both open away from the app, and safely.
    for (const link of [booking, compare]) {
        await expect(link).toHaveAttribute('target', '_blank')
        await expect(link).toHaveAttribute('rel', /noopener/)
    }

    /*
     * ONE EXPECTATION LINE UNDER THEM, and only one. It says where the number
     * came from and who has the live one — which is what a reader standing in
     * front of a possibly-cached fare needs — and it MERGED the old "we don't
     * sell tickets" disclaimer rather than being stacked under it.
     */
    await expect(page.locator('.booking__disclaimer')).toHaveText(
        'Prices come from recent searches — the booking site shows live availability.',
    )
    await expect(page.getByText("We don't sell tickets")).toHaveCount(0)

    /*
     * AND IT DOES NOT SHOUT OVER THE ADVICE ABOVE IT. A callout reading "Above
     * usual — wait" with a glowing accent Book button under it is the app
     * arguing with itself in front of somebody about to spend money, and the
     * button wins because it is the loudest thing on the screen. The variant is
     * read off the callout's own tone, so the two cannot disagree — which is
     * what this asserts, on whichever route the tour happened to be on.
     */
    const warned = (await page.locator('.callout').getAttribute('class')).includes('callout--warn')

    await expect(booking).toHaveClass(warned ? /booking__cta--secondary/ : /booking__cta--primary/)

    await shot(page, 'route-detail')
})

/*
 * ============================================================================
 * THE APP DOES NOT ARGUE WITH ITSELF
 * ============================================================================
 * "Above usual — wait" in the callout, and directly under it a glowing accent
 * button reading "See this fare on Aviasales" — the loudest element on the screen,
 * contradicting the sentence above it, in front of somebody about to spend
 * money. The hand-off is still there for anybody who has decided anyway; it is
 * simply no longer the conclusion of the page.
 *
 * IT SKIPS RATHER THAN PINNING A ROUTE. Which routes are in the "wait" state is
 * the fake provider's arithmetic against today's date, so naming one here would
 * be a fixture that rots on a date nobody chose. The tone → variant RULE is
 * asserted unconditionally in the test above, on whichever route the tour
 * happened to be on; this one is here to photograph it.
 */
test('a route the app says to wait on gets the quiet Book button', async ({ page }) => {
    await page.goto('/watch')
    await expect(page.locator('.pass')).toHaveCount(6)

    const waiting = page.locator('.pass').filter({ has: page.locator('.pill', { hasText: 'Wait' }) }).first()

    test.skip((await waiting.count()) === 0, 'no route is in the wait state in this sandbox today')

    const code = `${await waiting.locator('.end__code').first().textContent()}-${await waiting
        .locator('.end--to .end__code')
        .textContent()}`

    await waiting.getByRole('link', { name: `Open ${code}` }).click()

    await expect(page.locator('.detail__code')).toHaveText(code.replace('-', ' → '))
    await expect(page.locator('.callout')).toHaveClass(/callout--warn/)

    const booking = page.getByRole('link', { name: /see this fare on aviasales/i })

    await expect(booking).toHaveClass(/booking__cta--secondary/)
    // Still the same target, the same size and the same one tap.
    await expect(booking).toHaveAttribute('target', '_blank')

    /*
     * AND THE WHOLE PAIR GOES QUIET, which is what "secondary" has to mean now
     * that there are two controls on the line. Leaving the accent on one of them
     * would keep the page arguing with itself — "wait", under a glowing button —
     * just more quietly. So: no fill on either, and the accent is reserved for
     * the case where the app is actually saying yes.
     */
    const fills = await page.evaluate(() =>
        [...document.querySelectorAll('.booking__link')].map(
            (link) => getComputedStyle(link).backgroundColor,
        ),
    )

    expect(fills).toHaveLength(2)
    expect(new Set(fills).size, 'one of the two hand-offs is still filled under a warning').toBe(1)

    await shot(page, 'route-detail-wait')
})

test('Back returns to the globe', async ({ page }) => {
    await page.goto('/')
    await waitForGlobe(page)
    await page.locator('.spotlight').click()
    await expect(page.locator('.detail__code')).toBeVisible()

    await page.getByRole('button', { name: 'Back' }).click()

    await expect(page).toHaveURL(/\/$/)
    await expect(page.locator('.home__greeting')).toBeVisible()
})

/*
 * ============================================================================
 * A ROUTE ORBIT HAS NEVER PRICED
 * ============================================================================
 * This screen can be opened for a pair with no route row at all — the watch
 * form's "Look up" navigates straight here — so it owns the fetch, and the two
 * or three seconds that fetch takes are a state a person actually sits in
 * front of. What is photographed here is that state; what is asserted is that
 * it ends.
 *
 * EIN-VIE is not one of the six seeded routes, so this is the real path: a 404
 * from the read, a lookup that creates the route and prices it, and a screen
 * that fills in.
 */
test('says what it is doing while it prices a route for the first time', async ({ page, browserConsole }) => {
    // The read that says "never priced" — see the note in watchlist.spec.js.
    browserConsole.allow(/Failed to load resource.*404/)

    /*
     * HELD OPEN ON PURPOSE. The sandbox runs the FAKE fare provider
     * (scripts/e2e.sh), which answers instantly and in-process — so the state
     * somebody on a metered API waits two or three seconds in would flash past
     * in a frame and could neither be asserted nor photographed. Delaying the
     * lookup is the only thing this route handler does; the request itself goes
     * through untouched.
     */
    await page.route('**/api/routes/lookup', async (route) => {
        await new Promise((resolve) => setTimeout(resolve, 1500))
        await route.continue()
    })

    await page.goto('/route/EIN-VIE')

    // A sentence, not a skeleton: a skeleton says "this is arriving", and what
    // is happening is a fare provider being asked about six months of
    // departures.
    await expect(page.locator('.checking__title')).toHaveText('Checking current fares…')

    await shot(page, 'route-lookup-checking')

    // And it ends, with a price on it.
    await expect(page.locator('.detail__code')).toHaveText('EIN → VIE')
    await expect(page.locator('.price__value')).toHaveText(/^€\d+$/)
    await expect(page.locator('.checking')).toHaveCount(0)
})

/*
 * THE OFFER, IN BOTH THEMES. The strip is the one new control on this screen
 * and it is the only accent-filled button above the fold, so it is exactly the
 * kind of element that reads perfectly in the theme it was built in and
 * disappears into the card in the other one. Both palettes are a full token
 * swap (theme.spec.js), and only a real renderer knows.
 */
test('an unwatched route offers the watch list, in dark and in light', async ({ page }) => {
    await page.goto('/route/EIN-VIE')

    const strip = page.locator('.watch')
    await expect(strip).toContainText('Not on your watch list')
    await expect(page.getByRole('button', { name: 'Watch this route' })).toBeVisible()

    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
    await shot(page, 'route-detail-unwatched-dark')

    // Through the control that owns the theme, rather than by setting the
    // attribute — the point is the palette that a user's own choice produces.
    await page.goto('/alerts')
    await page.getByRole('radiogroup', { name: 'Theme' }).getByRole('radio', { name: 'Light' }).click()
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')

    await page.goto('/route/EIN-VIE')
    await expect(page.getByRole('button', { name: 'Watch this route' })).toBeVisible()

    await shot(page, 'route-detail-unwatched-light')
})

/*
 * AND THE SCREEN A WATCHED ROUTE GETS IS THE SCREEN IT ALWAYS GOT. The strip is
 * for routes nobody is tracking; on one of the six the morning poll already
 * owns, there is nothing to decide and nothing new on the page.
 */
test('a route that is already watched gets no strip and no extra fetch', async ({ page }) => {
    await page.goto('/route/AMS-LIS')

    await expect(page.locator('.detail__code')).toHaveText('AMS → LIS')
    await expect(page.locator('.watch')).toHaveCount(0)
    await expect(page.locator('.checking')).toHaveCount(0)
})

/*
 * A CODE THAT IS NOT A ROUTE AT ALL, which since "look before you watch" takes
 * two requests to establish rather than one: the read says Orbit has no such
 * route, the lookup says it cannot make one either — ZZZ is not an airport it
 * knows and not one of the three it flies from. The screen ends up exactly
 * where it always did, plus the server's sentence about which half is wrong.
 */
test('an unknown route code says so instead of throwing', async ({ page, browserConsole }) => {
    // Both refusals are the answers this test is asking for, and RouteDetail.vue
    // is careful NOT to console.error on either — a miss is not a fault.
    // Chromium still writes the failed requests to the console, so those two
    // lines are waived and nothing else is.
    browserConsole.allow(/Failed to load resource.*(404|422)/)

    // `[A-Z]{3}-[A-Z]{3}` is well-formed, so this reaches the controller. A
    // malformed code would not even be routed (routes/web.php constrains it).
    await page.goto('/route/ZZZ-YYY')

    await expect(page.locator('.empty__title')).toHaveText('No such route')
    await expect(page.locator('.empty__code')).toHaveText('ZZZ-YYY')
    // Which half, in the server's own words (App\Http\Requests\RoutePairRequest).
    await expect(page.locator('.empty__why')).toHaveText(/Orbit does not know that airport yet/)
})
