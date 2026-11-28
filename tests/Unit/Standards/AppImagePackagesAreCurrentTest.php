<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

/** Runs inside the image the gate builds, so it reads that image's own apk database. */
final class AppImagePackagesAreCurrentTest extends TestCase
{
    private const APK_DATABASE = '/lib/apk/db/installed';

    /** Card 1263: CVE-2026-11979, 86138-86140, 86142-86144 (libxml2), 111497 (brotli), 92392 (curl). */
    private const FLOORS = [
        'libxml2'     => '2.13.9-r5',
        'brotli-libs' => '1.2.1-r0',
        'curl'        => '8.23.0-r0',
        'libcurl'     => '8.23.0-r0',
    ];

    /** @return iterable<string, array{string, string}> */
    public static function floors(): iterable
    {
        foreach (self::FLOORS as $package => $floor) {
            yield $package => [$package, $floor];
        }
    }

    #[Test]
    #[DataProvider('floors')]
    public function the_app_image_carries_each_package_at_or_above_its_security_floor(string $package, string $floor): void
    {
        $installed = $this->installedVersion($package);

        $this->assertContains(
            $this->apkCompare($installed, $floor),
            ['=', '>'],
            "The app image ships {$package} {$installed}, below its floor {$floor}, so the CVEs fixed there are "
            ."live. docker/app/Dockerfile holds it with `apk add '{$package}>={$floor}'`."
        );
    }

    private function installedVersion(string $package): string
    {
        $this->assertFileIsReadable(
            self::APK_DATABASE,
            'No apk database: this suite is running outside the app image, which T2 forbids.'
        );

        $database = (string) file_get_contents(self::APK_DATABASE);

        if (preg_match('/^P:'.preg_quote($package, '/').'\nV:(\S+)$/m', $database, $found) !== 1) {
            $this->fail("{$package} is not installed in the app image.");
        }

        return $found[1];
    }

    private function apkCompare(string $left, string $right): string
    {
        $process = proc_open(['apk', 'version', '-t', $left, $right], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        $this->assertIsResource($process, 'apk could not be started.');

        $answer = trim((string) stream_get_contents($pipes[1]));
        $errors = trim((string) stream_get_contents($pipes[2]));
        $exit = proc_close($process);

        $this->assertSame(0, $exit, "apk version -t {$left} {$right} failed: {$errors}");

        return $answer;
    }
}
