// Route detail (design/README.md §2) — reached by tapping the spotlight
// card, not typing the URL, since that hand-off is what's under test.
import { expect, shot, test, waitForGlobe } from '../fixtures.js'

test('the spotlight card opens the route it was showing', async ({ page }) => {
    await page.goto('/')
    await waitForGlobe(page)

    const code = await page.locator('.spotlight__code').textContent()
    const [origin, destination] = code.split('→').map((part) => part.trim())

    await page.locator('.spotlight').click()

    await expect(page).toHaveURL(new RegExp(`/route/${origin}-${destination}$`))
    await expect(page.locator('.detail__code')).toHaveText(`${origin} → ${destination}`)

    // No tab bar on this screen (design/README.md §2, `meta.layout: 'bare'`) —
    // its presence would mean the layout string stopped being read.
    await expect(page.getByRole('navigation', { name: 'Primary' })).toHaveCount(0)
})

test('the price, the gauge, the chart and the booking link are all really there', async ({ page }) => {
    await page.goto('/')
    await waitForGlobe(page)
    await page.locator('.spotlight').click()

    const code = await page.locator('.detail__code').textContent()
    const [origin, destination] = code.split('→').map((part) => part.trim())

    // A euro amount, not the em dash shown for a route with no fare yet —
    // sixty days of seeded history means every route has one.
    await expect(page.locator('.price__value')).toHaveText(/^€\d+$/)
    await expect(page.locator('.price__caption')).not.toBeEmpty()

    // The DEPARTURE date, labelled as one — never confused with the chart's
    // FOUND date below it (one €75 could be Friday or eleven weeks out).
    await expect(page.locator('.price__when')).toHaveText(
        /^Cheapest departure · (Mon|Tue|Wed|Thu|Fri|Sat|Sun), \w{3} \d{1,2}$/,
    )

    // No freshness line is the correct answer here (fares are hours old) —
    // proves the "Seen N days ago" threshold exists, not just its format.
    // Why: docs/BUSINESS-LOGIC.md §36.
    await expect(page.locator('.price__seen')).toHaveCount(0)

    // The ring's dash-offset IS the score — asserting both the number and
    // the sweep catches "arithmetic right, drawing wrong".
    const gauge = page.locator('.gauge__value')
    await expect(gauge).toHaveText(/^\d{1,3}$/)

    // Out of WHAT — "65", "65/100" and "65th percentile" were all readings
    // offered; the scale is now on the label itself.
    await expect(page.locator('.gauge__caption')).toHaveText('Deal score /100')

    const offset = await page.locator('.gauge__ring').getAttribute('stroke-dashoffset')
    expect(Number(offset)).toBeGreaterThanOrEqual(0)
    expect(Number(offset)).toBeLessThan(157)

    // A real `d` attribute on the line path — an empty/degenerate one is
    // what a zero-point chart produces, and renders as nothing with no error.
    const line = page.locator('svg.chart path.chart__line')
    await expect(line).toBeVisible()

    const path = await line.getAttribute('d')
    expect(path).toMatch(/^M[\d.\-\s,]+L/)
    expect(path.match(/L/g).length, 'the price line has almost no points on it').toBeGreaterThan(10)

    // `toHaveCount` + attributes, not `toBeVisible` — a horizontal SVG
    // <line> has a zero-height bbox, so Playwright reports it "hidden" though visible.
    // Why: docs/BUSINESS-LOGIC.md §36.
    const usual = page.locator('svg.chart line.chart__usual')
    await expect(usual).toHaveCount(1)

    const medianY = Number(await usual.getAttribute('y1'))
    expect(medianY).toBeGreaterThan(0)
    expect(medianY).toBeLessThan(140)

    await expect(page.locator('svg.chart circle.chart__dot')).toBeVisible()

    // Two links; Aviasales is the primary CTA (Orbit's fares come from its
    // Travelpayouts cache) — format: `{ORIGIN}{DDMM}{DEST}1` via BookingLink.
    // Why: docs/BUSINESS-LOGIC.md §36.
    const booking = page.getByRole('link', { name: /see this fare on aviasales/i })
    await expect(booking).toBeVisible()

    expect(await booking.getAttribute('href')).toMatch(
        new RegExp(
            `^https://www\\.aviasales\\.com/search/${origin}\\d{4}${destination}1(\\?marker=.+)?$`,
        ),
    )

    // Skyscanner "second opinion" is now a real button (was a sub-44px text
    // link nobody could tell was tappable) — side by side, Aviasales primary.
    // Why: docs/BUSINESS-LOGIC.md §36.
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

    // One disclaimer line, merged from the old separate "we don't sell
    // tickets" text — states where the number came from and who has the live one.
    await expect(page.locator('.booking__disclaimer')).toHaveText(
        'Prices come from recent searches — the booking site shows live availability.',
    )
    await expect(page.getByText("We don't sell tickets")).toHaveCount(0)

    // The booking CTA's variant mirrors the callout's own tone so the two can
    // never disagree ("wait" advice + a glowing primary Book button).
    // Why: docs/BUSINESS-LOGIC.md §36.
    const warned = (await page.locator('.callout').getAttribute('class')).includes('callout--warn')

    await expect(booking).toHaveClass(warned ? /booking__cta--secondary/ : /booking__cta--primary/)

    await shot(page, 'route-detail')
})

