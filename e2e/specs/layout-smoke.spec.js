// The tablet and desktop projects run this file and nothing else. Phase 1 asks for the frame
// (rail, no bar, no sideways scroll) and the landing page (docs/DESKTOP-LAYOUT-PLAN.md).
import { expect, shot, test, waitForGlobe } from '../fixtures.js'

const TABBED = ['/', '/calendar', '/watch', '/search', '/alerts']

const DESTINATIONS = ['Orbit', 'Calendar', 'Search', 'Watch', 'Alerts']

/** The seeded watchlist, in the seeder's own order (database/seeders/WatchlistSeeder.php). */
const WATCHED = ['AMS-LIS', 'AMS-OPO', 'AMS-NAP', 'EIN-BCN', 'AMS-FAO', 'DUS-AGP']

const pair = (page) => page.locator('.home__panel .detail__code')

test('the icon rail carries the navigation, and the tab bar is gone', async ({ page }) => {
    await page.goto('/')

    await expect(page.locator('.app-shell')).toBeVisible()
    await expect(page.locator('.rail-nav')).toBeVisible()

    // The bar and the rail are the same five destinations; two of them on one screen would be
    // the frame failing to take over.
    await expect(page.locator('.tab-bar')).toHaveCount(0)

    const rail = page.getByRole('navigation', { name: 'Primary' })

    for (const name of DESTINATIONS) {
        await expect(rail.getByRole('link', { name, exact: true })).toBeVisible()
    }
})

test('no tabbed screen scrolls sideways', async ({ page }) => {
    for (const path of TABBED) {
        await page.goto(path)
        await expect(page.locator('.rail-nav')).toBeVisible()

        const width = await page.evaluate(() => ({
            scroll: document.documentElement.scrollWidth,
            inner: window.innerWidth,
        }))

        expect(width.scroll, `${path} is wider than the window`).toBe(width.inner)
    }
})

// 1024px and up: master pane, then the globe and the route detail in the pane beside it.
test.describe('the landing page', () => {
    test.skip(({ viewport }) => viewport.width < 1024, 'the master pane needs 1024px')

    test('lists every watched route, and opens on the first', async ({ page }) => {
        await page.goto('/')

        const rows = page.locator('.route-row')

        await expect(rows).toHaveCount(WATCHED.length)
        expect(await rows.evaluateAll((all) => all.map((row) => row.dataset.code))).toEqual(WATCHED)

        await expect(page.locator('.home__rows-count')).toHaveText('6 watched')
        await expect(rows.first()).toHaveClass(/route-row--active/)
        await expect(pair(page)).toHaveText('AMS → LIS')
    })

    test('swaps the detail for the row that was clicked, and says so in the URL', async ({ page }) => {
        await page.goto('/')
        await expect(pair(page)).toHaveText('AMS → LIS')

        await page.locator('.route-row[data-code="AMS-OPO"]').click()

        await expect(pair(page)).toHaveText('AMS → OPO')
        await expect(page).toHaveURL(/\?route=AMS-OPO$/)
        await expect(page.locator('.route-row[data-code="AMS-OPO"]')).toHaveClass(/route-row--active/)
        await expect(page.locator('.route-row[data-code="AMS-LIS"]')).not.toHaveClass(/route-row--active/)

        // Nothing navigated: the master pane and the globe are still the ones that were there.
        await expect(page.locator('.route-row')).toHaveCount(WATCHED.length)
        await expect(page.locator('.stage__globe canvas')).toBeVisible()
    })

    test('opens on the route a shared link names', async ({ page }) => {
        await page.goto('/?route=AMS-NAP')

        await expect(pair(page)).toHaveText('AMS → NAP')
        await expect(page.locator('.route-row[data-code="AMS-NAP"]')).toHaveClass(/route-row--active/)
    })

    // The whole point of the frame: a bigger screen is a bigger globe, not a 360px box in a
    // 430px column (docs/DESKTOP-LAYOUT-PLAN.md).
    test('gives the globe a share of the pane rather than a phone-sized box', async ({ page }) => {
        await page.goto('/')

        const box = await (await waitForGlobe(page)).boundingBox()

        expect(box.height, 'the globe is a banner here, not a 360px box').toBeGreaterThan(280)
        expect(box.width, 'the globe should have the detail pane to itself').toBeGreaterThan(700)

        await shot(page, 'landing-desktop')
    })
})

// 768-1023px: one pane, with the chip strip standing in for the master list.
test.describe('the collapsed pane', () => {
    test.skip(({ viewport }) => viewport.width >= 1024, 'the single pane is 768-1023px')

    test('stacks the chip strip, the globe and the detail in one pane', async ({ page }) => {
        await page.goto('/')

        await expect(page.locator('.route-row')).toHaveCount(0)
        await expect(page.locator('.rail__chip')).toHaveCount(WATCHED.length)
        await expect(pair(page)).toHaveText('AMS → LIS')

        const box = await (await waitForGlobe(page)).boundingBox()

        expect(box.height, 'the globe should still take a share of the pane').toBeGreaterThan(280)

        await shot(page, 'landing-tablet')
    })

    test('swaps the detail from the chip strip, with the same query', async ({ page }) => {
        await page.goto('/')
        await expect(pair(page)).toHaveText('AMS → LIS')

        await page.locator('.rail__chip[data-code="AMS-FAO"]').click()

        await expect(pair(page)).toHaveText('AMS → FAO')
        await expect(page).toHaveURL(/\?route=AMS-FAO$/)
    })
})
