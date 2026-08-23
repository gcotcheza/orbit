// Home — the globe. The screen that needed this harness: a WebGL earth
// cannot be checked from jsdom (docs/E2E.md "Why this exists").
import { expect, sampleCanvas, shot, tab, test, waitForGlobe } from '../fixtures.js'

// The one spec that opts out of the sandbox clock: fake timers move the tour's
// camera between frames, and this file samples what it drew (docs/E2E.md).
test.use({ sandboxClock: false })

test('the earth draws, and it is not a flat disc of one colour', async ({ page }) => {
    await page.goto('/')

    const canvas = await waitForGlobe(page)

    // The canvas fills the stage the design gives it: full width, 360 tall.
    const box = await canvas.boundingBox()
    expect(box.width).toBeGreaterThan(300)
    expect(box.height).toBeGreaterThan(250)

    const sample = await sampleCanvas(page, canvas)

    // The only assertion that catches a black planet — a broken render
    // measures in single digits, a real one in the thousands (docs/E2E.md).
    expect(sample.distinctColours, 'the globe canvas is one flat fill').toBeGreaterThan(200)
    expect(sample.variedFraction, 'almost the whole canvas is one colour').toBeGreaterThan(0.2)

    await shot(page, 'home-globe-dark')
})

test('the caption and the spotlight card name the route the camera is on', async ({ page }) => {
    await page.goto('/')
    await waitForGlobe(page)

    // "AMS → LIS · Lisbon" — the design's own caption format.
    const caption = page.locator('.stage__caption')
    await expect(caption).toHaveText(/^[A-Z]{3} → [A-Z]{3} · .+/)

    // The chip counting what is in orbit agrees with the rail, which is drawn
    // from the same store (stores/watchlist.js). Six seeded routes.
    await expect(page.locator('.stage__chip')).toContainText('6')
    await expect(page.locator('.rail__chip')).toHaveCount(6)

    // The spotlight card is showing the SAME route the caption is.
    const codes = (await caption.textContent()).match(/([A-Z]{3}) → ([A-Z]{3})/)
    await expect(page.locator('.spotlight__code')).toHaveText(`${codes[1]} → ${codes[2]}`)

    // A fare is not an offer until it has a date on it.
    await expect(page.locator('.spotlight__when')).toHaveText(
        /^(Mon|Tue|Wed|Thu|Fri|Sat|Sun), \w{3} \d{1,2}$/,
    )

    // Read and then compared, not a retrying matcher — the auto-tour moves
    // the selection every eleven seconds and would eventually race it.
    const city = await page.locator('.spotlight__city').textContent()
    const railCity = await page.locator('.rail__chip--active .rail__city').textContent()

    expect(railCity).toBe(city)
})

/**
 * The defect this harness found on its first run, kept as a regression test
 * — the caption was drawn entirely underneath the card (docs/BUSINESS-LOGIC.md §36).
 */
test('the globe caption is drawn where it can be read, not under the card', async ({ page }) => {
    await page.goto('/')
    await waitForGlobe(page)

    const layout = await page.evaluate(() => {
        const caption = document.querySelector('.stage__caption').getBoundingClientRect()
        const card = document.querySelector('.home__spotlight').getBoundingClientRect()
        const top = document.elementFromPoint(caption.x + caption.width / 2, caption.y + caption.height / 2)

        return {
            covering: top === null ? null : top.className.toString(),
            inStage: Boolean(top?.closest('.stage')),
            clearance: Math.round((card.top - caption.bottom) * 10) / 10,
        }
    })

    // Loose on purpose: `.stage__caption` is pointer-events: none, so the hit
    // test finds the globe underneath it, never the caption itself.
    expect(layout.inStage, `something outside the globe covers the caption: "${layout.covering}"`).toBe(true)
    expect(layout.clearance, 'the caption is not clear of the spotlight card').toBeGreaterThan(0)
})

