// Price calendar — a month grid whose whole meaning is COLOUR (docs/E2E.md
// "Why this exists").
import { expect, fixedNow, shot, test } from '../fixtures.js'
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
 * `September 15, 2026` → `260915` — worked out from the sheet's own heading,
 * not re-derived the way the app does (docs/BUSINESS-LOGIC.md §12).
 */
function yymmdd(shown) {
    const [, month, day, year] = shown.match(/^(\w+) (\d+), (\d{4})$/)

    return `${year.slice(2)}${String(MONTHS.indexOf(month) + 1).padStart(2, '0')}${day.padStart(2, '0')}`
}

/**
 * `September 15, 2026` → `1509`, day before month (docs/BUSINESS-LOGIC.md §12).
 */
function ddmm(shown) {
    const [, month, day] = shown.match(/^(\w+) (\d+), (\d{4})$/)

    return `${day.padStart(2, '0')}${String(MONTHS.indexOf(month) + 1).padStart(2, '0')}`
}

/**
 * The route code out of a chip — read with a regex, not split, since the
 * chip's text also carries the destination city.
 */
async function chipCode(page, selector = '.chip--active') {
    return (await page.locator(selector).first().textContent()).match(/([A-Z]{3})→([A-Z]{3})/)
}

/**
 * The "★ Cheapest this month" figure, or null for a month holding no fares.
 * A still-loading month renders neither, so there is no stale value to read.
 */
async function cheapestOnScreen(page) {
    const banner = page.locator('.banner')

    if ((await banner.count()) === 0) {
        return null
    }

    return Number((await banner.textContent()).match(/€(\d+)/)[1])
}

/**
 * One month across — waits for the grid, not merely the heading, which
 * changes instantly while the fares arrive a request later.
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

    // Next month, not this one — correctly half-empty vs fully inside
    // the window (docs/BUSINESS-LOGIC.md §4).
    const month = page.locator('.calendar__subtitle')
    const prev = page.getByRole('button', { name: /^Go to / }).first()
    const next = page.getByRole('button', { name: /^Go to / }).last()

    // The landing month has to have ARRIVED before the arrows mean anything.
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

    // Two separate failures: the default colour means `cellStyle` never
    // ran; all-the-same non-default colour means the heat scale collapsed.
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
 * The edge of the maintained horizon, walked in a real browser — the arrows
 * offer this month and eleven more, then stop (docs/BUSINESS-LOGIC.md §4).
 */
