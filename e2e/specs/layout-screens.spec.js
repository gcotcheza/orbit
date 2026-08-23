// The tablet and desktop projects' second file: the calendar, the watch list and the two-column
// landing detail inside the frame (docs/DESKTOP-LAYOUT-PLAN.md phase 2).
import { expect, shot, test, waitForGlobe } from '../fixtures.js'

/** The seeded watchlist, in the seeder's own order (database/seeders/WatchlistSeeder.php). */
const WATCHED = ['AMS-LIS', 'AMS-OPO', 'AMS-NAP', 'EIN-BCN', 'AMS-FAO', 'DUS-AGP']

/** Nothing may push the window sideways, whatever a pane has been asked to hold. */
async function expectNoSidewaysScroll(page, where) {
    const width = await page.evaluate(() => ({
        scroll: document.documentElement.scrollWidth,
        inner: window.innerWidth,
    }))

    expect(width.scroll, `${where} is wider than the window`).toBe(width.inner)
}

test.describe('the calendar in the frame', () => {
    test.skip(({ viewport }) => viewport.width < 1024, 'the master pane needs 1024px')

    test('lists the routes beside the month, and keeps every cell square', async ({ page }) => {
        await page.goto('/calendar')
        await expect(page.locator('.cell--fare').first()).toBeVisible()

        const rows = page.locator('.route-row')

        await expect(rows).toHaveCount(WATCHED.length)
        expect(await rows.evaluateAll((all) => all.map((row) => row.dataset.code))).toEqual(WATCHED)
        // The chip strip is what stands in for these rows on a narrower screen.
        await expect(page.locator('.chips')).toHaveCount(0)

        // Five or six week rows, whichever the month has — and every cell square, which is the
        // plan's one hard rule about this screen.
        const weeks = await page
            .locator('.cell')
            .evaluateAll((all) => new Set(all.map((cell) => Math.round(cell.getBoundingClientRect().top))).size)

        expect(weeks, 'the month is drawn as whole weeks').toBeGreaterThanOrEqual(5)
        expect(weeks).toBeLessThanOrEqual(6)

        const cell = await page.locator('.cell--fare').first().boundingBox()

        expect(Math.abs(cell.width - cell.height), 'calendar cells stay square').toBeLessThan(1)

        await expectNoSidewaysScroll(page, '/calendar')
    })

    test('docks the tapped day beside the grid rather than over it', async ({ page }) => {
        await page.goto('/calendar')

        const day = page.locator('.cell--fare').first()
        await expect(day).toBeVisible()

        const number = await day.locator('.cell__day').innerText()
        const price = await day.locator('.cell__price').innerText()

        await day.click()

        const panel = page.locator('.calendar__day .sheet')

        await expect(panel).toBeVisible()
        await expect(panel).toHaveClass(/sheet--docked/)
        await expect(panel.locator('.sheet__price')).toHaveText(price)
        await expect(panel.locator('.sheet__date')).toContainText(` ${number}, `)

        // Not a bottom sheet: nothing is covered, so there is nothing to dismiss.
        await expect(page.locator('.backdrop')).toHaveCount(0)
        await expect(page.locator('.sheet')).toHaveCount(1)
        expect(await panel.getAttribute('role')).toBe('region')

        const grid = await page.locator('.grid-card').boundingBox()
        const box = await panel.boundingBox()

        // 1264px and up. Below it the pane cannot hold a 560px month and a readable panel side by
        // side, and the panel wraps under the grid rather than the cells shrinking (docs/E2E.md).
        expect(box.x, 'the day panel sits to the right of the month').toBeGreaterThan(grid.x + grid.width - 1)

        await expectNoSidewaysScroll(page, '/calendar with a day open')
        await shot(page, 'calendar-desktop')
    })
})

