<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pwa;

use Tests\TestCase;
use App\Services\Pwa\BuildAssets;
use PHPUnit\Framework\Attributes\Test;

/**
 * Precache list tested against a real (trimmed) Vite manifest fixture, since this decides how much a phone downloads
 * on first launch after a deploy (docs/BUSINESS-LOGIC.md §35).
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
     * Entry chunk's statically-imported chunks must precache too, or caching the entry alone still costs a network round
     * trip for the rest (docs/BUSINESS-LOGIC.md §35).
     */
    #[Test]
    public function chunks_the_entry_imports_statically_are_precached_too(): void
    {
        $urls = $this->assets()->precacheUrls();

        $this->assertContains('/build/assets/vue.esm-bundler-sbGJpPMH.js', $urls);
        $this->assertContains('/build/assets/rolldown-runtime-hePW80VL.js', $urls);
    }

    /**
     * DO NOT precache dynamic imports (globe.gl, lazy views): walk `imports` only, never `dynamicImports`, or installs
     * balloon by ~1.9 MB on mobile (docs/BUSINESS-LOGIC.md §35).
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
     * woff2 only: every browser that can run a service worker reads woff2, so caching the .woff fallback doubles font
     * install cost for nothing (docs/BUSINESS-LOGIC.md §35).
     */
    #[Test]
    public function fonts_are_precached_in_one_format(): void
    {
        $urls = $this->assets()->precacheUrls();

        $this->assertContains('/build/assets/space-grotesk-latin-700-normal-RjhwGPKo.woff2', $urls);
        $this->assertNotContains('/build/assets/space-grotesk-latin-700-normal-CwsQ-cCU.woff', $urls);
    }

    /**
     * Stylesheet is both its own entry and a `css` entry on the script (fonts hang off both); must dedupe or the worker
     * double-fetches on install.
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
     * No build yet (fresh checkout) must still serve a coherent worker: the precache list falls back to the static shell
     * instead of throwing (docs/BUSINESS-LOGIC.md §35).
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
     * Untrusted manifest content must never 500 /sw.js: a build killed halfway can leave a truncated file, read live at
     * request time (docs/BUSINESS-LOGIC.md §35).
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
