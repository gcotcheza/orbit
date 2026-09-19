// Not ../fixtures.js: it fails a spec on any console error, and Chromium logs
// every policy violation as one — this spec causes one on purpose (S4).
import { expect, test } from '@playwright/test'

test('a deliberate inline script is refused by the served policy', async ({ page }) => {
    const violations = []

    await page.exposeFunction('__recordCspViolation', (violation) => {
        violations.push(violation)
    })

    // Before the first navigation, or the violation below is reported to nobody.
    await page.addInitScript(() => {
        document.addEventListener('securitypolicyviolation', (event) => {
            window.__recordCspViolation({
                violatedDirective: event.violatedDirective,
                blockedURI: event.blockedURI,
            })
        })
    })

    const response = await page.goto('/login')
    const headers = response.headers()

    expect
        .soft(headers['content-security-policy'], 'the sidecar must serve an enforcing policy')
        .toContain("script-src 'self'")
    expect
        .soft(headers['content-security-policy-report-only'], 'report-only reports and then permits')
        .toBeUndefined()

    await page.evaluate(() => {
        const script = document.createElement('script')
        script.textContent = 'window.__inlineScriptRan = true'
        document.head.appendChild(script)
    })

    await expect
        .poll(() => violations.some((violation) => violation.violatedDirective.includes('script-src')), {
            message: 'the browser policed nothing, so nothing was served',
        })
        .toBe(true)
    expect(
        await page.evaluate(() => window.__inlineScriptRan),
        'the script was reported and ran anyway, which is report-only, not enforcement'
    ).toBeUndefined()
})
