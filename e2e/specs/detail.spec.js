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

    // --- The deal-score gauge ------------------------------------------------
    // The ring is an SVG circle whose dash offset IS the score. Asserting the
    // number is legible AND that the ring was actually swept catches the case
    // where the arithmetic works and the drawing does not.
    const gauge = page.locator('.gauge__value')
    await expect(gauge).toHaveText(/^\d{1,3}$/)

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

    await shot(page, 'route-detail')
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
