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
import { expect, shot, tab, test, waitForGlobe } from '../fixtures.js'

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
 * AND A PAUSED ROW SAYS THE WORD
 * ============================================================================
 * The test above proves the row is dimmed. Dimming is not a sentence: 58%
 * opacity reads as "loading" or as a rendering glitch at least as readily as it
 * reads as "off", and the only other cue was a 46 px switch somebody has to
 * already know the meaning of. The stub's own line — which otherwise holds
 * "Tracking 3 days", or a decorative barcode once a route is established — is
 * where the row says what Orbit is doing with it, and for a paused route it was
 * saying nothing at all.
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

    const paused = await page.locator('.pass').first().locator('.end--to .end__code').textContent()

    await tab(page, 'Orbit').click()
    await expect(page.locator('.rail__chip')).toHaveCount(5)
    await expect(page.locator('.stage__chip')).toContainText('5')

    /*
     * AND THE CALENDAR'S CHIPS SAY SO TOO. That screen keeps paused routes —
     * their cheapest days are still worth reading, and docs/API.md is explicit
     * that paused routes are not filtered out — but it drew them identically to
     * live ones, so the screen quietly disagreed with the switch that had just
     * been moved.
     *
     * THE ASSERTION IS THE COMPUTED OPACITY, not the class. The watch screen's
     * own dimming was broken for exactly as long as it was tested by asserting
     * a class name: `.rise-in` was filling forwards and won the cascade, so the
     * class was there and the row was not dimmed. Only a real renderer knows.
     */
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

    // The typeahead had nothing to offer either, and says so rather than
    // showing an empty panel.
    await expect(form.locator('.option--empty')).toHaveText('No matching destination.')

    /*
     * AND THE BUTTON IS STILL PRESSABLE WITH THE PANEL OPEN, which is not a
     * given and was not true the first time this ran. The panel is in the flow;
     * closing it on the input's blur removed ~50 px from between the box and
     * this button ON MOUSEDOWN, so the mouseup landed on nothing and the press
     * never became a click. See AddRouteForm.vue's `onFocusOut`. This click is
     * the regression — it times out on a build where the form reflows under
     * the pointer.
     */
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

    // And the ordinary path still works: letters are kept, as typed.
    await field.fill('l1s')
    await expect(field).toHaveValue('ls')
})

/*
 * ============================================================================
 * THE BOX STOPPED SHOUTING
 * ============================================================================
 * It upper-cased every keystroke, which is right for a three-letter code and
 * wrong for everything it has taken since it grew a typeahead: typing "Lisbon"
 * produced "LISBON". A search field rewriting a place name in capitals reads as
 * a complaint about what was just typed, and it is the first thing anybody does
 * on this screen.
 *
 * THE ELEMENT, NOT THE MODEL, WHICH IS WHY THIS IS HERE AND NOT IN VITEST. The
 * same file already carries one defect (a rejected character the DOM kept) that
 * was invisible to jsdom for exactly this reason: the model was right and the
 * box was wrong. The upper-casing was the reverse — the model and the box agreed
 * with each other and both disagreed with the person typing — but the assertion
 * worth making is still "what does the field say", and a real browser is where
 * `autocapitalize` lives too.
 */
