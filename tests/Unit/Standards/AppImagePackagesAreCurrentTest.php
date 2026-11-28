<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/** Runs inside the image the gate builds, so it reads that image's own apk database. */
final class AppImagePackagesAreCurrentTest extends TestCase
{
    private const APK_DATABASE = '/lib/apk/db/installed';

    /** CVE-2026-11979 and CVE-2026-86138..86144 are fixed from here (card 1263). */
    private const LIBXML2_FLOOR = '2.13.9-r5';

    #[Test]
    public function the_app_image_carries_a_libxml2_with_its_security_fixes(): void
    {
        $installed = $this->installedVersion('libxml2');

        $this->assertContains(
            $this->apkCompare($installed, self::LIBXML2_FLOOR),
            ['=', '>'],
            "The app image ships libxml2 {$installed}, below ".self::LIBXML2_FLOOR.', so the CVEs fixed there are '
            .'live in PHP\'s dom and xml extensions. docker/app/Dockerfile holds it with `apk add \'libxml2>=…\'`.'
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