/*
 * The app must never contradict itself (a "wait" callout under a glowing
 * primary Book button). Skips rather than naming a route — which one is
 * "waiting" is the fake provider's arithmetic against today's date.
 * Why: docs/BUSINESS-LOGIC.md §36.
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

    // Both hand-offs go unfilled under a "wait" warning — leaving one
    // accented would still be the app arguing with itself, just more quietly.
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
 * A route with no row at all: the watch form's "Look up" navigates here
 * directly, so this screen owns the (real, 2-3s) fetch. EIN-VIE is unseeded,
 * exercising the real path: 404 → lookup creates + prices it → screen fills in.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
test('says what it is doing while it prices a route for the first time', async ({ page, browserConsole }) => {
    // The read that says "never priced" — see the note in watchlist.spec.js.
    browserConsole.allow(/Failed to load resource.*404/)

    // Delay is deliberate: the fake provider answers in-process/instantly,
    // so without it the real-world "checking" state would flash past unseen.
    await page.route('**/api/routes/lookup', async (route) => {
        await new Promise((resolve) => setTimeout(resolve, 1500))
        await route.continue()
    })

    await page.goto('/route/EIN-VIE')

    // A sentence, not a skeleton — a skeleton implies "arriving"; this is a
    // fare provider being asked about six months of departures.
    await expect(page.locator('.checking__title')).toHaveText('Checking current fares…')

    await shot(page, 'route-lookup-checking')

    // And it ends, with a price on it.
    await expect(page.locator('.detail__code')).toHaveText('EIN → VIE')
    await expect(page.locator('.price__value')).toHaveText(/^€\d+$/)
    await expect(page.locator('.checking')).toHaveCount(0)
})

/* Only a real renderer catches this: the watch strip's accent fill is
 * exactly what disappears if a theme token is missed (theme.spec.js). */
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

/* A watched route (one of the six the morning poll owns) gets no strip and
 * no extra fetch — there's nothing to decide, nothing new on the page. */
test('a route that is already watched gets no strip and no extra fetch', async ({ page }) => {
    await page.goto('/route/AMS-LIS')

    await expect(page.locator('.detail__code')).toHaveText('AMS → LIS')
    await expect(page.locator('.watch')).toHaveCount(0)
    await expect(page.locator('.checking')).toHaveCount(0)
})

/* An unrecognised code ("look before you watch") needs two refusals: the
 * read says no such route, the lookup says it can't make one either. */
test('an unknown route code says so instead of throwing', async ({ page, browserConsole }) => {
    // Both 404/422 are the expected answers (RouteDetail.vue never
    // console.errors on a miss) — Chromium still logs the failed requests.
    browserConsole.allow(/Failed to load resource.*(404|422)/)

    // `[A-Z]{3}-[A-Z]{3}` is well-formed, so this reaches the controller. A
    // malformed code would not even be routed (routes/web.php constrains it).
    await page.goto('/route/ZZZ-YYY')

    await expect(page.locator('.empty__title')).toHaveText('No such route')
    await expect(page.locator('.empty__code')).toHaveText('ZZZ-YYY')
    // Which half, in the server's own words (App\Http\Requests\RoutePairRequest).
    await expect(page.locator('.empty__why')).toHaveText(/Orbit does not know that airport yet/)
})