test.describe('the watch list in the frame', () => {
    test.skip(({ viewport }) => viewport.width < 1024, 'the master pane needs 1024px')

    test('leads with one pass, grids the rest and gives the rules a column', async ({ page }) => {
        await page.goto('/watch')
        await expect(page.locator('.pass')).toHaveCount(WATCHED.length)

        await expect(page.locator('.route-row')).toHaveCount(WATCHED.length)
        await expect(page.locator('.route-row').first()).toHaveClass(/route-row--active/)

        const lead = page.locator('.pass.is-selected')

        await expect(lead).toHaveCount(1)
        await expect(lead).toContainText('LIS')

        const others = page.locator('.pass:not(.is-selected)')
        const leadBox = await lead.boundingBox()
        const first = await others.nth(0).boundingBox()
        const second = await others.nth(1).boundingBox()

        expect(Math.abs(first.y - second.y), 'the other passes are two abreast').toBeLessThan(2)
        expect(second.x).toBeGreaterThan(first.x + first.width - 1)
        expect(leadBox.width, 'the chosen pass spans the column').toBeGreaterThan(first.width * 1.8)

        const rules = await page.locator('.rules').boundingBox()

        await expect(page.locator('.rules__title')).toHaveText('Deal rules')
        expect(rules.x, 'the rules are a column beside the passes').toBeGreaterThan(leadBox.x + leadBox.width - 1)
        // The chip that scrolled down to them has nothing left to do.
        await expect(page.getByRole('button', { name: /Go to your \d+ deal rule/ })).toHaveCount(0)

        await expectNoSidewaysScroll(page, '/watch')
        await shot(page, 'watch-desktop')
    })

    test('moves the lead to the row that was clicked', async ({ page }) => {
        await page.goto('/watch')
        await expect(page.locator('.pass.is-selected')).toContainText('LIS')

        await page.locator('.route-row[data-code="AMS-NAP"]').click()

        await expect(page.locator('.pass.is-selected')).toContainText('NAP')
        await expect(page.locator('.route-row[data-code="AMS-NAP"]')).toHaveClass(/route-row--active/)
        await expect(page.locator('.pass.is-selected')).toHaveCount(1)
    })

    // Put back inside the test: every spec shares one database (docs/E2E.md "The specs").
    test('pauses a route from the pane, and dims its row', async ({ page }) => {
        await page.goto('/watch')

        const toggle = page.locator('.pass.is-selected').getByRole('switch')
        const row = page.locator('.route-row[data-code="AMS-LIS"]')

        await expect(toggle).toHaveAttribute('aria-checked', 'true')

        await toggle.click()
        await expect(toggle).toHaveAttribute('aria-checked', 'false')
        await expect(row).toHaveClass(/route-row--paused/)

        await toggle.click()
        await expect(toggle).toHaveAttribute('aria-checked', 'true')
        await expect(row).not.toHaveClass(/route-row--paused/)
    })
})

test.describe('the landing detail', () => {
    test.skip(({ viewport }) => viewport.width < 1024, 'the two-column detail needs 1024px')

    test('puts the chart beside the price, not under it', async ({ page }) => {
        await page.goto('/')

        const panel = page.locator('.home__panel')

        await expect(panel.locator('.detail__code')).toHaveText('AMS → LIS')

        const head = await panel.locator('.detail__head').boundingBox()
        const price = await panel.locator('.price').boundingBox()
        const chart = await panel.locator('.chart-card').boundingBox()
        const booking = await panel.locator('.booking').boundingBox()

        expect(chart.x, 'the chart is the right-hand column').toBeGreaterThan(price.x + price.width - 1)
        expect(booking.x, 'the booking pair is under the chart').toBeGreaterThan(price.x + price.width - 1)
        expect(Math.abs(chart.y - head.y), 'both columns start on the same line').toBeLessThan(4)

        await expectNoSidewaysScroll(page, '/')
    })

    // The whole point of the two columns: the detail is short enough to leave the globe something.
    test('gives the globe every pixel the detail does not need', async ({ page }) => {
        await page.goto('/')

        const canvas = await (await waitForGlobe(page)).boundingBox()

        expect(canvas.height, 'the globe keeps its floor').toBeGreaterThan(280)
        expect(canvas.height, 'and takes more than the floor once the detail is two columns').toBeGreaterThan(300)

        const stage = await page.locator('.home__stage').boundingBox()
        const panel = await page.locator('.home__panel').boundingBox()
        const pane = await page.locator('.home__pane').boundingBox()

        expect(Math.round(stage.height + panel.height)).toBe(Math.round(pane.height))

        await shot(page, 'landing-desktop-columns')
    })
})

