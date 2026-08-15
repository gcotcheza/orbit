// =============================================================================
// Price calendar (design/README.md §3)
// =============================================================================
// A month grid whose whole meaning is COLOUR: every cell's background is a
// position on a green→red heat scale interpolated across the month's own
// min/max. A bug that leaves every cell the same shade is a screen that renders
// perfectly, has the right numbers on it, and answers the question the screen
// exists to ask ("when is it cheap?") with nothing at all.
//
// Reading the computed backgrounds back out of the browser is the only place
// that check can be made. `resources/js/Components/calendar/heat.js` has vitest
// tests for the interpolation itself; this is about whether the result reaches
// the pixels.
// =============================================================================
import { expect, shot, test } from '../fixtures.js'

const MONTHS = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
]

/**
 * `September 15, 2026` → `260915`, the six digits a Skyscanner path carries.
 *
 * Read back out of the SHEET'S OWN HEADING and worked out here from scratch,
 * which is the point: the app builds that URL by substituting into a template
 * the server sent, and a check that re-derived it the same way would agree with
 * a bug. This starts from the date a person can read on the screen.
 */
function yymmdd(shown) {
    const [, month, day, year] = shown.match(/^(\w+) (\d+), (\d{4})$/)

    return `${year.slice(2)}${String(MONTHS.indexOf(month) + 1).padStart(2, '0')}${day.padStart(2, '0')}`
}

/**
 * `September 15, 2026` → `1509`, the four digits an Aviasales params string
 * carries — DAY BEFORE MONTH, two digits each.
 *
 * Worked out here from the sheet's own heading for the same reason `yymmdd` is:
 * a check that built the string the way the app does would agree with a bug.
 * The order is the thing being checked — `1509` and `0915` are both plausible
 * and only one of them searches September.
 */
function ddmm(shown) {
    const [, month, day] = shown.match(/^(\w+) (\d+), (\d{4})$/)

    return `${day.padStart(2, '0')}${String(MONTHS.indexOf(month) + 1).padStart(2, '0')}`
}

/**
 * The route code out of a chip — `['AMS→LIS', 'AMS', 'LIS']`.
 *
 * READ WITH A REGEX RATHER THAN BY SPLITTING THE CHIP'S TEXT, because a chip is
 * no longer only a code: it carries the destination CITY under it, which is the
 * whole point of that change — six chips reading AMS→OPO, AMS→FAO, EIN→LIS are
 * six anagrams to anybody who does not already know them. `textContent` returns
 * the code and the city run together, so the code is matched out of it.
 */
async function chipCode(page, selector = '.chip--active') {
    return (await page.locator(selector).first().textContent()).match(/([A-Z]{3})→([A-Z]{3})/)
}

/**
 * The "★ Cheapest this month" figure, or null for a month holding no fares.
 *
 * A month that is still loading renders neither — the grid, the legend and the
 * banner are all inside the same `v-else-if="payload"` — so there is no stale
 * value to read here, only no value.
 */
async function cheapestOnScreen(page) {
    const banner = page.locator('.banner')

    if ((await banner.count()) === 0) {
        return null
    }

    return Number((await banner.textContent()).match(/€(\d+)/)[1])
}

/**
 * One month across, and WAIT FOR THE GRID RATHER THAN FOR THE HEADING.
 *
 * The subtitle changes the instant the arrow is tapped; the fares arrive a
 * request later. Reading the banner in between would be reading a month that
 * is not on screen yet — so this waits for the response and then for the
 * skeleton the screen shows while it is in flight to be gone, which is the
 * moment the new payload has actually rendered.
 */
async function step(page, arrow) {
    const landed = page.waitForResponse((response) => response.url().includes('/calendar?'))

    await arrow.click()
    await landed
    await expect(page.locator('.skeleton')).toHaveCount(0)
}

