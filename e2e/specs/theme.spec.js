// Theme: dark/light is a full palette swap via `data-theme` on <html>; only a
// computed colour proves it reached a live document (docs/BUSINESS-LOGIC.md §36).
import { expect, shot, tab, test, waitForGlobe } from '../fixtures.js'

// design/README.md's own token values, both themes.
const DARK = { bg: '#0a0f1e', ink: 'rgb(238, 242, 252)' }
const LIGHT = { bg: '#edeefb', ink: 'rgb(13, 6, 48)' }

/**
 * What the page is actually painted with — `--bg` alone isn't enough
 * (docs/BUSINESS-LOGIC.md §36).
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

    // One of the three committed baselines (docs/E2E.md "Baselines vs artifacts").
    await expect(page).toHaveScreenshot('settings-dark.png', { fullPage: true })
    await shot(page, 'settings-dark')

    const themeControl = page.getByRole('radiogroup', { name: 'Theme' })
    await themeControl.getByRole('radio', { name: 'Light' }).click()

    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')
    expect(await paletteOf(page)).toEqual(LIGHT)

    // The phone's browser chrome follows the app, not staying dark behind
    // a light screen (docs/BUSINESS-LOGIC.md §36).
    await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute('content', '#edeefb')

    await shot(page, 'settings-light')

    // localStorage, applied before mount — after mount would flash white
    // on every cold start.
    await page.reload()
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')
    expect(await paletteOf(page)).toEqual(LIGHT)

    // Put it back for whatever runs next.
    await page.getByRole('radiogroup', { name: 'Theme' }).getByRole('radio', { name: 'Dark' }).click()
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
})

/**
 * The screen the centre tab opens on, in both palettes — the five tab labels
 * are asserted here, not on Home (docs/BUSINESS-LOGIC.md §36).
 */
test('Search, both themes, photographed', async ({ page }) => {
    const labels = page.getByRole('navigation', { name: 'Primary' }).locator('.tab__label')

    // Filled in, not empty — an empty form photographs as a rectangle.
    const fill = async () => {
        await page.locator('#search-from').fill('BCN')
        await page.locator('#search-to').fill('lisb')
        await expect(page.getByRole('listbox', { name: 'Destination suggestions' })).toBeVisible()
    }

    await page.goto('/search')

    await expect(page.locator('.screen__title')).toHaveText('Search')

    // Every tab is named, including the centre accent one — the middle tab
    // says "Search," not "Rule" (docs/BUSINESS-LOGIC.md §36).
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

    // Re-checked, not assumed — Home is <KeepAlive>d, a cache hit, and the
    // atmosphere colour must still react to the theme.
    await waitForGlobe(page)
    expect(await paletteOf(page)).toEqual(LIGHT)

    await shot(page, 'home-light')

    // Back to dark, so the next spec starts where the app ships.
    await tab(page, 'Alerts').click()
    await page.getByRole('radiogroup', { name: 'Theme' }).getByRole('radio', { name: 'Dark' }).click()
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
})
