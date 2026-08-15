<?php

declare(strict_types=1);

namespace App\Services\Pwa;

use Illuminate\Support\Facades\File;

/**
 * What the service worker precaches, and what "this build" means.
 *
 * ---------------------------------------------------------------------------
 * THE VITE MANIFEST IS THE SOURCE OF TRUTH, NOT A HAND-WRITTEN LIST
 *
 * A precache list written by hand is a list that is wrong one deploy later:
 * every filename in `public/build/assets` carries a content hash, so a worker
 * precaching `app-B1WR5ovF.js` after that build has been replaced installs
 * nothing and says nothing. The list is therefore READ from
 * `public/build/manifest.json` — the same file `@vite()` reads to write the
 * script tags, so the worker and the page cannot disagree about what the build
 * produced.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS PRECACHED: THE SHELL, AND ONLY BECAUSE IT IS SMALL
 *
 * Precaching happens on INSTALL — the first launch after a deploy, quite
 * possibly on mobile data — so every byte in this list is a byte downloaded
 * before the user has asked for anything. The rule is: the entry chunks and
 * what they cannot run without.
 *
 * Concretely (PR12's build, ~410 KB): the entry chunk, the two chunks it
 * imports STATICALLY (the rolldown runtime and Vue itself), the entry's
 * stylesheets, and the woff2 faces. Plus the handful of static URLs below.
 *
 * WHAT IS NOT, and this is the part that matters:
 *
 *   THE GLOBE. `globe.gl` is 1.88 MB minified — bigger than everything else
 *   this app ships put together — and `resources/js/Views/Home.vue` reaches it
 *   through a DYNAMIC import. That is why this class walks `imports` and never
 *   `dynamicImports`: the split the home screen already makes for the network
 *   is the same split the cache should make, and it means nothing has to name
 *   the globe chunk here to keep it out. A lazy view that gets its own chunk
 *   tomorrow is excluded by the same rule, with no edit.
 *
 *   THE EARTH TEXTURES. 2.5 MB in `public/globe/`, and not in the manifest at
 *   all — they are fetched by literal path when the globe first draws. The
 *   worker's runtime cache-first rule picks them up then, which is the moment
 *   the user has demonstrably asked for a planet.
 *
 *   THE `.woff` FALLBACKS. Every browser that can run a service worker has
 *   supported woff2 for a decade; caching both formats would double the font
 *   cost of an install to keep a copy nothing will ever request.
 *
 * The runtime cache-first rule for `/build/` picks up everything omitted here
 * the first time it is genuinely fetched, which is the right moment for all of
 * it.
 *
 * ---------------------------------------------------------------------------
 * THE VERSION is a hash of the manifest itself. It changes when, and only when,
 * the build output changes — so a deploy busts the cache and a
 * `docker compose restart` does not. It is also what makes the served `/sw.js`
 * bytes differ after a deploy, which is the only thing that makes a browser
 * treat it as a new worker at all.
 * ---------------------------------------------------------------------------
 */
final class BuildAssets
{
    /**
     * Everything precached that the build does not produce.
     *
     * The offline page is first because it is the one entry whose absence turns
     * the whole feature off. The icons are here rather than left to the OS
     * because they are also what the shell links to, and they are 21 KB.
     *
     * @var list<string>
     */
    public const STATIC_ASSETS = [
        '/offline',
        '/manifest.webmanifest',
        '/icon.svg',
        '/icons/icon-192.png',
        '/icons/icon-512.png',
        '/icons/apple-touch-icon-180.png',
    ];

    /**
     * @param  string|null  $manifestPath  the Vite manifest; defaults to the built one
     */
    public function __construct(private readonly ?string $manifestPath = null) {}

    /**
     * Absolute paths, from the site root, of every URL the worker precaches.
     *
     * @return list<string>
     */
    public function precacheUrls(): array
    {
        return [...self::STATIC_ASSETS, ...$this->entryUrls()];
    }

    /**
     * The entry chunks, their stylesheets, their fonts, and the chunks they
     * import statically — i.e. exactly what has to be on disk for the app to
     * boot.
     *
     * @return list<string>
     */
    public function entryUrls(): array
    {
        $manifest = $this->manifest();

        $urls = [];
        $visited = [];

        foreach ($manifest as $key => $chunk) {
            if (($chunk['isEntry'] ?? false) === true) {
                $this->collect($manifest, $key, $urls, $visited);
            }
        }

        // Vite lists an entry's stylesheet on the entry AND as its own manifest
        // key when the stylesheet is itself an input, so the same file arrives
        // twice. A duplicate would make the worker fetch it twice on install.
        return array_values(array_unique($urls));
    }

    /**
     * Short, stable, changes exactly when the build does.
     *
     * Falls back to a constant when there is no build yet — a fresh checkout
     * that has not run `npm run build` should still serve a coherent worker
     * rather than throw on a missing file.
     */
    public function version(): string
    {
        $path = $this->path();

        if (! File::exists($path)) {
            return 'no-build';
        }

        return substr((string) md5_file($path), 0, 12);
    }

    public function hasBuild(): bool
    {
        return File::exists($this->path());
    }

    /**
     * Add one chunk's URLs, then the URLs of everything it imports statically.
     *
     * `dynamicImports` IS NOT FOLLOWED — see the note at the top of the class.
     * `$visited` is keyed by manifest key rather than by URL because the graph
     * is a graph: the Vue chunk is imported by the entry and by every view, and
     * without this the walk would revisit it once per importer.
     *
     * @param  array<string, array<mixed, mixed>>  $manifest
     * @param  list<string>  $urls
     * @param  array<string, true>  $visited
     */
    private function collect(array $manifest, string $key, array &$urls, array &$visited): void
    {
        if (isset($visited[$key])) {
            return;
        }

        $visited[$key] = true;

        $chunk = $manifest[$key] ?? null;

        if (! is_array($chunk)) {
            return;
        }

        $file = $chunk['file'] ?? null;

        if (is_string($file)) {
            $urls[] = '/build/'.$file;
        }

        foreach ($this->strings($chunk['css'] ?? null) as $css) {
            $urls[] = '/build/'.$css;
        }

        foreach ($this->strings($chunk['assets'] ?? null) as $asset) {
            if (str_ends_with($asset, '.woff2')) {
                $urls[] = '/build/'.$asset;
            }
        }

        foreach ($this->strings($chunk['imports'] ?? null) as $import) {
            $this->collect($manifest, $import, $urls, $visited);
        }
    }

    /**
     * The manifest, with anything that is not a chunk dropped rather than
     * trusted: this file is read at request time, and a truncated one written
     * by a build that was killed halfway must not become a TypeError on /sw.js.
     *
     * @return array<string, array<mixed, mixed>>
     */
    private function manifest(): array
    {
        $path = $this->path();

        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode((string) File::get($path), true);

        if (! is_array($decoded)) {
            return [];
        }

        $chunks = [];

        foreach ($decoded as $key => $chunk) {
            if (is_string($key) && is_array($chunk)) {
                $chunks[$key] = $chunk;
            }
        }

        return $chunks;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    private function path(): string
    {
        return $this->manifestPath ?? public_path('build/manifest.json');
    }
}
