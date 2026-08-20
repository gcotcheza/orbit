// Theme (design/README.md "Design Tokens")
//
// Dark/light is a full palette swap via one `data-theme` attribute on
// <html>; only a computed colour proves the swap reached a live document.
// Why: docs/BUSINESS-LOGIC.md §36.
//
// Also where both themes of Home and Search get photographed, against
// design/screenshots/01 and 07.
import { expect, shot, tab, test, waitForGlobe } from '../fixtures.js'

// design/README.md's own token values, both themes.
const DARK = { bg: '#0a0f1e', ink: 'rgb(238, 242, 252)' }
const LIGHT = { bg: '#edeefb', ink: 'rgb(13, 6, 48)' }

/**
 * What the page is actually painted with, in two independent readings.
 *
 * `--bg` alone isn't enough — it resolves whether or not anything uses it;
 * `color` on <body> is a real painted property every word is drawn in.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * `background-color` is deliberately excluded — light theme's `--bg-grad`
 * is a gradient, so the shorthand reads transparent in one theme only.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
async function paletteOf(page) {
    return page.evaluate(() => ({
        bg: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim(),
        ink: getComputedStyle(document.body).color,
    }))
}

test('the alerts screen switches the whole palette, and remembers', async ({ page }) => {
    await page.goto('/alerts')
    await expect(page.locator('.screen__title')).toHaveText('Alerts')

    // Dark is the default, in the stylesheet and in the store.
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
    expect(await paletteOf(page)).toEqual(DARK)

    // ONE OF THE THREE COMMITTED BASELINES. The alerts screen is settings rows
    // and switches: no fares, no canvas, nothing that changes between runs.
    await expect(page).toHaveScreenshot('settings-dark.png', { fullPage: true })
    await shot(page, 'settings-dark')

    const themeControl = page.getByRole('radiogroup', { name: 'Theme' })
    await themeControl.getByRole('radio', { name: 'Light' }).click()

    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')
    expect(await paletteOf(page)).toEqual(LIGHT)

    // The phone's browser chrome follows the app rather than staying dark
    // behind a light screen — stores/theme.js reads it back so they can't drift.
    // Why: docs/BUSINESS-LOGIC.md §36.
    await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute('content', '#edeefb')

    await shot(page, 'settings-light')

    // localStorage, applied before mount (app.js), so the first frame is
    // already light — applied after mount, it'd flash white on every cold start.
    // Why: docs/BUSINESS-LOGIC.md §36.
    await page.reload()
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')
    expect(await paletteOf(page)).toEqual(LIGHT)

    // Put it back for whatever runs next.
    await page.getByRole('radiogroup', { name: 'Theme' }).getByRole('radio', { name: 'Dark' }).click()
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
})

/**
 * The screen the centre tab opens on, in both palettes.
 *
 * Search's two boxes are the most palette-dependent thing in Orbit (card,
 * focus border, distinct suggestion surface, bolded match) — Home has none.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * The five tab labels are asserted here, not on Home — pinned to a test
 * that rasterises a 1.4 MB earth first, so on a loaded box it never ran.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
test('Search, both themes, photographed', async ({ page }) => {
    const labels = page.getByRole('navigation', { name: 'Primary' }).locator('.tab__label')

    // FILLED IN, NOT EMPTY: an empty form photographs as a rectangle; a
    // reviewer needs the panel open over the buttons it pushes down.
    // Why: docs/BUSINESS-LOGIC.md §36.
    const fill = async () => {
        await page.locator('#search-from').fill('BCN')
        await page.locator('#search-to').fill('lisb')
        await expect(page.getByRole('listbox', { name: 'Destination suggestions' })).toBeVisible()
    }

    await page.goto('/search')

    await expect(page.locator('.screen__title')).toHaveText('Search')

    // Every tab item is named, including the centre accent one — a label is
    // a colour decision too; it was the only unlabelled control in the app.
    // Why: docs/BUSINESS-LOGIC.md §36.
    //
    // The middle tab says "Search", not "Rule" — it opened rule creation until
    // 2026-08-16; that moved to the watch screen. Asserted as text, not icon.
    // Why: docs/BUSINESS-LOGIC.md §36.
    await expect(labels).toHaveText(['Orbit', 'Calendar', 'Search', 'Watch', 'Alerts'])

    await fill()
    await shot(page, 'search-dark')

    await tab(page, 'Alerts').click()
    await page.getByRole('radiogroup', { name: 'Theme' }).getByRole('radio', { name: 'Light' }).click()
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')

    await tab(page, 'Search').click()

    expect(await paletteOf(page)).toEqual(LIGHT)
    // The same five names, on the light bar.
    await expect(labels).toHaveText(['Orbit', 'Calendar', 'Search', 'Watch', 'Alerts'])

    await fill()
    await shot(page, 'search-light')

    // Back to dark, so the next spec starts where the app ships.
    await tab(page, 'Alerts').click()
    await page.getByRole('radiogroup', { name: 'Theme' }).getByRole('radio', { name: 'Dark' }).click()
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
})

test('Home, both themes, photographed', async ({ page }) => {
    await page.goto('/')
    await waitForGlobe(page)

    await shot(page, 'home-dark')

    await tab(page, 'Alerts').click()
    await page.getByRole('radiogroup', { name: 'Theme' }).getByRole('radio', { name: 'Light' }).click()
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')

    await tab(page, 'Orbit').click()

    // THE GLOBE HAS TO BE RE-CHECKED, not assumed — Home is <KeepAlive>d, so
    // it's a cache hit, and the atmosphere colour must still react to the theme.
    // Why: docs/BUSINESS-LOGIC.md §36.
    await waitForGlobe(page)
    expect(await paletteOf(page)).toEqual(LIGHT)

    await shot(page, 'home-light')

    // Back to dark, so the next spec starts where the app ships.
    await tab(page, 'Alerts').click()
    await page.getByRole('radiogroup', { name: 'Theme' }).getByRole('radio', { name: 'Dark' }).click()
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
})
