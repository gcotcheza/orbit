<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pwa;

use Tests\TestCase;
use App\Services\Pwa\BuildAssets;
use PHPUnit\Framework\Attributes\Test;

/**
 * The precache list, read against a real Vite manifest.
 *
 * tests/Fixtures/vite-manifest.json is a trimmed copy of the one `npm run
 * build` actually produced — same keys, same shapes, same relationships — so
 * these assertions are about Vite's format rather than about a format invented
 * to make them pass. What matters most is what is NOT in the list: this is the
 * only place that decides whether a phone downloads 1.9 MB of globe on the
 * first launch after a deploy.
 */
final class BuildAssetsTest extends TestCase
{
    private function assets(): BuildAssets
    {
        return new BuildAssets(base_path('tests/Fixtures/vite-manifest.json'));
    }

    #[Test]
    public function the_entry_chunk_and_its_stylesheets_are_precached(): void
    {
        $urls = $this->assets()->precacheUrls();

        $this->assertContains('/build/assets/app-B1WR5ovF.js', $urls);
        $this->assertContains('/build/assets/app-CFkF99lj.css', $urls);
        $this->assertContains('/build/assets/app-Bgy56Azy.css', $urls);
    }

    /**
     * The entry chunk is useless without them — they are its `import`
     * statements — so caching one and not the others would buy a hit on the
     * cheapest file and a network round trip on the expensive one.
     */
    #[Test]
    public function chunks_the_entry_imports_statically_are_precached_too(): void
    {
        $urls = $this->assets()->precacheUrls();

        $this->assertContains('/build/assets/vue.esm-bundler-sbGJpPMH.js', $urls);
        $this->assertContains('/build/assets/rolldown-runtime-hePW80VL.js', $urls);
    }

    /**
     * THE ONE THAT MATTERS. globe.gl is 1.88 MB and reached through a dynamic
     * import from the home screen; precaching it would spend that on install,
     * on mobile data, for a chunk the runtime cache picks up the moment the
     * globe first draws.
     *
     * Nothing names it — the exclusion falls out of walking `imports` and never
     * `dynamicImports`, which is also what keeps every lazy view out.
     */
    #[Test]
    public function the_globe_and_the_lazy_views_are_not_precached(): void
    {
        $urls = $this->assets()->precacheUrls();

        $this->assertNotContains('/build/assets/globe.gl-XnNELMe5.js', $urls);
        $this->assertNotContains('/build/assets/Home-b7yxOmrZ.js', $urls);
        $this->assertNotContains('/build/assets/Home-mU4mzQZt.css', $urls);
    }

    /**
     * woff2 only. Every browser that can run a service worker has read woff2
     * for a decade, so caching the fallback doubles the font cost of an install
     * for a file nothing will request.
     */
    #[Test]
    public function fonts_are_precached_in_one_format(): void
    {
        $urls = $this->assets()->precacheUrls();

        $this->assertContains('/build/assets/space-grotesk-latin-700-normal-RjhwGPKo.woff2', $urls);
        $this->assertNotContains('/build/assets/space-grotesk-latin-700-normal-CwsQ-cCU.woff', $urls);
    }

    /**
     * The stylesheet is both an entry in its own right and a `css` entry on the
     * script, and the fonts hang off both. A duplicate would make the worker
     * fetch the same file twice on install.
     */
    #[Test]
    public function nothing_is_listed_twice(): void
    {
        $urls = $this->assets()->precacheUrls();

        $this->assertSame(array_values(array_unique($urls)), $urls);
    }

    /**
     * The offline page first: it is the entry whose absence turns the whole
     * feature off.
     */
    #[Test]
    public function the_static_shell_is_precached_ahead_of_the_build(): void
    {
        $urls = $this->assets()->precacheUrls();

        $this->assertSame('/offline', $urls[0]);
        $this->assertContains('/manifest.webmanifest', $urls);
        $this->assertContains('/icons/icon-192.png', $urls);
    }

    /**
     * The version IS the manifest's hash — that is what makes a deploy a new
     * cache and a restart the same one.
     */
    #[Test]
    public function the_version_is_a_stable_hash_of_the_manifest(): void
    {
        $version = $this->assets()->version();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $version);
        $this->assertSame($version, $this->assets()->version());
        $this->assertTrue($this->assets()->hasBuild());
    }

    /**
     * A fresh checkout that has never run `npm run build` still has to serve a
     * coherent worker: the precache list is then the static shell alone, and
     * the version says what happened rather than throwing.
     */
    #[Test]
    public function a_checkout_with_no_build_is_not_an_error(): void
    {
        $assets = new BuildAssets(base_path('tests/Fixtures/no-such-manifest.json'));

        $this->assertFalse($assets->hasBuild());
        $this->assertSame('no-build', $assets->version());
        $this->assertSame(BuildAssets::STATIC_ASSETS, $assets->precacheUrls());
    }

    /**
     * Anything that is not a build artefact is dropped rather than trusted:
     * this file is read at request time, and a manifest truncated by a build
     * that was killed halfway must not become a 500 on /sw.js.
     */
    #[Test]
    public function a_manifest_that_is_not_a_manifest_yields_the_static_shell(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'manifest').'.json';

        file_put_contents($path, '{"resources/js/app.js": "half a file');

        try {
            $this->assertSame(BuildAssets::STATIC_ASSETS, (new BuildAssets($path))->precacheUrls());
        } finally {
            @unlink($path);
        }
    }
}
