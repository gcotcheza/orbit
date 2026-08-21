// =============================================================================
// Create a rule (design/README.md §4)
// =============================================================================
// The screen where a sentence becomes criteria. `.env.e2e` pins
// ORBIT_NLP_PARSER=regex, so the reading below is the deterministic parser's and
// not a model's — the exact eight chips docs/API.md publishes for the design's
// own sentence, which tests/Unit/Infrastructure/RegexRuleTextParserTest already
// asserts server-side. What is checked HERE is the other half: that the eight
// arrive, in order, and that removing one re-reads the rest.
// =============================================================================
import { expect, shot, test } from '../fixtures.js'

// The design's own seeded sentence (design/README.md §4).
const SENTENCE =
    'cheap weekend somewhere sunny in spring, leaving Friday from any NL airport, under €80'

const MONTH_NAMES = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
]

/**
 * A month Orbit holds no fares for at all — found by ASKING, not by knowing.
 *
 * ===========================================================================
 * WHY THIS IS A PROBE AND NOT A MONTH NAME
 * ===========================================================================
 * The test below needs a date window that matches nothing, so that removing it
 * is the one edit that can move the banner off zero. It used to say "spring",
 * and the comment underneath said "the fake provider prices a 90-day forward
 * window, so in August there is not a single March fare" — which was true in
 * August, true in September, and false the moment the calendar rolled far
 * enough for March to be inside the window. A premise that expires is a test
 * that goes red on a date rather than on a change, and it takes an afternoon to
 * work out which.
 *
 * Worse, the number it depended on is not even this spec's: `poll.window_days`
 * is config, it has already widened once (three months to six), and it is being
 * widened again. Pinning any month here — March, or a bigger number of months
 * out — is the same bug with a longer fuse.
 *
 * SO THE SPEC ASKS THE APP. `GET /api/routes/{code}/calendar` answers an
 * out-of-window month with `days: []` and a 200 (docs/API.md: "Empty months are
 * a 200, not a 404"), so walking forward until a month comes back empty finds
 * the edge of whatever window this build actually polls. That empty month IS
 * the premise, stated directly rather than inferred from a constant.
 *
 * ONE ROUTE IS ENOUGH, and it is enough because the seeder polls every watched
 * route over the same `poll.window_days` — the window is a property of the
 * poller, not of a route. The month it finds is then past the end for all of
 * them, which is what "matches nothing" needs.
 *
 * `page.request` rather than `fetch` in the page: it carries the same session
 * cookie the browser has and does not disturb the screen under test.
 */
async function monthWithNoFares(page, code) {
    const today = new Date()

    // Two years is far past any window this app could grow; reaching the end of
    // it means the endpoint is answering something other than what is documented.
    for (let ahead = 1; ahead <= 24; ahead += 1) {
        const probe = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth() + ahead, 1))
        const month = `${probe.getUTCFullYear()}-${String(probe.getUTCMonth() + 1).padStart(2, '0')}`

        const response = await page.request.get(`/api/routes/${code}/calendar`, { params: { month } })
        expect(response.ok(), `the calendar endpoint refused ${month}`).toBe(true)

        const body = await response.json()

        if ((body.data?.days ?? []).length === 0) {
            return { name: MONTH_NAMES[probe.getUTCMonth()], month }
        }
    }

    throw new Error('every month for the next two years has fares in it — the poll window cannot be that wide')
}

// docs/API.md's "the exact reading of the design's own sentence", in the
// design's order: where from, how much, how long, which day, when, what for.
const EXPECTED_CHIPS = [
    ['From', 'AMS'],
    ['From', 'EIN'],
    ['From', 'DUS'],
    ['Max price', '€80'],
    ['Trip length', '2–3 nights'],
    ['Depart', 'Fridays'],
    ['Date window', 'Mar – May'],
    ['Vibe', '☀ Sunny'],
]

test('the design sentence is read back as its eight chips', async ({ page }) => {
    await page.goto('/create')

    await expect(page.locator('.screen__title')).toHaveText('New deal rule')

    // `fill` rather than `type`: the screen re-parses on a 500 ms debounce and
    // `/api/rules/parse` is throttled 20/min, so typing this sentence a
    // keystroke at a time would spend most of the budget on prefixes nobody
    // asked about.
    await page.locator('#rule-text').fill(SENTENCE)

    const chips = page.locator('.chips .chip')
    await expect(chips).toHaveCount(EXPECTED_CHIPS.length)

    // ORDER IS PART OF THE CONTRACT, not an accident of iteration —
    // docs/API.md says chips arrive in the design's order, so they are asserted
    // as a sequence rather than as a set.
    const read = await chips.evaluateAll((elements) =>
        elements.map((element) => [
            element.querySelector('.chip__category').textContent.trim(),
            element.querySelector('.chip__label').textContent.trim(),
        ]),
    )

    expect(read).toEqual(EXPECTED_CHIPS)

    /*
     * The banner underneath says what the rule matches right now, and there are
     * THREE correct wordings of that (docs/API.md):
     *
     *   - nothing yet, because the sweep has not priced the routes it named;
     *   - "at least N so far", because it has priced SOME of them — `partial`;
     *   - a final count with a cheapest fare, once every candidate is priced.
     *
     * The middle one is the honest phrasing this app was missing: the number
     * used to be stated as a total and then grew from 2 to 32 a minute later,
     * which reads as the app having been wrong when it was only busy.
     */
    await expect(page.locator('.banner')).toHaveText(
        /(At least \d+ match so far — Orbit is still pricing the rest|\d+ trips? match this right now — cheapest €\d+|Nothing matches yet)/,
    )

    await shot(page, 'create-rule')
})

