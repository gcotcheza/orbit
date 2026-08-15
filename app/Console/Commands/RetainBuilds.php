<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * `php artisan build:retain` — keep the last few builds' assets on disk, and
 * delete the ones before that.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT IS FOR
 *
 * `vite.config.js` sets `build.emptyOutDir: false`, so a build now ADDS files
 * to `public/build/assets` rather than replacing the directory — see that file
 * for the two ways a wiped directory kills a page that is already open. Adding
 * without ever removing is a disk that fills up, and this is the removing half.
 *
 * ---------------------------------------------------------------------------
 * A LEDGER, BECAUSE A FILE'S mtime IS NOT ITS BUILD
 *
 * Something has to know which file belonged to which build, and that is not
 * recoverable from the filesystem: a chunk whose content did not change keeps
 * its name AND its timestamp across builds, so by mtime it would look ancient
 * forever and be deleted while the current page is still asking for it.
 *
 * So each run writes a snapshot — `public/build/builds/<version>.json`, listing
 * exactly the files the manifest referenced at that moment. `<version>` is the
 * md5 prefix of manifest.json: the same string App\Services\Pwa\BuildAssets
 * gives the service worker for its cache name, so "a build" means the same
 * thing in both places.
 *
 * Retention is then trivial and, more importantly, honest: keep the newest N
 * snapshots, keep the union of the files they name, delete every other file in
 * `assets/`. A chunk shared by three builds is listed in three snapshots and
 * survives until the last of them is dropped.
 *
 * ---------------------------------------------------------------------------
 * WHY THREE
 *
 * The window that matters is "how long can a phone hold a reference to an old
 * build and still be rescued", and it is bounded by how often this app is
 * deployed on a bad day. Two would be one short of that. Beyond three the
 * returns are nothing: a device that has missed four deploys has been asleep
 * for days and fetches fresh HTML the moment it wakes.
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
            // A checkout that has never been built. Not an error: the deploy
            // runs this straight after the build, and a missing manifest there
            // means the build failed and has already said so.
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
     * Every file the manifest points at, as paths relative to the build dir.
     *
     * `file`, `css` and `assets` — the chunk itself, the stylesheets Vite
     * attached to it, and everything it emitted, which is where the font faces
     * are.
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
     * NOT overwritten on a re-run, and that is the point: `recorded_at` is what
     * orders the ledger, so refreshing it would make today's scheduled run look
     * like a new build and push a genuinely newer one out of the window.
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

            /*
             * MICROSECONDS, and they are load bearing.
             *
             * `recorded_at` is the only thing that orders the ledger, and two
             * deploys can easily land in the same second — a rebuild that only
             * touches one screen takes well under one. At second resolution the
             * comparison ties, the sort becomes arbitrary, and retention starts
             * dropping the wrong build.
             */
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
                'path' => $path,
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
     * Scoped to that one directory on purpose. `public/build` also holds
     * `manifest.json` and the ledger itself, and a prune that walked the whole
     * tree would be one glob away from deleting its own bookkeeping.
     *
     * A KEPT FILE KEEPS ITS SOURCE MAP. Vite does not list `app-XYZ.js.map` in
     * the manifest — only `app-XYZ.js` — so a prune that named files purely
     * from the manifest would delete every map in the directory on its first
     * run after each deploy. `build.sourcemap` is off today and this line does
     * nothing; the day somebody turns it on to debug a phone is the day they
     * would otherwise discover the maps vanish an hour later.
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
