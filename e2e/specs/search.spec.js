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

    /*
     * THE COMMON CASE IS ONE TAP AND IT IS ALREADY TAKEN — by the PILL. The box
     * under it is empty and prompting, which is what stops it reading as a
     * read-out of a decision somebody has to undo before they can type.
     */
    await expect(page.locator(FROM)).toHaveValue('')
    await expect(page.locator('.quick__chip[aria-pressed="true"]')).toHaveText('AMS')

    await shot(page, 'search-origin-default')

    // --- From: an airport only the world half knows --------------------------
    await page.locator(FROM).fill('wee')

    const origins = listbox(page, 'Origin suggestions')
    const weeze = origins.getByRole('option').filter({ hasText: 'NRN' })

    /*
     * IT ARRIVES FROM THE NETWORK, which is the point of the assertion. Nothing
     * in the browser has heard of Weeze until `GET /api/airports?q=wee`
     * answers — it is not one of the 184 curated places, so no rule will ever
     * suggest it and nothing paints it on the keystroke. Waiting for a row that
     * can only come from the server is waiting for the world half of the
     * typeahead to work at this end of the pair.
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
 * THE ORIGIN BLOCK — three pills, and a box that is not one of them
 * ============================================================================
 * The box used to arrive holding the lit pill's code. One value, two controls,
 * and a field that read as a READ-OUT: three capitals, no placeholder, and a
 * selection-and-delete before it looked typeable at all — while the To box
 * beside it, same component, empty and prompting, read as a field immediately.
 *
 * So the pills hold the origin and the box holds "somewhere else". Views/Search
 * .test.js already asserts every branch of which one wins. WHY THIS IS ALSO
 * HERE is three things jsdom is structurally unable to answer:
 *
 *   - THE ✕ IS ON THE FIELD, not under it. It is absolutely positioned inside
 *     the box, and the only way to know it is not sitting over the text or
 *     hanging off the end is to measure two rectangles a layout engine drew.
 *   - IT IS PRESSED WITH A PANEL OPEN, which is this screen's oldest defect
 *     class: the suggestions are in the flow, focusout fires on mousedown, and
 *     anything that closes the panel between press and release takes the button
 *     with it. The ✕ and the pills are both above the panel, so both must
 *     survive it — and `@mousedown.prevent` is the reason they do.
 *   - THE PLACEHOLDER IS THE WHOLE AFFORDANCE and a placeholder is a thing you
 *     look at.
 */