test('the month grid is a heat map, not a table of identical squares', async ({ page }) => {
    await page.goto('/calendar')

    await expect(page.locator('.calendar__title')).toHaveText('When is it cheap?')
    await expect(page.locator('.calendar__subtitle')).toHaveText(/Cheapest fare per day · /)

    /*
     * NEXT MONTH, NOT THIS ONE, AND THAT IS ABOUT THE DATA RATHER THAN THE UI.
     *
     * The poll window is 181 days FORWARD (config/orbit.php), so the current
     * month is priced from today onwards and empty behind it — on the 15th it
     * has 17 priced cells and the grid is half grey, correctly. Next month is
     * entirely inside the window whatever day the suite runs on, which is what
     * makes "more than twenty coloured cells" a statement about the heat map
     * rather than about the calendar date.
     *
     * IT WALKS BACK TO THE FIRST MONTH FIRST, and that is new. The screen no
     * longer opens on the current month: it opens on the month the selected
     * route's CHEAPEST departure is in (see the landing test below), which is
     * data-dependent and can be the last month of the window — where "next" is
     * a disabled arrow and this test would have been asserting against a grid
     * that never moved. Walking to the near edge and forward one lands on the
     * same month this test always meant, whatever the fares say.
     */
    const month = page.locator('.calendar__subtitle')
    const prev = page.getByRole('button', { name: /^Go to / }).first()
    const next = page.getByRole('button', { name: /^Go to / }).last()

    // The landing month has to have ARRIVED before the arrows mean anything:
    // the screen starts on the current month and moves to the route's cheapest
    // one when the watchlist lands. See the bounds test below.
    await expect(page.locator('.cell--fare').first()).toBeVisible()

    while (await prev.isEnabled()) {
        await step(page, prev)
    }

    const firstMonth = await month.textContent()
    await step(page, next)
    await expect(month).not.toHaveText(firstMonth)

    const fares = page.locator('.cell--fare')
    await expect(fares.first()).toBeVisible()

    const count = await fares.count()
    expect(count, 'the month has almost no priced days in it').toBeGreaterThan(20)

    /*
     * EVERY PRICED CELL CARRIES ITS OWN COLOUR, AND THEY ARE NOT ALL THE SAME.
     *
     * Two separate failures are being separated here. `background-color` still
     * being the stylesheet's default on a cell means `cellStyle` never ran —
     * the inline style is missing. All of them being the SAME non-default
     * colour means it ran and the scale collapsed, which is what happens when
     * min and max arrive equal, or null, or as strings.
     */
    const backgrounds = await fares.evaluateAll((cells) =>
        cells.map((cell) => getComputedStyle(cell).backgroundColor),
    )

    const uncoloured = backgrounds.filter(
        (colour) => colour === 'rgba(0, 0, 0, 0)' || colour === 'transparent',
    )
    expect(uncoloured, 'some priced cells never got a heat colour').toEqual([])

    expect(
        new Set(backgrounds).size,
        'every priced day is the same colour — the heat scale collapsed',
    ).toBeGreaterThan(4)

    // Each cell says a day and a price, which is the other half of the design.
    await expect(fares.first().locator('.cell__price')).toHaveText(/^€\d+$/)

    // The legend and the "cheapest this month" banner the design puts under it.
    await expect(page.locator('.banner')).toContainText(/Cheapest this month: .+ · €\d+/)

    await shot(page, 'calendar')
})

/**
 * THE EDGE OF THE POLL WINDOW, walked in a real browser.
 *
 * `orbit.poll.window_days` is six months, so the arrows offer this month and
 * six more and then stop. The failure this catches is the pair of numbers
 * drifting apart — a config widened without the screen following it hides half
 * a year of fares behind a disabled arrow, and a screen that walks further than
 * the poller reaches promises months that can never have anything in them.
 *
 * WHAT THE LAST MONTH CONTAINS IS NOT ASSERTED HERE, deliberately. A window
 * that opens on the 1st of a short month closes inside the sixth one, so on a
 * few mornings a year the last grid is legitimately empty — and a suite that
 * only passes on the other 95% of days is worse than no suite. That the empty
 * state renders is pinned deterministically in resources/js/Views/
 * Calendar.test.js, against a stubbed endpoint. What matters HERE is that the
 * screen is showing a calendar rather than an error at the far end.
 */
