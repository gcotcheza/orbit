// =============================================================================
// Watch list (design/README.md §5)
// =============================================================================
// The one screen in Orbit that WRITES. Everything else reads: this is where a
// route gets paused, resumed, added and removed, and where a change that looks
// like it happened but did not is most expensive — a paused route that is still
// being polled sends alerts the owner turned off.
//
// So the toggle is checked twice: once for what the row does, and once for what
// the server did, by reloading and looking again. A store that updates its own
// state optimistically and swallows the failed PATCH passes the first check and
// fails the second, which is exactly the bug worth having a browser for.
// =============================================================================
import { expect, shot, tab, test } from '../fixtures.js'

test.describe.configure({ mode: 'serial' })

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

    // --- Off ------------------------------------------------------------------
    await toggle.click()

    await expect(toggle).toHaveAttribute('aria-checked', 'false')
    // `is-paused` is what is SUPPOSED to dim it — Watchlist.vue binds the class
    // from the route's own `active`, so this is the row agreeing with the
    // switch. Whether the class then reaches the pixels is the next test, and
    // the answer today is no.
    await expect(row).toHaveClass(/is-paused/)

    // --- And the server agrees ------------------------------------------------
    // A SECOND PAGE LOAD, not a re-read of the store. The store is the thing
    // under suspicion: an optimistic update that never reached the API looks
    // identical until the state is thrown away and fetched again.
    await page.reload()

    const reloaded = page.locator('.pass').filter({ hasText: code.split('-')[1] }).first()
    await expect(reloaded.getByRole('switch')).toHaveAttribute('aria-checked', 'false')

    await shot(page, 'watchlist-paused')

    // --- Back on --------------------------------------------------------------
    // Put it back, because the next spec in this file and every later run start
    // from the seeded state.
    await reloaded.getByRole('switch').click()
    await expect(reloaded.getByRole('switch')).toHaveAttribute('aria-checked', 'true')

    await page.reload()
    await expect(page.locator('.pass').first().getByRole('switch')).toHaveAttribute('aria-checked', 'true')
})

/*
 * ============================================================================
 * A DEFECT, WRITTEN DOWN AS A FAILING TEST RATHER THAN AS A COMMENT
 * ============================================================================
 * `test.fail()` inverts the result: this passes while the bug is there and
 * turns RED the day somebody fixes it, which is the reminder to delete it.
 *
 * WHAT IS WRONG. Watchlist.vue dims a paused route with
 * `.is-paused { opacity: 0.58 }`, and every row also carries `.rise-in`, which
 * is `animation: orbit-rise 0.5s ease both` (resources/css/app.css). The
 * keyframes end at `opacity: 1` and `animation-fill-mode: both` makes that
 * final frame persist — and an animated value beats a normal declaration in
 * the cascade no matter how specific the declaration is. So the computed
 * opacity of a paused row is 1, forever, and the row is not dimmed at all.
 * Measured in the browser: `class="pass rise-in is-paused"`, `opacity: "1"`,
 * `animation-name: "orbit-rise"`, `animation-fill-mode: "both"`.
 *
 * WHY IT WAS NEVER CAUGHT. It cannot be. jsdom does not run animations and
 * does not compute the cascade, so a component test asserting the class is
 * present is green and correct; only a real renderer computes the value the
 * animation left behind. This is the first thing the browser gate found.
 *
 * NOT FIXED HERE. resources/js/Views/Watchlist.vue and resources/css/app.css
 * belong to other work; the harness's job is to report, not to patch the app
 * it is measuring. The fix is one of: put the dimming on an inner element,
 * drop `both` for `forwards`-less filling, or animate `opacity` from the class.
 */
test.fail('KNOWN BUG: a paused row is not actually dimmed — .rise-in wins', async ({ page }) => {
    await page.goto('/watch')

    const row = page.locator('.pass').first()
    const toggle = row.getByRole('switch')

    await expect(toggle).toHaveAttribute('aria-checked', 'true')
    await toggle.click()
    await expect(row).toHaveClass(/is-paused/)

    const opacity = Number(await row.evaluate((element) => getComputedStyle(element).opacity))

    // Restore before the assertion, so this test leaves the list the way it
    // found it whichever way the assertion goes.
    await toggle.click()
    await expect(toggle).toHaveAttribute('aria-checked', 'true')

    expect(opacity, 'a paused row should be visibly dimmed').toBeLessThan(1)
})

test('a paused route drops out of the globe tour', async ({ page }) => {
    // The two screens share stores/watchlist.js. Before the DRY pass Home
    // fetched the list for itself, so a route paused here stayed in the tour
    // until a reload — the exact regression this asserts is gone.
    await page.goto('/watch')
    await expect(page.locator('.pass')).toHaveCount(6)

    await page.locator('.pass').first().getByRole('switch').click()
    await expect(page.locator('.pass').first().getByRole('switch')).toHaveAttribute('aria-checked', 'false')

    await tab(page, 'Orbit').click()
    await expect(page.locator('.rail__chip')).toHaveCount(5)
    await expect(page.locator('.stage__chip')).toContainText('5')

    // Restore.
    await tab(page, 'Watch').click()
    await page.locator('.pass').first().getByRole('switch').click()
    await expect(page.locator('.pass').first().getByRole('switch')).toHaveAttribute('aria-checked', 'true')
})

