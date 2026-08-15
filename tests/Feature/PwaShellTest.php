<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Pwa\ManifestController;
use App\Http\Controllers\Pwa\OfflineController;
use App\Http\Controllers\Pwa\ServiceWorkerController;
use App\Services\Pwa\BuildAssets;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The three routes that make Orbit installable, and the three ways they break.
 *
 * They break by being SHADOWED — routes/web.php ends in a catch-all that
 * answers almost everything with the SPA shell, and a manifest served as HTML
 * installs a bookmark while a worker served as HTML fails registration with a
 * MIME error nobody reads.
 *
 * They break by being SESSIONED — a browser revalidates /sw.js on every
 * navigation, and inside the `web` group each of those writes a `sessions` row
 * and answers with a Set-Cookie, which also stops the edge caching any of them.
 *
 * They break by being WRONG ABOUT THE BUILD — a precache list naming last
 * deploy's hashes installs nothing, silently.
 *
 * All three are invisible in a browser that already has a working install,
 * which is why they are asserted here.
 */
final class PwaShellTest extends TestCase
{
    /**
     * A build the test controls, so these assertions do not depend on whether
     * `npm run build` has been run in this checkout.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(
            BuildAssets::class,
            new BuildAssets(base_path('tests/Fixtures/vite-manifest.json')),
        );
    }

    /**
     * @return array<string, array{string, class-string}>
     */
    public static function pwaRoutes(): array
    {
        return [
            'manifest' => ['/manifest.webmanifest', ManifestController::class],
            'service worker' => ['/sw.js', ServiceWorkerController::class],
            'offline page' => ['/offline', OfflineController::class],
        ];
    }

    /**
     * The same three, for the assertions that only care where they point.
     *
     * @return array<string, array{string}>
     */
    public static function pwaPaths(): array
    {
        return array_map(static fn (array $route): array => [$route[0]], self::pwaRoutes());
    }

    /**
     * The SPA catch-all is registered before these routes are (bootstrap/
     * app.php loads them from `then:`), so without the fallback demotion in
     * that file every one of them would be a 200 of HTML — which is the failure
     * that looks most like success.
     *
     * @param  class-string  $controller
     */
    #[Test]
    #[DataProvider('pwaRoutes')]
    public function each_route_reaches_its_controller_and_not_the_spa_shell(string $path, string $controller): void
    {
        $response = $this->get($path);

        $response->assertOk();
        $response->assertDontSee('<div id="app">', false);

        $this->assertStringStartsWith(
            $controller,
            (string) $this->app->make('router')->getRoutes()->match(Request::create($path, 'GET'))->getAction('controller'),
        );
    }

    /**
     * None of the three is in the `web` group, so none of them may start a
     * session. A Set-Cookie here would be a `sessions` row per navigation and
     * an uncacheable response at Cloudflare.
     */
    #[Test]
    #[DataProvider('pwaPaths')]
    public function no_route_hands_back_a_cookie(string $path): void
    {
        $response = $this->get($path);

        $this->assertSame([], $response->headers->getCookies());
        $this->assertNull($response->headers->get('Set-Cookie'));
    }

    #[Test]
    public function the_manifest_is_served_as_a_manifest(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $response->assertOk();

        // Not application/json, and above all not the octet-stream nginx would
        // fall back to: that is a download rather than an install.
        $this->assertSame('application/manifest+json', $response->headers->get('Content-Type'));

        // Asserted directive by directive: Symfony normalises and re-orders the
        // header it sends, so comparing the whole string tests its formatting
        // rather than our policy.
        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
        $this->assertSame('3600', $response->headers->getCacheControlDirective('max-age'));
    }

    #[Test]
    public function the_manifest_describes_an_installed_orbit(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $response->assertJson([
            'name' => 'Orbit',
            'short_name' => 'Orbit',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'theme_color' => '#0a0f1e',
            'background_color' => '#0a0f1e',
        ]);
    }

    /**
     * The colours come from config/orbit.php so that the manifest and the
     * shell's `theme-color` meta cannot drift; this is what says they have not.
     */
    #[Test]
    public function the_manifest_and_the_shell_agree_about_the_colour(): void
    {
        $theme = (string) config('orbit.pwa.theme_color');

        $this->get('/manifest.webmanifest')->assertJson(['theme_color' => $theme]);

        $this->withoutVite()->get('/')->assertSee('name="theme-color" content="'.$theme.'"', false);
    }

