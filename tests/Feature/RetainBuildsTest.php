<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;

/**
 * `build:retain` — the half of `emptyOutDir: false` that stops the disk filling: keep files a phone might still need,
 * delete what nothing can.
 *
 * Runs against a temp dir, not public/build, so a prune bug can't delete the assets the rest of the suite is served
 * from.
 */
final class RetainBuildsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/build-'.uniqid());

        File::ensureDirectoryExists($this->dir.'/assets');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    /**
     * Write a manifest naming `$files`, and create those files.
     *
     * @param  list<string>  $files
     */
    private function build(array $files): string
    {
        $chunks = [];

        foreach ($files as $index => $file) {
            $chunks['chunk-'.$index] = ['file' => $file];

            File::put($this->dir.'/'.$file, 'built');
        }

        File::put($this->dir.'/manifest.json', (string) json_encode($chunks));

        return substr((string) md5_file($this->dir.'/manifest.json'), 0, 12);
    }

    private function retain(int $keep = 3): void
    {
        $this->command(['--dir' => $this->dir, '--keep' => $keep])->assertSuccessful();
    }

    /**
     * `$this->artisan()` is typed `PendingCommand|int`; narrowed here for readable assertion chains and honest static
     * analysis (docs/BUSINESS-LOGIC.md §36).
     *
     * @param  array<string, mixed>  $parameters
     */
    private function command(array $parameters): PendingCommand
    {
        $pending = $this->artisan('build:retain', $parameters);

        if (! $pending instanceof PendingCommand) {
            $this->fail('build:retain has already been run.');
        }

        return $pending;
    }

    #[Test]
    public function a_run_records_the_current_build_in_the_ledger(): void
    {
        $version = $this->build(['assets/app-one.js']);

        $this->retain();

        $ledger = $this->dir.'/builds/'.$version.'.json';

        $this->assertFileExists($ledger);

        $snapshot = json_decode((string) File::get($ledger), true);

        $this->assertIsArray($snapshot);
        $this->assertSame($version, $snapshot['version']);
        $this->assertSame(['assets/app-one.js'], $snapshot['files']);
    }

    /**
     * DO NOT refresh `recorded_at` on a no-op run, or a daily run would make the oldest build look like the newest.
     */
    #[Test]
    public function running_twice_without_a_build_changes_nothing(): void
    {
        $version = $this->build(['assets/app-one.js']);

        $this->retain();

        $ledger = $this->dir.'/builds/'.$version.'.json';
        $recordedAt = json_decode((string) File::get($ledger), true);

        $this->assertIsArray($recordedAt);

        $this->retain();

        $after = json_decode((string) File::get($ledger), true);

        $this->assertIsArray($after);
        $this->assertSame($recordedAt['recorded_at'], $after['recorded_at']);
        $this->assertFileExists($this->dir.'/assets/app-one.js');
    }

    /**
     * The command's core behavior: keep 3 builds (a phone can miss 3 deploys and still be rescued), prune the rest
     * (docs/BUSINESS-LOGIC.md §36).
     */
    #[Test]
    public function the_newest_builds_survive_and_older_assets_are_deleted(): void
    {
        $versions = [];

        foreach (['one', 'two', 'three', 'four'] as $name) {
            $versions[$name] = $this->build(['assets/app-'.$name.'.js']);

            $this->retain(keep: 3);
        }

        $this->assertFileDoesNotExist($this->dir.'/builds/'.$versions['one'].'.json');
        $this->assertFileDoesNotExist($this->dir.'/assets/app-one.js');

        foreach (['two', 'three', 'four'] as $name) {
            $this->assertFileExists($this->dir.'/builds/'.$versions[$name].'.json');
            $this->assertFileExists($this->dir.'/assets/app-'.$name.'.js');
        }
    }

    /**
     * DO NOT prune by mtime: an unchanged chunk keeps its name across builds, so it looks old but is still current
     * (docs/BUSINESS-LOGIC.md §36).
     */
    #[Test]
    public function a_file_shared_by_a_retained_build_is_kept(): void
    {
        $this->build(['assets/shared.js', 'assets/app-one.js']);
        $this->retain(keep: 1);

        $this->build(['assets/shared.js', 'assets/app-two.js']);
        $this->retain(keep: 1);

        $this->assertFileExists($this->dir.'/assets/shared.js');
        $this->assertFileDoesNotExist($this->dir.'/assets/app-one.js');
    }

    #[Test]
    public function a_dry_run_deletes_nothing(): void
    {
        $this->build(['assets/app-one.js']);
        $this->retain(keep: 1);

        $this->build(['assets/app-two.js']);

        $this->command(['--dir' => $this->dir, '--keep' => 1, '--dry-run' => true])->assertSuccessful();

        $this->assertFileExists($this->dir.'/assets/app-one.js');
    }

    /**
     * Runs right after `npm run build` in deploy; a failed build already errored, so this must warn (not fail) or it
     * buries the real error (docs/BUSINESS-LOGIC.md §36).
     */
    #[Test]
    public function a_checkout_with_no_build_is_a_warning_and_not_a_failure(): void
    {
        $this->command(['--dir' => $this->dir.'/nothing-here'])
            ->expectsOutputToContain('nothing to retain')
            ->assertSuccessful();
    }

    /**
     * DO NOT delete outside `assets/`: the build dir also holds the manifest and ledger, one glob away if the prune walked
     * the whole tree (docs/BUSINESS-LOGIC.md §36).
     */
    #[Test]
    public function it_never_deletes_outside_the_assets_directory(): void
    {
        $this->build(['assets/app-one.js']);

        File::put($this->dir.'/hot', 'not ours to delete');

        $this->retain(keep: 1);

        $this->assertFileExists($this->dir.'/hot');
        $this->assertFileExists($this->dir.'/manifest.json');
    }
}
