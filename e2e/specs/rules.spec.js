// The screen where a sentence becomes criteria — the eight chips arrive, in
// order, and removing one re-reads the rest (docs/BUSINESS-LOGIC.md §11).
import { expect, fixedNow, shot, test } from '../fixtures.js'

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
 * A month Orbit holds no fares for at all — found by ASKING, not by knowing
 * (docs/E2E.md "Adding a spec").
 */
async function monthWithNoFares(page, code) {
    const today = new Date(fixedNow)

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

    // `fill`, not `type`: parse is throttled 20/min, and a keystroke at a
    // time would spend the budget on prefixes nobody asked about.
    await page.locator('#rule-text').fill(SENTENCE)

    const chips = page.locator('.chips .chip')
    await expect(chips).toHaveCount(EXPECTED_CHIPS.length)

    // Order is part of the contract, not an accident of iteration — asserted
    // as a sequence, not a set.
    const read = await chips.evaluateAll((elements) =>
        elements.map((element) => [
            element.querySelector('.chip__category').textContent.trim(),
            element.querySelector('.chip__label').textContent.trim(),
        ]),
    )

    expect(read).toEqual(EXPECTED_CHIPS)

    // Three correct wordings of the banner: nothing yet, "at least N so
    // far" (partial), or a final count with a price (docs/BUSINESS-LOGIC.md §11).
    await expect(page.locator('.banner')).toHaveText(
        /(At least \d+ match so far — Orbit is still pricing the rest|\d+ trips? match this right now — cheapest €\d+|Nothing matches yet)/,
    )

    await shot(page, 'create-rule')
})

test('removing a chip re-reads the rule and updates the match banner', async ({ page }) => {
    // Removes the date window specifically — the only chip that makes this
    // rule match NOTHING, so dropping it is unambiguously what moves the banner.
    const outside = await monthWithNoFares(page, 'AMS-LIS')
    const sentence = SENTENCE.replace('in spring', `in ${outside.name}`)

    await page.goto('/create')

    const chips = page.locator('.chips .chip')

    // The seed's own reading is waited for FIRST: until it lands, "no
    // chips" and "nothing parsed yet" are the same screen.
    await expect(chips).toHaveCount(8)

    await page.locator('#rule-text').fill('')
    await expect(chips).toHaveCount(0)

    await page.locator('#rule-text').fill(sentence)
    await expect(chips).toHaveCount(8)

    const banner = page.locator('.banner')
    await expect(banner).toBeVisible()
    const before = await banner.textContent()

    // The premise the rest of the test rests on, asserted rather than
    // assumed — if the probe stops finding an empty month, this line says so.
    await expect(banner).toContainText('Nothing matches yet')

    // The chip's own label, read back rather than constructed: the parser's
    // short form for a single month may differ from a range's.
    const dateChip = chips.filter({ has: page.locator('.chip__category', { hasText: 'Date window' }) })
    const dateLabel = (await dateChip.locator('.chip__label').textContent()).trim()

    await page.getByRole('button', { name: `Remove Date window ${dateLabel}` }).click()

    // The chip itself first, then the count: if the removal ever stops working,
    // "the date window is still there" is a more useful failure than "8 is not 7".
    await expect(dateChip).toHaveCount(0)
    await expect(chips).toHaveCount(7)

    // The server folds surviving chips back into criteria and re-matches —
    // the client is not meant to reimplement that fold.
    await expect
        .poll(async () => banner.textContent(), {
            message: 'the match banner never re-read after a chip was removed',
        })
        .not.toBe(before)

    // Re-read to a REAL answer, not a different flavour of nothing — either
    // wording carries a number, neither is "nothing matches yet".
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
