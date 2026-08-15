// =============================================================================
// The harness: a `test` that fails on a dirty console, and the helpers
// =============================================================================
// Every spec imports `test` from here rather than from '@playwright/test'.
// =============================================================================
import { expect, test as base } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { screenPath } from './paths.js'

// -----------------------------------------------------------------------------
// The seeded login
// -----------------------------------------------------------------------------
// READ OUT OF .env.e2e RATHER THAN WRITTEN HERE. scripts/e2e.sh generates that
// file with a random password on first run, so there is no credential in this
// repository to leak and no fixed one to be reused by anything else. The file
// is the sandbox's `.env` as well (docker-compose.e2e.yml mounts it), so what
// the tests type and what the seeder created cannot drift.
const ENV_FILE = new URL('../.env.e2e', import.meta.url)

function envValue(name) {
    let contents

    try {
        contents = readFileSync(ENV_FILE, 'utf8')
    } catch {
        throw new Error('.env.e2e is missing — run scripts/e2e.sh, which generates it')
    }

    const line = contents.split('\n').find((row) => row.startsWith(`${name}=`))

    if (line === undefined) {
        throw new Error(`.env.e2e has no ${name} — regenerate it with scripts/e2e.sh --fresh-env`)
    }

    return line.slice(name.length + 1).trim()
}

export const account = {
    email: envValue('SEED_USER_EMAIL'),
    password: envValue('SEED_USER_PASSWORD'),
}

// -----------------------------------------------------------------------------
// The console guard
// -----------------------------------------------------------------------------
// A Vue app fails quietly. A component that throws during render leaves the
// screen it was on and paints nothing new; a store that rejects logs and moves
// on. The screen still has a header, a tab bar and a background, so a
// screenshot looks plausible and an "is the heading visible" assertion passes.
// The uncaught exception in the console is the only thing that says otherwise,
// and it is the reason this fixture is `auto` — it applies to every test in
// every spec whether the spec thinks about it or not.
//
// WHAT IT WATCHES:
//   - `pageerror` — an uncaught exception or an unhandled rejection. NEVER
//     waivable. There is no such thing as an expected uncaught exception.
//   - `console` at error level — both the app's own `console.error(...)` calls
//     (there are seventeen, all on failure paths) and Chromium's own "Failed to
//     load resource" line for a request that came back 4xx/5xx.
//
// The second kind can be legitimate: a guest's boot probe to `/api/me` is
// SUPPOSED to be a 401, and refusing a bad password is supposed to be a 422. A
// test says so explicitly, one regex at a time, next to the assertion that
// makes it true. `allow()` is deliberately per-test and deliberately not
// available for `pageerror`.
export const test = base.extend({
    browserConsole: [
        async ({ page }, use) => {
            const problems = []
            const allowed = []

            page.on('console', (message) => {
                if (message.type() !== 'error') {
                    return
                }

                const text = message.text()

                if (allowed.some((pattern) => pattern.test(text))) {
                    return
                }

                problems.push(`console.error — ${text}`)
            })

            page.on('pageerror', (error) => {
                problems.push(`pageerror — ${error.message}`)
            })

            await use({
                /** Waive one KIND of console error, with a reason in the spec. */
                allow(...patterns) {
                    allowed.push(...patterns)
                },
                /** What has been seen so far — for a spec that wants to report it. */
                seen() {
                    return [...problems]
                },
            })

            // Runs after the test body, so a spec that failed on its own
            // assertion also reports whatever the console had to say about why.
            expect(problems, 'the browser console must be clean').toEqual([])
        },
        { auto: true },
    ],
})

export { expect }

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * A full-page screenshot, kept for a person to look at.
 *
 * NOT AN ASSERTION. These are the run's output — the thing an agent or a
 * reviewer opens to see whether the app looks like design/screenshots/. The
 * screens that are compared automatically use `toHaveScreenshot` instead, and
 * there are only three of them; docs/E2E.md says why.
 *
 * `animations: 'disabled'` IS THE DIFFERENCE BETWEEN A PICTURE AND A LIBEL,
 * and both halves of it were learned the hard way on this branch:
 *
 *   - the price-history chart draws itself on over 1.2s (`chart-draw`, a
 *     stroke-dashoffset from 1 to 0), so a raw screenshot caught the line a
 *     quarter drawn and made the route detail look like a chart that had lost
 *     three quarters of its data;
 *   - the theme swap TRANSITIONS colours over 0.18s, so a raw screenshot taken
 *     straight after the toggle showed the segmented control mid-way between
 *     the dark and light palettes — a grey pill with grey text on it, which
 *     reads exactly like a contrast bug and is not one.
 *
 * Playwright finishes every running animation and transition at its end state
 * before it captures, which is the screen a person is looking at a second
 * later. Both of the above were reported as app bugs before this flag went in.
 */
