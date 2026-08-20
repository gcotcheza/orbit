/*
 * Search is the centre tab; most of this file moved from watchlist.spec.js unchanged, plus the FROM box.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
import { expect, shot, tab, test } from '../fixtures.js'

test.describe.configure({ mode: 'serial' })

const FROM = '#search-from'
const TO = '#search-to'

/** The suggestion panel belonging to one of the two boxes. */
const listbox = (page, name) => page.getByRole('listbox', { name })

/*
 * Journey test uses NRN (Weeze), an airport with no origin config, so typing it into From was a 422 before this screen.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
test('an airport nobody flies from is searched, priced, and then watched', async ({ page, browserConsole }) => {
    /*
     * The 404 from GET /api/routes/NRN-AGP is expected, not a fault — RouteDetail.vue doesn't log it, Chromium's network log does.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    browserConsole.allow(/Failed to load resource.*404/)

    await page.goto('/watch')
    await expect(page.locator('.pass')).toHaveCount(6)

    await tab(page, 'Search').click()

    await expect(page).toHaveURL(/\/search$/)
    await expect(page.locator('.screen__title')).toHaveText('Search')

    // A pill already answers "which airport" — the box below stays empty
    // and prompting rather than reading as a decision to undo.
    await expect(page.locator(FROM)).toHaveValue('')
    await expect(page.locator('.quick__chip[aria-pressed="true"]')).toHaveText('AMS')

    await shot(page, 'search-origin-default')

    await page.locator(FROM).fill('wee')

    const origins = listbox(page, 'Origin suggestions')
    const weeze = origins.getByRole('option').filter({ hasText: 'NRN' })

    /*
     * Weeze only appears via GET /api/airports?q=wee, not the 184 curated places — this row proves the world typeahead worked.
     * Why: docs/BUSINESS-LOGIC.md §36.
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

    await page.getByRole('button', { name: 'Look up' }).click()

    await expect(page).toHaveURL(/\/route\/NRN-AGP$/)
    await expect(page.locator('.detail__code')).toHaveText('NRN → AGP')
    await expect(page.locator('.price__value')).toHaveText(/^€\d+$/)

    await shot(page, 'search-lookup-nrn-agp')

    // The route did not exist a second ago and the watchlist still does not
    // know about it.
    await page.goto('/watch')
    await expect(page.locator('.pass')).toHaveCount(6)

    await page.goto('/route/NRN-AGP')
    await page.getByRole('button', { name: 'Watch this route' }).click()

    await expect(page.locator('.watch--on')).toContainText('On your watch list')

    await page.goto('/watch')
    await expect(page.locator('.pass')).toHaveCount(7)
    await expect(page.locator('.pass').filter({ hasText: 'NRN' })).toHaveCount(1)

    await shot(page, 'watchlist-with-nrn')

    const added = page.locator('.pass').filter({ hasText: 'NRN' }).first()
    await added.getByRole('button', { name: /stop watching/i }).click()
    await added.getByRole('button', { name: 'Remove' }).click()

    await expect(page.locator('.pass')).toHaveCount(6)
})

/*
 * Browser-only: the clear (x) needs real layout, must survive mousedown while a panel is open (@mousedown.prevent).
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
test('the origin is three pills and a box for anywhere else', async ({ page }) => {
    await page.goto('/search')

    const lit = page.locator('.quick__chip[aria-pressed="true"]')
    const clear = page.locator('.field__clear')
    const origins = listbox(page, 'Origin suggestions')

    await expect(page.locator(FROM)).toHaveValue('')
    await expect(page.locator(FROM)).toHaveAttribute('placeholder', 'Somewhere else? City or code…')
    await expect(page.locator(FROM)).toHaveAttribute('aria-label', 'Origin — any airport')
    await expect(lit).toHaveText('AMS')

    // Nothing to clear, so there is no ✕ to press.
    await expect(clear).toHaveCount(0)

    // Pill still counts as the origin with the box empty: only the
    // destination is missing, which is what these two assertions read off.
    await expect(page.getByRole('button', { name: 'Look up' })).toBeDisabled()
    await page.locator(TO).fill('AGP')
    await expect(page.getByRole('button', { name: 'Look up' })).toBeEnabled()

    await page.locator(FROM).fill('barcel')

    await expect(lit).toHaveCount(0)
    await expect(clear).toBeVisible()
    await expect(origins).toBeVisible()

    // On the field, inside it, at the end of the line — overflow or a low
    // position would read as belonging to the panel instead.
    const box = await page.locator(FROM).boundingBox()
    const cross = await clear.boundingBox()

    expect(cross.x).toBeGreaterThan(box.x)
    expect(cross.x + cross.width).toBeLessThanOrEqual(box.x + box.width)
    expect(cross.y).toBeGreaterThanOrEqual(box.y)
    expect(cross.y + cross.height).toBeLessThanOrEqual(box.y + box.height)

    await shot(page, 'search-origin-somewhere-else')

    // Panel is open when this is pressed: emptying the box closes it and
    // shifts everything below, which is the same focus race as the pill picks.
    await clear.click()

    await expect(page.locator(FROM)).toHaveValue('')
    await expect(origins).toBeHidden()
    await expect(lit).toHaveText('AMS')

    await page.locator(FROM).fill('barcel')
    await expect(origins).toBeVisible()

    await page.locator('.quick__chip', { hasText: 'DUS' }).click()

    await expect(page.locator(FROM)).toHaveValue('')
    await expect(lit).toHaveText('DUS')

    /*
     * Matched on the code element, not the row: Playwright's `hasText` is a case-insensitive substring, and "Dushanbe" contains "dus".
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    await page.locator(TO).fill('DUS')

    const codes = page.locator('#search-to-list .option__code')

    await expect(codes.filter({ hasText: 'DYU' })).toHaveCount(1)
    await expect(codes.filter({ hasText: 'DUS' })).toHaveCount(0)

    // DUS→DUS refusal proves the pill, not the box, is read as the origin —
    // pressed with the panel open again (same focus race).
    await page.getByRole('button', { name: 'Add to watch' }).click()

    await expect(page.locator('.search__error')).toHaveText('A route needs two different airports.')
})

/*
 * "Add to watch" stays on the search screen rather than navigating to the emptiest detail page a route can have.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
test('a route can be watched straight from the search screen', async ({ page }) => {
    await page.goto('/search')

    await page.locator(FROM).fill('AMS')
    await page.locator(TO).fill('MAD')

    // Pressed with the destination panel open, deliberately — the same
    // focus race the panel-close-on-mousedown defect could reintroduce.
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
 * Moved from watchlist.spec.js with the box it's about: curated matches paint instantly, world matches arrive from GET /api/airports?q= under a divider. Browser-only: AirportField.test.js already covers
 * the merge against a mock — this proves the real endpoint/ranking/table agree, and that the panel growing a second time doesn't reflow anything unpressable (docs/BUSINESS-LOGIC.md §36).
 */