test('the month arrows walk six months forward and stop', async ({ page }) => {
    await page.goto('/calendar')

    const subtitle = page.locator('.calendar__subtitle')

    /*
     * WAIT FOR THE FIRST GRID, not merely for the heading, and this is the one
     * line the landing change cost this test. The subtitle reads "Cheapest fare
     * per day · August 2026" from the first frame — `month` starts at the
     * current month and is MOVED to the route's cheapest month once the
     * watchlist lands. Walking the arrows before that arrives means walking
     * from a month the screen is about to leave, and every assertion after it
     * is off by however far the landing jumped.
     */
    await expect(page.locator('.cell--fare').first()).toBeVisible()

    const prev = page.locator('.month-nav__button').first()
    const next = page.locator('.month-nav__button').last()

    /* The label the screen should be showing `ahead` months from now. */
    const label = (ahead) => {
        const now = new Date()
        const month = new Date(Date.UTC(now.getFullYear(), now.getMonth() + ahead, 1))

        return `${MONTHS[month.getUTCMonth()]} ${month.getUTCFullYear()}`
    }

    /*
     * BACK TO THE NEAR EDGE BEFORE COUNTING FORWARD, and that is the one thing
     * this test had to learn from the landing change. The screen no longer
     * opens on the current month — it opens on the month the selected route's
     * CHEAPEST departure is in — so "walk six and stop" has to start from the
     * edge rather than from wherever the fares put it.
     *
     * Which is also the clamp, asserted: the landing month is inside
     * FIRST_MONTH..LAST_MONTH, so walking back from it always ARRIVES at this
     * month rather than at some month behind it.
     */
    while (await prev.isEnabled()) {
        await step(page, prev)
    }

    // The past is not offered at all: a fare you can no longer buy is not a deal.
    await expect(prev).toBeDisabled()
    await expect(subtitle).toHaveText(`Cheapest fare per day · ${label(0)}`)

    for (let ahead = 1; ahead <= 6; ahead += 1) {
        await expect(next, `the arrow was already dead at +${ahead - 1}`).toBeEnabled()
        await next.click()
        await expect(subtitle).toHaveText(`Cheapest fare per day · ${label(ahead)}`)
    }

    await expect(next).toBeDisabled()
    await expect(prev).toBeEnabled()

    // A calendar, not a failure. The grid is drawn whether or not this last
    // month has fares in it, and the load-failure copy is nowhere.
    await expect(page.locator('.grid-card')).toBeVisible()
    await expect(page.getByText('Could not load this month')).toHaveCount(0)

    if ((await page.locator('.cell--fare').count()) === 0) {
        await expect(page.locator('.calendar__note--centred')).toHaveText('No fares seen for this month yet.')
    }
})

/*
 * ============================================================================
 * IT OPENS ON THE MONTH WORTH LOOKING AT
 * ============================================================================
 * The screen always opened on the CURRENT month, which is the one month the
 * poll window only half covers — everything before today is gone. "When is it
 * cheap?" was therefore answered with a half-grey grid while the route's
 * actual cheapest day sat two taps away in another month, unmentioned, under a
 * banner that says "cheapest THIS month" and never said which month to be in.
 *
 * THE ASSERTION IS MADE FROM THE SCREEN ALONE. Every month in the window is
 * walked and its banner read, and the landing month has to be one of the ones
 * holding the cheapest fare of the lot. Nothing here re-derives the answer the
 * way the app does — it reads the prices a person can see and checks the app
 * landed on the best of them.
 */
test('the calendar opens on the month the route is cheapest in', async ({ page }) => {
    await page.goto('/calendar')
    await expect(page.locator('.cell--fare').first()).toBeVisible()

    const subtitle = page.locator('.calendar__subtitle')
    const landingMonth = await subtitle.textContent()
    const landingPrice = await cheapestOnScreen(page)

    expect(landingPrice, 'the calendar opened on a month with no fares in it at all').not.toBeNull()

    // The city under the codes — this screen's chips are a filter somebody
    // picks a PLACE with, and they used to be three-letter codes alone.
    await expect(page.locator('.chip--active .chip__city')).not.toBeEmpty()

    await shot(page, 'calendar-landing')

    const prev = page.getByRole('button', { name: /^Go to / }).first()
    const next = page.getByRole('button', { name: /^Go to / }).last()

    // To the near edge of the window, then all the way across it.
    while (await prev.isEnabled()) {
        await step(page, prev)
    }

    const months = []

    for (;;) {
        months.push({ month: await subtitle.textContent(), price: await cheapestOnScreen(page) })

        if (!(await next.isEnabled())) {
            break
        }

        await step(page, next)
    }

    const priced = months.filter((month) => month.price !== null)
    expect(priced.length, 'no month in the window had a fare in it').toBeGreaterThan(1)

    const best = Math.min(...priced.map((month) => month.price))

    expect(landingPrice, 'a cheaper month than the landing one is in the window').toBe(best)
    // Named rather than deduced, so a tie between two months is a pass rather
    // than a coin toss this test calls a failure.
    expect(priced.filter((month) => month.price === best).map((month) => month.month)).toContain(landingMonth)

    // AND THE WHOLE WINDOW IS REACHABLE FROM WHERE IT LANDED: the landing month
    // is inside the bounds the arrows enforce, not past them.
    expect(months.map((month) => month.month)).toContain(landingMonth)
})

