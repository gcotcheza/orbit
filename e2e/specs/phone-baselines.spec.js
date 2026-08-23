// The phone's rendering, frozen — the guard every desktop phase is measured
// against (docs/DESKTOP-LAYOUT-PLAN.md, docs/E2E.md "Baselines vs artifacts").
import { expect, fixedNow, test, waitForGlobe } from '../fixtures.js'
import { BASELINE_STYLE } from '../paths.js'

// Through `contextOptions`: `reducedMotion` is not a top-level test option in
// Playwright 1.62, and one set there is silently ignored.
test.use({ contextOptions: { reducedMotion: 'reduce', timezoneId: 'Europe/Amsterdam' } })

const THEMES = ['dark', 'light']

// A route no earlier spec touches: live-price.spec.js leaves a cached live
// answer on AMS-LIS, which would be in the picture (docs/E2E.md).
const DETAIL_ROUTE = 'AMS-OPO'

// Masked, not dropped: the box is still drawn at its own place and size, so the
// layout stays covered (docs/E2E.md "The phone baselines").
const VOLATILE = {
    home: [
        '.home__live',
        '.spotlight__money',
        '.spark',
        '.pill',
        '.rail__dot',
        '.rail__price',
    ],
    calendar: ['.calendar__subtitle', '.cell--fare', '.cell--empty', '.legend', '.banner'],
    detail: [
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
    ],
    watch: ['.pill', '.stub__price', '.stub__tracking'],
    search: ['.finds__note', '.find__lane', '.find__money', '.find__evidence', '.find__badge', '.find__seen'],
    create: ['.banner__text'],
    alerts: [],
    login: [],
}

async function baseline(page, screen, theme) {
    await expect(page).toHaveScreenshot(`${screen}-${theme}.png`, {
        fullPage: true,
        animations: 'disabled',
        stylePath: BASELINE_STYLE,
        // Zero, meant literally (docs/E2E.md "The phone baselines").
        maxDiffPixels: 0,
        mask: VOLATILE[screen].map((selector) => page.locator(selector)),
    })
}

/** The theme is read out of localStorage before the app mounts (stores/theme.js). */
function remember(theme) {
    return async ({ page }) => {
        await page.addInitScript((value) => window.localStorage.setItem('orbit-theme', value), theme)
    }
}

for (const theme of THEMES) {
    test.describe(theme, () => {
        test.beforeEach(remember(theme))

        test('home', async ({ page }) => {
            await page.goto('/')
            await waitForGlobe(page)

            // The two premises of the whole file: a still globe, and a browser
            // that agrees with the server about what time it is.
            expect(
                await page.evaluate(() => window.matchMedia('(prefers-reduced-motion: reduce)').matches),
                'the browser must be emulating reduced motion',
            ).toBe(true)
            await expect(page.locator('.stage__hint')).toHaveCount(0)
            const skew = Math.abs((await page.evaluate(() => Date.now())) - new Date(fixedNow).getTime())
            expect(skew, 'the browser clock must start at E2E_FIXED_NOW').toBeLessThan(600_000)
            await expect(page.locator('.home__greeting')).toHaveText('Good morning')

            await expect(page.locator('.spotlight')).toBeVisible()
            await expect(page.locator('.rail__chip').first()).toBeVisible()

            await baseline(page, 'home', theme)
        })

        test('calendar', async ({ page }) => {
            await page.goto('/calendar')
            await expect(page.locator('.cell--fare').first()).toBeVisible()
            await expect(page.locator('.legend')).toBeVisible()

            await baseline(page, 'calendar', theme)
        })

        test('route detail', async ({ page }) => {
            await page.goto(`/route/${DETAIL_ROUTE}`)
            await expect(page.locator('.detail__code')).toHaveText(/→/)
            await expect(page.locator('.chart')).toBeVisible()
            await expect(page.locator('.callout')).toBeVisible()

            await baseline(page, 'detail', theme)
        })

        test('watch', async ({ page }) => {
            await page.goto('/watch')
            await expect(page.locator('.pass')).toHaveCount(6)

            await baseline(page, 'watch', theme)
        })

        test('search', async ({ page }) => {
            await page.goto('/search')
            await expect(page.locator('.search__submit')).toBeVisible()
            await expect(page.locator('.finds__list li').first()).toBeVisible()

            await baseline(page, 'search', theme)
        })

        // The discovery strip on its own: a full-page diff says something
        // moved, this one says the strip did.
        test('discover', async ({ page }) => {
            await page.goto('/search')
            const finds = page.locator('.finds')
            await expect(finds.locator('li').first()).toBeVisible()

            await expect(finds).toHaveScreenshot(`discover-${theme}.png`, {
                animations: 'disabled',
                stylePath: BASELINE_STYLE,
                maxDiffPixels: 0,
                mask: VOLATILE.search.map((selector) => page.locator(selector)),
            })
        })

        // The box arrives with a sentence already in it and parses it, so the
        // settled screen is its chips — not the half second before them.
        test('create', async ({ page }) => {
            await page.goto('/create')
            await expect(page.locator('.compose__input')).toBeVisible()
            await expect(page.locator('.chips .chip').first()).toBeVisible()
            await expect(page.locator('.banner--loading')).toHaveCount(0)
            await expect(page.locator('.cta')).toBeEnabled()

            await baseline(page, 'create', theme)
        })

        test('alerts', async ({ page }) => {
            await page.goto('/alerts')
            await expect(page.locator('.screen__title')).toHaveText('Alerts')
            await expect(page.locator('.account__email')).toBeVisible()

            await baseline(page, 'alerts', theme)
        })
    })

    test.describe(`${theme}, signed out`, () => {
        test.use({ storageState: { cookies: [], origins: [] } })

        test.beforeEach(async ({ browserConsole, page }) => {
            // A guest's boot probe is supposed to be a 401 (docs/E2E.md).
            browserConsole.allow(/Failed to load resource.*401/)
            await remember(theme)({ page })
        })

        test('login', async ({ page }) => {
            await page.goto('/login')
            await expect(page.locator('.login__title')).toHaveText('Orbit')

            await baseline(page, 'login', theme)
        })
    })
}
