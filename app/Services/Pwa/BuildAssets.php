<?php

declare(strict_types=1);

namespace App\Services\Pwa;

use Illuminate\Support\Facades\File;

/**
 * What the service worker precaches, and what "this build" means: read from the live Vite
 * manifest, static imports only (docs/BUSINESS-LOGIC.md §35).
 */
final class BuildAssets
{
    /**
     * Everything precached that the build does not produce. The offline page is first
     * because its absence turns the whole feature off; the icons are 21 KB.
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
     * The entry chunks, their stylesheets, their fonts, and the chunks they import
     * statically — exactly what has to be on disk for the app to boot.
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

        // Vite lists an entry's stylesheet twice when it is itself an input; a duplicate
        // would make the worker fetch it twice on install.
        return array_values(array_unique($urls));
    }

    /**
     * Short, stable, changes exactly when the build does. Falls back to a constant when
     * there is no build yet, so a fresh checkout still serves a coherent worker.
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
     * `$visited` is keyed by manifest key because the import graph is a graph.
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
     * The manifest, with anything that is not a chunk dropped rather than trusted: a
     * half-written file must not become a TypeError on /sw.js.
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