test('tapping a day opens the sheet for that day', async ({ page }) => {
    await page.goto('/calendar')

    const cell = page.locator('.cell--fare').first()
    await expect(cell).toBeVisible()

    const day = await cell.locator('.cell__day').textContent()
    const price = await cell.locator('.cell__price').textContent()

    await cell.click()

    const sheet = page.getByRole('dialog')
    await expect(sheet).toBeVisible()

    // The sheet is about the day that was tapped — not about the first of the
    // month, which is what an off-by-one in the grid's blank-cell padding
    // produces and what nothing else would catch.
    await expect(sheet.locator('.sheet__date')).toContainText(new RegExp(`\\b${day}\\b`))
    await expect(sheet.locator('.sheet__price')).toHaveText(price)

    // The verdict pill (cheap / pricey / ordinary) comes from the server so the
    // calendar and a future alert cannot disagree about what cheap means.
    await expect(sheet.locator('.pill')).not.toBeEmpty()

    /*
     * HOW OLD THIS PRICE IS, WHICH IS THE LINE THE SHEET GAINED.
     *
     * Orbit's fares come from a cache of other people's searches, so a number
     * in this sheet can be days old — the app showed €36 for a date whose live
     * cheapest was €56 — and this is the screen with a hand-off button on it.
     *
     * "just now", BECAUSE THE SANDBOX'S FAKE PROVIDER STAMPS THE CURRENT CLOCK.
     * That is a render check rather than an assertion about ageing: what it
     * proves is that `found_at` survives the adapter, the upsert, the resource
     * and the props and reaches the pixels. The arithmetic that turns an
     * instant into "3 hours ago" or "4 days ago" is pinned on paper in
     * resources/js/lib/format.test.js, where the clock can be moved.
     */
    await expect(sheet.locator('.sheet__seen')).toHaveText('Seen just now')

    /*
     * THE HAND-OFFS ARE AIMED AT THE DAY THAT WAS TAPPED, and are checked by
     * READING THEIR HREFS RATHER THAN BY FOLLOWING THEM. Nothing in this suite
     * may navigate to aviasales.com or skyscanner.nl: they are third parties,
     * they are slow, and a run would then fail whenever somebody else's site
     * was having a bad morning. The hrefs are the whole of what this app
     * decided.
     *
     * The failure this catches is a link that opens perfectly and books the
     * wrong date — the route's cheapest day, the first of the month, or (for
     * Aviasales) the day and the month the wrong way round — which is what "the
     * sheet has a Book button" alone would pass on.
     */
    const [, origin, destination] = await chipCode(page)
    const shownDate = await sheet.locator('.sheet__date').textContent()

    /*
     * AVIASALES IS THE PRIMARY, and that is a correctness matter: Orbit prices
     * from Aviasales' cache and used to hand readers to Skyscanner, which had
     * often never had the fare. `?marker=` is optional here because the sandbox
     * has no TRAVELPAYOUTS_MARKER — its presence is asserted on paper in
     * tests/Feature/BookingLinkTest.php.
     */
    const book = sheet.getByRole('link', { name: 'See this fare' })

    expect(await book.getAttribute('href')).toMatch(
        new RegExp(
            `^https://www\\.aviasales\\.com/search/${origin}${ddmm(shownDate)}${destination}1(\\?marker=.+)?$`,
        ),
    )

    // And the quiet second opinion, on the same day in its own encoding.
    const compare = sheet.getByRole('link', { name: 'Compare on Skyscanner' })
    const path = `${origin.toLowerCase()}/${destination.toLowerCase()}/${yymmdd(shownDate)}/`

    await expect(compare).toHaveAttribute('href', `https://www.skyscanner.nl/transport/flights/${path}`)

    // They leave the app, so: a new tab, no `window.opener` handle back into
    // this one — and NO `noreferrer`, which is what the affiliate attribution
    // rides on (Components/route/BookingCta.vue).
    for (const link of [book, compare]) {
        await expect(link).toHaveAttribute('target', '_blank')
        await expect(link).toHaveAttribute('rel', 'noopener')
    }

    /* One expectation line, and the old "we don't sell tickets" is gone. */
    await expect(sheet.locator('.disclaimer')).toHaveText(
        'Prices come from recent searches — the booking site shows live availability.',
    )

    await shot(page, 'calendar-day-sheet')

    // And it closes again on the backdrop — the actions did not turn the sheet
    // into something that has to be dismissed some other way.
    await page.locator('.backdrop').click()
    await expect(sheet).toHaveCount(0)
})

