// T8 at phone width: every small control is hit over at least 44 x 44 px, measured by where taps
// actually land rather than by reading the stylesheet, and no hit area covers a neighbour.
import { expect, test } from '../fixtures.js'

const FINGER = 44

/**
 * How far a tap still lands on `locator`, through its centre, both ways: each reach is found by
 * bisection on `elementFromPoint`, so a neighbour drawn on top shortens it.
 */
async function hitArea(locator) {
    await expect(locator).toBeVisible()

    return locator.evaluate((el) => {
        el.scrollIntoView({ block: 'center', inline: 'center', behavior: 'instant' })

        const box = el.getBoundingClientRect()
        const cx = box.left + box.width / 2
        const cy = box.top + box.height / 2
        const lands = (x, y) => el.contains(document.elementFromPoint(x, y))

        const reach = (dx, dy) => {
            let inside = 0
            let outside = 60

            while (outside - inside > 1 / 1024) {
                const mid = (inside + outside) / 2

                if (lands(cx + dx * mid, cy + dy * mid)) {
                    inside = mid
                } else {
                    outside = mid
                }
            }

            return inside
        }

        const tenth = (px) => Math.round(px * 10) / 10

        return {
            width: tenth(reach(-1, 0) + reach(1, 0)),
            height: tenth(reach(0, -1) + reach(0, 1)),
        }
    })
}

async function expectFingerSized(locator, name) {
    const area = await hitArea(locator)

    expect(area.width, `${name}: hit width`).toBeGreaterThanOrEqual(FINGER)
    expect(area.height, `${name}: hit height`).toBeGreaterThanOrEqual(FINGER)
}

/** The centre and the middle of each edge of the control's own box still belong to it. */
async function expectUncovered(locator, name) {
    const covered = await locator.evaluate((el) => {
        const box = el.getBoundingClientRect()
        const cx = box.left + box.width / 2
        const cy = box.top + box.height / 2
        const points = [
            [cx, cy],
            [box.left + 1, cy],
            [box.right - 1, cy],
            [cx, box.top + 1],
            [cx, box.bottom - 1],
        ]

        return points.filter(([x, y]) => !el.contains(document.elementFromPoint(x, y)))
    })

    expect(covered, `${name}: points of its own box that land elsewhere`).toEqual([])
}

test('the watch row remove button is finger-sized and leaves the pause switch alone', async ({ page }) => {
    await page.goto('/watch')

    const row = page.locator('.pass').first()
    await expectFingerSized(row.locator('.stub__remove'), 'remove')
    await expectUncovered(row.getByRole('switch'), 'pause switch')
})

test('the month arrows are finger-sized and do not cover each other', async ({ page }) => {
    await page.goto('/calendar')

    const arrows = page.locator('.month-nav__button')
    await expect(arrows).toHaveCount(2)
    await expectFingerSized(arrows.first(), 'previous month')
    await expectFingerSized(arrows.last(), 'next month')
})

test('the home profile button is finger-sized', async ({ page }) => {
    await page.goto('/')

    await expectFingerSized(page.getByRole('link', { name: 'Your account and alert settings' }), 'profile')
})

test('the search pills and Add to watch are finger-sized', async ({ page }) => {
    await page.goto('/search')

    const pills = page.getByRole('group', { name: 'Home airports' }).getByRole('button')
    await expect(pills.first()).toBeVisible()
    expect(await pills.count()).toBeGreaterThan(1)

    for (const pill of await pills.all()) {
        await expectFingerSized(pill, `pill ${await pill.textContent()}`)
    }

    await expectFingerSized(page.getByRole('button', { name: 'Add to watch' }), 'Add to watch')
})

test('the segmented controls are finger-sized', async ({ page }) => {
    await page.goto('/alerts')

    const options = page.getByRole('radiogroup', { name: 'Theme' }).getByRole('radio')
    await expect(options.first()).toBeVisible()
    expect(await options.count()).toBeGreaterThan(1)

    for (const option of await options.all()) {
        await expectFingerSized(option, `theme ${await option.textContent()}`)
    }
})

// The toast only follows a real service-worker update; the browser here reports one waiting.
test('the update toast close is finger-sized', async ({ page }) => {
    await page.addInitScript(() => {
        const registration = { waiting: {}, addEventListener() {}, update: () => Promise.resolve() }

        Object.defineProperty(navigator, 'serviceWorker', {
            configurable: true,
            value: { controller: {}, register: () => Promise.resolve(registration), addEventListener() {} },
        })
    })
    await page.goto('/')

    await expectFingerSized(page.getByRole('button', { name: 'Dismiss' }), 'toast close')
})
