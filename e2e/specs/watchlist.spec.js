// Watch list — the one screen in Orbit that WRITES. The toggle is checked
// twice: what the row does, and what the server did, by reloading (docs/E2E.md).
import { expect, shot, tab, test, waitForGlobe } from '../fixtures.js'

test.describe.configure({ mode: 'serial' })

/**
 * Put a route on the list, through the screen that does it now — the add
 * form moved to search.spec.js on 2026-08-16.
 */
async function watchRoute(page, origin, destination) {
    await page.goto('/search')

    await page.locator('#search-from').fill(origin)
    await page.locator('#search-to').fill(destination)
    await page.getByRole('button', { name: 'Add to watch' }).click()

    await expect(page.locator('.search__added')).toContainText(`${origin}\u2192${destination}`)

    await page.goto('/watch')
}


test('pausing a route dims the row and survives a reload', async ({ page }) => {
    await page.goto('/watch')

    const rows = page.locator('.pass')
    await expect(rows).toHaveCount(6)

    const row = rows.first()
    const code = `${await row.locator('.end__code').first().textContent()}-${await row
        .locator('.end--to .end__code')
        .textContent()}`

    const toggle = row.getByRole('switch')
    await expect(toggle).toHaveAttribute('aria-checked', 'true')

    await shot(page, 'watchlist')

    await toggle.click()

    await expect(toggle).toHaveAttribute('aria-checked', 'false')
    // `is-paused` is SUPPOSED to dim it — whether the class reaches the
    // pixels is the next test, and the answer today is no.
    await expect(row).toHaveClass(/is-paused/)

    // A second page load, not a re-read of the store — an optimistic update
    // that never reached the API looks identical until refetched.
    await page.reload()

    const reloaded = page.locator('.pass').filter({ hasText: code.split('-')[1] }).first()
    await expect(reloaded.getByRole('switch')).toHaveAttribute('aria-checked', 'false')

    await shot(page, 'watchlist-paused')

    // Put it back — the next spec starts from the seeded state.
    await reloaded.getByRole('switch').click()
    await expect(reloaded.getByRole('switch')).toHaveAttribute('aria-checked', 'true')

    await page.reload()
    await expect(page.locator('.pass').first().getByRole('switch')).toHaveAttribute('aria-checked', 'true')
})

/**
 * And a paused row says the word — dimming alone reads as "loading" at
 * least as readily as "off".
 */
test('a paused row says "Paused" where its tracking note goes', async ({ page }) => {
    await page.goto('/watch')

    const row = page.locator('.pass').first()
    const toggle = row.getByRole('switch')

    await expect(toggle).toHaveAttribute('aria-checked', 'true')
    // An established route draws the barcode there and no prose.
    await expect(row.locator('.stub__tracking')).toHaveCount(0)

    await toggle.click()
    await expect(toggle).toHaveAttribute('aria-checked', 'false')

    await expect(row.locator('.stub__tracking')).toHaveText('Paused')
    await expect(row.locator('.stub__barcode')).toHaveCount(0)

    await shot(page, 'watchlist-paused-row')

    // Back on, for whatever runs next.
    await toggle.click()
    await expect(toggle).toHaveAttribute('aria-checked', 'true')
    await expect(row.locator('.stub__barcode')).toHaveCount(1)
})

/**
 * A defect, written down as a test — `.rise-in`'s `both` fill mode beat
 * `.is-paused`'s opacity in the cascade (docs/E2E.md "test.fail()").
 */
test('a paused row is actually dimmed, and not just given a class', async ({ page }) => {
    await page.goto('/watch')

    const row = page.locator('.pass').first()
    const toggle = row.getByRole('switch')

    await expect(toggle).toHaveAttribute('aria-checked', 'true')
    await toggle.click()
    await expect(row).toHaveClass(/is-paused/)

    // Wait for the entrance to be over — a measurement taken mid-animation
    // would be testing how fast this box is, not the fix.
    await row.evaluate((element) => Promise.all(element.getAnimations().map((animation) => animation.finished)))

    const paused = await row.evaluate((element) => ({
        opacity: Number(getComputedStyle(element).opacity),
        fill: getComputedStyle(element).animationFillMode,
    }))

    // Restore before the assertions, so this test leaves the list the way it
    // found it whichever way they go.
    await toggle.click()
    await expect(toggle).toHaveAttribute('aria-checked', 'true')

    expect(paused.opacity, 'a paused row should be visibly dimmed').toBeLessThan(1)
    expect(paused.fill, 'an entrance animation is filling forwards again — it will win the cascade').not.toBe('both')
})

