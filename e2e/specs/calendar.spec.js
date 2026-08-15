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

test('the month grid is a heat map, not a table of identical squares', async ({ page }) => {
    await page.goto('/calendar')

    await expect(page.locator('.calendar__title')).toHaveText('When is it cheap?')
    await expect(page.locator('.calendar__subtitle')).toHaveText(/Cheapest fare per day · /)

    /*
     * NEXT MONTH, NOT THIS ONE, AND THAT IS ABOUT THE DATA RATHER THAN THE UI.
     *
     * The poll window is 90 days FORWARD (config/orbit.php), so the current
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

    await shot(page, 'calendar-day-sheet')

    // And it closes again on the backdrop.
    await page.locator('.backdrop').click()
    await expect(sheet).toHaveCount(0)
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
