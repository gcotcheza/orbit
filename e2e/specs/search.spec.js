// =============================================================================
// Search — the centre tab, and the journey the app turned out to be for
// =============================================================================
// The centre of the tab bar wrote a deal RULE until 2026-08-16. On the first day
// of real use the owner made thirty-two look-ups and wrote zero rules, through a
// form folded away behind a small + in the watch screen's header — so the
// centre became a magnifying glass, the form became a screen, and the origin
// stopped being one of three (App\Http\Requests\RoutePairRequest).
//
// MOST OF THIS FILE MOVED HERE FROM watchlist.spec.js, unchanged in substance,
// because the thing it was testing moved: the typeahead, the two tiers, the
// refusals, and "look before you watch". What is genuinely new is the FROM box,
// and the journey below is written around an airport that could not have been
// typed into this app yesterday.
//
// WHY THE BROWSER, for the parts that jsdom already covers. Three of the things
// asserted here are not testable anywhere else:
//
//   - A TAP ON A SUGGESTION HAS TO BEAT THE FOCUSOUT that would close the list.
//     Real pointer sequence, real event order.
//   - THE BUTTONS HAVE TO STAY UNDER THE THUMB. The panel is in the flow, so
//     anything that closes it on mousedown moves "Look up" out from under the
//     press between mousedown and mouseup and no click is ever produced. That
//     was a real defect on the old form; the form here has two panels that can
//     do it.
//   - THE ELEMENT, NOT THE MODEL. A character the box rejects has to actually
//     leave the box, and jsdom will happily report a field as holding whatever
//     the last render put there.
// =============================================================================
import { expect, shot, tab, test } from '../fixtures.js'

test.describe.configure({ mode: 'serial' })

const FROM = '#search-from'
const TO = '#search-to'

/** The suggestion panel belonging to one of the two boxes. */
const listbox = (page, name) => page.getByRole('listbox', { name })

/*
 * ============================================================================
 * THE JOURNEY — an airport this app could not have been told about yesterday
 * ============================================================================
 * NRN is Weeze, a small field on the German side of the Dutch border that
 * exists in the OurAirports snapshot, has no `is_origin` flag, and is in no
 * version of `config('orbit.origins')`. Typing it into the From box was a 422
 * until the day this screen was drawn — "Orbit only tracks departures from AMS,
 * EIN or DUS." — so it is the honest test of what changed.
 *
 * IT GOES THROUGH THE SERVER THREE TIMES: the world airport search that finds
 * Weeze, the detail screen's read (a 404, because nothing has ever priced this
 * pair), and the lookup that creates the route and prices it against the fake
 * provider. The assertion in the middle is the one that matters — THE LIST IS
 * STILL SIX. A lookup that quietly added a route would pass everything else.
 */
