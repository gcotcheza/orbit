// =============================================================================
// Theme (design/README.md "Design Tokens")
// =============================================================================
// Dark and light are a full palette swap driven by one `data-theme` attribute
// on <html>, and the only thing that can prove the swap actually reached the
// screen is a computed colour read out of a live document. A store that sets
// the attribute against a stylesheet that no longer keys off it is green in
// vitest and grey in the browser.
//
// This spec is also where both themes of Home and of Search get photographed,
// which is the set a reviewer holds up against design/screenshots/01 and 07.
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

/*
 * =============================================================================
 * THE SCREEN THE CENTRE TAB OPENS ON, in both palettes
 * =============================================================================
 * Search is the app's newest primary screen (2026-08-16) and its two boxes are
 * the most palette-dependent thing in Orbit: a card on a panel, an accent
 * border on a focused field, a suggestion panel that is deliberately a
 * DIFFERENT surface from the card it sits on, and a matched run bolded inside a
 * row of three colours. Every one of those is a token that has to hold in both
 * themes, and none of them appears on Home.
 *
 * THE FIVE TAB LABELS ARE ASSERTED HERE and no longer on Home, which is a fix
 * rather than a move. They are a fact about the bar and have nothing to do with
 * a planet — but they were pinned to the one test that must first rasterise a
 * 1.4 MB earth on a software renderer, so on a loaded box the assertion that
 * the centre tab says "Search" was the assertion that never ran.
 */
test('Search, both themes, photographed', async ({ page }) => {
    const labels = page.getByRole('navigation', { name: 'Primary' }).locator('.tab__label')

    /*
     * FILLED IN, NOT EMPTY. An empty form photographs as a rectangle; what a
     * reviewer needs to see is the panel open on top of the buttons it pushes
     * down, with a match bolded inside a row — which is the layout decision this
     * screen departs from every other flight search on.
     */
    const fill = async () => {
        await page.locator('#search-from').fill('BCN')
        await page.locator('#search-to').fill('lisb')
        await expect(page.getByRole('listbox', { name: 'Destination suggestions' })).toBeVisible()
    }

    await page.goto('/search')

    await expect(page.locator('.screen__title')).toHaveText('Search')

    /*
     * EVERY ITEM IN THE BAR IS NAMED, INCLUDING THE ACCENT ONE IN THE MIDDLE,
     * because a label is a colour decision as much as a copy one. It was the
     * only unlabelled control in the app.
     *
     * THE MIDDLE ONE SAYS "SEARCH" AND USED TO SAY "RULE". The centre button
     * wrote a deal rule until 2026-08-16 and now opens this screen; rule
     * creation kept its own screen and moved its door to the watch screen's
     * rules section. The label is asserted rather than the icon because the icon
     * is a magnifying glass in an accent square and the word is what tells
     * anybody so.
     */
    await expect(labels).toHaveText(['Orbit', 'Calendar', 'Search', 'Watch', 'Alerts'])

    await fill()
    await shot(page, 'search-dark')

    // --- The same screen, the other palette ----------------------------------
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