export async function shot(page, name) {
    await page.screenshot({ path: screenPath(name), fullPage: true, animations: 'disabled' })
}

/**
 * Sign in through the form, as a person would.
 *
 * Only auth.setup.js and login.spec.js call this — everything else inherits the
 * session auth.setup.js saved. See paths.js: `POST /login` is throttled 5/min.
 */
export async function signIn(page, { email = account.email, password = account.password } = {}) {
    await page.goto('/login')
    await page.locator('input[name="email"]').fill(email)
    await page.locator('input[name="password"]').fill(password)
    await page.getByRole('button', { name: /sign in/i }).click()
}

/**
 * What the globe's canvas actually has on it, as numbers.
 *
 * THE SCREENSHOT IS THE SOURCE, NOT `canvas.toDataURL()`, and that is the whole
 * subtlety of testing a WebGL element. three.js creates its context WITHOUT
 * `preserveDrawingBuffer`, so the drawing buffer is thrown away the instant the
 * compositor has read it — `toDataURL()` from a test, which necessarily runs
 * between frames, hands back a fully transparent image of the right size. A
 * test built on it passes on a broken globe and fails on a working one.
 *
 * Compositing the region and reading the PNG back is what a person sees. The
 * decode happens in the page because the browser already has a PNG decoder and
 * the alternative is a dependency.
 */
export async function sampleCanvas(page, canvas) {
    const box = await canvas.boundingBox()
    expect(box, 'the globe canvas must have a box to sample').not.toBeNull()

    const png = await page.screenshot({
        clip: { x: box.x, y: box.y, width: box.width, height: box.height },
    })

    return page.evaluate(async (dataUrl) => {
        const image = new Image()
        image.src = dataUrl
        await image.decode()

        const surface = document.createElement('canvas')
        surface.width = image.width
        surface.height = image.height

        const context = surface.getContext('2d')
        context.drawImage(image, 0, 0)

        const { data } = context.getImageData(0, 0, surface.width, surface.height)
        const histogram = new Map()

        for (let index = 0; index < data.length; index += 4) {
            // 5 bits per channel: enough to tell an ocean from a continent,
            // coarse enough that SwiftShader's dithering is not counted as
            // thousands of distinct colours.
            const key =
                ((data[index] >> 3) << 10) | ((data[index + 1] >> 3) << 5) | (data[index + 2] >> 3)

            histogram.set(key, (histogram.get(key) ?? 0) + 1)
        }

        const pixels = data.length / 4
        const commonest = Math.max(...histogram.values())

        return {
            pixels,
            distinctColours: histogram.size,
            // How much of the region is NOT the single most common colour. A
            // blank canvas — cleared, transparent, or one flat fill — is ~0.
            variedFraction: (pixels - commonest) / pixels,
        }
    }, `data:image/png;base64,${png.toString('base64')}`)
}

/**
 * Wait until the globe's canvas has actually drawn an earth.
 *
 * THE ELEMENT IS NOT THE EVIDENCE. `<canvas>` appears the moment globe.gl is
 * constructed, and it appears identically when the texture 404s, when the
 * context is lost and when the scene is unlit — so `toBeVisible()` on it is an
 * assertion that cannot fail for any reason anybody cares about. Polling what
 * is ON it is the only check that distinguishes those.
 *
 * IT POLLS RATHER THAN WAITING FOR THE TEXTURE RESPONSE, because the texture is
 * 1.4 MB with a week-long `Cache-Control` (docker/web/nginx.conf) — the second
 * screen in a run that visits Home twice never requests it, and a helper built
 * on `waitForResponse` would hang there forever.
 */
export async function waitForGlobe(page) {
    const canvas = page.locator('.stage__globe canvas')
    await expect(canvas).toBeVisible()

    await expect
        .poll(async () => (await sampleCanvas(page, canvas)).variedFraction, {
            message: 'the globe canvas never drew anything but one flat colour',
            timeout: 60_000,
            intervals: [500, 1000, 2000, 2000, 3000],
        })
        .toBeGreaterThan(0.05)

    return canvas
}

/**
 * A link in the bottom tab bar, and only there.
 *
 * NOT `page.getByRole('link', { name: 'Alerts' })`. On Home that also matches
 * the round profile button in the header, whose aria-label is "Alerts and
 * settings" — accessible-name matching is a substring match — and Playwright
 * refuses an ambiguous locator rather than picking one. Scoping to the nav
 * and asking for an exact name says which of the two is meant.
 */
export function tab(page, name) {
    return page
        .getByRole('navigation', { name: 'Primary' })
        .getByRole('link', { name, exact: true })
}
