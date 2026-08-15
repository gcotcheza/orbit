// =============================================================================
// Home — the globe (design/README.md §1)
// =============================================================================
// THE SCREEN THAT NEEDED THIS HARNESS. Everything else on it can be checked
// from PHP or from jsdom; a WebGL earth cannot be checked from anywhere but a
// browser with a rasteriser behind it. `resources/js/lib/tour.js` and `geo.js`
// are pure functions with vitest tests precisely so that the arithmetic is
// settled before we get here — what is left, and what is here, is whether the
// thing draws.
// =============================================================================
import { expect, sampleCanvas, shot, tab, test, waitForGlobe } from '../fixtures.js'

test('the earth draws, and it is not a flat disc of one colour', async ({ page }) => {
    await page.goto('/')

    const canvas = await waitForGlobe(page)

    // The canvas fills the stage the design gives it: full width, 360 tall.
    const box = await canvas.boundingBox()
    expect(box.width).toBeGreaterThan(300)
    expect(box.height).toBeGreaterThan(250)

    const sample = await sampleCanvas(page, canvas)

    /*
     * THE ONLY ASSERTION THAT CATCHES A BLACK PLANET.
     *
     * A globe that failed to load its texture, lost its context or was never
     * lit renders as a flat silhouette against the page background — two
     * colours, and one of them covering almost everything. A photographic earth
     * is hundreds of colours with no single one dominant. The thresholds are
     * far apart on purpose: a real render on this box measures in the thousands
     * of colours, a broken one in single digits, and there is nothing in
     * between that a tolerance would have to be tuned for.
     */
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
})

/*
 * ============================================================================
 * THE DEFECT THIS HARNESS FOUND ON ITS FIRST RUN, KEPT AS A REGRESSION TEST
 * ============================================================================
 * WHAT WAS WRONG. design/README.md §1 asks for TWO things that turn out to be
 * incompatible as written: a caption pinned to the bottom of the globe stage
 * ("AMS → LIS · Lisbon"), and a spotlight card that overlaps the stage by
 * −30px. GlobeStage.vue put the caption at `bottom: 6px` of a 360px stage;
 * Home.vue gives `.home__spotlight` that negative margin, an opaque `--card`
 * background and `z-index: 4`. Measured in the browser at a 390px viewport,
 * before the fix:
 *
 *   .stage           y 62.3 … 422.3
 *   .stage__caption  y 398.9 … 416.3
 *   .home__spotlight y 392.3 … 538.6   ← starts 6.6px ABOVE the caption
 *   elementFromPoint(caption centre) → "spotlight rise-in"
 *
 * So the caption was rendered, was in the accessibility tree, had the right
 * text, and was never seen by anybody — every assertion above about it passed.
 *
 * WHY NOTHING ELSE WOULD CATCH IT. jsdom has no layout engine: every box is
 * zero and `elementFromPoint` is meaningless, so a component test can only ever
 * ask whether the text is in the DOM — which it was. This needs a renderer.
 *
 * THE FIX: `--spotlight-overlap` in tokens.css. The card's climb is one number
 * that both components read, and the caption clears it — which is where
 * design/screenshots/01 draws it, below the globe and above the card.
 *
 * WHY THE HIT TEST ASKS FOR "SOMETHING IN THE STAGE" rather than for the
 * caption itself: `.stage__caption` is `pointer-events: none`, so that a drag
 * across it still rotates the globe, and `elementFromPoint` answers with what
 * is hittable — the globe underneath it. What must never come back is the card.
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

    expect(layout.inStage, `something outside the globe covers the caption: "${layout.covering}"`).toBe(true)
    expect(layout.clearance, 'the caption is not clear of the spotlight card').toBeGreaterThan(0)
})

test('tapping a rail chip flies to that route', async ({ page }) => {
    await page.goto('/')
    await waitForGlobe(page)

    const caption = page.locator('.stage__caption')
    const before = await caption.textContent()

    /*
     * PICK A CHIP THAT IS NOT THE ONE ALREADY SELECTED, rather than "the second
     * one". The auto-tour is running while this test reads the screen, so which
     * route is active by the time the tap lands is a race — and a test that
     * taps the active chip is a test asserting that nothing changes.
     */
    const inactive = page.locator('.rail__chip:not(.rail__chip--active)').first()
    const wanted = (await inactive.textContent()).match(/([A-Z]{3})→([A-Z]{3})/)

    await inactive.click()

    // The caption follows the selection. This is the whole choreography in one
    // assertion: the tap sets activeCode, Home hands it to GlobeStage, and the
    // stage replays the camera sequence for the new route.
    await expect(caption).toHaveText(new RegExp(`^${wanted[1]} → ${wanted[2]} · `))
    await expect(caption).not.toHaveText(before)

    // And the card underneath came with it.
    await expect(page.locator('.spotlight__code')).toHaveText(`${wanted[1]} → ${wanted[2]}`)

    await shot(page, 'home-globe-after-chip-tap')
})

test('the globe survives a tab switch and back — the KeepAlive contract', async ({ page }) => {
    await page.goto('/')
    await waitForGlobe(page)

    // Home is <KeepAlive>d (App.vue) precisely so that leaving and returning
    // does not rebuild a WebGL context and refetch 2.5 MB of texture.
    // GlobeStage pauses itself on deactivate; if that pause ever fails to
    // resume, this is where it shows.
    await tab(page, 'Calendar').click()
    await expect(page.locator('.calendar__title')).toBeVisible()

    await tab(page, 'Orbit').click()

    const canvas = await waitForGlobe(page)
    const sample = await sampleCanvas(page, canvas)

    expect(sample.distinctColours, 'the globe came back blank after a tab switch').toBeGreaterThan(200)
})