test('the month arrows walk eleven months forward and stop', async ({ page }) => {
    await page.goto('/calendar')

    const subtitle = page.locator('.calendar__subtitle')

    // Wait for the first grid, not merely the heading — `month` moves to
    // the route's cheapest month once the watchlist lands.
    await expect(page.locator('.cell--fare').first()).toBeVisible()

    const prev = page.locator('.month-nav__button').first()
    const next = page.locator('.month-nav__button').last()

    /* The label the screen should be showing `ahead` months from now. */
    const label = (ahead) => {
        const now = new Date(fixedNow)
        const month = new Date(Date.UTC(now.getFullYear(), now.getMonth() + ahead, 1))

        return `${MONTHS[month.getUTCMonth()]} ${month.getUTCFullYear()}`
    }

    // Back to the near edge before counting forward — the screen opens on
    // the route's cheapest month, not the current one.
    while (await prev.isEnabled()) {
        await step(page, prev)
    }

    // The past is not offered at all: a fare you can no longer buy is not a deal.
    await expect(prev).toBeDisabled()
    await expect(subtitle).toHaveText(`Cheapest fare per day · ${label(0)}`)

    for (let ahead = 1; ahead <= 11; ahead += 1) {
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

/**
 * It opens on the month worth looking at — every month in the window is
 * walked and read, from the screen alone (docs/BUSINESS-LOGIC.md §36).
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

    // The day tapped, not the first of the month — what an off-by-one in
    // the grid's blank-cell padding would produce.
    await expect(sheet.locator('.sheet__date')).toContainText(new RegExp(`\\b${day}\\b`))
    await expect(sheet.locator('.sheet__price')).toHaveText(price)

    // The verdict pill (cheap / pricey / ordinary) comes from the server so the
    // calendar and a future alert cannot disagree about what cheap means.
    await expect(sheet.locator('.pill')).not.toBeEmpty()

    // "just now" because the fake provider stamps the current clock — a
    // render check that `found_at` reaches the pixels (docs/BUSINESS-LOGIC.md §2).
    await expect(sheet.locator('.sheet__seen')).toHaveText('Seen just now')

    // Hand-offs aimed at the tapped day, checked by reading hrefs rather
    // than following them — nothing here may navigate to a third party.
    const [, origin, destination] = await chipCode(page)
    const shownDate = await sheet.locator('.sheet__date').textContent()

    // Aviasales is primary (docs/BUSINESS-LOGIC.md §12); `?marker=` is
    // optional here (no sandbox TRAVELPAYOUTS_MARKER) — see BookingLinkTest.php.
    const book = sheet.getByRole('link', { name: 'See this fare on Aviasales' })

    expect(await book.getAttribute('href')).toMatch(
        new RegExp(
            `^https://www\\.aviasales\\.com/search/${origin}${ddmm(shownDate)}${destination}1(\\?marker=.+)?$`,
        ),
    )

    // The second opinion, on the same day in its own encoding — a button
    // beside the primary, not a line of grey text under it.
    const compare = sheet.getByRole('link', { name: 'Compare on Skyscanner' })

    await expect(compare).toHaveClass(/action/)
    const path = `${origin.toLowerCase()}/${destination.toLowerCase()}/${yymmdd(shownDate)}/`

    await expect(compare).toHaveAttribute('href', `https://www.skyscanner.nl/transport/flights/${path}`)

    // They leave the app: a new tab, no `window.opener` — and NO
    // `noreferrer`, which is what affiliate attribution rides on.
    for (const link of [book, compare]) {
        await expect(link).toHaveAttribute('target', '_blank')
        await expect(link).toHaveAttribute('rel', 'noopener')
    }

    /* One expectation line, and the old "we don't sell tickets" is gone. */
    await expect(sheet.locator('.disclaimer')).toHaveText(
        'Prices come from recent searches — the booking site shows live availability.',
    )

    // The swatch says what it is of — the sheet covers the grid it would
    // otherwise be read against.
    await expect(sheet.locator('.sheet__swatch-label')).toHaveText('Price vs month')

    await shot(page, 'calendar-day-sheet')

    // And it closes again on the backdrop — the actions did not turn the sheet
    // into something that has to be dismissed some other way.
    await page.locator('.backdrop').click()
    await expect(sheet).toHaveCount(0)
})

/**
 * The sheet in the light palette — `--muted` on `--panel` survives one
 * theme and disappears in the other (docs/E2E.md).
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

    // Same `--muted` on `--panel` as the other two quiet lines.
    await expect(sheet.locator('.sheet__swatch-label')).toHaveText('Price vs month')

    await shot(page, 'calendar-day-sheet-light')

    // Put it back for whatever runs next — the suite's default is dark.
    await page.locator('.backdrop').click()
    await page.goto('/alerts')
    await page.getByRole('radiogroup', { name: 'Theme' }).getByRole('radio', { name: 'Dark' }).click()
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
})

/**
 * The other half of the sheet: the way out of it that stays in the app —
 * it used to be a dead end.
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

    // Re-found by its code after the tap, not held as a locator — a live
    // query re-evaluates and would resolve to a different chip (docs/E2E.md).
    const [wanted] = await chipCode(page, '.chip:not(.chip--active)')
    const chip = page.locator('.chip', { hasText: wanted })

    await chip.click()

    await expect(chip).toHaveAttribute('aria-pressed', 'true')

    // Deterministic per route — two routes cannot share a column of prices.
    await expect
        .poll(async () => (await page.locator('.cell--fare .cell__price').allTextContents()).join(','))
        .not.toBe(before.join(','))
})
