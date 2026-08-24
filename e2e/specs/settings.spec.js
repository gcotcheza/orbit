// The "This app" card's line about the SerpAPI month (docs/BUSINESS-LOGIC.md §31). The
// sandbox is given no key, so the only truthful note here is "Not configured".
import { expect, test } from '../fixtures.js'

test('the alerts screen prints the Google checks left, or says why it cannot', async ({ page }) => {
    await page.goto('/alerts')
    await expect(page.locator('.screen__title')).toHaveText('Alerts')

    const row = page.locator('.set--app .row')

    await expect(row.locator('.row__title')).toHaveText('Google price checks')
    await expect(row.locator('.row__note')).toHaveText('Not configured')
})
