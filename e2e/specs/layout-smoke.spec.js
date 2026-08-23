// The tablet and desktop projects run this file and nothing else. Phase 0 asks
// only that the phone layout survives a wide window (docs/DESKTOP-LAYOUT-PLAN.md).
import { expect, test } from '../fixtures.js'

const TABBED = ['/', '/calendar', '/watch', '/search', '/alerts']

test('the signed-in shell renders, with the primary navigation on it', async ({ page }) => {
    await page.goto('/')

    await expect(page.locator('.app-shell')).toBeVisible()
    // Phase 1 replaces this bar with the icon rail above 768 px, and replaces
    // this assertion with it.
    await expect(page.getByRole('navigation', { name: 'Primary' })).toBeVisible()
    await expect(page.locator('.spotlight, .home__notice').first()).toBeVisible()
})

test('no tabbed screen scrolls sideways', async ({ page }) => {
    for (const path of TABBED) {
        await page.goto(path)
        await expect(page.getByRole('navigation', { name: 'Primary' })).toBeVisible()

        const width = await page.evaluate(() => ({
            scroll: document.documentElement.scrollWidth,
            inner: window.innerWidth,
        }))

        expect(width.scroll, `${path} is wider than the window`).toBe(width.inner)
    }
})
