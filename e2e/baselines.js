// What the two baseline specs share: the themes, the theme seed, the panel's mask list, the
// screenshot call and the premises every picture in both files rests on (docs/E2E.md).
import { expect, fixedNow } from './fixtures.js'
import { BASELINE_STYLE } from './paths.js'

export const THEMES = ['dark', 'light']

/** A route no earlier spec touches — live-price.spec.js leaves a cached answer on AMS-LIS. */
export const DETAIL_ROUTE = 'AMS-OPO'

/** The panel's own volatile lines, which the landing pane draws as well as the detail screen. */
export const DETAIL = [
    '.price__value',
    '.price__live',
    '.price__when',
    '.price__gone',
    '.price__seen',
    '.price__typical',
    '.price__cached',
    '.price__caption',
    '.gauge__dial',
    '.chart',
    '.chart-card__usual',
    '.chart-card__note',
    '.callout__icon',
    '.callout__title',
    '.callout__body',
]

/** The theme is read out of localStorage before the app mounts (stores/theme.js). */
export function remember(theme) {
    return async ({ page }) => {
        await page.addInitScript((value) => window.localStorage.setItem('orbit-theme', value), theme)
    }
}

// Masked, not dropped: Playwright paints a flat box at the element's own place and size, so the
// layout is still compared and only the content is not (docs/E2E.md "The phone baselines").
export function makeBaseline(volatile, prefix = () => '') {
    return async function baseline(page, screen, theme) {
        await expect(page).toHaveScreenshot(`${prefix()}${screen}-${theme}.png`, {
            fullPage: true,
            animations: 'disabled',
            stylePath: BASELINE_STYLE,
            // Zero, meant literally (docs/E2E.md "The phone baselines").
            maxDiffPixels: 0,
            mask: volatile[screen].map((selector) => page.locator(selector)),
        })
    }
}

/** The two premises under every picture: a browser emulating reduced motion, and one that agrees
 *  with the server about what time it is. */
export async function expectPremises(page) {
    expect(
        await page.evaluate(() => window.matchMedia('(prefers-reduced-motion: reduce)').matches),
        'the browser must be emulating reduced motion',
    ).toBe(true)

    const skew = Math.abs((await page.evaluate(() => Date.now())) - new Date(fixedNow).getTime())
    expect(skew, 'the browser clock must start at E2E_FIXED_NOW').toBeLessThan(600_000)
}

/** A signed-out describe seeds the theme too, and a guest's boot probe is a 401 (docs/E2E.md). */
export function signedOutBeforeEach(theme) {
    return async ({ browserConsole, page }) => {
        browserConsole.allow(/Failed to load resource.*401/)
        await remember(theme)({ page })
    }
}
