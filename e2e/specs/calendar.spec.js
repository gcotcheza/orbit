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
     */
    const month = page.locator('.calendar__subtitle')
    const thisMonth = await month.textContent()
    await page.getByRole('button', { name: /^Go to / }).last().click()
    await expect(month).not.toHaveText(thisMonth)

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
    await expect(subtitle).toHaveText(/Cheapest fare per day · /)

    const prev = page.locator('.month-nav__button').first()
    const next = page.locator('.month-nav__button').last()

    // The past is not offered at all: a fare you can no longer buy is not a deal.
    await expect(prev).toBeDisabled()

    /* The label the screen should be showing `ahead` months from now. */
    const label = (ahead) => {
        const now = new Date()
        const month = new Date(Date.UTC(now.getFullYear(), now.getMonth() + ahead, 1))

        return `${MONTHS[month.getUTCMonth()]} ${month.getUTCFullYear()}`
    }

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
     * THE BOOKING LINK IS AIMED AT THE DAY THAT WAS TAPPED, and is checked by
     * READING ITS HREF RATHER THAN BY FOLLOWING IT. Nothing in this suite may
     * navigate to skyscanner.nl: it is a third party, it is slow, and a run
     * would then fail whenever somebody else's site was having a bad morning.
     * The href is the whole of what this app decided.
     *
     * The failure this catches is a link that opens perfectly and books the
     * wrong date — the route's cheapest day, or the first of the month — which
     * is what "the sheet has a Book button" alone would pass on.
     */
    const chip = await page.locator('.chip--active').textContent()
    const [origin, destination] = chip.trim().split('→')
    const shownDate = await sheet.locator('.sheet__date').textContent()

    const book = sheet.getByRole('link', { name: 'Book this day' })
    const path = `${origin.toLowerCase()}/${destination.toLowerCase()}/${yymmdd(shownDate)}/`

    await expect(book).toHaveAttribute(
        'href',
        `https://www.skyscanner.nl/transport/flights/${path}`,
    )

    // It leaves the app, so: a new tab, no `window.opener` handle back into
    // this one — and NO `noreferrer`, which is what the affiliate attribution
    // rides on (Components/route/BookingCta.vue).
    await expect(book).toHaveAttribute('target', '_blank')
    await expect(book).toHaveAttribute('rel', 'noopener')

    await shot(page, 'calendar-day-sheet')

    // And it closes again on the backdrop — the two actions did not turn the
    // sheet into something that has to be dismissed some other way.
    await page.locator('.backdrop').click()
    await expect(sheet).toHaveCount(0)
})

/**
 * The other half of the sheet: the way out of it that stays in the app. It
 * exists because the sheet used to be a dead end — a date, a price and a
 * verdict, and no way to act on any of them.
 */
test('the day sheet leads to the route it is about', async ({ page }) => {
    await page.goto('/calendar')

    const chip = await page.locator('.chip--active').textContent()
    const code = chip.trim().replace('→', '-')

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
     * THE CHIP IS RE-FOUND BY ITS TEXT AFTER THE TAP, not held as a locator.
     * `.chip:not(.chip--active)` is a live query: the moment the tap lands the
     * chip stops matching it and the OLD active one starts, so re-asserting on
     * the same locator reads the wrong element and reports aria-pressed=false.
     */
    const wanted = await page.locator('.chip:not(.chip--active)').first().textContent()
    await page.locator('.chip', { hasText: wanted }).click()

    await expect(page.getByRole('button', { name: wanted, exact: true })).toHaveAttribute(
        'aria-pressed',
        'true',
    )

    // The fake provider is deterministic PER ROUTE, so two routes cannot
    // produce the same column of prices — if they do, the chip changed the
    // highlight and not the request.
    await expect
        .poll(async () => (await page.locator('.cell--fare .cell__price').allTextContents()).join(','))
        .not.toBe(before.join(','))
})