test('a destination Orbit does not know is refused, in the form', async ({ page, browserConsole }) => {
    /*
     * THE ONE WAIVED APP-LEVEL console.error IN THE SUITE, and it is waived
     * because it is the behaviour being tested. Watchlist.vue:add() writes the
     * refusal into the form AND logs the failure — a deliberate diagnostic, not
     * a leak — so asserting the message appears means accepting the log line
     * that comes with it. The pattern is narrow: this waives that sentence and
     * the 422 that caused it, nothing else.
     */
    browserConsole.allow(/Could not add a route/, /Failed to load resource.*422/)

    await page.goto('/watch')
    await page.getByRole('button', { name: 'Add a route' }).click()

    const form = page.locator('form.add')
    await expect(form).toBeVisible()

    // --- Garbage that never leaves the browser --------------------------------
    // Digits are not an airport code, so the model stays empty and the button
    // stays shut. That IS the first line of validation: the form refuses to
    // send a request it already knows the server will reject.
    const field = form.locator('#add-destination')
    await field.fill('12')
    await expect(form.getByRole('button', { name: /add route/i })).toBeDisabled()

    // (What the BOX shows after that is a separate, real problem — see the
    // `test.fail` below.)

    // --- Garbage that is well-formed and still wrong --------------------------
    // Three letters, so the client is satisfied and the request goes; ZZZ is
    // not an airport, so AddWatchedRouteRequest refuses it with a sentence.
    await field.fill('ZZZ')
    await form.getByRole('button', { name: /add route/i }).click()

    const error = form.getByRole('alert')
    await expect(error).toBeVisible()
    await expect(error).toHaveText(/Orbit does not know an airport with that code/i)

    // Nothing was added, and the form stayed open holding what was typed.
    await expect(page.locator('.pass')).toHaveCount(6)
    await expect(field).toHaveValue('ZZZ')

    await shot(page, 'watchlist-add-refused')
})

/*
 * ============================================================================
 * A SECOND DEFECT, same treatment: passes while it is broken, red when fixed
 * ============================================================================
 * WHAT IS WRONG. AddRouteForm.vue binds `:value="destination"` and normalises
 * in `@input`: `event.target.value.toUpperCase().replace(/[^A-Z]/g, '')`. Type
 * "1L" and `destination` goes from "" to "L", Vue re-renders, and the box shows
 * "L" — the stated behaviour, "stripped to letters AS IT IS TYPED". Type "12"
 * and the strip produces "", which is what `destination` ALREADY IS: no
 * reactive change, no re-render, and the DOM keeps the two digits the user
 * typed. The box now disagrees with the model it is supposed to be showing.
 *
 * The consequence is small and confusing rather than dangerous: the field
 * displays "12", the Add button is disabled, and nothing on screen says why.
 * `v-model` would not have this problem — it force-writes the element's value
 * on update for exactly this case — which is what makes a hand-rolled
 * `:value` + `@input` pair worth flagging.
 *
 * NOT FIXED HERE: AddRouteForm.vue is not this branch's file.
 */
test.fail('KNOWN BUG: rejected characters stay in the destination box', async ({ page }) => {
    await page.goto('/watch')
    await page.getByRole('button', { name: 'Add a route' }).click()

    const field = page.locator('form.add #add-destination')
    await field.fill('12')

    // A short timeout on purpose: this assertion is EXPECTED to fail, and the
    // suite should not spend the default fifteen seconds proving a bug it
    // already knows about. Two seconds is far longer than a Vue re-render.
    await expect(field).toHaveValue('', { timeout: 2000 })
})

test('a real route can be added and removed again', async ({ page }) => {
    await page.goto('/watch')
    await page.getByRole('button', { name: 'Add a route' }).click()

    const form = page.locator('form.add')
    // AMS is already the default origin, but saying so makes the route this
    // test creates deterministic rather than dependent on the form's default.
    await form.getByRole('radio', { name: 'AMS' }).click()
    await form.locator('#add-destination').fill('MAD')
    await form.getByRole('button', { name: /add route/i }).click()

    // The form closes on success (Watchlist.vue) and the new pass appears.
    await expect(form).toHaveCount(0)
    await expect(page.locator('.pass')).toHaveCount(7)

    const added = page.locator('.pass').filter({ hasText: 'MAD' }).first()
    await expect(added).toBeVisible()

    // Put the watchlist back the way the seeder left it — this suite runs
    // serially against one database and the next spec expects six.
    await added.getByRole('button', { name: /stop watching/i }).click()
    await added.getByRole('button', { name: 'Remove' }).click()

    await expect(page.locator('.pass')).toHaveCount(6)

    await page.reload()
    await expect(page.locator('.pass')).toHaveCount(6)
})