    /**
     * Five icons: the SVG for anything that can rasterise for itself, two PNGs
     * for the rest, and two SEPARATE maskable renderings — one file declared
     * `any maskable` is either clipped on Android or wastefully small
     * everywhere else.
     */
    #[Test]
    public function the_manifest_declares_icons_that_exist_on_disk(): void
    {
        $manifest = $this->get('/manifest.webmanifest')->json();

        $this->assertIsArray($manifest);
        $icons = $manifest['icons'] ?? [];
        $this->assertIsArray($icons);
        $this->assertCount(5, $icons);

        $purposes = [];

        foreach ($icons as $icon) {
            $this->assertIsArray($icon);
            $src = $icon['src'] ?? '';
            $this->assertIsString($src);
            $this->assertFileExists(public_path(ltrim($src, '/')), "{$src} is declared but not on disk");

            $purposes[] = $icon['purpose'] ?? null;
        }

        $this->assertContains('maskable', $purposes);
    }

    #[Test]
    public function the_shell_links_the_manifest_and_the_home_screen_icon(): void
    {
        $response = $this->withoutVite()->get('/');

        $response->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false);
        $response->assertSee('rel="apple-touch-icon" href="/icons/apple-touch-icon-180.png"', false);
    }

    #[Test]
    public function the_service_worker_is_served_as_a_script_that_may_not_be_cached(): void
    {
        $response = $this->get('/sw.js');

        $response->assertOk();

        $this->assertSame('application/javascript; charset=utf-8', $response->headers->get('Content-Type'));

        // A worker cached for any length of time is an app that cannot be
        // updated, and `.js` is on Cloudflare's default-cacheable list.
        $this->assertTrue($response->headers->hasCacheControlDirective('no-cache'));
        $this->assertTrue($response->headers->hasCacheControlDirective('must-revalidate'));

        $this->assertSame('/', $response->headers->get('Service-Worker-Allowed'));
    }

    /**
     * The whole reason /sw.js is a controller: the list names THIS build's
     * content-hashed files, and nothing else.
     */
    #[Test]
    public function the_service_worker_carries_the_current_builds_precache_list(): void
    {
        $script = $this->serviceWorker();

        $this->assertStringNotContainsString('__SW_PRECACHE__', $script);
        $this->assertStringNotContainsString('__SW_VERSION__', $script);

        $this->assertStringContainsString('"/build/assets/app-B1WR5ovF.js"', $script);
        $this->assertStringContainsString('"/build/assets/vue.esm-bundler-sbGJpPMH.js"', $script);
        $this->assertStringContainsString('"/offline"', $script);

        // 1.88 MB, reached by dynamic import. See BuildAssetsTest.
        $this->assertStringNotContainsString('globe.gl-XnNELMe5.js', $script);

        // The cache name has to move with the build or a deploy leaves the old
        // one in place.
        $version = (new BuildAssets(base_path('tests/Fixtures/vite-manifest.json')))->version();
        $this->assertStringContainsString("const VERSION = '{$version}'", $script);
    }

    /**
     * The update check a browser makes on EVERY navigation. With the ETag it is
     * an empty 304; without it, it is this file again, several times a minute,
     * for the life of the install.
     */
    #[Test]
    public function the_service_worker_answers_a_revalidation_with_304(): void
    {
        $first = $this->get('/sw.js');

        $etag = $first->headers->get('ETag');

        $this->assertNotNull($etag);

        $second = $this->withHeaders(['If-None-Match' => $etag])->get('/sw.js');

        $second->assertStatus(304);
        $this->assertSame('', $second->content());
    }

    /**
     * A different build is a different file, or the phone keeps the old app.
     */
    #[Test]
    public function a_new_build_is_a_new_worker(): void
    {
        $before = $this->serviceWorker();

        $path = (string) tempnam(sys_get_temp_dir(), 'manifest');

        // The same manifest, one byte longer: a different hash, and therefore a
        // different cache name and different bytes on the wire.
        file_put_contents($path, file_get_contents(base_path('tests/Fixtures/vite-manifest.json'))."\n");

        try {
            $this->app->instance(BuildAssets::class, new BuildAssets($path));

            $this->assertNotSame($before, $this->serviceWorker());
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function the_offline_page_stands_on_its_own(): void
    {
        $response = $this->get('/offline');

        $response->assertOk();
        $response->assertSee('You&rsquo;re offline', false);

        // No bundle, no font file, no image request: every one of those is a
        // dependency that is unavailable in exactly the conditions this page
        // exists for. And no script at all — the CSP forbids an inline one.
        $response->assertDontSee('/build/', false);
        $response->assertDontSee('<script', false);
    }

    #[Test]
    public function the_offline_page_may_be_cached_because_it_holds_no_data(): void
    {
        $response = $this->get('/offline');

        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
        $this->assertSame('86400', $response->headers->getCacheControlDirective('max-age'));
    }

    private function serviceWorker(): string
    {
        $response = $this->get('/sw.js');

        $response->assertOk();

        return $response->content();
    }
}