// 768-1023px: rail plus one pane, and these two screens keep the phone layout centred in it.
test.describe('the collapsed pane', () => {
    test.skip(({ viewport }) => viewport.width >= 1024, 'the single pane is 768-1023px')

    test('leaves the calendar and the watch list in the phone column', async ({ page }) => {
        for (const path of ['/calendar', '/watch']) {
            await page.goto(path)

            await expect(page.locator('.app-shell__main--column')).toHaveCount(1)
            await expect(page.locator('.route-rows')).toHaveCount(0)

            await expectNoSidewaysScroll(page, path)
        }

        await page.goto('/calendar')
        await expect(page.locator('.chips .chip').first()).toBeVisible()
        await expect(page.locator('.sheet--docked')).toHaveCount(0)
    })
})

/*
 * The short, narrow end of the frame: 1024x600 is the smallest window `lib/layout.js` still calls a
 * desktop, and it is where every "two columns" decision has to degrade rather than break.
 */
test.describe('at the frame\'s own floor', () => {
    test.skip(({ viewport }) => viewport.width < 1024, 'this resizes the desktop project itself')

    test.beforeEach(async ({ page }) => {
        await page.setViewportSize({ width: 1024, height: 600 })
    })

    test('never clips a boarding pass', async ({ page }) => {
        await page.goto('/watch')
        await expect(page.locator('.rail-nav')).toBeVisible()
        await expect(page.locator('.pass')).toHaveCount(WATCHED.length)

        // `.pass` hides its own overflow, so a squeezed card loses its IATA codes silently — the
        // sideways-scroll guard cannot see this one.
        const clipped = await page
            .locator('.pass .end__code, .pass .end__city')
            .evaluateAll((all) =>
                all.filter((one) => one.scrollWidth > one.clientWidth + 1).map((one) => one.textContent.trim()),
            )

        expect(clipped, 'no pass may cut its own codes or cities off').toEqual([])

        // The rules drop under the passes rather than starving them of width.
        const passes = await page.locator('.screen__passes').boundingBox()
        const rules = await page.locator('.rules').boundingBox()

        expect(rules.y, 'the rules wrap below the passes here').toBeGreaterThan(passes.y + passes.height - 1)

        await expectNoSidewaysScroll(page, '/watch at 1024x600')
        await shot(page, 'watch-desktop-short')
    })

    test('scrolls the landing detail under a globe that stays put', async ({ page }) => {
        await page.goto('/')

        const stage = page.locator('.home__stage')
        const panel = page.locator('.home__panel')

        await expect(panel.locator('.detail__code')).toBeVisible()

        const before = await stage.boundingBox()

        expect(before.height, 'the globe is held at its floor, not squeezed past it').toBeGreaterThanOrEqual(280)

        const scrollable = await panel.evaluate((one) => one.scrollHeight - one.clientHeight)

        expect(scrollable, 'the detail is taller than the room left for it here').toBeGreaterThan(0)

        await panel.evaluate((one) => one.scrollTo(0, one.scrollHeight))

        const after = await stage.boundingBox()

        expect(after.y, 'the globe does not scroll away with the detail').toBe(before.y)
        expect(after.height).toBe(before.height)
        // The master pane is still there, which is what a pane-level overflow would have cost.
        await expect(page.locator('.route-row')).toHaveCount(WATCHED.length)

        await expectNoSidewaysScroll(page, '/ at 1024x600')
        await shot(page, 'landing-desktop-short')
    })

    test('wraps the day panel under the month rather than shrinking the cells', async ({ page }) => {
        await page.goto('/calendar')

        const day = page.locator('.cell--fare').first()
        await expect(day).toBeVisible()
        await day.click()

        const grid = await page.locator('.grid-card').boundingBox()
        const panel = await page.locator('.calendar__day .sheet').boundingBox()
        const cell = await day.boundingBox()

        expect(panel.y, 'under the month, not beside it, below 1264px').toBeGreaterThan(grid.y + grid.height - 1)
        expect(Math.abs(cell.width - cell.height), 'and the cells are still square').toBeLessThan(1)
        expect(cell.width, 'at a size the phone would recognise').toBeGreaterThan(48)

        await expectNoSidewaysScroll(page, '/calendar at 1024x600')
        await shot(page, 'calendar-desktop-short')
    })
})
