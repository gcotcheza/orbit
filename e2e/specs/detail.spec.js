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
    // `/transport/flights/{origin}/{dest}/{yymmdd}/`, lower case, two-digit
    // year — App\Application\Routes\BookingLink. This is the one link in the
    // app that leaves it, so a malformed one is a dead end at the moment
    // somebody has decided to buy.
    const booking = page.getByRole('link', { name: /book on skyscanner/i })
    await expect(booking).toBeVisible()

    const href = await booking.getAttribute('href')
    expect(href).toMatch(
        new RegExp(
            `^https://www\\.skyscanner\\.nl/transport/flights/${origin.toLowerCase()}/${destination.toLowerCase()}/\\d{6}/$`,
        ),
    )

    // It opens away from the app, and safely.
    await expect(booking).toHaveAttribute('target', '_blank')
    await expect(booking).toHaveAttribute('rel', /noopener/)

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
 * button reading "Book on Skyscanner" — the loudest element on the screen,
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

    const booking = page.getByRole('link', { name: /book on skyscanner/i })

    await expect(booking).toHaveClass(/booking__cta--secondary/)
    // Still the same target, the same size and the same one tap.
    await expect(booking).toHaveAttribute('target', '_blank')

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

test('an unknown route code says so instead of throwing', async ({ page, browserConsole }) => {
    // The 404 is the answer this test is asking for, and RouteDetail.vue is
    // careful NOT to console.error on it — a miss is not a fault. Chromium
    // still writes the failed request to the console, so that one line is
    // waived and nothing else is.
    browserConsole.allow(/Failed to load resource.*404/)

    // `[A-Z]{3}-[A-Z]{3}` is well-formed, so this reaches the controller and
    // comes back 404 — the branch RouteDetail.vue draws `notFound` for. A
    // malformed code would not even be routed (routes/web.php constrains it).
    await page.goto('/route/ZZZ-YYY')

    await expect(page.locator('.empty__title')).toHaveText('No such route')
    await expect(page.locator('.empty__code')).toHaveText('ZZZ-YYY')
})
