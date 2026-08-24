// The frame's rendering, frozen — what the phone baselines are to the phone, these are to an iPad
// and a desktop window (docs/DESKTOP-LAYOUT-PLAN.md phase 4, docs/E2E.md "The wide baselines").
import { expect, test, waitForGlobe } from '../fixtures.js'
import {
    DETAIL,
    DETAIL_ROUTE,
    THEMES,
    expectPremises,
    makeBaseline,
    remember,
    signedOutBeforeEach,
} from '../baselines.js'

// Through `contextOptions`, as the phone spec does: `reducedMotion` is not a top-level test option
// in Playwright 1.62, and one set there is silently ignored.
test.use({ contextOptions: { reducedMotion: 'reduce', timezoneId: 'Europe/Amsterdam' } })

/** The rows carry a fare and a verdict tone, which the phone has nothing equivalent to. */
const ROWS = ['.route-row__price', '.route-row__dot']

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
const baseline = makeBaseline(VOLATILE, () => `wide-${test.info().project.name}-`)

for (const theme of THEMES) {
    test.describe(theme, () => {
        test.beforeEach(remember(theme))

        test('home', async ({ page }) => {
            await page.goto('/')
            await expect(page.locator('.rail-nav')).toBeVisible()
            await waitForGlobe(page)

            await expectPremises(page)
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

        test.beforeEach(signedOutBeforeEach(theme))

        test('login', async ({ page }) => {
            await page.goto('/login')
            await expect(page.locator('.login__title')).toHaveText('Orbit')
            await expect(page.locator('.rail-nav')).toHaveCount(0)

            await baseline(page, 'login', theme)
        })
    })
}
