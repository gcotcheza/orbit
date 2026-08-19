// =============================================================================
// A fare that may already be gone, and the button that goes and asks
// =============================================================================
// ⚠ WHY THREE OF THESE FOUR TESTS INTERCEPT THE APP'S OWN API, WHEN NOTHING
//   ELSE IN THIS SUITE DOES: the sandbox cannot hold the states. Its fares are
//   minutes old (nothing can be demoted), it has no SERPAPI_KEY and must never
//   have one, and a Google that times out is not something a seeder can make.
//   The endpoint itself is proven in tests/Feature/LivePriceCheckTest against
//   recorded fixtures; what is left is that a browser DRAWS these documents.
//
// The fourth is the whole round trip, and it is the state this box is really
// in: no key, so no search, so a 503 with a sentence.
// =============================================================================
import { expect, shot, test } from '../fixtures.js'

/** Seeded by database/seeders/WatchlistSeeder — six routes, this is the first. */
const CODE = 'AMS-LIS'

/**
 * The document the screen is drawing, with the one fact this sandbox cannot
 * hold: a cheapest fare found three days ago that the SERVER judged
 * `mayBeGone`.
 *
 * ⚠ The advice is qualified here too, because App\Http\Resources\
 * RouteDetailResource qualifies it — a body with `mayBeGone` true and a callout
 * saying "lock it in" is not a document this API can produce.
 */
function ageTheCheapestFare(body) {
    const threeDaysAgo = new Date(Date.now() - 3 * 24 * 3_600_000).toISOString()

    body.data.cheapest = { ...body.data.cheapest, foundAt: threeDaysAgo, mayBeGone: true }

    /* The sentence RouteDetailResource::advice() builds, to the letter, so the
       screenshot is of the real callout rather than an approximation. */
    body.data.advice = {
        title: 'Cheap, but it may be gone',
        body:
            `€${body.data.cheapest.price} is ${body.data.price.pctBelow}% under this route’s usual price, ` +
            'and old enough that fares like it have usually sold. ' +
            'Check the live price before counting on it.',
        tone: 'warn',
    }

    return body
}

async function pretendTheFareIsThreeDaysOld(page) {
    await page.route(`**/api/routes/${CODE}`, async (route) => {
        const response = await route.fetch()

        await route.fulfill({ response, json: ageTheCheapestFare(await response.json()) })
    })
}

test('a stale, far-below-usual fare is demoted instead of shouted', async ({ page }) => {
    await pretendTheFareIsThreeDaysOld(page)

    await page.goto(`/route/${CODE}`)
    await expect(page.locator('.detail__code')).toHaveText('AMS → LIS')

    await expect(page.locator('.price__gone')).toHaveText('Seen 3 days ago — may be gone')

    /* The plain "Seen 3 days ago" line is REPLACED rather than joined. */
    await expect(page.locator('.price__seen')).toHaveCount(0)

    /*
     * ⚠ THE DEMOTION IS REAL AT THE PIXEL LEVEL, which is the half a class
     * assertion cannot make: a build that added the class and no style would
     * pass `toHaveClass` and change nothing a person sees.
     */
    const headline = await page.evaluate(() => {
        const style = getComputedStyle(document.querySelector('.price__value'))

        return { size: style.fontSize, colour: style.color }
    })

    expect(Number.parseFloat(headline.size)).toBeLessThan(42)

    const ordinary = await page.evaluate(() => getComputedStyle(document.body).getPropertyValue('--ink').trim())
    expect(headline.colour, 'the demoted headline is still drawn in the confident ink').not.toBe(ordinary)

    /* And the callout and the hand-off go quiet with it — both off the server's
       own judgement, not off anything this screen worked out. */
    await expect(page.locator('.callout__title')).toHaveText('Cheap, but it may be gone')
    await expect(page.getByRole('link', { name: /see this fare on aviasales/i })).toHaveClass(
        /booking__cta--secondary/,
    )

    await shot(page, 'route-detail-may-be-gone')
})

