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
 * A DEFECT, WRITTEN DOWN AS A TEST RATHER THAN AS A COMMENT
 * ============================================================================
 * WHAT WAS WRONG. Watchlist.vue dims a paused route with
 * `.is-paused { opacity: 0.58 }`, and every row also carries `.rise-in`, which
 * was `animation: orbit-rise 0.5s ease both` (resources/css/app.css). The
 * keyframes end at `opacity: 1` and `animation-fill-mode: both` made that
 * final frame persist — and an animated value beats a normal declaration in
 * the cascade no matter how specific the declaration is. So the computed
 * opacity of a paused row was 1, forever, and the row was not dimmed at all.
 * Measured in the browser: `class="pass rise-in is-paused"`, `opacity: "1"`,
 * `animation-name: "orbit-rise"`, `animation-fill-mode: "both"`.
 *
 * WHY IT WAS NEVER CAUGHT. It cannot be. jsdom does not run animations and
 * does not compute the cascade, so a component test asserting the class is
 * present is green and correct; only a real renderer computes the value the
 * animation left behind. This is the first thing the browser gate found.
 *
 * THE FIX is `both` → `backwards` on `.rise-in`: an entrance stops owning the
 * properties it animated once it is over. It is one line and it covers all
 * nine users of that class, each of which was one state class away from the
 * same silent override.
 *
 * THE ASSERTION IS THE COMPUTED VALUE, not the class. The class was never in
 * doubt — the test above already asserts it — and asserting it here again is
 * exactly the test that was green throughout the defect.
 */
test('a paused row is actually dimmed, and not just given a class', async ({ page }) => {
    await page.goto('/watch')

    const row = page.locator('.pass').first()
    const toggle = row.getByRole('switch')

    await expect(toggle).toHaveAttribute('aria-checked', 'true')
    await toggle.click()
    await expect(row).toHaveClass(/is-paused/)

    /*
     * WAIT FOR THE ENTRANCE TO BE OVER before reading the value. While it runs
     * the animation owns `opacity` legitimately, and a measurement taken during
     * it would be testing how fast this box is. `getAnimations()` returns an
     * empty list once a non-filling animation has finished — which is itself
     * the fix, so this resolves immediately on a fixed build and waits half a
     * second on a broken one rather than reporting a false pass.
     */
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

    // (What the BOX shows after that was a separate, real problem, and it has
    // its own test below.)

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

    // The typeahead had nothing to offer either, and said so rather than
    // showing an empty panel.
    await expect(form.locator('.option--empty')).toHaveText('No matching destination.')

    await shot(page, 'watchlist-add-refused')
})

/*
 * ============================================================================
 * A SECOND DEFECT, same treatment
 * ============================================================================
 * WHAT WAS WRONG. AddRouteForm.vue bound `:value="destination"` and normalised
 * in `@input`: `event.target.value.toUpperCase().replace(/[^A-Z]/g, '')`. Type
 * "1L" and `destination` went from "" to "L", Vue re-rendered, and the box
 * showed "L" — the stated behaviour, "stripped to letters AS IT IS TYPED".
 * Type "12" and the strip produces "", which is what `destination` ALREADY IS:
 * no reactive change, no re-render, and the DOM kept the two digits the user
 * typed. The box disagreed with the model it is supposed to be showing.
 *
 * The consequence was small and confusing rather than dangerous: the field
 * displayed "12", the Add button was disabled, and nothing on screen said why.
 *
 * THE FIX is `v-model`, which does not have this problem — it assigns the raw
 * value (always a change, so always a re-render) and force-writes the
 * element's value from the model on update, for exactly this case. The
 * normalisation moved to a pre-flush watcher. See the component.
 *
 * WHY THIS IS A BROWSER TEST. The model was correct throughout: the fault was
 * in an element that jsdom will happily report as holding whatever the last
 * render put there.
 */