test('a paused route drops out of the globe tour', async ({ page }) => {
    // The two screens share stores/watchlist.js — before the DRY pass a
    // route paused here stayed in the tour until a reload.
    await page.goto('/watch')
    await expect(page.locator('.pass')).toHaveCount(6)

    await page.locator('.pass').first().getByRole('switch').click()
    await expect(page.locator('.pass').first().getByRole('switch')).toHaveAttribute('aria-checked', 'false')

    const paused = await page.locator('.pass').first().locator('.end--to .end__code').textContent()

    await tab(page, 'Orbit').click()
    await expect(page.locator('.rail__chip')).toHaveCount(5)
    await expect(page.locator('.stage__chip')).toContainText('5')

    // The calendar's chips say so too — that screen keeps paused routes but
    // used to draw them identically to live ones (docs/E2E.md "test.fail()").
    await tab(page, 'Calendar').click()

    const chip = page.locator('.chip', { hasText: paused })
    await expect(chip).toBeVisible()
    await expect(chip).toHaveClass(/chip--paused/)

    expect(
        await chip.evaluate((element) => Number(getComputedStyle(element).opacity)),
        'a paused route is drawn on the calendar exactly like a live one',
    ).toBeLessThan(1)

    // Still selectable, which is the other half of "dimmed, not hidden".
    await chip.click()
    await expect(chip).toHaveAttribute('aria-pressed', 'true')

    // Restore.
    await tab(page, 'Watch').click()
    await page.locator('.pass').first().getByRole('switch').click()
    await expect(page.locator('.pass').first().getByRole('switch')).toHaveAttribute('aria-checked', 'true')
})

// A route arrives and leaves again — the LIST's half of the journey, since
// adding moved to the search tab.
test('a route added elsewhere appears here, and can be removed again', async ({ page }) => {
    await watchRoute(page, 'AMS', 'MAD')

    await expect(page.locator('.pass')).toHaveCount(7)

    const added = page.locator('.pass').filter({ hasText: 'MAD' }).first()
    await expect(added).toBeVisible()

    // Put the watchlist back the way the seeder left it.
    await added.getByRole('button', { name: /stop watching/i }).click()
    await added.getByRole('button', { name: 'Remove' }).click()

    await expect(page.locator('.pass')).toHaveCount(6)

    await page.reload()
    await expect(page.locator('.pass')).toHaveCount(6)
})

/**
 * Tapping a row opens it — and the controls on it still do not
 * (docs/BUSINESS-LOGIC.md §36).
 */
test('tapping a row opens that route, and its controls do not', async ({ page }) => {
    await page.goto('/watch')

    const row = page.locator('.pass').first()
    const code = `${await row.locator('.end__code').first().textContent()}-${await row
        .locator('.end--to .end__code')
        .textContent()}`

    const toggle = row.getByRole('switch')
    await toggle.click()

    await expect(toggle).toHaveAttribute('aria-checked', 'false')
    await expect(page).toHaveURL(/\/watch$/)

    // Back on, and that does not navigate either.
    await toggle.click()
    await expect(toggle).toHaveAttribute('aria-checked', 'true')
    await expect(page).toHaveURL(/\/watch$/)

    await row.getByRole('button', { name: /stop watching/i }).click()

    const keep = row.getByRole('button', { name: 'Keep' })
    await expect(keep).toBeVisible()
    await expect(page).toHaveURL(/\/watch$/)

    await keep.click()
    await expect(page).toHaveURL(/\/watch$/)
    await expect(page.locator('.pass')).toHaveCount(6)

    await row.getByRole('link', { name: `Open ${code}` }).click()

    await expect(page).toHaveURL(new RegExp(`/route/${code}$`))
    await expect(page.locator('.detail__code')).toHaveText(code.replace('-', ' → '))
})

/**
 * Remove says so, and it can be taken back — undo is the ordinary add write,
 * honest only because removing never deletes history (docs/BUSINESS-LOGIC.md §36).
 */
test('a removed route says so and can be put straight back', async ({ page }) => {
    await watchRoute(page, 'AMS', 'MAD')

    await expect(page.locator('.pass')).toHaveCount(7)

    const added = page.locator('.pass').filter({ hasText: 'MAD' }).first()
    await added.getByRole('button', { name: /stop watching/i }).click()
    await added.getByRole('button', { name: 'Remove' }).click()

    await expect(page.locator('.pass')).toHaveCount(6)

    const notice = page.locator('.screen__notice--undo')
    await expect(notice).toContainText('Stopped watching AMS\u2192MAD')

    await shot(page, 'watchlist-removed-with-undo')

    await notice.getByRole('button', { name: 'Undo' }).click()

    await expect(page.locator('.pass')).toHaveCount(7)
    await expect(page.locator('.pass').filter({ hasText: 'MAD' })).toHaveCount(1)
    // The offer is spent: it named one removal and that removal is undone.
    await expect(notice).toHaveCount(0)

    // The write really happened, and not just the optimism.
    await page.reload()
    await expect(page.locator('.pass').filter({ hasText: 'MAD' })).toHaveCount(1)

    const again = page.locator('.pass').filter({ hasText: 'MAD' }).first()
    await again.getByRole('button', { name: /stop watching/i }).click()
    await again.getByRole('button', { name: 'Remove' }).click()

    await expect(page.locator('.pass')).toHaveCount(6)

    // And the offer goes quietly — a notice that stays forever becomes
    // furniture. The generous timeout is for the machine, not the timer.
    await expect(page.locator('.screen__notice--undo')).toHaveCount(0, { timeout: 12_000 })
})

