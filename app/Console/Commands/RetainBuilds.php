<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * `php artisan build:retain` — keep the last few builds' assets on disk,
 * delete the rest via a per-build ledger (mtime can't identify a build).
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * Default `--keep=3`: balances phone cache staleness against build count.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
final class RetainBuilds extends Command
{
    protected $signature = 'build:retain
                            {--keep=3 : How many builds to keep, counting the current one}
                            {--dir= : Build directory (default public/build)}
                            {--dry-run : List what would be deleted, delete nothing}';

    protected $description = 'Snapshot the current build and prune assets belonging to builds older than the last few';

    /** Where the per-build snapshots live, relative to the build directory. */
    private const LEDGER = 'builds';

    /** The only directory this command will ever delete a file from. */
    private const ASSETS = 'assets';

    public function handle(): int
    {
        $dirOption = $this->option('dir');

        $dir = is_string($dirOption) && $dirOption !== '' ? $dirOption : public_path('build');

        $keep = max(1, (int) $this->option('keep'));

        $manifestPath = $dir.'/manifest.json';

        if (! File::exists($manifestPath)) {
            // Not an error: deploy runs this right after build; a missing
            // manifest means build already failed and reported it.
            // Why: docs/BUSINESS-LOGIC.md §36.
            $this->components->warn("No build manifest at {$manifestPath} — nothing to retain.");

            return self::SUCCESS;
        }

        $version = substr((string) md5_file($manifestPath), 0, 12);

        $recorded = $this->record($dir, $version, $this->filesInManifest($manifestPath));

        $this->components->twoColumnDetail(
            "build {$version}",
            $recorded ? 'recorded' : 'already recorded',
        );

        [$retained, $dropped] = $this->pruneSnapshots($dir, $keep);

        $this->components->twoColumnDetail('keeping', implode(', ', $retained));

        foreach ($dropped as $old) {
            $this->components->twoColumnDetail("build {$old}", 'dropped');
        }

        $deleted = $this->pruneAssets($dir, $retained);

        $this->components->twoColumnDetail(
            $this->option('dry-run') ? 'would delete' : 'deleted',
            count($deleted).' orphaned asset file(s)',
        );

        foreach ($deleted as $file) {
            $this->line("    {$file}");
        }

        return self::SUCCESS;
    }

    /**
     * Every file the manifest points at: `file`, `css`, and `assets` chunks
     * (font faces live under `assets`), relative to the build dir.
     *
     * @return list<string>
     */
    private function filesInManifest(string $manifestPath): array
    {
        $decoded = json_decode((string) File::get($manifestPath), true);

        if (! is_array($decoded)) {
            return [];
        }

        $files = [];

        foreach ($decoded as $chunk) {
            if (! is_array($chunk)) {
                continue;
            }

            if (isset($chunk['file']) && is_string($chunk['file'])) {
                $files[] = $chunk['file'];
            }

            foreach (['css', 'assets'] as $key) {
                $extras = $chunk[$key] ?? [];

                if (! is_array($extras)) {
                    continue;
                }

                foreach ($extras as $extra) {
                    if (is_string($extra)) {
                        $files[] = $extra;
                    }
                }
            }
        }

        sort($files);

        return array_values(array_unique($files));
    }

    /**
     * Write the snapshot for this build, unless one already exists.
     *
     * Not overwritten on rerun: `recorded_at` orders the ledger, so
     * refreshing would bump this run ahead of a genuinely newer build.
     * Why: docs/BUSINESS-LOGIC.md §36.
     *
     * @param  list<string>  $files
     * @return bool whether a new snapshot was written
     */
    private function record(string $dir, string $version, array $files): bool
    {
        $ledger = $dir.'/'.self::LEDGER;

        File::ensureDirectoryExists($ledger);

        $path = $ledger.'/'.$version.'.json';

        if (File::exists($path)) {
            return false;
        }

        File::put($path, (string) json_encode([
            'version' => $version,

            // MICROSECONDS matter: two deploys can land in the same second;
            // at that resolution the sort ties and retention drops the wrong build.
            // Why: docs/BUSINESS-LOGIC.md §36.
            'recorded_at' => now()->format('Y-m-d\TH:i:s.uP'),

            'files' => $files,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return true;
    }

    /**
     * Keep the newest `$keep` snapshots; delete the rest.
     *
     * @return array{0: list<string>, 1: list<string>} retained versions, dropped versions
     */
    private function pruneSnapshots(string $dir, int $keep): array
    {
        $ledger = $dir.'/'.self::LEDGER;

        $snapshots = [];

        foreach (File::glob($ledger.'/*.json') ?: [] as $path) {
            $decoded = json_decode((string) File::get($path), true);

            $recordedAt = is_array($decoded) ? ($decoded['recorded_at'] ?? null) : null;

            $snapshots[] = [
                'version' => basename($path, '.json'),
                'path'    => $path,
                // The file's own timestamp is the fallback, for a snapshot
                // written by hand or truncated by a half-finished deploy.
                'at' => is_string($recordedAt) ? $recordedAt : date(DATE_ATOM, (int) File::lastModified($path)),
            ];
        }

        usort($snapshots, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        $retained = array_slice($snapshots, 0, $keep);
        $dropped = array_slice($snapshots, $keep);

        foreach ($dropped as $snapshot) {
            if (! $this->option('dry-run')) {
                File::delete($snapshot['path']);
            }
        }

        return [
            array_map(static fn (array $s): string => (string) $s['version'], $retained),
            array_map(static fn (array $s): string => (string) $s['version'], $dropped),
        ];
    }

    /**
     * Delete everything in `assets/` that no retained snapshot names.
     *
     * Scoped to `assets/` only — `public/build` also holds manifest.json
     * and the ledger; walking the whole tree risks deleting our own bookkeeping.
     * Why: docs/BUSINESS-LOGIC.md §36.
     *
     * A kept file also keeps its `.map` (Vite omits maps from the manifest).
     * Why: docs/BUSINESS-LOGIC.md §36.
     *
     * @param  list<string>  $retained
     * @return list<string> paths deleted, relative to the build dir
     */
    private function pruneAssets(string $dir, array $retained): array
    {
        $keepSet = [];

        foreach ($retained as $version) {
            $decoded = json_decode((string) File::get($dir.'/'.self::LEDGER.'/'.$version.'.json'), true);

            $files = is_array($decoded) ? ($decoded['files'] ?? []) : [];

            if (! is_array($files)) {
                continue;
            }

            foreach ($files as $file) {
                if (is_string($file)) {
                    $keepSet[$file] = true;
                    $keepSet[$file.'.map'] = true;
                }
            }
        }

        $assets = $dir.'/'.self::ASSETS;

        if (! File::isDirectory($assets)) {
            return [];
        }

        $deleted = [];

        foreach (File::files($assets) as $file) {
            $relative = self::ASSETS.'/'.$file->getFilename();

            if (isset($keepSet[$relative])) {
                continue;
            }

            $deleted[] = $relative;

            if (! $this->option('dry-run')) {
                File::delete($file->getPathname());
            }
        }

        sort($deleted);

        return $deleted;
    }
}