test('a rejected character does not stay in the destination box', async ({ page }) => {
    await page.goto('/watch')
    await page.getByRole('button', { name: 'Add a route' }).click()

    const field = page.locator('form.add #add-destination')

    // Digits: normalise to "", which is what the model already held — the exact
    // case the old hand-rolled binding could not repaint.
    await field.fill('12')
    await expect(field).toHaveValue('')

    // And the ordinary path still works: letters are kept, upper-cased, as typed.
    await field.fill('l1s')
    await expect(field).toHaveValue('LS')
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

/*
 * ============================================================================
 * TAPPING A ROW OPENS IT — and the controls on it still do not
 * ============================================================================
 * The design gave this card a switch and a bin and no way into the route
 * detail, so the only detail screen reachable on a phone was whichever route
 * the globe happened to be flying. The owner tapped a row, twice, and nothing
 * happened.
 *
 * THE INTERESTING HALF IS THE NEGATIVE ONE. A card that navigates is one line;
 * a card that navigates WITHOUT stealing the taps meant for the switch and the
 * bin is the thing worth a browser. Both controls sit outside the link rather
 * than inside it with a stopped event, and this is what says so — including for
 * the confirmation, which appears where the switch was and would be the easiest
 * of the three to swallow.
 */
test('tapping a row opens that route, and its controls do not', async ({ page }) => {
    await page.goto('/watch')

    const row = page.locator('.pass').first()
    const code = `${await row.locator('.end__code').first().textContent()}-${await row
        .locator('.end--to .end__code')
        .textContent()}`

    // --- The switch: flips, stays put -----------------------------------------
    const toggle = row.getByRole('switch')
    await toggle.click()

    await expect(toggle).toHaveAttribute('aria-checked', 'false')
    await expect(page).toHaveURL(/\/watch$/)

    // Back on, and that does not navigate either.
    await toggle.click()
    await expect(toggle).toHaveAttribute('aria-checked', 'true')
    await expect(page).toHaveURL(/\/watch$/)

    // --- The bin, and the question it asks ------------------------------------
    await row.getByRole('button', { name: /stop watching/i }).click()

    const keep = row.getByRole('button', { name: 'Keep' })
    await expect(keep).toBeVisible()
    await expect(page).toHaveURL(/\/watch$/)

    await keep.click()
    await expect(page).toHaveURL(/\/watch$/)
    await expect(page.locator('.pass')).toHaveCount(6)

    // --- The row body: opens it -----------------------------------------------
    await row.getByRole('link', { name: `Open ${code}` }).click()

    await expect(page).toHaveURL(new RegExp(`/route/${code}$`))
    await expect(page.locator('.detail__code')).toHaveText(code.replace('-', ' → '))
})

/*
 * ============================================================================
 * THE DESTINATION BOX SUGGESTS PLACES
 * ============================================================================
 * The design drew a three-letter field, which assumed the person filling it in
 * knew that Bilbao is BIO. The owner does not, and said so — this is the
 * deviation, asked for on 2026-08-15.
 *
 * THE BROWSER IS WHERE THIS HAS TO BE TESTED, twice over. A tap on a
 * suggestion has to beat the blur that closes the list, which is a real
 * pointer sequence and not something jsdom dispatches; and the panel has to
 * leave the Add button reachable, which is layout. AddRouteForm.test.js holds
 * the ranking and the keyboard.
 */
test('the destination box finds a city by name and adds it', async ({ page }) => {
    await page.goto('/watch')
    await page.getByRole('button', { name: 'Add a route' }).click()

    const form = page.locator('form.add')
    const field = form.locator('#add-destination')
    const listbox = form.getByRole('listbox', { name: 'Destination suggestions' })

    // An empty box suggests nothing at all.
    await expect(listbox).toBeHidden()

    await field.fill('bilb')

    await expect(listbox).toBeVisible()

    const option = listbox.getByRole('option').first()
    await expect(option).toContainText('Bilbao')
    await expect(option).toContainText('BIO')
    await expect(option).toContainText('Spain')

    // What was typed, bolded inside what was found, in the original spelling.
    await expect(option.locator('b').first()).toHaveText('Bilb')

    await shot(page, 'watchlist-typeahead')

    // --- Taking one ----------------------------------------------------------
    // A click, not a keypress: this is the focus race, and the assertion that
    // it was won is that anything happened at all.
    await option.click()

    await expect(field).toHaveValue('BIO')
    await expect(listbox).toBeHidden()

    // --- And the add is the add it always was --------------------------------
    await form.getByRole('radio', { name: 'AMS' }).click()
    await form.getByRole('button', { name: /add route/i }).click()

    await expect(form).toHaveCount(0)
    await expect(page.locator('.pass')).toHaveCount(7)

    const added = page.locator('.pass').filter({ hasText: 'BIO' }).first()
    await expect(added).toContainText('Bilbao')

    // Back to the seeded six for whatever runs next.
    await added.getByRole('button', { name: /stop watching/i }).click()
    await added.getByRole('button', { name: 'Remove' }).click()
    await expect(page.locator('.pass')).toHaveCount(6)
})

/*
 * The `Remove` half of that confirmation, which is destructive and so is asked
 * on a route this test brings with it rather than on one the other specs need.
 * A remove that also navigated would land on the detail screen of a route that
 * no longer exists.
 */
test('confirming a removal stays on the list', async ({ page }) => {
    await page.goto('/watch')
    await page.getByRole('button', { name: 'Add a route' }).click()

    const form = page.locator('form.add')
    await form.getByRole('radio', { name: 'AMS' }).click()
    await form.locator('#add-destination').fill('MAD')
    await form.getByRole('button', { name: /add route/i }).click()

    await expect(page.locator('.pass')).toHaveCount(7)

    const added = page.locator('.pass').filter({ hasText: 'MAD' }).first()
    await added.getByRole('button', { name: /stop watching/i }).click()
    await added.getByRole('button', { name: 'Remove' }).click()

    await expect(page).toHaveURL(/\/watch$/)
    await expect(page.locator('.pass')).toHaveCount(6)
    await expect(page.locator('.screen__title')).toHaveText('Watch list')
})