/*
 * ============================================================================
 * THE SHEET IN THE LIGHT PALETTE
 * ============================================================================
 * The sheet gained two quiet elements — the "Seen …" line and the expectation
 * line under the hand-offs — and both are drawn in `--muted` on `--panel`.
 * Muted-on-panel is exactly the pair that survives one theme and disappears in
 * the other: it is the lowest-contrast text in the app by design, and the whole
 * point of these two lines is that somebody about to spend money can read them.
 *
 * A SCREENSHOT AND TWO ASSERTIONS RATHER THAN A BASELINE COMPARISON. What the
 * sheet looks like is for a person to judge (docs/E2E.md says why only three
 * screens are compared automatically); what a test can say is that the lines
 * are present and non-empty in the palette they were not designed in.
 */
test('the day sheet reads in the light theme too', async ({ page }) => {
    await page.goto('/alerts')

    await page.getByRole('radiogroup', { name: 'Theme' }).getByRole('radio', { name: 'Light' }).click()
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')

    await page.goto('/calendar')

    const cell = page.locator('.cell--fare').first()
    await expect(cell).toBeVisible()
    await cell.click()

    const sheet = page.getByRole('dialog')
    await expect(sheet).toBeVisible()

    await expect(sheet.locator('.sheet__seen')).toHaveText('Seen just now')
    await expect(sheet.locator('.disclaimer')).toHaveText(
        'Prices come from recent searches — the booking site shows live availability.',
    )

    await shot(page, 'calendar-day-sheet-light')

    // Put it back for whatever runs next — the suite's default is dark.
    await page.locator('.backdrop').click()
    await page.goto('/alerts')
    await page.getByRole('radiogroup', { name: 'Theme' }).getByRole('radio', { name: 'Dark' }).click()
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
})

/**
 * The other half of the sheet: the way out of it that stays in the app. It
 * exists because the sheet used to be a dead end — a date, a price and a
 * verdict, and no way to act on any of them.
 */
test('the day sheet leads to the route it is about', async ({ page }) => {
    await page.goto('/calendar')

    const [, origin, destination] = await chipCode(page)
    const code = `${origin}-${destination}`

    const cell = page.locator('.cell--fare').first()
    await expect(cell).toBeVisible()
    await cell.click()

    const sheet = page.getByRole('dialog')
    await expect(sheet).toBeVisible()

    await sheet.getByRole('link', { name: 'Route details' }).click()

    // The route the CHIPS were on, not some default — and the detail screen
    // really rendered, rather than the router landing somewhere blank.
    await expect(page).toHaveURL(new RegExp(`/route/${code}$`))
    await expect(page.locator('.detail__code')).toHaveText(code.replace('-', ' → '))

    // Navigating away took the sheet with it; nothing is left over the detail.
    await expect(page.getByRole('dialog')).toHaveCount(0)
})

test('switching route redraws the month', async ({ page }) => {
    await page.goto('/calendar')
    await expect(page.locator('.cell--fare').first()).toBeVisible()

    const chips = page.locator('.chips .chip')
    await expect(chips).toHaveCount(6)

    const before = await page.locator('.cell--fare .cell__price').allTextContents()

    /*
     * THE CHIP IS RE-FOUND BY ITS CODE AFTER THE TAP, not held as a locator.
     * `.chip:not(.chip--active)` is a live query: the moment the tap lands the
     * chip stops matching it and the OLD active one starts, so re-asserting on
     * the same locator reads the wrong element and reports aria-pressed=false.
     *
     * By its CODE and not by its whole text, because the chip now has a city
     * on a second line — `textContent` runs the two together and would match
     * neither the rendered text nor the accessible name.
     */
    const [wanted] = await chipCode(page, '.chip:not(.chip--active)')
    const chip = page.locator('.chip', { hasText: wanted })

    await chip.click()

    await expect(chip).toHaveAttribute('aria-pressed', 'true')

    // The fake provider is deterministic PER ROUTE, so two routes cannot
    // produce the same column of prices — if they do, the chip changed the
    // highlight and not the request.
    await expect
        .poll(async () => (await page.locator('.cell--fare .cell__price').allTextContents()).join(','))
        .not.toBe(before.join(','))
})
