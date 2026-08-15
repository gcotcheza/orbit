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

    // The banner underneath says what the rule matches right now. Either
    // wording is correct — a rule can legitimately match nothing until the
    // sweep has priced the routes it named (docs/API.md) — so this asserts it
    // says one of them rather than inventing a number.
    await expect(page.locator('.banner')).toHaveText(
        /(\d+ trips? match this right now — cheapest €\d+|Nothing matches yet)/,
    )

    await shot(page, 'create-rule')
})

test('removing a chip re-reads the rule and updates the match banner', async ({ page }) => {
    await page.goto('/create')
    await page.locator('#rule-text').fill(SENTENCE)

    const chips = page.locator('.chips .chip')
    await expect(chips).toHaveCount(8)

    const banner = page.locator('.banner')
    await expect(banner).toBeVisible()
    const before = await banner.textContent()

    /*
     * REMOVE THE DATE WINDOW, AND SPECIFICALLY THAT ONE.
     *
     * "Mar – May" is the chip that makes this rule match nothing: the fake
     * provider prices a 90-day forward window, so in August there is not a
     * single March fare in the database and the banner correctly reads
     * "Nothing matches yet". Dropping it opens the rule to every month Orbit
     * holds and the count moves off zero.
     *
     * The other seven do not. Measured against the running sandbox: removing
     * the vibe, the departure day or the price ceiling each leaves the count at
     * 0, because the empty date window dominates all three — and removing the
     * trip length is documented as changing nothing at all (docs/API.md: parsed
     * and shown, not matched on). A test that removed one of those would be
     * asserting that a banner does not change, and calling it a pass.
     */
    await page.getByRole('button', { name: 'Remove Date window Mar – May' }).click()

    await expect(chips).toHaveCount(7)
    await expect(page.locator('.chip__label', { hasText: 'Mar – May' })).toHaveCount(0)

    // The server folds the surviving chips back into criteria and re-matches —
    // the client is explicitly told not to reimplement that fold, so this is
    // the assertion that it did not have to.
    await expect
        .poll(async () => banner.textContent(), {
            message: 'the match banner never re-read after a chip was removed',
        })
        .not.toBe(before)

    // And it re-read to a REAL answer rather than to a different flavour of
    // nothing: a count, and a cheapest fare to go with it.
    await expect(banner).toHaveText(/\d+ trips? match this right now — cheapest €\d+/)

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
