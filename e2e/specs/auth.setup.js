// Sign in once, for everything that follows — a `setup` project rather than
// `globalSetup`, so a broken login reports as one failure (docs/E2E.md "The specs").
import { expect, signIn, test as setup } from '../fixtures.js'
import { STORAGE_STATE } from '../paths.js'

setup('sign in and save the session', async ({ page, browserConsole }) => {
    // The boot probe: a guest's `/api/me` is a 401, and Chromium logs every
    // 4xx, so the one request meant to fail is named here.
    browserConsole.allow(/Failed to load resource.*401/)

    await signIn(page)

    // The globe's heading proves the session took — the router sends anyone
    // without one straight back to /login.
    await expect(page).toHaveURL(/\/$/)
    await expect(page.locator('.home__greeting')).toBeVisible()

    await page.context().storageState({ path: STORAGE_STATE })
})
