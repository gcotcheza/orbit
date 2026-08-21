// The harness: a `test` that fails on a dirty console. Every spec imports
// `test` from here, never from '@playwright/test' (docs/E2E.md "The console guard").
import { expect, test as base } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { screenPath } from './paths.js'

// Read out of .env.e2e rather than written here, so no credential lives in
// this repository (docs/E2E.md "What it does, in order").
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

// `auto: true` — applies to every test whether the spec thinks about it or
// not (docs/E2E.md "The console guard").
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

            // Runs after the test body, so a failed assertion also reports
            // whatever the console had to say about why.
            expect(problems, 'the browser console must be clean').toEqual([])
        },
        { auto: true },
    ],
})

export { expect }

/**
 * A screenshot for a person to look at, not an assertion — `animations:
 * 'disabled'` is the difference between a picture and a libel (docs/E2E.md).
 */
export async function shot(page, name) {
    await page.screenshot({ path: screenPath(name), fullPage: true, animations: 'disabled' })
}

/**
 * Sign in through the form. Only auth.setup.js and login.spec.js call this —
 * everything else inherits the saved session (docs/E2E.md "The specs").
 */
export async function signIn(page, { email = account.email, password = account.password } = {}) {
    await page.goto('/login')
    await page.locator('input[name="email"]').fill(email)
    await page.locator('input[name="password"]').fill(password)
    await page.getByRole('button', { name: /sign in/i }).click()
}

/**
 * What the globe's canvas actually has on it, as numbers. The screenshot is
 * the source, not `canvas.toDataURL()` (docs/E2E.md "Reading a WebGL canvas").
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
            // 5 bits per channel (docs/E2E.md "Reading a WebGL canvas").
            const key =
                ((data[index] >> 3) << 10) | ((data[index + 1] >> 3) << 5) | (data[index + 2] >> 3)

            histogram.set(key, (histogram.get(key) ?? 0) + 1)
        }

        const pixels = data.length / 4
        const commonest = Math.max(...histogram.values())

        return {
            pixels,
            distinctColours: histogram.size,
            // Not the commonest colour — a blank canvas is ~0.
            variedFraction: (pixels - commonest) / pixels,
        }
    }, `data:image/png;base64,${png.toString('base64')}`)
}

/**
 * Wait until the globe's canvas has actually drawn an earth — the element is
 * not the evidence (docs/E2E.md "SwiftShader, and what may therefore be asserted").
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
 * A link in the bottom tab bar, and only there — not
 * `getByRole('link', { name: 'Alerts' })` (docs/E2E.md "Adding a spec").
 */
export function tab(page, name) {
    return page
        .getByRole('navigation', { name: 'Primary' })
        .getByRole('link', { name, exact: true })
}
