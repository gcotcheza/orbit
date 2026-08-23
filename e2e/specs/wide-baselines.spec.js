// The frame's rendering, frozen — what the phone baselines are to the phone, these are to an iPad
// and a desktop window (docs/DESKTOP-LAYOUT-PLAN.md phase 4, docs/E2E.md "The wide baselines").
import { expect, fixedNow, test, waitForGlobe } from '../fixtures.js'
import { BASELINE_STYLE } from '../paths.js'

// Through `contextOptions`, as the phone spec does: `reducedMotion` is not a top-level test option
// in Playwright 1.62, and one set there is silently ignored.
test.use({ contextOptions: { reducedMotion: 'reduce', timezoneId: 'Europe/Amsterdam' } })

const THEMES = ['dark', 'light']

/** A route no earlier spec touches — live-price.spec.js leaves a cached answer on AMS-LIS. */
const DETAIL_ROUTE = 'AMS-OPO'

/** The panel's own volatile lines, which the landing pane draws as well as the detail screen. */
const DETAIL = [
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

/** The rows carry a fare and a verdict tone, which the phone has nothing equivalent to. */
const ROWS = ['.route-row__price', '.route-row__dot']

// Masked, not dropped: Playwright paints a flat box at the element's own place and size, so the
// layout is still compared and only the content is not (docs/E2E.md "The phone baselines").
const VOLATILE = {
    home: ['.home__live', '.spotlight__money', '.spark', '.pill', '.rail__dot', '.rail__price', ...ROWS, ...DETAIL],
    calendar: ['.calendar__subtitle', '.cell--fare', '.cell--empty', '.legend', '.banner', ...ROWS],
    detail: DETAIL,
    watch: ['.pill', '.stub__price', '.stub__tracking', ...ROWS],
    search: ['.finds__note', '.find__lane', '.find__money', '.find__evidence', '.find__badge', '.find__seen'],
    create: ['.banner__text'],
    alerts: [],
    login: [],
}

/** 1024px and up is the master-detail frame; 768-1023 is the rail plus one pane. */
const isWide = (viewport) => viewport.width >= 1024

/** The project is in the file name, or the two would write over each other's images. */
async function baseline(page, screen, theme) {
    await expect(page).toHaveScreenshot(`wide-${test.info().project.name}-${screen}-${theme}.png`, {
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
            await expect(page.locator('.rail-nav')).toBeVisible()
            await waitForGlobe(page)

            // The two premises of the whole file: a still globe, and a browser that agrees with
            // the server about what time it is.
            expect(
                await page.evaluate(() => window.matchMedia('(prefers-reduced-motion: reduce)').matches),
                'the browser must be emulating reduced motion',
            ).toBe(true)
            const skew = Math.abs((await page.evaluate(() => Date.now())) - new Date(fixedNow).getTime())
            expect(skew, 'the browser clock must start at E2E_FIXED_NOW').toBeLessThan(600_000)
            await expect(page.locator('.home__greeting')).toHaveText('Good morning')

            await expect(page.locator('.home__panel .detail__code')).toHaveText(/→/)
            await expect(page.locator('.home__panel .callout')).toBeVisible()

            await baseline(page, 'home', theme)
        })

        test('calendar', async ({ page, viewport }) => {
            await page.goto('/calendar')
            await expect(page.locator('.rail-nav')).toBeVisible()
            await expect(page.locator('.cell--fare').first()).toBeVisible()
            await expect(page.locator('.legend')).toBeVisible()

            if (isWide(viewport)) {
                await expect(page.locator('.route-row')).toHaveCount(6)
            }

            await baseline(page, 'calendar', theme)
        })

        // A bare screen keeps the phone column at any width, and that is a promise worth a picture:
        // --shell-max is retargeted on the frame, not on :root (docs/BUSINESS-LOGIC.md §36).
        test('route detail', async ({ page }) => {
            await page.goto(`/route/${DETAIL_ROUTE}`)
            await expect(page.locator('.detail__code')).toHaveText(/→/)
            await expect(page.locator('.chart')).toBeVisible()
            await expect(page.locator('.callout')).toBeVisible()
            await expect(page.locator('.rail-nav')).toHaveCount(0)

            await baseline(page, 'detail', theme)
        })

        test('watch', async ({ page }) => {
            await page.goto('/watch')
            await expect(page.locator('.rail-nav')).toBeVisible()
            await expect(page.locator('.pass')).toHaveCount(6)
            // Nothing seeds a rule, so the section settles on its empty line rather than on a row.
            await expect(page.locator('.rules__empty')).toBeVisible()

            await baseline(page, 'watch', theme)
        })

        test('search', async ({ page }) => {
            await page.goto('/search')
            await expect(page.locator('.rail-nav')).toBeVisible()
            await expect(page.locator('.search__submit')).toBeVisible()
            await expect(page.locator('.finds__list li').first()).toBeVisible()

            await baseline(page, 'search', theme)
        })

        test('create', async ({ page, viewport }) => {
            await page.goto('/create')
            await expect(page.locator('.rail-nav')).toBeVisible()
            await expect(page.locator('.compose__input')).toBeVisible()
            await expect(page.locator('.chips .chip').first()).toBeVisible()
            await expect(page.locator('.banner--loading')).toHaveCount(0)
            await expect(page.locator('.cta')).toBeEnabled()

            // The master pane's rules list is only mounted inside the frame.
            if (isWide(viewport)) {
                await expect(page.locator('.rules__empty')).toBeVisible()
            }

            await baseline(page, 'create', theme)
        })

        test('alerts', async ({ page, viewport }) => {
            await page.goto('/alerts')
            await expect(page.locator('.rail-nav')).toBeVisible()
            await expect(page.locator('.screen__title')).toHaveText('Alerts')
            await expect(page.locator('.account__email')).toBeVisible()

            if (isWide(viewport)) {
                await expect(page.locator('.seclist__item')).toHaveCount(5)
            }

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
            await expect(page.locator('.rail-nav')).toHaveCount(0)

            await baseline(page, 'login', theme)
        })
    })
}