test('typing a city name does not shout it back', async ({ page }) => {
    await page.goto('/watch')
    await page.getByRole('button', { name: 'Add a route' }).click()

    const form = page.locator('form.add')
    const field = form.locator('#add-destination')

    await field.fill('Lisbon')
    await expect(field).toHaveValue('Lisbon')

    // The keyboard is not allowed to do it either — `autocapitalize="characters"`
    // was the same shouting one layer down, where no watcher of ours can see it.
    await expect(field).toHaveAttribute('autocapitalize', 'none')

    /*
     * And a code typed in lower case is still a code — the capitals moved to the
     * boundary rather than being dropped. The button going live is that boundary
     * being read: `canSubmit` tests the NORMALISED value, not the field.
     *
     * Asserted rather than pressed, deliberately: a look-up navigates to a
     * screen that prices the pair, which would create a route this spec has no
     * business creating. AddRouteForm.test.js holds the emit.
     */
    await field.fill('mad')
    await expect(field).toHaveValue('mad')
    await expect(form.getByRole('button', { name: 'Look up' })).toBeEnabled()
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
 * ============================================================================
 * REMOVE SAYS SO, AND IT CAN BE TAKEN BACK
 * ============================================================================
 * The row simply vanished: nothing said it had gone, nothing named what had
 * gone, and the only way back was to remember the pair and type it in again —
 * on the one screen where a mis-tap on a 26 px bin is the likeliest mistake
 * there is. The confirmation catches the mis-tap; it does nothing for somebody
 * who meant to remove one route and removed its neighbour.
 *
 * THE ROUND TRIP IS THE TEST, AND IT GOES THROUGH THE SERVER TWICE. Undo is the
 * ordinary add write, which is only honest because removing a route does not
 * delete its history — the route, its observations and its fares are Orbit's,
 * and only the watchlist row is the owner's. A reload at the end is what says
 * the row really came back rather than being put back on screen.
 */
test('a removed route says so and can be put straight back', async ({ page }) => {
    await page.goto('/watch')
    await page.getByRole('button', { name: 'Add a route' }).click()

    const form = page.locator('form.add')
    await form.getByRole('radio', { name: 'AMS' }).click()
    await form.locator('#add-destination').fill('MAD')
    await form.getByRole('button', { name: /add route/i }).click()

    await expect(page.locator('.pass')).toHaveCount(7)

    // --- Gone, and named ------------------------------------------------------
    const added = page.locator('.pass').filter({ hasText: 'MAD' }).first()
    await added.getByRole('button', { name: /stop watching/i }).click()
    await added.getByRole('button', { name: 'Remove' }).click()

    await expect(page.locator('.pass')).toHaveCount(6)

    const notice = page.locator('.screen__notice--undo')
    await expect(notice).toContainText('Stopped watching AMS→MAD')

    await shot(page, 'watchlist-removed-with-undo')

    // --- And back -------------------------------------------------------------
    await notice.getByRole('button', { name: 'Undo' }).click()

    await expect(page.locator('.pass')).toHaveCount(7)
    await expect(page.locator('.pass').filter({ hasText: 'MAD' })).toHaveCount(1)
    // The offer is spent: it named one removal and that removal is undone.
    await expect(notice).toHaveCount(0)

    // The write really happened, and not just the optimism.
    await page.reload()
    await expect(page.locator('.pass').filter({ hasText: 'MAD' })).toHaveCount(1)

    // --- Put the list back to the seeded six ----------------------------------
    const again = page.locator('.pass').filter({ hasText: 'MAD' }).first()
    await again.getByRole('button', { name: /stop watching/i }).click()
    await again.getByRole('button', { name: 'Remove' }).click()

    await expect(page.locator('.pass')).toHaveCount(6)

    /*
     * AND THE OFFER GOES QUIETLY. Six seconds, then it is gone: a notice that
     * stays forever becomes furniture, and this one sits above the list it is
     * about. The generous timeout is for the machine, not for the timer.
     */
    await expect(page.locator('.screen__notice--undo')).toHaveCount(0, { timeout: 12_000 })
})

/*
 * The `Remove` half of that confirmation, which is destructive and so is asked
 * on a route this test brings with it rather than on one the other specs need.
 * A remove that also navigated would land on the detail screen of a route that
 * no longer exists.
 */
/*
 * ============================================================================
 * THE EMPTY HOME IS STILL THIS APP'S SCREEN
 * ============================================================================
 * With nothing to tour, the globe home was a small card floating in six
 * hundred pixels of nothing: the signature screen of a flight tracker, on the
 * morning somebody installed it, showing neither a flight nor a tracker. And
 * the one thing to do next was four words of body copy with an underline on
 * two of them.
 *
 * REACHED BY PAUSING RATHER THAN BY REMOVING, because `activeRoutes` is what
 * this state branches on and pausing is the reversible way to empty it — this
 * spec runs against a database the rest of the suite shares, and six removals
 * would be six routes to add back by hand.
 */
test('with nothing to tour, the home still draws the globe and one thing to do', async ({ page }) => {
    await page.goto('/watch')

    /*
     * THE COUNT IS ASSERTED BEFORE EVERY SWEEP, and that is not belt and
     * braces. `Locator.all()` does not wait for anything — it resolves however
     * many rows exist at that instant — so calling it on a screen whose
     * watchlist request is still in flight returns an empty array and the loop
     * below silently does nothing at all. The restore sweep after the
     * navigation is where this actually bit.
     */
    const switches = page.locator('.pass').getByRole('switch')

    await expect(switches).toHaveCount(6)

    for (const toggle of await switches.all()) {
        await toggle.click()
        await expect(toggle).toHaveAttribute('aria-checked', 'false')
    }

    await tab(page, 'Orbit').click()

    await expect(page.locator('.home__notice-title')).toHaveText('Nothing orbiting yet')

    // The planet is drawn — empty, with nothing on it. `play()` has no active
    // route so there is no arc and no camera work, which is the honest picture
    // of an empty watchlist and the picture of what the screen becomes.
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

    // --- Every route back on, for whatever runs next --------------------------
    await expect(switches).toHaveCount(6)

    for (const toggle of await switches.all()) {
        await toggle.click()
        await expect(toggle).toHaveAttribute('aria-checked', 'true')
    }

    // Every one of them, from the server — the restore is this test's mess to
    // clean up and a spec that left five of six paused would fail the next one.
    await page.reload()
    await expect(switches).toHaveCount(6)

    for (const toggle of await switches.all()) {
        await expect(toggle).toHaveAttribute('aria-checked', 'true')
    }
})

/*
 * ============================================================================
 * LOOK BEFORE YOU WATCH — the round trip, through the browser
 * ============================================================================
 * The form used to have exactly one action and it was a COMMITMENT: the only
 * way to find out what Amsterdam to Prague costs was to start watching
 * Amsterdam to Prague. This is the journey that replaced it, end to end — type
 * a place, get a price, decide afterwards — and the assertion in the middle is
 * the one that matters: THE LIST IS STILL SIX. A lookup that quietly added a
 * route would pass every other check in this file.
 *
 * IT GOES THROUGH THE SERVER TWICE AND THROUGH A REAL FETCH ONCE. AMS-PRG is
 * not seeded, so the detail screen's read comes back 404 and the lookup that
 * follows creates the route and prices it against the fake provider — the same
 * two steps a real one takes, minus the metered call.
 */
test('a route is looked up first, and watched from the screen that priced it', async ({ page, browserConsole }) => {
    /*
     * THE 404 IS THE QUESTION, NOT A FAULT. `GET /api/routes/AMS-PRG` answering
     * "no such route" is how the screen finds out Orbit has never priced this
     * pair, and the lookup it makes next is the answer (docs/API.md).
     * RouteDetail.vue deliberately does not console.error on it; Chromium logs
     * the failed request itself, and that one line is what this waives.
     */
    browserConsole.allow(/Failed to load resource.*404/)

    await page.goto('/watch')
    await expect(page.locator('.pass')).toHaveCount(6)

    await page.getByRole('button', { name: 'Add a route' }).click()

    const form = page.locator('form.add')
    await form.getByRole('radio', { name: 'AMS' }).click()
    await form.locator('#add-destination').fill('PRG')

    /*
     * PRESSED WITH THE SUGGESTION PANEL OPEN, which is the same reflow trap the
     * Add button fell into when this form grew a typeahead: the panel is in the
     * flow, and anything that closes it on mousedown moves the button out from
     * under the pointer between press and release. The primary button is new
     * and sits in exactly the same place.
     */
    await form.getByRole('button', { name: 'Look up' }).click()

    await expect(page).toHaveURL(/\/route\/AMS-PRG$/)
    await expect(page.locator('.detail__code')).toHaveText('AMS → PRG')

    // Priced, on the spot, by a route that did not exist a second ago.
    await expect(page.locator('.price__value')).toHaveText(/^€\d+$/)

    const offer = page.getByRole('button', { name: 'Watch this route' })
    await expect(offer).toBeVisible()

    await shot(page, 'route-lookup-unwatched')

    // --- And nothing was written to get here ---------------------------------
    await page.goto('/watch')
    await expect(page.locator('.pass')).toHaveCount(6)

    // --- Now decide, on the screen that showed the price ---------------------
    await page.goto('/route/AMS-PRG')
    await page.getByRole('button', { name: 'Watch this route' }).click()

    await expect(page.locator('.watch--on')).toContainText('On your watch list')
    await expect(page.getByRole('button', { name: 'Watch this route' })).toHaveCount(0)

    await shot(page, 'route-lookup-watched')

    // The write really happened, and the shared store carried it to the list.
    await page.goto('/watch')
    await expect(page.locator('.pass')).toHaveCount(7)
    await expect(page.locator('.pass').filter({ hasText: 'PRG' })).toHaveCount(1)

    // --- Back to the seeded six for whatever runs next ------------------------
    const added = page.locator('.pass').filter({ hasText: 'PRG' }).first()
    await added.getByRole('button', { name: /stop watching/i }).click()
    await added.getByRole('button', { name: 'Remove' }).click()

    await expect(page.locator('.pass')).toHaveCount(6)
})

/*
 * ============================================================================
 * THE RULES ARE TWO AND A HALF SCREENS DOWN, AND NOW SOMETHING SAYS SO
 * ============================================================================
 * Deal rules live under the boarding passes, which is the right order — the
 * routes are what the owner chose, a rule is a standing question. With seven
 * routes on a phone that puts the section far below the fold behind seven
 * near-identical cards, and the UX pass simply never found it: the + tab writes
 * a rule, and the rule then appears somewhere the person who wrote it has no
 * reason to scroll to.
 *
 * IT HAS TO CREATE ONE, because nothing is seeded with rules — and that is also
 * the honest version of the journey: write a rule, go to the list, find it. The
 * rule is removed again at the end, so the screen is left as it was found.
 *
 * THE ASSERTION IS A SCROLL POSITION, which is the whole reason this is a
 * browser test. jsdom has no layout and no viewport, so "is the section on
 * screen" has no meaning in it; the anchor could be wired to the wrong element
 * and every component test would still pass.
 */
test('a count chip finds the rules section that is below the fold', async ({ page }) => {
    await page.goto('/create')
    await page.locator('#rule-text').fill('cheap city break under €90')

    await page.getByRole('button', { name: /create rule/i }).click()
    await expect(page.locator('.screen__title')).toHaveText('Rule created')

    await page.goto('/watch')

    const anchor = page.getByRole('button', { name: /go to your 1 deal rule/i })
    await expect(anchor).toHaveText(/Rules · 1/)

    // Below the fold to begin with — which is the defect, stated as a
    // measurement rather than as an opinion.
    const section = page.locator('.rules')
    const viewport = page.viewportSize()

    expect((await section.boundingBox()).y).toBeGreaterThan(viewport.height)

    await shot(page, 'watchlist-rules-anchor')

    await anchor.click()

    await expect
        .poll(async () => (await section.boundingBox()).y, {
            message: 'the rules section never came into view',
        })
        .toBeLessThan(viewport.height)

    // --- And away again, so the next run starts where this one did -----------
    await page.locator('.rules .rule__open').first().click()
    await page.getByRole('button', { name: 'Remove rule' }).click()
    await page.getByRole('button', { name: 'Remove', exact: true }).click()

    await expect(page.locator('.rules')).toHaveCount(0)
    // The anchor is an advert with nothing behind it once the section is gone.
    await expect(page.getByRole('button', { name: /deal rule/i })).toHaveCount(0)
})

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

/*
 * ============================================================================
 * THE WORLD, IN THE SAME BOX — world flights
 * ============================================================================
 * The typeahead offered 77 European cities and the box refused everything else,
 * which made the app's one write a write about Europe. It now offers every
 * scheduled airport on Earth, from two places at once, and the join between
 * them is the thing worth putting a browser in front of:
 *
 *   - the CURATED matches are in memory and paint on the keystroke;
 *   - the WORLD matches arrive from `GET /api/airports?q=` a quarter of a
 *     second later, under a divider, deduped against the rows above them.
 *
 * WHY jsdom IS NOT ENOUGH HERE. AddRouteForm.test.js already asserts the merge,
 * the divider and the dedupe against a mocked endpoint. What it cannot see is
 * the panel growing a second time after the request lands — the same reflow
 * that made the Add button unpressable when this form first grew a typeahead —
 * or whether the real endpoint, the real ranking and the real 3,270-row table
 * answer with what the component expects. The seeded stack has all three.
 *
 * AND IT ENDS IN A PRICE. A suggestion nobody can act on is a nicer dead end,
 * not a feature: the test takes a world-only airport all the way to a priced
 * route-detail screen, which is the journey the feature exists for.
 */
test('the box finds an airport on the other side of the world, and prices it', async ({ page, browserConsole }) => {
    /* The detail screen's first read is a 404 by design — see the lookup test above. */
    browserConsole.allow(/Failed to load resource.*404/)

    await page.goto('/watch')
    await page.getByRole('button', { name: 'Add a route' }).click()

    const form = page.locator('form.add')
    const field = form.locator('#add-destination')
    const listbox = form.getByRole('listbox', { name: 'Destination suggestions' })
    const divider = form.locator('.options__split')

    // --- Both tiers, in one panel --------------------------------------------
    await field.fill('new york')

    /*
     * JFK IS CURATED and comes first: it is one of the 184 places with vibes
     * and month-by-month warmth attached, which is what the rule engine
     * matches against (docs/BUSINESS-LOGIC.md §1).
     */
    const first = listbox.getByRole('option').first()
    await expect(first).toContainText('New York')
    await expect(first).toContainText('JFK')

    /*
     * LGA IS NOT. It is in the OurAirports snapshot, Orbit will price it, and
     * no rule will ever suggest it — so it sits under the divider that says so.
     * This assertion is also the one that proves the request really happened:
     * nothing in the browser knows LaGuardia exists until it answers.
     */
    await expect(divider).toHaveText('Everywhere else Orbit can price')
    await expect(listbox.getByRole('option').filter({ hasText: 'LGA' })).toHaveCount(1)

    await shot(page, 'watchlist-typeahead-world')

    // --- A match that only the world half has --------------------------------
    await field.fill('newark')

    // Nothing curated matched, so there is nothing for a divider to divide.
    await expect(divider).toHaveCount(0)

    const newark = listbox.getByRole('option').first()
    await expect(newark).toContainText('Newark')
    await expect(newark).toContainText('EWR')
    await expect(newark).toContainText('United States')
    // What was typed, bolded inside what the SERVER sent, in its own spelling.
    await expect(newark.locator('b').first()).toHaveText('Newark')

    await shot(page, 'watchlist-typeahead-world-only')

    await newark.click()
    await expect(field).toHaveValue('EWR')
    await expect(listbox).toBeHidden()

    // --- And it is a real route, priced on the spot --------------------------
    await form.getByRole('radio', { name: 'AMS' }).click()
    await form.getByRole('button', { name: 'Look up' }).click()

    await expect(page).toHaveURL(/\/route\/AMS-EWR$/)
    await expect(page.locator('.detail__code')).toHaveText('AMS → EWR')
    await expect(page.locator('.price__value')).toHaveText(/^€\d+$/)

    await shot(page, 'route-lookup-world')

    // Nothing was watched to get here, and the seeded six are untouched.
    await page.goto('/watch')
    await expect(page.locator('.pass')).toHaveCount(6)
})