test('an airport nobody flies from is searched, priced, and then watched', async ({ page, browserConsole }) => {
    /*
     * THE 404 IS THE QUESTION, NOT A FAULT. `GET /api/routes/NRN-AGP` answering
     * "no such route" is how the screen finds out Orbit has never priced this
     * pair, and the lookup it makes next is the answer (docs/API.md).
     * RouteDetail.vue deliberately does not console.error on it; Chromium logs
     * the failed request itself, and that one line is what this waives.
     */
    browserConsole.allow(/Failed to load resource.*404/)

    await page.goto('/watch')
    await expect(page.locator('.pass')).toHaveCount(6)

    // --- In through the centre tab, which is what changed --------------------
    await tab(page, 'Search').click()

    await expect(page).toHaveURL(/\/search$/)
    await expect(page.locator('.screen__title')).toHaveText('Search')

    // The common case is one tap and it is already taken: AMS is in the box.
    await expect(page.locator(FROM)).toHaveValue('AMS')

    // --- From: an airport only the world half knows --------------------------
    await page.locator(FROM).fill('wee')

    const origins = listbox(page, 'Origin suggestions')
    const weeze = origins.getByRole('option').filter({ hasText: 'NRN' })

    /*
     * IT ARRIVES FROM THE NETWORK, which is the point of the assertion. Nothing
     * in the browser has heard of Weeze until `GET /api/airports?q=wee`
     * answers — the curated 184 do not contain it, and no rule will ever
     * suggest it, so it sits under the divider that says exactly that.
     */
    await expect(weeze).toHaveCount(1)
    await expect(weeze).toContainText('Weeze')
    await expect(weeze).toContainText('Germany')

    await shot(page, 'search-origin-typeahead')

    await weeze.click()
    await expect(page.locator(FROM)).toHaveValue('NRN')
    await expect(origins).toBeHidden()

    // …and no chip claims to be the origin any more.
    await expect(page.locator('.quick__chip[aria-pressed="true"]')).toHaveCount(0)

    // --- To: a curated place, found instantly --------------------------------
    await page.locator(TO).fill('malaga')

    const destinations = listbox(page, 'Destination suggestions')
    const malaga = destinations.getByRole('option').first()

    // Accents fold in the browser, which is why this one is answered without a
    // round trip (resources/js/stores/destinations.js).
    await expect(malaga).toContainText('Málaga')
    await expect(malaga).toContainText('AGP')

    await malaga.click()
    await expect(page.locator(TO)).toHaveValue('AGP')

    await shot(page, 'search-filled')

    // --- Look up: a price, and nothing written -------------------------------
    await page.getByRole('button', { name: 'Look up' }).click()

    await expect(page).toHaveURL(/\/route\/NRN-AGP$/)
    await expect(page.locator('.detail__code')).toHaveText('NRN → AGP')
    await expect(page.locator('.price__value')).toHaveText(/^€\d+$/)

    await shot(page, 'search-lookup-nrn-agp')

    // The route did not exist a second ago and the watchlist still does not
    // know about it.
    await page.goto('/watch')
    await expect(page.locator('.pass')).toHaveCount(6)

    // --- Decide, on the screen that showed the price -------------------------
    await page.goto('/route/NRN-AGP')
    await page.getByRole('button', { name: 'Watch this route' }).click()

    await expect(page.locator('.watch--on')).toContainText('On your watch list')

    await page.goto('/watch')
    await expect(page.locator('.pass')).toHaveCount(7)
    await expect(page.locator('.pass').filter({ hasText: 'NRN' })).toHaveCount(1)

    await shot(page, 'watchlist-with-nrn')

    // --- Back to the seeded six for whatever runs next -----------------------
    const added = page.locator('.pass').filter({ hasText: 'NRN' }).first()
    await added.getByRole('button', { name: /stop watching/i }).click()
    await added.getByRole('button', { name: 'Remove' }).click()

    await expect(page.locator('.pass')).toHaveCount(6)
})

/*
 * ============================================================================
 * THE SECOND BUTTON, WHICH COMMITS — and stays on the screen it was pressed on
 * ============================================================================
 * A route added a second ago has no polls, no history and no opinion, so the
 * screen says what it did and offers the way in rather than pushing anybody at
 * the emptiest possible detail page.
 */
test('a route can be watched straight from the search screen', async ({ page }) => {
    await page.goto('/search')

    await page.locator(FROM).fill('AMS')
    await page.locator(TO).fill('MAD')

    /*
     * PRESSED WITH THE SUGGESTION PANEL OPEN, deliberately. The panel is in the
     * flow; anything that closes it on mousedown moves this button ~250 px up
     * between press and release and the click never happens. It is the defect
     * the old single-box form had, and this screen has two panels that could
     * reintroduce it. See Views/Search.vue's `onFocusOut`.
     */
    await expect(listbox(page, 'Destination suggestions')).toBeVisible()
    await page.getByRole('button', { name: 'Add to watch' }).click()

    const notice = page.locator('.search__added')
    await expect(notice).toContainText('AMS→MAD is on your watch list')

    // The box is emptied for the next question; the origin is not.
    await expect(page.locator(TO)).toHaveValue('')
    await expect(page.locator(FROM)).toHaveValue('AMS')

    await shot(page, 'search-added')

    // The write really happened, and the shared store carried it to the list.
    await page.goto('/watch')
    await expect(page.locator('.pass')).toHaveCount(7)

    const added = page.locator('.pass').filter({ hasText: 'MAD' }).first()
    await expect(added).toBeVisible()

    // Back to the seeded six.
    await added.getByRole('button', { name: /stop watching/i }).click()
    await added.getByRole('button', { name: 'Remove' }).click()
    await expect(page.locator('.pass')).toHaveCount(6)
})