/**
 * The empty home is still this app's screen — reached by pausing rather
 * than removing, the reversible way to empty `activeRoutes`.
 */
test('with nothing to tour, the home still draws the globe and one thing to do', async ({ page }) => {
    await page.goto('/watch')

    // The count is asserted before every sweep — `Locator.all()` does not
    // wait, so a request still in flight would return an empty array.
    const switches = page.locator('.pass').getByRole('switch')

    await expect(switches).toHaveCount(6)

    for (const toggle of await switches.all()) {
        await toggle.click()
        await expect(toggle).toHaveAttribute('aria-checked', 'false')
    }

    await tab(page, 'Orbit').click()

    await expect(page.locator('.home__notice-title')).toHaveText('Nothing orbiting yet')

    // The planet is drawn — empty, with nothing on it. `play()` has no
    // active route, so there is no arc and no camera work.
    await waitForGlobe(page)

    // …and neither overlay is left talking about the nothing: no "0 routes
    // orbiting" chip, no route caption.
    await expect(page.locator('.stage__chip')).toBeHidden()
    await expect(page.locator('.stage__caption')).toBeHidden()

    // A real button, and it goes where it says.
    const cta = page.getByRole('link', { name: 'Add your first route' })
    await expect(cta).toBeVisible()

    await shot(page, 'home-nothing-to-tour')

    await cta.click()
    await expect(page).toHaveURL(/\/watch$/)

    // Every route back on, for whatever runs next.
    await expect(switches).toHaveCount(6)

    for (const toggle of await switches.all()) {
        await toggle.click()
        await expect(toggle).toHaveAttribute('aria-checked', 'true')
    }

    // From the server — a spec that left five of six paused fails the next one.
    await page.reload()
    await expect(switches).toHaveCount(6)

    for (const toggle of await switches.all()) {
        await expect(toggle).toHaveAttribute('aria-checked', 'true')
    }
})

/**
 * The rules are two and a half screens down, and now something says so — the
 * assertion is a scroll position, the whole reason this is a browser test.
 */
test('a count chip finds the rules section that is below the fold', async ({ page }) => {
    // In through the door that replaced the centre tab — "+ New rule" in
    // this section's own header is now the only way to /create.
    await page.goto('/watch')

    const section = page.locator('.rules')
    await expect(section).toBeVisible()
    await expect(section.locator('.rules__empty')).toContainText('plain English')

    await shot(page, 'watchlist-rules-empty')

    await section.getByRole('link', { name: 'New rule' }).click()

    await expect(page).toHaveURL(/\/create$/)
    await page.locator('#rule-text').fill('cheap city break under €90')

    // Wait for the re-read before pressing anything — this is a race, not a
    // nicety: "Create rule" is enabled for the SEEDED sentence first.
    await expect(page.locator('.chips')).toContainText('€90')

    await page.getByRole('button', { name: /create rule/i }).click()
    await expect(page.locator('.screen__title')).toHaveText('Rule created')

    await page.goto('/watch')

    const anchor = page.getByRole('button', { name: /go to your 1 deal rule/i })
    await expect(anchor).toHaveText(/Rules · 1/)

    // Below the fold to begin with — the defect, stated as a measurement.
    const viewport = page.viewportSize()

    expect((await section.boundingBox()).y).toBeGreaterThan(viewport.height)

    await shot(page, 'watchlist-rules-anchor')

    await anchor.click()

    await expect
        .poll(async () => (await section.boundingBox()).y, {
            message: 'the rules section never came into view',
        })
        .toBeLessThan(viewport.height)

    // And away again, so the next run starts where this one did.
    await page.locator('.rules .rule__open').first().click()
    await page.getByRole('button', { name: 'Remove rule' }).click()
    await page.getByRole('button', { name: 'Remove', exact: true }).click()

    // The anchor disappears: a count chip with nothing to count is a
    // scroll to nothing.
    await expect(page.locator('.rules__empty')).toBeVisible()
    await expect(page.getByRole('button', { name: /deal rule/i })).toHaveCount(0)
})

// The `Remove` half of that confirmation, asked on a route this test brings
// with it rather than one another spec needs.
test('confirming a removal stays on the list', async ({ page }) => {
    await watchRoute(page, 'AMS', 'MAD')

    await expect(page.locator('.pass')).toHaveCount(7)

    const added = page.locator('.pass').filter({ hasText: 'MAD' }).first()
    await added.getByRole('button', { name: /stop watching/i }).click()
    await added.getByRole('button', { name: 'Remove' }).click()

    await expect(page).toHaveURL(/\/watch$/)
    await expect(page.locator('.pass')).toHaveCount(6)
    await expect(page.locator('.screen__title')).toHaveText('Watch list')
})
