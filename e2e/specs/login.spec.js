// =============================================================================
// Login — the only screen a guest can reach
// =============================================================================
import { account, expect, shot, signIn, test } from '../fixtures.js'

// A GUEST, EXPLICITLY. Every other spec inherits the session auth.setup.js
// saved; this one is about not having it, so it throws the storage state away.
// Without this line the first `goto('/login')` would be bounced to Home by the
// router's own guard and the spec would test nothing.
test.use({ storageState: { cookies: [], origins: [] } })

test.beforeEach(async ({ browserConsole }) => {
    // The two failures on this screen that are the app working correctly:
    // `/api/me` answers 401 to a guest on every page load (routes/web.php), and
    // a refused password is a 422 (LoginController). Chromium writes both to
    // the console as "Failed to load resource"; nothing else is waived.
    browserConsole.allow(/Failed to load resource.*(401|422)/)
})

test('a wrong password is refused, in words, on the form', async ({ page }) => {
    await signIn(page, { password: 'not-the-password' })

    const error = page.getByRole('alert')
    await expect(error).toBeVisible()

    // The server deliberately gives the SAME message for a wrong address and a
    // wrong password — see LoginController — so this asserts the sentence
    // arrives, not that it names which half was wrong.
    await expect(error).not.toBeEmpty()

    // Still on the login screen, and the password box has been emptied so the
    // next attempt starts clean (Login.vue's catch does this).
    await expect(page).toHaveURL(/\/login/)
    await expect(page.locator('input[name="password"]')).toHaveValue('')

    await shot(page, 'login-refused')
})

test('the right password lands on the globe', async ({ page }) => {
    await signIn(page)

    await expect(page).toHaveURL(/\/$/)
    await expect(page.locator('.home__greeting')).toBeVisible()

    // The tab bar only exists on a signed-in layout (App.vue), so its presence
    // is a second, independent statement that this is the app and not the shell
    // with a login form in it.
    await expect(page.getByRole('navigation', { name: 'Primary' })).toBeVisible()
})

test('the empty login screen matches its baseline', async ({ page }) => {
    await page.goto('/login')
    await expect(page.locator('.login__title')).toHaveText('Orbit')

    // ONE OF THE THREE SCREENS WITH A COMMITTED BASELINE. It has no seeded
    // data on it, no canvas and no animation once `animations: 'disabled'` has
    // finished the pulse — so it is a real pixel promise rather than a
    // screenshot of today's fake fares. docs/E2E.md lists the other two.
    await expect(page).toHaveScreenshot('login.png', { fullPage: true })

    await shot(page, 'login')
})

test('the seeded account is the sandbox one, never production', async () => {
    // A guard on the harness itself rather than on the app: if `.env.e2e` ever
    // came to carry the owner's real address, every trace file in
    // e2e/artifacts/ would have their password typed into a form in it.
    expect(account.email).toMatch(/\.test$/)
    expect(account.email).not.toBe('ghie.cotcheza@gmail.com')
})
