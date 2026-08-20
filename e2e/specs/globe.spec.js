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

    /*
     * AND THE CARD SAYS WHICH DAY ITS FARE IS FOR. €74 to Lisbon is not an
     * offer until it has a date on it, and this card printed the number alone
     * — the one thing the whole screen exists to hand somebody was the one
     * thing it left out.
     */
    await expect(page.locator('.spotlight__when')).toHaveText(
        /^(Mon|Tue|Wed|Thu|Fri|Sat|Sun), \w{3} \d{1,2}$/,
    )

    /*
     * THE RAIL NAMES PLACES, not only codes. AMS→OPO, AMS→FAO and EIN→LIS are
     * anagrams to anybody who does not already know them, and "fly to a route"
     * is exactly the moment somebody is choosing a PLACE. The active chip's
     * city has to be the city on the card, or the rail is labelling the wrong
     * route.
     *
     * Both are READ AND THEN COMPARED rather than asserted with a retrying
     * matcher: the auto-tour moves the selection every eleven seconds, and a
     * matcher that kept re-reading would eventually be comparing two different
     * routes and calling it a failure.
     */
    const city = await page.locator('.spotlight__city').textContent()
    const railCity = await page.locator('.rail__chip--active .rail__city').textContent()

    expect(railCity).toBe(city)
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

/*
 * ============================================================================
 * AND IT IS DRAWN AGAINST SOMETHING, NOT AGAINST THE ATLANTIC
 * ============================================================================
 * Clearing the card was only half of it. This text sits on a PHOTOGRAPH — the
 * one surface in the app whose colour no token controls — and it was `--muted`
 * with two soft haloes in the page colour, which is the standard answer and
 * which the UX pass judged insufficient: barely legible over the ocean in the
 * dark theme and very nearly invisible in the light one, where the ink is dark,
 * the halo is nearly white, and the sea underneath is neither.
 *
 * WHAT A TEST CAN SAY ABOUT LEGIBILITY. Not much, honestly — whether the
 * contrast is enough is for a person looking at the screenshot. What it CAN say
 * is that the fix is present and reaches the pixels: a scrim behind the words,
 * in both palettes, painted rather than transparent. A halo is a `text-shadow`
 * and would leave this computed value at `rgba(0, 0, 0, 0)`, which is exactly
 * the state being regressed against.
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

/*
 * ============================================================================
 * THE RAIL FOLLOWS THE TOUR
 * ============================================================================
 * The selection is not always the user's: the tour advances it every eleven
 * seconds, in list order, so a six-route watchlist spends most of its time with
 * the accent-filled chip somewhere off the right-hand edge of a horizontal
 * scroller. The UX pass caught it exactly — the card said Naples and the
 * AMS→NAP chip was half over the edge of the screen.
 *
 * It is worse than untidy: the rail is the one control that says WHERE IN THE
 * LIST the camera is, and off screen it says nothing.
 *
 * ONLY A BROWSER KNOWS. `scrollIntoView` is a no-op in jsdom (no layout engine),
 * and "is the chip inside the track's box" is a question about two rectangles
 * that do not exist there.
 */
test('selecting a chip off the end of the rail scrolls it into view', async ({ page }) => {
    await page.goto('/')
    await waitForGlobe(page)

    const chips = page.locator('.rail__chip')
    await expect(chips).toHaveCount(6)

    /*
     * `dispatchEvent` AND NOT `click`, WHICH WOULD TEST PLAYWRIGHT. An ordinary
     * click scrolls the target into view first — that is Playwright being a
     * careful user — and it would then be Playwright's scroll being measured
     * below, on a build with no fix in it at all. Dispatching the event runs no
     * actionability checks and moves nothing.
     *
     * The FOURTH chip rather than the last, because the last cannot be centred:
     * it is at the end of the scroll range, so a browser that merely nudged it
     * inside the edge would look identical to one that centred it.
     */
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

    /*
     * CENTRED, not merely visible, and polled because the scroll is smooth and
     * arrives over a few hundred milliseconds. A tolerance of a fifth of the
     * rail's width is far tighter than "nudged inside the right-hand edge" and
     * far looser than sub-pixel scroll arithmetic.
     */
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

/*
 * ============================================================================
 * THE PERSON ICON LEADS TO THE PERSON
 * ============================================================================
 * The round button in the header is a drawing of a HUMAN BEING, and it dropped
 * people at the top of a screen headed "Alerts" — channels, sensitivity, quiet
 * hours — with the account card fifth down and off the fold on a phone. The UX
 * pass tapped it looking for itself and found somebody else's settings.
 *
 * THE SCREEN KEEPS ITS NAME. "Alerts" is the design's own heading
 * (design/screenshots/06-settings.png) and the tab bar's own label, and
 * retitling it would make the tab disagree with the screen it opens for the sake
 * of one entrance. What changed is where THIS entrance lands: the link carries
 * `#account`, and the screen goes there once its settings have arrived. The tab
 * bar's Alerts item carries no hash and still lands at the top, which is right —
 * two entrances that mean different things.
 *
 * THE WAIT IS THE SUBTLE PART AND IS WHY THIS IS A BROWSER TEST. The account
 * card renders before the settings do (it needs none of them), so scrolling to
 * it on arrival and then letting four cards appear ABOVE it lands the reader in
 * the middle of the quiet hours. Only a real layout can tell the two apart.
 */
test('the profile button lands on the account, not on the top of the alerts screen', async ({ page }) => {
    await page.goto('/')

    await page.getByRole('link', { name: 'Your account and alert settings' }).click()

    await expect(page).toHaveURL(/\/alerts#account$/)

    const account = page.locator('#account')
    const viewport = page.viewportSize()

    // The settings render ABOVE the account card, so the arrival that matters is
    // the one after they are there. Why: docs/E2E.md.
    await expect(page.getByRole('heading', { name: 'Channels' })).toBeVisible()

    /*
     * Scrolled AND on screen, in one poll: `scrollY > 0` says everything above
     * it really is above it rather than the account happening to fit on an
     * unscrolled page, and `>= 0` says it was not scrolled off the top.
     */
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

/*
 * ============================================================================
 * A PHONE ON ITS SIDE
 * ============================================================================
 * The installed app is locked to portrait (config/orbit.php's manifest), so this
 * is only ever a browser tab — and a browser tab is how somebody who has not
 * installed it yet looks at Orbit. In landscape, a 360 px globe plus the header
 * left nothing of a 390 px viewport for the spotlight card: the screen the globe
 * exists to introduce was entirely below the fold, and the app looked like a
 * planet and a tab bar.
 *
 * The mitigation is a media query in GlobeStage.vue and nothing else — the stage
 * is the one element here that can give up height without losing information,
 * and globeScene.js already resizes the renderer from that element's own box
 * through a ResizeObserver.
 *
 * WHAT IS ASSERTED IS THE FOLD, not the pixel height: the card has to be on
 * screen, and the globe has to still be a globe rather than a sliver.
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

    await shot(page, 'home-landscape')

    // The canvas came with it rather than keeping its portrait box.
    const canvas = await page.locator('.stage__globe canvas').boundingBox()
    expect(Math.abs(canvas.height - stage.height)).toBeLessThan(2)

    // Back to the suite's viewport for whatever runs next.
    await page.setViewportSize({ width: 390, height: 844 })
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
