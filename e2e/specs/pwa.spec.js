// =============================================================================
// The PWA surface — at the request level, deliberately
// =============================================================================
// NO SERVICE WORKER IS REGISTERED HERE AND NONE CAN BE. `navigator.serviceWorker`
// only exists in a secure context, and the sandbox is plain http on a loopback
// port under a hostname that is not `localhost` — so the browser does not offer
// the API at all. resources/js/lib/pwa.js feature-detects exactly that and does
// nothing, which is why no spec in this suite trips over a missing worker.
//
// What CAN be checked, and is the part that actually breaks, is what the three
// routes answer with. The trap is written out in the deploy runbook and it is
// worth repeating: the SPA catch-all in routes/web.php answers 200 text/html
// for every unclaimed path, so a status code proves nothing about any of these.
// If the fallback demotion in bootstrap/app.php ever stops applying, all three
// of these paths go on returning 200 — of the wrong thing. THE CONTENT TYPE IS
// THE ASSERTION.
// =============================================================================
import { expect, shot, test } from '../fixtures.js'

test('the manifest is a manifest', async ({ request }) => {
    const response = await request.get('/manifest.webmanifest')

    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toMatch(/application\/manifest\+json/)

    const manifest = await response.json()
    expect(manifest.name).toBe('Orbit')
    expect(manifest.short_name).toBe('Orbit')
    // config/orbit.php's dark `--bg`, which the shell's theme-color meta tag
    // reads from the same place.
    expect(manifest.theme_color).toBe('#0a0f1e')
    expect(manifest.icons.length).toBeGreaterThan(0)
})

test('the service worker is javascript, and it precaches this build', async ({ request }) => {
    const response = await request.get('/sw.js')

    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toMatch(/application\/javascript/)

    // Told never to be served from cache, because the browser revalidates it on
    // every navigation and a cached worker is a deploy that never lands.
    expect(response.headers()['cache-control']).toMatch(/no-cache/)
    expect(response.headers()['service-worker-allowed']).toBe('/')

    const source = await response.text()
    expect(source).toMatch(/PRECACHE/)

    // The worker names the CURRENT bundle. A stale hash here is `build:retain`
    // or the asset build having run in the wrong order — the deploy runbook's
    // step 4 warning, checkable before a deploy rather than after one.
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
    // No bundle, no fonts, no script — it is what the worker shows when the
    // network is gone, so a reference to anything it would have to fetch is the
    // bug (docs/API.md).
    expect(html).not.toMatch(/<script/i)
    expect(html).not.toMatch(/build\/assets/)

    await page.goto('/offline')
    await shot(page, 'offline')

    // ONE OF THE THREE COMMITTED BASELINES: a static page with no data, no
    // canvas and no clock on it, which is exactly what makes a pixel comparison
    // mean something.
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