/*
 * ============================================================================
 * THE WORLD AND THE CURATED SET, IN ONE PANEL — world flights
 * ============================================================================
 * Moved from watchlist.spec.js with the box it is about.
 *
 *   - the CURATED matches are in memory and paint on the keystroke;
 *   - the WORLD matches arrive from `GET /api/airports?q=` a quarter of a
 *     second later, under a divider, deduped against the rows above them.
 *
 * WHY jsdom IS NOT ENOUGH HERE. AirportField.test.js already asserts the merge,
 * the divider and the dedupe against a mocked endpoint. What it cannot see is
 * the panel growing a second time after the request lands — the same reflow
 * that made the old Add button unpressable — or whether the real endpoint, the
 * real ranking and the real 3,270-row table answer with what the component
 * expects. The seeded stack has all three.
 */
test('the destination box finds a city by name, and the world under a divider', async ({ page }) => {
    await page.goto('/search')

    const field = page.locator(TO)
    const list = listbox(page, 'Destination suggestions')
    const divider = page.locator('#search-to-list .options__split')

    // An empty box suggests nothing at all.
    await expect(list).toBeHidden()

    // --- The instant half ----------------------------------------------------
    await field.fill('bilb')

    await expect(list).toBeVisible()

    const bilbao = list.getByRole('option').first()
    await expect(bilbao).toContainText('Bilbao')
    await expect(bilbao).toContainText('BIO')
    await expect(bilbao).toContainText('Spain')

    // What was typed, bolded inside what was found, in the original spelling.
    await expect(bilbao.locator('b').first()).toHaveText('Bilb')

    // --- Both tiers, in one panel --------------------------------------------
    await field.fill('new york')

    /*
     * JFK IS CURATED and comes first: it is one of the 184 places with vibes
     * and month-by-month warmth attached, which is what the rule engine matches
     * against (docs/BUSINESS-LOGIC.md §1).
     */
    const first = list.getByRole('option').first()
    await expect(first).toContainText('New York')
    await expect(first).toContainText('JFK')

    /*
     * LGA IS NOT. It is in the OurAirports snapshot, Orbit will price it, and
     * no rule will ever suggest it — so it sits under the divider that says so.
     * This assertion is also the one that proves the request really happened:
     * nothing in the browser knows LaGuardia exists until it answers.
     */
    await expect(divider).toHaveText('Everywhere else Orbit can price')
    await expect(list.getByRole('option').filter({ hasText: 'LGA' })).toHaveCount(1)

    await shot(page, 'search-typeahead-world')

    // --- A match that only the world half has --------------------------------
    await field.fill('newark')

    // Nothing curated matched, so there is nothing for a divider to divide.
    await expect(divider).toHaveCount(0)

    const newark = list.getByRole('option').first()
    await expect(newark).toContainText('Newark')
    await expect(newark).toContainText('EWR')
    // What was typed, bolded inside what the SERVER sent, in its own spelling.
    await expect(newark.locator('b').first()).toHaveText('Newark')

    /*
     * A CLICK, NOT A KEYPRESS: this is the focus race, and the assertion that it
     * was won is that anything happened at all.
     */
    await newark.click()
    await expect(field).toHaveValue('EWR')
    await expect(list).toBeHidden()
})

/*
 * ONE PANEL AT A TIME, which is a layout fact and therefore this file's. Two
 * open listboxes stacked down a 430 px column is a screen with no buttons left
 * on it, and the flag that prevents it lives on the form rather than in either
 * box — see the note in Components/search/AirportField.vue.
 */