test('the destination box finds a city by name, and the world under a divider', async ({ page }) => {
    await page.goto('/search')

    const field = page.locator(TO)
    const list = listbox(page, 'Destination suggestions')
    const divider = page.locator('#search-to-list .options__split')

    // An empty box suggests nothing at all.
    await expect(list).toBeHidden()

    await field.fill('bilb')

    await expect(list).toBeVisible()

    const bilbao = list.getByRole('option').first()
    await expect(bilbao).toContainText('Bilbao')
    await expect(bilbao).toContainText('BIO')
    await expect(bilbao).toContainText('Spain')

    // What was typed, bolded inside what was found, in the original spelling.
    await expect(bilbao.locator('b').first()).toHaveText('Bilb')

    await field.fill('new york')

    // JFK is curated — one of the 184 rule-engine places — so it sorts
    // first (docs/BUSINESS-LOGIC.md §1).
    const first = list.getByRole('option').first()
    await expect(first).toContainText('New York')
    await expect(first).toContainText('JFK')

    // LGA is not curated (OurAirports only, no rule will ever suggest it),
    // so it sits under the divider; this also proves the request happened.
    await expect(divider).toHaveText('Everywhere else Orbit can price')
    await expect(list.getByRole('option').filter({ hasText: 'LGA' })).toHaveCount(1)

    await shot(page, 'search-typeahead-world')

    await field.fill('newark')

    // Nothing curated matched, so there is nothing for a divider to divide.
    await expect(divider).toHaveCount(0)

    const newark = list.getByRole('option').first()
    await expect(newark).toContainText('Newark')
    await expect(newark).toContainText('EWR')
    // What was typed, bolded inside what the SERVER sent, in its own spelling.
    await expect(newark.locator('b').first()).toHaveText('Newark')

    // A click, not a keypress: this is the focus race, and anything
    // happening at all is the assertion that it was won.
    await newark.click()
    await expect(field).toHaveValue('EWR')
    await expect(list).toBeHidden()
})