test('removing a chip re-reads the rule and updates the match banner', async ({ page }) => {
    /*
     * REMOVE THE DATE WINDOW, AND SPECIFICALLY THAT ONE.
     *
     * It has to be the chip that makes this rule match NOTHING, so that dropping
     * it is unambiguously what moved the banner. The other seven are not:
     * measured against the running sandbox, removing the vibe, the departure day
     * or the price ceiling each leaves the count at 0 because the empty date
     * window dominates all three — and removing the trip length is documented as
     * changing nothing at all (docs/API.md: parsed and shown, not matched on). A
     * test that removed one of those would be asserting that a banner does not
     * change, and calling it a pass.
     *
     * Which month is empty is asked rather than assumed — see `monthWithNoFares`
     * for the dated premise that used to live here. The sentence is the design's
     * with its season swapped for that month, so the rule is still the same eight
     * chips and still the same shape of question.
     */
    const outside = await monthWithNoFares(page, 'AMS-LIS')
    const sentence = SENTENCE.replace('in spring', `in ${outside.name}`)

    await page.goto('/create')

    const chips = page.locator('.chips .chip')

    // The seed's own reading is waited for FIRST: until it lands, "no chips"
    // and "nothing parsed yet" are the same screen. Why: docs/E2E.md.
    await expect(chips).toHaveCount(8)

    await page.locator('#rule-text').fill('')
    await expect(chips).toHaveCount(0)

    await page.locator('#rule-text').fill(sentence)
    await expect(chips).toHaveCount(8)

    const banner = page.locator('.banner')
    await expect(banner).toBeVisible()
    const before = await banner.textContent()

    // Nothing matches, because nothing has a fare in that month at all. This is
    // the premise the rest of the test rests on, so it is asserted rather than
    // assumed — if the probe above ever stops finding a genuinely empty month,
    // THIS is the line that says so, instead of the banner comparison below
    // failing for a reason nobody can see.
    await expect(banner).toContainText('Nothing matches yet')

    /*
     * The chip's own label, read back rather than constructed: the parser writes
     * "Mar – May" for a range and its own short form for a single month, and
     * this test has no business knowing which. `chip__remove`'s accessible name
     * is `Remove {category} {label}` (RuleChip.vue).
     */
    const dateChip = chips.filter({ has: page.locator('.chip__category', { hasText: 'Date window' }) })
    const dateLabel = (await dateChip.locator('.chip__label').textContent()).trim()

    await page.getByRole('button', { name: `Remove Date window ${dateLabel}` }).click()

    // The chip itself first, then the count: if the removal ever stops working,
    // "the date window is still there" is a more useful failure than "8 is not 7".
    await expect(dateChip).toHaveCount(0)
    await expect(chips).toHaveCount(7)

    // The server folds the surviving chips back into criteria and re-matches —
    // the client is explicitly told not to reimplement that fold, so this is
    // the assertion that it did not have to.
    await expect
        .poll(async () => banner.textContent(), {
            message: 'the match banner never re-read after a chip was removed',
        })
        .not.toBe(before)

    // And it re-read to a REAL answer rather than to a different flavour of
    // nothing: a count that is not zero. Which of the two counted wordings it
    // gets depends on how much of the candidate set the sandbox has priced —
    // both carry the number, and neither is "nothing matches yet".
    await expect(banner).toHaveText(
        /(At least \d+ match so far|\d+ trips? match this right now — cheapest €\d+)/,
    )

    // Reset puts the removed chip back.
    await page.getByRole('button', { name: 'Reset' }).click()
    await expect(chips).toHaveCount(8)

    await shot(page, 'create-rule-chip-removed')
})

test('a sentence with nothing in it says so, and the CTA stays shut', async ({ page }) => {
    await page.goto('/create')

    await page.locator('#rule-text').fill('qqqq wwww eeee')

    await expect(page.locator('.empty')).toContainText(/could not read a trip out of that/i)
    await expect(page.getByRole('button', { name: /create rule/i })).toBeDisabled()
})