test('only one suggestion list is open at a time', async ({ page }) => {
    await page.goto('/search')

    const origins = listbox(page, 'Origin suggestions')
    const destinations = listbox(page, 'Destination suggestions')

    await page.locator(TO).fill('lisb')
    await expect(destinations).toBeVisible()
    await expect(origins).toBeHidden()

    await page.locator(FROM).fill('barcel')
    await expect(origins).toBeVisible()
    await expect(destinations).toBeHidden()

    // And neither box offers what the other one is holding: a route from a
    // place to itself is not a route.
    await page.locator(FROM).fill('BCN')
    await page.locator(TO).fill('barcel')

    await expect(destinations.getByRole('option')).toHaveCount(0)
})

/*
 * ============================================================================
 * WHAT THE SERVER REFUSES, AND WHAT NEVER LEAVES THE BROWSER
 * ============================================================================
 */
test('a code Orbit has no airport for is refused, beside the boxes', async ({ page, browserConsole }) => {
    /*
     * THE ONE WAIVED APP-LEVEL console.error IN THE SUITE, and it is waived
     * because it is the behaviour being tested. Search.vue's `add` writes the
     * refusal into the form AND logs the failure — a deliberate diagnostic, not
     * a leak — so asserting the message appears means accepting the log line
     * that comes with it.
     */
    browserConsole.allow(/Could not add a route/, /Failed to load resource.*422/)

    await page.goto('/search')

    // --- Garbage that never leaves the browser -------------------------------
    // Digits are not an airport code, so the model stays empty and both buttons
    // stay shut. That IS the first line of validation: the screen refuses to
    // send a request it already knows the server will reject.
    await page.locator(TO).fill('12')
    await expect(page.getByRole('button', { name: 'Add to watch' })).toBeDisabled()

    /*
     * AND THE BOX DOES NOT KEEP THEM. The strip produces "", which is what the
     * model already held — no reactive change, no re-render — and a hand-rolled
     * `:value`/`@input` binding leaves the two digits sitting in the element.
     * The model was right and the ELEMENT was wrong, which is why this
     * assertion is here and not in vitest.
     */
    await expect(page.locator(TO)).toHaveValue('')

    // And the ordinary path still works: letters are kept, as typed.
    await page.locator(TO).fill('l1s')
    await expect(page.locator(TO)).toHaveValue('ls')

    // --- Garbage that is well-formed and still wrong -------------------------
    await page.locator(TO).fill('ZZZ')

    // The typeahead had nothing to offer either, and says so rather than
    // showing an empty panel.
    await expect(page.locator('#search-to-list .option--empty')).toHaveText('No matching airport.')

    await page.getByRole('button', { name: 'Add to watch' }).click()

    const error = page.locator('.search__error')
    await expect(error).toBeVisible()
    await expect(error).toHaveText(/Orbit does not know an airport with that code/i)

    await shot(page, 'search-refused')

    // Nothing was added, and the screen stayed as it was.
    await expect(page.locator(TO)).toHaveValue('ZZZ')

    await page.goto('/watch')
    await expect(page.locator('.pass')).toHaveCount(6)
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
 * THE ELEMENT, NOT THE MODEL, WHICH IS WHY THIS IS HERE AND NOT IN VITEST — and
 * `autocapitalize` lives in a real browser too.
 */
test('typing a city name does not shout it back, at either end', async ({ page }) => {
    await page.goto('/search')

    for (const box of [FROM, TO]) {
        await page.locator(box).fill('Lisbon')
        await expect(page.locator(box)).toHaveValue('Lisbon')

        // The keyboard is not allowed to do it either — `autocapitalize="characters"`
        // was the same shouting one layer down, where no watcher of ours can see it.
        await expect(page.locator(box)).toHaveAttribute('autocapitalize', 'none')
    }

    /*
     * And a code typed in lower case is still a code — the capitals moved to the
     * boundary rather than being dropped. The button going live is that boundary
     * being read: `canSubmit` tests the NORMALISED value, not the field.
     *
     * Asserted rather than pressed, deliberately: a look-up navigates to a
     * screen that prices the pair, which would create a route this test has no
     * business creating. Search.test.js holds the emit.
     */
    await page.locator(FROM).fill('ams')
    await page.locator(TO).fill('mad')

    await expect(page.locator(FROM)).toHaveValue('ams')
    await expect(page.getByRole('button', { name: 'Look up' })).toBeEnabled()
})