/**
 * A scrim behind the caption, not a halo — legible over a photograph in both
 * themes (docs/BUSINESS-LOGIC.md §36).
 */
test('the globe caption has a backdrop in both themes', async ({ page }) => {
    await page.goto('/')
    await waitForGlobe(page)

    const scrim = page.locator('.stage__caption-text')

    const opacityOf = async () => {
        const colour = await scrim.evaluate((element) => getComputedStyle(element).backgroundColor)
        const alpha = colour.match(/rgba?\([^)]*?(?:,\s*([\d.]+))?\)$/)

        return colour === 'transparent' ? 0 : Number(alpha?.[1] ?? 1)
    }

    expect(await opacityOf(), 'the dark theme caption is drawn straight onto the Earth').toBeGreaterThan(0.5)

    await tab(page, 'Alerts').click()
    await page.getByRole('radiogroup', { name: 'Theme' }).getByRole('radio', { name: 'Light' }).click()
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')

    await tab(page, 'Orbit').click()
    await waitForGlobe(page)

    expect(await opacityOf(), 'the light theme caption is drawn straight onto the Earth').toBeGreaterThan(0.5)

    await shot(page, 'home-globe-light')

    // Back to the suite's default.
    await tab(page, 'Alerts').click()
    await page.getByRole('radiogroup', { name: 'Theme' }).getByRole('radio', { name: 'Dark' }).click()
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
})

/**
 * The rail follows the tour — the selection is not always the user's, and
 * off-screen the rail says nothing about where the camera is.
 */
test('selecting a chip off the end of the rail scrolls it into view', async ({ page }) => {
    await page.goto('/')
    await waitForGlobe(page)

    const chips = page.locator('.rail__chip')
    await expect(chips).toHaveCount(6)

    // `dispatchEvent`, not `click`, which auto-scrolls the target into view
    // and would test Playwright instead. Fourth chip: the last can't be centred.
    const wanted = chips.nth(3)

    const before = await page.evaluate(() => {
        const rail = document.querySelector('.rail__track')

        rail.scrollLeft = 0

        const track = rail.getBoundingClientRect()
        const chip = document.querySelectorAll('.rail__chip')[3].getBoundingClientRect()

        return { offscreen: chip.left > track.right }
    })

    // Asserted, so that a rail which stopped overflowing turns this into a
    // failure rather than into a test of nothing.
    expect(before.offscreen, 'the rail no longer overflows, so this proves nothing').toBe(true)

    await wanted.dispatchEvent('click')

    // Centred, not merely visible, and polled since the scroll is smooth.
    await expect
        .poll(
            async () =>
                page.evaluate(() => {
                    const track = document.querySelector('.rail__track').getBoundingClientRect()
                    const chip = document.querySelector('.rail__chip--active').getBoundingClientRect()

                    const off = Math.abs((chip.left + chip.right) / 2 - (track.left + track.right) / 2)

                    return off < track.width / 5
                }),
            { message: 'the selected chip never came to the middle of the rail' },
        )
        .toBe(true)

    // And the PAGE did not go with it. `block: 'nearest'` is what stops the
    // browser scrolling the 360 px globe off the top to reveal a chip below it.
    expect(await page.evaluate(() => window.scrollY)).toBe(0)

    await shot(page, 'home-rail-scrolled')
})

/**
 * The person icon leads to the person — the link carries `#account`, the tab
 * bar's own entrance does not (docs/BUSINESS-LOGIC.md §10).
 */