test('the live price takes the headline and Orbit’s own becomes context', async ({ page }) => {
    await pretendTheFareIsThreeDaysOld(page)

    await page.route(`**/api/routes/${CODE}/live-price`, async (route) => {
        const response = await route.fetch({ url: new URL(`/api/routes/${CODE}`, page.url()).href, method: 'GET' })

        /* ⚠ Aged here too: `route.fetch()` goes to the server directly rather
           than back through the handler above it. */
        const body = ageTheCheapestFare(await response.json())

        body.meta.liveCheck = {
            date: body.data.cheapest.date,
            lowest: 150,
            typicalLow: 90,
            typicalHigh: 260,
            level: 'typical',
            checkedAt: new Date().toISOString(),
        }

        /* What the server sends once Google has contradicted the cached fare,
           in the same words — €150 is well past `contradiction_percent`. */
        const day = new Date(`${body.data.cheapest.date}T00:00:00Z`)
        const when = `${day.getUTCDate()} ${day.toLocaleString('en-GB', { month: 'short', timeZone: 'UTC' })}`

        body.data.advice = {
            title: 'Google cannot find this fare',
            body:
                `Orbit has €${body.data.cheapest.price} cached; ` +
                `the cheapest Google can find for ${when} is €150. Treat the cached fare as gone.`,
            tone: 'warn',
        }

        await route.fulfill({ json: body, status: 200 })
    })

    await page.goto(`/route/${CODE}`)

    const cached = (await page.locator('.price__value').textContent()).trim()

    await page.getByRole('button', { name: 'Check live price' }).click()

    await expect(page.locator('.price__value')).toHaveText('€150')
    await expect(page.locator('.price__live')).toHaveText('Live on Google · checked just now')
    await expect(page.locator('.price__typical')).toHaveText('Google’s typical €90–€260')
    await expect(page.locator('.price__cached')).toHaveText(
        new RegExp(`^Orbit’s cached fare ${cached}, seen 3 days ago$`),
    )

    await expect(page.locator('.price__value')).not.toHaveClass(/price__value--gone/)

    /* No second tap to sell: the server serves this answer free for six hours. */
    await expect(page.getByRole('button', { name: 'Check live price' })).toHaveCount(0)

    await expect(page.locator('.callout__title')).toHaveText('Google cannot find this fare')
    await expect(page.getByRole('link', { name: /see this fare on aviasales/i })).toHaveClass(
        /booking__cta--secondary/,
    )

    await shot(page, 'route-detail-live-checked')
})

/**
 * ⚠ A CHECK THAT COULD NOT BE MADE COST NOTHING, SO THE BUTTON STAYS. This is
 * the state the endpoint answers when SerpAPI itself is unreachable: no row is
 * written, no cooldown starts, and the offer to try again is honest.
 */
test('a Google that could not be reached leaves the offer standing', async ({ page, browserConsole }) => {
    browserConsole.allow(/Failed to load resource.*status of 503/)

    await page.route(`**/api/routes/${CODE}/live-price`, async (route) => {
        await route.fulfill({
            status: 503,
            json: { message: 'Orbit could not reach Google just now. Nothing was spent — try again in a moment.' },
        })
    })

    await page.goto(`/route/${CODE}`)

    const before = (await page.locator('.price__value').textContent()).trim()

    await page.getByRole('button', { name: 'Check live price' }).click()

    await expect(page.locator('.live__error')).toHaveText(
        'Orbit could not reach Google just now. Nothing was spent — try again in a moment.',
    )

    await expect(page.getByRole('button', { name: 'Check live price' })).toBeEnabled()
    await expect(page.locator('.price__value')).toHaveText(before)
    await expect(page.locator('.price__live')).toHaveCount(0)

    await shot(page, 'route-detail-live-unreachable')
})

/**
 * The real round trip: this sandbox has no SERPAPI_KEY, which is the DEFAULT
 * state of the app, so the endpoint refuses without making a single request.
 */
test('with no budget it refuses, explains, and leaves the price alone', async ({ page, browserConsole }) => {
    /* A 503 from this endpoint is the app working exactly as configured. */
    browserConsole.allow(/Failed to load resource.*status of 503/)

    await page.goto(`/route/${CODE}`)

    const before = (await page.locator('.price__value').textContent()).trim()

    await page.getByRole('button', { name: 'Check live price' }).click()

    await expect(page.locator('.live__error')).toHaveText(
        'Orbit is holding its remaining live checks in reserve.',
    )

    await expect(page.locator('.price__value')).toHaveText(before)
    await expect(page.locator('.price__live')).toHaveCount(0)

    await shot(page, 'route-detail-live-reserved')
})
