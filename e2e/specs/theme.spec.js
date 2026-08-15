// =============================================================================
// Theme (design/README.md "Design Tokens")
// =============================================================================
// Dark and light are a full palette swap driven by one `data-theme` attribute
// on <html>, and the only thing that can prove the swap actually reached the
// screen is a computed colour read out of a live document. A store that sets
// the attribute against a stylesheet that no longer keys off it is green in
// vitest and grey in the browser.
//
// This spec is also where both themes of Home get photographed, which is the
// pair a reviewer holds up against design/screenshots/01 and 07.
// =============================================================================
import { expect, shot, tab, test, waitForGlobe } from '../fixtures.js'

// design/README.md's own token values, both themes.
const DARK = { bg: '#0a0f1e', ink: 'rgb(238, 242, 252)' }
const LIGHT = { bg: '#edeefb', ink: 'rgb(13, 6, 48)' }

/**
 * What the page is actually painted with, in two independent readings.
 *
 * `--bg` IS NOT ENOUGH ON ITS OWN. A custom property resolves whether or not
 * anything uses it, so reading it back proves the attribute selector matched
 * and nothing more. `color` on <body> is a real, inherited, painted property
 * that every word on every screen is drawn in — if the palette swap stops
 * reaching the page, that is where it shows.
 *
 * `background-color` on <body> is deliberately NOT one of the two: the light
 * theme's `--bg-grad` ends in a linear-gradient rather than a flat colour
 * (tokens.css), so the shorthand leaves background-color transparent and a
 * naive check would read "no background" as a failure in one theme only.
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

    // --- To light -------------------------------------------------------------
    const themeControl = page.getByRole('radiogroup', { name: 'Theme' })
    await themeControl.getByRole('radio', { name: 'Light' }).click()

    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')
    expect(await paletteOf(page)).toEqual(LIGHT)

    // The phone's browser chrome follows the app rather than staying dark
    // behind a light screen — stores/theme.js reads the value back out of the
    // stylesheet so the two cannot drift.
    await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute('content', '#edeefb')

    await shot(page, 'settings-light')

    // --- It survives a reload -------------------------------------------------
    // localStorage, applied before mount (app.js), so the FIRST frame is
    // already light. A theme applied after mount is a white flash on every cold
    // start, which is the thing that would not show up in any other test.
    await page.reload()
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')
    expect(await paletteOf(page)).toEqual(LIGHT)

    // Put it back for whatever runs next.
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

    /*
     * THE GLOBE HAS TO BE RE-CHECKED, not assumed. Home is <KeepAlive>d, so it
     * comes back from a cache rather than being rebuilt — and the atmosphere
     * colour is one of the things design/README.md says follows the theme, so
     * something in the scene really does have to react to a change that
     * happened while the screen was not mounted.
     */
    await waitForGlobe(page)
    expect(await paletteOf(page)).toEqual(LIGHT)

    await shot(page, 'home-light')

    // Back to dark, so the next spec starts where the app ships.
    await tab(page, 'Alerts').click()
    await page.getByRole('radiogroup', { name: 'Theme' }).getByRole('radio', { name: 'Dark' }).click()
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
})