test('the profile button lands on the account, not on the top of the alerts screen', async ({ page }) => {
    await page.goto('/')

    await page.getByRole('link', { name: 'Your account and alert settings' }).click()

    await expect(page).toHaveURL(/\/alerts#account$/)

    const account = page.locator('#account')
    const viewport = page.viewportSize()

    // The settings render ABOVE the account card, so the arrival that
    // matters is the one after they are there.
    await expect(page.getByRole('heading', { name: 'Channels' })).toBeVisible()

    // Scrolled AND on screen, in one poll (docs/E2E.md "Adding a spec").
    await expect
        .poll(
            async () => {
                const box = await account.boundingBox()
                const scrolled = await page.evaluate(() => window.scrollY)

                return scrolled > 0 && box.y >= 0 && box.y < viewport.height
            },
            { message: 'the account section never came to rest on screen' },
        )
        .toBe(true)

    await shot(page, 'alerts-from-profile')

    // And the tab bar's own entrance is unchanged: no hash, top of the screen.
    await tab(page, 'Orbit').click()
    await tab(page, 'Alerts').click()

    await expect(page).toHaveURL(/\/alerts$/)
    expect(await page.evaluate(() => window.scrollY)).toBe(0)
})

test('tapping a rail chip flies to that route', async ({ page }) => {
    await page.goto('/')
    await waitForGlobe(page)

    const caption = page.locator('.stage__caption')
    const before = await caption.textContent()

    // Not "the second one" — the auto-tour races this read, and tapping the
    // active chip would assert that nothing changes.
    const inactive = page.locator('.rail__chip:not(.rail__chip--active)').first()
    const wanted = (await inactive.textContent()).match(/([A-Z]{3})→([A-Z]{3})/)

    await inactive.click()

    // The caption follows the selection — the whole choreography in one
    // assertion.
    await expect(caption).toHaveText(new RegExp(`^${wanted[1]} → ${wanted[2]} · `))
    await expect(caption).not.toHaveText(before)

    // And the card underneath came with it.
    await expect(page.locator('.spotlight__code')).toHaveText(`${wanted[1]} → ${wanted[2]}`)

    await shot(page, 'home-globe-after-chip-tap')
})

/**
 * A phone on its side — the stage collapses via a media query rather than
 * losing the card below the fold (docs/BUSINESS-LOGIC.md §36).
 */
test('a phone on its side still shows the card under the globe', async ({ page }) => {
    await page.goto('/')
    await waitForGlobe(page)

    await page.setViewportSize({ width: 844, height: 390 })

    // The renderer follows the box; give the ResizeObserver and the layout a
    // frame to settle before measuring either.
    await expect
        .poll(async () => Math.round((await page.locator('.stage').boundingBox()).height), {
            message: 'the stage never shrank for the landscape viewport',
        })
        .toBeLessThan(360)

    const stage = await page.locator('.stage').boundingBox()
    const card = await page.locator('.home__spotlight').boundingBox()

    // Still a globe, not a letterbox.
    expect(stage.height).toBeGreaterThan(120)

    // And the card's top edge is on screen, which is the whole point.
    expect(card.y).toBeLessThan(390)

    // The canvas came with it rather than keeping its portrait box. Polled, and
    // both boxes re-read together: the renderer follows one frame behind the
    // element, and a full-page shot resizes the viewport under both of them.
    await expect
        .poll(
            async () => {
                const box = await page.locator('.stage').boundingBox()
                const drawn = await page.locator('.stage__globe canvas').boundingBox()

                return Math.round(Math.abs(drawn.height - box.height))
            },
            { message: 'the canvas never followed the stage to its landscape height' },
        )
        .toBeLessThan(2)

    await shot(page, 'home-landscape')

    // Back to the suite's viewport for whatever runs next.
    await page.setViewportSize({ width: 390, height: 844 })
})

test('the globe survives a tab switch and back — the KeepAlive contract', async ({ page }) => {
    await page.goto('/')
    await waitForGlobe(page)

    // Home is <KeepAlive>d so leaving and returning does not rebuild the
    // WebGL context (docs/BUSINESS-LOGIC.md §36).
    await tab(page, 'Calendar').click()
    await expect(page.locator('.calendar__title')).toBeVisible()

    await tab(page, 'Orbit').click()

    const canvas = await waitForGlobe(page)
    const sample = await sampleCanvas(page, canvas)

    expect(sample.distinctColours, 'the globe came back blank after a tab switch').toBeGreaterThan(200)
})