// Only one panel open at a time is a layout fact tested here — see the
// flag on the form in Components/search/AirportField.vue.
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
     * Neither box offers what the other holds (no self-route) — "barcel" matches both BCN and BLA in the seed, so this proves exclusion, not a broken search.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    await page.locator(FROM).fill('BCN')
    await page.locator(TO).fill('barcel')

    await expect(destinations.getByRole('option').filter({ hasText: 'BLA' })).toHaveCount(1)
    await expect(destinations.getByRole('option').filter({ hasText: 'BCN' })).toHaveCount(0)
})

test('a code Orbit has no airport for is refused, beside the boxes', async ({ page, browserConsole }) => {
    /*
     * The one waived app-level console.error in the suite: Search.vue's `add` both shows and logs the refusal deliberately.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    browserConsole.allow(/Could not add a route/, /Failed to load resource.*422/)

    await page.goto('/search')

    // Digits aren't a code, so the model stays empty and both buttons stay
    // shut — the screen refuses to send a request the server would reject.
    await page.locator(TO).fill('12')
    await expect(page.getByRole('button', { name: 'Add to watch' })).toBeDisabled()

    /*
     * A hand-rolled :value/@input binding could leave stale digits in the element with no reactive change to catch it — hence a browser test, not vitest.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    await expect(page.locator(TO)).toHaveValue('')

    // And the ordinary path still works: letters are kept, as typed.
    await page.locator(TO).fill('l1s')
    await expect(page.locator(TO)).toHaveValue('ls')

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
 * Field used to uppercase every keystroke, wrong once it grew a typeahead; browser-only since `autocapitalize` isn't visible to vitest.
 * Why: docs/BUSINESS-LOGIC.md §36.
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

    // Lower-case code still counts: canSubmit reads the normalised value.
    // Asserted, not pressed — Look up would create a route (see Search.test.js).
    await page.locator(FROM).fill('ams')
    await page.locator(TO).fill('mad')

    await expect(page.locator(FROM)).toHaveValue('ams')
    await expect(page.getByRole('button', { name: 'Look up' })).toBeEnabled()
})

/*
 * Discoveries are seeded via DiscoverySeeder, which runs the real DiscoverDeals job against fake providers, not fixture rows.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
test.describe('deals from your airports', () => {
    test('the strip renders what the funnel actually found, in both themes', async ({ page }) => {
        await page.goto('/search')

        const strip = page.locator('.finds')
        await expect(strip).toBeVisible()

        await expect(strip.getByRole('heading', { name: 'Deals from your airports' })).toBeVisible()

        // The heading states when these were checked (05:20, against a
        // cache up to 3 days old) — without it the section implies a live check.
        await expect(strip.locator('.finds__note')).toContainText('Orbit found these on its own')

        const cards = strip.locator('.find')
        expect(await cards.count()).toBeGreaterThan(0)

        const first = cards.first()

        // A destination, a real euro price and a real departure day.
        await expect(first.locator('.find__city')).not.toBeEmpty()
        await expect(first.locator('.find__price')).toHaveText(/^€\d+$/)
        await expect(first.locator('.find__when')).toHaveText(/^\w{3}, \w{3} \d+$/)

        // No SERPAPI_KEY in the sandbox, so every badge must honestly say
        // unverified rather than showing an absent or unearned badge.
        await expect(first.locator('.find__badge')).toHaveAttribute('data-verified', 'false')
        await expect(first.locator('.find__badge')).toHaveText('Unverified')

        // And how old the price is, which a discovery always states.
        await expect(first.locator('.find__seen')).toHaveText(/^seen /)

        await shot(page, 'search-discoveries-dark')

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
     * A verified badge needs a real metered Google search (250/month), so /api/discoveries is stubbed here rather than the verdict written to the database.
     * Why: docs/BUSINESS-LOGIC.md §36.
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

        // Earned badge carries --good; unverified must NOT carry --warn,
        // since unverified is the normal state and a screen of yellow would mistrain.
        const tints = await page.evaluate(() => {
            const all = [...document.querySelectorAll('.finds .find__badge')]

            return all.map((badge) => getComputedStyle(badge).backgroundColor)
        })

        expect(tints[0]).not.toBe(tints[1])

        await shot(page, 'search-discoveries-badges-dark')

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
     * Relative lane reads REMEMBERED baselines: DiscoverySeeder measures a real sample of routes through PriceProvider before running the job.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    test('a relative find explains itself, in both themes', async ({ page }) => {
        await page.goto('/search')

        await expect(page.locator('.finds')).toBeVisible()

        const relative = page.locator('.finds .find').filter({ has: page.locator('.find__lane') })

        // Zero here is a real failure, not a flake — the seeder's baselines
        // and the fake's fares are both deterministic.
        expect(await relative.count()).toBeGreaterThan(0)

        const card = relative.first()

        await expect(card.locator('.find__lane')).toHaveText('Rare price for this route')

        // Lane text is a sibling of the route pair, not folded into one
        // string — keeps the navigation test below working on every card type.
        await expect(card.locator('.find__from')).toHaveText(/^[A-Z]{3} → [A-Z]{3}$/)
        await expect(card.locator('.find__price')).toHaveText(/^€\d+$/)

        // --info tint, not --good: a relative find isn't a BETTER find,
        // just a different kind, and green here would rank it above the absolute cards.
        const [lane, badge] = await Promise.all([
            card.locator('.find__lane').evaluate((el) => getComputedStyle(el).backgroundColor),
            card.locator('.find__badge').evaluate((el) => getComputedStyle(el).backgroundColor),
        ])

        expect(lane).not.toBe(badge)
        /* Not transparent — a token that failed to resolve would render as none. */
        expect(lane).not.toBe('rgba(0, 0, 0, 0)')

        await shot(page, 'search-discoveries-lanes-dark')

        await page.goto('/alerts')
        await page.getByRole('radiogroup', { name: 'Theme' }).getByRole('radio', { name: 'Light' }).click()
        await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')

        await page.goto('/search')

        const lightLane = page.locator('.finds .find .find__lane').first()
        await expect(lightLane).toBeVisible()

        // Both themes must define the token distinctly (light solid, dark
        // alpha) — identical tints here would mean one theme never got a value.
        const lightTint = await lightLane.evaluate((el) => getComputedStyle(el).backgroundColor)
        expect(lightTint).not.toBe(lane)
        expect(lightTint).not.toBe('rgba(0, 0, 0, 0)')

        await shot(page, 'search-discoveries-lanes-light')

        await page.goto('/alerts')
        await page.getByRole('radiogroup', { name: 'Theme' }).getByRole('radio', { name: 'Dark' }).click()
        await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
    })

    // A card just links into the ordinary /route/... lookup flow — this
    // feature added no booking link, no watch action, no second detail screen.
    test('tapping a discovery opens the ordinary route screen, priced', async ({ page, browserConsole }) => {
        // Same 404-then-lookup shape as the NRN journey above (this pair
        // has never been priced either) — Chromium logs the failed request; waived.
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
