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
//
// HALF THIS FILE MOVED TO search.spec.js ON 2026-08-16, with the form it was
// about. The add expander behind the + in this screen's header — the typeahead,
// the two tiers, the did-you-mean, "Look up" and "Add route" — became the centre
// tab, because keeping both would have left the app with two flight searches
// forty pixels apart in the same tab bar. What stayed here is what this screen
// is: the LIST, and what pausing, removing and undoing do to it.
// =============================================================================
import { expect, shot, tab, test, waitForGlobe } from '../fixtures.js'

test.describe.configure({ mode: 'serial' })

/**
 * Put a route on the list, through the screen that does it now.
 *
 * THE ADD FORM LEFT THIS SCREEN on 2026-08-16 — the whole route-finding
 * apparatus became the centre tab (e2e/specs/search.spec.js), and what is left
 * here is a link to it. The tests below that need a seventh route to remove,
 * undo and re-remove therefore go and get one, which is also the honest version
 * of what somebody does: search, add, come back to the list.
 *
 * IT USES THE BOXES RATHER THAN THE API, deliberately. A helper that POSTed
 * would leave the list's own read path untested for a row it did not create,
 * and this is the only place in the suite that would notice.
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

/*
 * ============================================================================
 * A ROUTE ARRIVES AND LEAVES AGAIN
 * ============================================================================
 * The add half of this used to be the expander at the top of this screen; it is
 * the search tab now, and the LIST's half of the journey — a seventh boarding
 * pass appearing, and the two taps that take it away again — is what stayed
 * here, because it is what this screen is for.
 */
test('a route added elsewhere appears here, and can be removed again', async ({ page }) => {
    await watchRoute(page, 'AMS', 'MAD')

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
    await watchRoute(page, 'AMS', 'MAD')

    await expect(page.locator('.pass')).toHaveCount(7)

    // --- Gone, and named ------------------------------------------------------
    const added = page.locator('.pass').filter({ hasText: 'MAD' }).first()
    await added.getByRole('button', { name: /stop watching/i }).click()
    await added.getByRole('button', { name: 'Remove' }).click()

    await expect(page.locator('.pass')).toHaveCount(6)

    const notice = page.locator('.screen__notice--undo')
    await expect(notice).toContainText('Stopped watching AMS\u2192MAD')

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
    /*
     * IN THROUGH THE DOOR THAT REPLACED THE CENTRE TAB. Rule creation kept its
     * screen and lost its seat in the tab bar on 2026-08-16, so "+ New rule" in
     * this section's own header is now the only way to /create — and a section
     * that only appeared once you already had a rule would be a door on the
     * inside of the room. It is drawn empty, with a sentence saying what a rule
     * is, and this is the journey through it.
     */
    await page.goto('/watch')

    const section = page.locator('.rules')
    await expect(section).toBeVisible()
    await expect(section.locator('.rules__empty')).toContainText('plain English')

    await shot(page, 'watchlist-rules-empty')

    await section.getByRole('link', { name: 'New rule' }).click()

    await expect(page).toHaveURL(/\/create$/)
    await page.locator('#rule-text').fill('cheap city break under €90')

    /*
     * WAIT FOR THE RE-READ BEFORE PRESSING ANYTHING, and this is a race rather
     * than a nicety.
     *
     * The screen seeds itself with the design's own sentence and parses it on
     * mount, so "Create rule" is ENABLED the moment this screen appears — for
     * the seeded rule, not for the one just typed. Typing schedules a re-parse
     * 500 ms later, and while that request is out `parsing` is true and
     * `save()` returns early. Playwright's actionability check and its mouse
     * event are a few milliseconds apart; land those either side of the
     * debounce and the button is enabled when it is checked, inert when it is
     * pressed, and the click silently does nothing.
     *
     * The seed says "under €80". Waiting for €90 is waiting for THIS sentence's
     * parse to have landed, after which nothing further is scheduled and the
     * button is stably live.
     */
    await expect(page.locator('.chips')).toContainText('€90')

    await page.getByRole('button', { name: /create rule/i }).click()
    await expect(page.locator('.screen__title')).toHaveText('Rule created')

    await page.goto('/watch')

    const anchor = page.getByRole('button', { name: /go to your 1 deal rule/i })
    await expect(anchor).toHaveText(/Rules · 1/)

    // Below the fold to begin with — which is the defect, stated as a
    // measurement rather than as an opinion.
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

    // The section stays — it is the way in — and goes back to saying what a
    // rule is. The anchor is what disappears: a count chip with nothing to
    // count is a scroll to nothing.
    await expect(page.locator('.rules__empty')).toBeVisible()
    await expect(page.getByRole('button', { name: /deal rule/i })).toHaveCount(0)
})

/*
 * The `Remove` half of that confirmation, which is destructive and so is asked
 * on a route this test brings with it rather than on one the other specs need.
 * A remove that also navigated would land on the detail screen of a route that
 * no longer exists.
 */
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