test('the origin is three pills and a box for anywhere else', async ({ page }) => {
    await page.goto('/search')

    const lit = page.locator('.quick__chip[aria-pressed="true"]')
    const clear = page.locator('.field__clear')
    const origins = listbox(page, 'Origin suggestions')

    // --- Empty, prompting, and already answered ------------------------------
    await expect(page.locator(FROM)).toHaveValue('')
    await expect(page.locator(FROM)).toHaveAttribute('placeholder', 'Somewhere else? City or code…')
    await expect(page.locator(FROM)).toHaveAttribute('aria-label', 'Origin — any airport')
    await expect(lit).toHaveText('AMS')

    // Nothing to clear, so there is no ✕ to press.
    await expect(clear).toHaveCount(0)

    /*
     * AND THE PILL IS THE ORIGIN WITH THE BOX EMPTY, which the buttons say:
     * a destination is the only half that was missing.
     *
     * Asserted rather than pressed — a look-up navigates to a screen that
     * PRICES the pair and would create a route this test has no business
     * creating. Search.test.js holds the navigation itself.
     */
    await expect(page.getByRole('button', { name: 'Look up' })).toBeDisabled()
    await page.locator(TO).fill('AGP')
    await expect(page.getByRole('button', { name: 'Look up' })).toBeEnabled()

    // --- Somewhere else, and the ✕ that comes with it ------------------------
    await page.locator(FROM).fill('barcel')

    await expect(lit).toHaveCount(0)
    await expect(clear).toBeVisible()
    await expect(origins).toBeVisible()

    /*
     * ON THE FIELD, INSIDE IT, AT THE END OF THE LINE. A ✕ that overflowed the
     * box or sat below it would look like a control belonging to the panel.
     */
    const box = await page.locator(FROM).boundingBox()
    const cross = await clear.boundingBox()

    expect(cross.x).toBeGreaterThan(box.x)
    expect(cross.x + cross.width).toBeLessThanOrEqual(box.x + box.width)
    expect(cross.y).toBeGreaterThanOrEqual(box.y)
    expect(cross.y + cross.height).toBeLessThanOrEqual(box.y + box.height)

    await shot(page, 'search-origin-somewhere-else')

    /*
     * PRESSED WITH THE PANEL OPEN. Emptying the box closes the panel underneath
     * it, so everything below the ✕ moves — and if the browser were allowed to
     * blur the input on mousedown, the form's focusout would close that panel
     * BEFORE the mouseup and this click would land on nothing.
     */
    await clear.click()

    await expect(page.locator(FROM)).toHaveValue('')
    await expect(origins).toBeHidden()
    await expect(lit).toHaveText('AMS')

    // --- Pills win on tap ----------------------------------------------------
    await page.locator(FROM).fill('barcel')
    await expect(origins).toBeVisible()

    await page.locator('.quick__chip', { hasText: 'DUS' }).click()

    await expect(page.locator(FROM)).toHaveValue('')
    await expect(lit).toHaveText('DUS')

    /*
     * AND DUS IS REALLY THE ORIGIN NOW, with nothing in the box to say so. Both
     * assertions below can only be reading it off the lit pill, and neither
     * touches the network.
     *
     * THE EXCLUSION FIRST. "DUS" matches Düsseldorf, Dushanbe and Lampedusa in
     * the seeded table, and only Düsseldorf goes — the rows that STAY are what
     * make the missing one mean something, exactly as in the pair test below.
     * Matched on the code element rather than the row, because Playwright's
     * `hasText` is a case-insensitive SUBSTRING and "Dushanbe" contains "dus".
     */
    await page.locator(TO).fill('DUS')

    const codes = page.locator('#search-to-list .option__code')

    await expect(codes.filter({ hasText: 'DYU' })).toHaveCount(1)
    await expect(codes.filter({ hasText: 'DUS' })).toHaveCount(0)

    /*
     * AND THEN THE REFUSAL, which is the same fact said by the buttons: DUS to
     * DUS is not a route, and the only place the From end can be reading DUS
     * from is the pill. Pressed with the panel open, so this is the focus race
     * one more time.
     */
    await page.getByRole('button', { name: 'Add to watch' }).click()

    await expect(page.locator('.search__error')).toHaveText('A route needs two different airports.')
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

    /*
     * AND NEITHER BOX OFFERS WHAT THE OTHER ONE IS HOLDING: a route from a
     * place to itself is not a route.
     *
     * THE SECOND ASSERTION IS WHAT MAKES THE FIRST ONE MEAN ANYTHING. "barcel"
     * matches two airports in the seeded table — BCN, and BLA, which is
     * Barcelona in Venezuela — so an empty panel would prove the exclusion had
     * eaten the search rather than one row of it. One goes, one stays.
     */
    await page.locator(FROM).fill('BCN')
    await page.locator(TO).fill('barcel')

    await expect(destinations.getByRole('option').filter({ hasText: 'BLA' })).toHaveCount(1)
    await expect(destinations.getByRole('option').filter({ hasText: 'BCN' })).toHaveCount(0)
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

/*
 * ============================================================================
 * DEALS FROM YOUR AIRPORTS — the strip below the form
 * ============================================================================
 * Routes nobody is watching, swept and verified by `orbit:discover` at 05:20
 * and seeded here by Database\Seeders\DiscoverySeeder — which runs the REAL
 * App\Jobs\DiscoverDeals against the fake sweep provider rather than writing
 * fixture rows. So what is photographed below came out of the actual funnel:
 * the same four thresholds, the same cross-sectional check against the same
 * PriceProvider the calendar uses. A hand-seeded row would have made this spec
 * a photograph of a shape rather than of a feature.
 *
 * WHY THE BROWSER, given DiscoveryCard.test.js already asserts every field:
 *
 *   - THE SECTION IS CONDITIONAL AND HAS NO EMPTY STATE. `v-if="finds.length"`
 *     with no skeleton is a deliberate choice (Views/Search.vue) and its
 *     failure mode is a screen that silently never grows a section. jsdom
 *     cannot tell "the fetch resolved and rendered nothing" from "the fetch
 *     never happened".
 *   - THE FORM MUST NOT MOVE. The strip arrives after the first paint, below
 *     two suggestion panels that are themselves in the flow. A section that
 *     reflowed the boxes upward while somebody was typing into them is the
 *     same class of defect the Add button had.
 *   - THE BADGE IS A COLOUR. `data-verified` is asserted in vitest; whether
 *     "unverified" reads as quiet rather than as a warning can only be seen.
 */
test.describe('deals from your airports', () => {
    test('the strip renders what the funnel actually found, in both themes', async ({ page }) => {
        await page.goto('/search')

        const strip = page.locator('.finds')
        await expect(strip).toBeVisible()

        await expect(strip.getByRole('heading', { name: 'Deals from your airports' })).toBeVisible()

        /*
         * THE HEADING SAYS WHEN, and that is not decoration: without it the
         * section implies the fares were checked when the screen opened, and
         * they were checked at 05:20 against a cache already up to three days
         * old.
         */
        await expect(strip.locator('.finds__note')).toContainText('Orbit found these on its own')

        const cards = strip.locator('.find')
        expect(await cards.count()).toBeGreaterThan(0)

        const first = cards.first()

        // A destination, a real euro price and a real departure day.
        await expect(first.locator('.find__city')).not.toBeEmpty()
        await expect(first.locator('.find__price')).toHaveText(/^€\d+$/)
        await expect(first.locator('.find__when')).toHaveText(/^\w{3}, \w{3} \d+$/)

        /*
         * THE SANDBOX HAS NO SERPAPI_KEY, so nothing here can be verified and
         * every badge must say so. This is the assertion that a missing key
         * produces an honest card rather than an absent one — or, worse, a
         * badge nobody earned.
         */
        await expect(first.locator('.find__badge')).toHaveAttribute('data-verified', 'false')
        await expect(first.locator('.find__badge')).toHaveText('Unverified')

        // And how old the price is, which a discovery always states.
        await expect(first.locator('.find__seen')).toHaveText(/^seen /)

        await shot(page, 'search-discoveries-dark')

        // --- The same strip, light ------------------------------------------
        await page.goto('/alerts')
        await page.getByRole('radiogroup', { name: 'Theme' }).getByRole('radio', { name: 'Light' }).click()
        await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')

        await page.goto('/search')
        await expect(page.locator('.finds .find').first()).toBeVisible()

        await shot(page, 'search-discoveries-light')

        // Back to dark for whatever runs next.
        await page.goto('/alerts')
        await page.getByRole('radiogroup', { name: 'Theme' }).getByRole('radio', { name: 'Dark' }).click()
        await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
    })

    /*
     * THE EARNED BADGE, WHICH THE SANDBOX CANNOT REACH ON ITS OWN.
     *
     * A verified card requires Google to agree, which requires a SERPAPI_KEY
     * and a real metered search out of a 250-a-MONTH allowance. Neither belongs
     * in a suite that runs twenty times an afternoon, and the owner's guardrails
     * (config/orbit.php, `serpapi`) exist precisely to stop a job from spending
     * that budget casually — a test harness has no business being the exception.
     *
     * So the ENDPOINT is stubbed rather than the verdict fabricated in the
     * database. The distinction matters: nothing here writes a claim Orbit could
     * later serve to a person. This is a photograph of how the client draws an
     * answer it may one day receive, and the answer's shape is docs/API.md's.
     */
    test('an earned badge looks earned, and an unearned one stays quiet', async ({ page }) => {
        await page.route('**/api/discoveries', async (route) => {
            const response = await route.fetch()
            const body = await response.json()

            const first = body.data[0]

            await route.fulfill({
                json: {
                    data: [
                        {
                            ...first,
                            verdict: {
                                verified: true,
                                label: 'Verified low by Google',
                                level: 'low',
                                googleLowest: 48,
                                typicalLow: 55,
                                typicalHigh: 175,
                            },
                        },
                        {
                            ...first,
                            code: 'AMS-AGP',
                            destination: { iata: 'AGP', city: 'Málaga', country: 'Spain' },
                            price: 36,
                            verdict: {
                                verified: false,
                                label: 'Unverified',
                                level: 'typical',
                                googleLowest: 70,
                                typicalLow: 55,
                                typicalHigh: 175,
                            },
                        },
                    ],
                    meta: { count: 2, discoveredAt: body.meta.discoveredAt },
                },
            })
        })

        await page.goto('/search')

        const badges = page.locator('.finds .find__badge')
        await expect(badges).toHaveCount(2)

        const earned = badges.first()
        await expect(earned).toHaveAttribute('data-verified', 'true')
        await expect(earned).toContainText('Verified low by Google')
        // The tick is what an earned verdict looks like; the other has none.
        await expect(earned.locator('svg')).toBeVisible()

        const quiet = badges.nth(1)
        await expect(quiet).toHaveAttribute('data-verified', 'false')
        await expect(quiet).toHaveText('Unverified')
        await expect(quiet.locator('svg')).toHaveCount(0)

        /*
         * THE COLOURS ARE THE POINT OF PHOTOGRAPHING THIS. The earned badge
         * carries the app's `--good` pair; the unverified one must NOT carry
         * `--warn`, because unverified is the ordinary state and a screen of
         * yellow labels would train the owner to distrust the whole strip.
         */
        const tints = await page.evaluate(() => {
            const all = [...document.querySelectorAll('.finds .find__badge')]

            return all.map((badge) => getComputedStyle(badge).backgroundColor)
        })

        expect(tints[0]).not.toBe(tints[1])

        await shot(page, 'search-discoveries-badges-dark')

        // --- Both badge states, light ---------------------------------------
        await page.goto('/alerts')
        await page.getByRole('radiogroup', { name: 'Theme' }).getByRole('radio', { name: 'Light' }).click()
        await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')

        await page.goto('/search')
        await expect(page.locator('.finds .find__badge')).toHaveCount(2)

        await shot(page, 'search-discoveries-badges-light')

        await page.goto('/alerts')
        await page.getByRole('radiogroup', { name: 'Theme' }).getByRole('radio', { name: 'Dark' }).click()
        await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
    })

    /*
     * ========================================================================
     * THE SECOND LANE — "rare price for this route"
     * ========================================================================
     * WHAT MAKES THIS HONEST RATHER THAN A PROP. The relative lane reads
     * REMEMBERED baselines, so `DiscoverySeeder` measures a sample of routes'
     * real windows through the ordinary PriceProvider before running the real
     * job — the fortnight of exploration a live box would have done three routes
     * at a time. Nothing is written by hand: the baseline is a real median of
     * the fake's own window and the discount is the fake's own swept sale price
     * against it. A hand-written "€110 usual" next to a €60 fare would make this
     * a photograph of a shape rather than of a feature, which is the trap the
     * seeder's docblock is mostly about.
     *
     * WHY THE BROWSER. jsdom already asserts the sentence (DiscoveryCard.test
     * .js); what it cannot see is whether "rare price for this route" reads as a
     * quiet aside or as a second badge competing with the verdict pill — and
     * whether the `--info` tint survives BOTH themes, which is the one thing
     * tokens.css can get wrong in a way no unit test notices.
     */
    test('a relative find explains itself, in both themes', async ({ page }) => {
        await page.goto('/search')

        await expect(page.locator('.finds')).toBeVisible()

        const relative = page.locator('.finds .find').filter({ has: page.locator('.find__lane') })

        /*
         * THE LANE HAS TO HAVE FOUND SOMETHING. If this is zero the flywheel is
         * not turning in the sandbox — which is a real failure and not a flaky
         * one, because the seeder's baselines and the fake's fares are both
         * deterministic.
         */
        expect(await relative.count()).toBeGreaterThan(0)

        const card = relative.first()

        await expect(card.locator('.find__lane')).toHaveText('Rare price for this route')

        /*
         * AND THE ROUTE PAIR IS STILL THERE, IN ITS OWN ELEMENT. The lane line is
         * a sibling rather than text folded into the eyebrow, which is what keeps
         * the navigation test below working on every card type.
         */
        await expect(card.locator('.find__from')).toHaveText(/^[A-Z]{3} → [A-Z]{3}$/)
        await expect(card.locator('.find__price')).toHaveText(/^€\d+$/)

        /*
         * THE TINT IS THE `--info` PAIR AND NOT THE VERDICT'S `--good`. A
         * relative find is not a BETTER find, it is a different kind of one, and
         * a green tag here would rank it against the absolute cards above it.
         */
        const [lane, badge] = await Promise.all([
            card.locator('.find__lane').evaluate((el) => getComputedStyle(el).backgroundColor),
            card.locator('.find__badge').evaluate((el) => getComputedStyle(el).backgroundColor),
        ])

        expect(lane).not.toBe(badge)
        /* Not transparent — a token that failed to resolve would render as none. */
        expect(lane).not.toBe('rgba(0, 0, 0, 0)')

        await shot(page, 'search-discoveries-lanes-dark')

        // --- The same strip, light -------------------------------------------
        await page.goto('/alerts')
        await page.getByRole('radiogroup', { name: 'Theme' }).getByRole('radio', { name: 'Light' }).click()
        await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')

        await page.goto('/search')

        const lightLane = page.locator('.finds .find .find__lane').first()
        await expect(lightLane).toBeVisible()

        /* BOTH THEMES DEFINE THE TOKEN. The light theme's --info-bg is a solid
           and the dark theme's is an alpha, so these must differ — a tag that
           looked identical in both would mean one theme never got a value. */
        const lightTint = await lightLane.evaluate((el) => getComputedStyle(el).backgroundColor)
        expect(lightTint).not.toBe(lane)
        expect(lightTint).not.toBe('rgba(0, 0, 0, 0)')

        await shot(page, 'search-discoveries-lanes-light')

        await page.goto('/alerts')
        await page.getByRole('radiogroup', { name: 'Theme' }).getByRole('radio', { name: 'Dark' }).click()
        await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
    })

    /*
     * TAPPING A DISCOVERY IS THE REUSE, AND IT IS THE WHOLE INTEGRATION STORY.
     * A card links into `/route/AMS-AGP` — the existing lookup flow, which
     * prices the pair, creates the route row and offers the watch button. This
     * feature added no booking link, no watch action and no second detail
     * screen, and this is the test that says so.
     */
    test('tapping a discovery opens the ordinary route screen, priced', async ({ page, browserConsole }) => {
        /*
         * Orbit has never priced this pair — a discovery is by definition a
         * route nobody watches — so the detail screen's read is a 404 and the
         * lookup it makes next is the answer (docs/API.md). Chromium logs the
         * failed request; that one line is what this waives.
         */
        browserConsole.allow(/Failed to load resource.*404/)

        await page.goto('/search')

        const card = page.locator('.finds .find').first()
        const code = await card.locator('.find__from').textContent()
        const [origin, destination] = code.split('→').map((part) => part.trim())

        await card.click()

        await expect(page).toHaveURL(new RegExp(`/route/${origin}-${destination}$`))
        await expect(page.locator('.detail__code')).toHaveText(`${origin} → ${destination}`)

        // The lookup really priced it, which is what makes the card worth tapping.
        await expect(page.locator('.price__value')).toHaveText(/^€\d+$/)

        await shot(page, 'search-discovery-opened')

        // And the watchlist is untouched: looking is not committing.
        await page.goto('/watch')
        await expect(page.locator('.pass')).toHaveCount(6)
    })
})
