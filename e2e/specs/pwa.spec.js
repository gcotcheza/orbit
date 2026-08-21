// The PWA surface, at the request level — no service worker can register on
// plain http, so the content type is the assertion (docs/BUSINESS-LOGIC.md §36).
import { expect, shot, test } from '../fixtures.js'

test('the manifest is a manifest', async ({ request }) => {
    const response = await request.get('/manifest.webmanifest')

    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toMatch(/application\/manifest\+json/)

    const manifest = await response.json()
    expect(manifest.name).toBe('Orbit')
    expect(manifest.short_name).toBe('Orbit')
    // config/orbit.php's dark `--bg`, same source as the shell's meta tag.
    expect(manifest.theme_color).toBe('#0a0f1e')
    expect(manifest.icons.length).toBeGreaterThan(0)
})

test('the service worker is javascript, and it precaches this build', async ({ request }) => {
    const response = await request.get('/sw.js')

    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toMatch(/application\/javascript/)

    // Never served from cache — a cached worker is a deploy that never lands.
    expect(response.headers()['cache-control']).toMatch(/no-cache/)
    expect(response.headers()['service-worker-allowed']).toBe('/')

    const source = await response.text()
    expect(source).toMatch(/PRECACHE/)

    // The worker names the CURRENT bundle — a stale hash is checkable here
    // before a deploy rather than after one.
    const named = source.match(/app-[A-Za-z0-9_-]+\.js/)
    expect(named, 'the service worker precaches no app bundle at all').not.toBeNull()

    const shell = await (await request.get('/')).text()
    expect(shell).toContain(named[0])
})

test('the offline page is a page, and it needs nothing to render', async ({ page, request }) => {
    const response = await request.get('/offline')

    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toMatch(/text\/html/)

    const html = await response.text()
    // No bundle, no fonts, no script — the network is gone when this shows.
    expect(html).not.toMatch(/<script/i)
    expect(html).not.toMatch(/build\/assets/)

    await page.goto('/offline')
    await shot(page, 'offline')

    // One of the three committed baselines (docs/E2E.md "Baselines vs artifacts").
    await expect(page).toHaveScreenshot('offline.png', { fullPage: true })
})

test('the shell declares the manifest and the theme colour', async ({ page }) => {
    await page.goto('/')

    await expect(page.locator('link[rel="manifest"]')).toHaveAttribute(
        'href',
        '/manifest.webmanifest',
    )
    await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute('content', '#0a0f1e')
    await expect(page.locator('link[rel="apple-touch-icon"]')).toHaveAttribute(
        'href',
        '/icons/apple-touch-icon-180.png',
    )
})
