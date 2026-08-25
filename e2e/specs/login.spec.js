// Login — the only screen a guest can reach.
import { account, expect, shot, signIn, test } from '../fixtures.js'

// A guest, explicitly — throws away the session auth.setup.js saved, or
// `goto('/login')` bounces to Home before the spec can test anything.
test.use({ storageState: { cookies: [], origins: [] } })

test.beforeEach(async ({ browserConsole }) => {
    // The two failures that are the app working correctly: a guest's 401
    // on every page load, and a refused password's 422.
    browserConsole.allow(/Failed to load resource.*(401|422)/)
})

test('a wrong password is refused, in words, on the form', async ({ page }) => {
    await signIn(page, { password: 'not-the-password' })

    const error = page.getByRole('alert')
    await expect(error).toBeVisible()

    // Same message for a wrong address and a wrong password, so this only
    // asserts the sentence arrives, not which half was named.
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

    // The tab bar only exists on a signed-in layout — a second, independent
    // statement that this is the app, not the shell with a form in it.
    await expect(page.getByRole('navigation', { name: 'Primary' })).toBeVisible()
})

test('the empty login screen renders for a guest', async ({ page }) => {
    await page.goto('/login')
    await expect(page.locator('.login__title')).toHaveText('Orbit')

    // The pixels are phone-baselines.spec.js's, in both themes; this test owns
    // the screen a guest can reach (docs/E2E.md "Baselines vs artifacts").
    await shot(page, 'login')
})

test('the seeded account is the sandbox one, never production', async () => {
    // A guard on the harness itself: if `.env.e2e` ever carried a real
    // address, every trace would have its password typed into a form.
    expect(account.email).toMatch(/\.test$/)
    expect(account.email, 'the sandbox must never sign in as the real owner').toMatch(/@orbit\.test$/)
})
