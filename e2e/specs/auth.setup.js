// =============================================================================
// Sign in once, for everything that follows
// =============================================================================
// A `setup` project rather than a `globalSetup` function: this way it shows up
// in the report as a step that passed or failed, it gets the same fixtures,
// trace and screenshot-on-failure as a real test, and a broken login is
// reported as a broken login instead of as eight mysteriously failing specs.
//
// WHY THE SESSION IS SHARED AT ALL. `POST /login` is throttled 5/min keyed on
// email+ip (AppServiceProvider), and the throttle runs BEFORE validation. Eight
// specs signing in for themselves is eight attempts from one address in under a
// minute, and the fifth onwards is a 429 — a suite that fails the way a
// brute-forcer does. Only login.spec.js signs in for real, because signing in
// is what it is about.
// =============================================================================
import { expect, signIn, test as setup } from '../fixtures.js'
import { STORAGE_STATE } from '../paths.js'

setup('sign in and save the session', async ({ page, browserConsole }) => {
    // The boot probe. routes/web.php explains why a guest's `/api/me` is a 401
    // with a body rather than a redirect; Chromium writes every 4xx to the
    // console, so the ONE request that is meant to fail is named here.
    browserConsole.allow(/Failed to load resource.*401/)

    await signIn(page)

    // The globe is the landing screen, and its heading is the first thing that
    // proves the session took: the router's `beforeEach` sends anyone without
    // one straight back to /login.
    await expect(page).toHaveURL(/\/$/)
    await expect(page.locator('.home__greeting')).toBeVisible()

    await page.context().storageState({ path: STORAGE_STATE })
})
