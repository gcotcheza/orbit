<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class BrowserGateImageIsNotProductionsTest extends TestCase
{
    #[Test]
    public function the_sandbox_builds_an_image_production_never_boots(): void
    {
        $sandbox = $this->appImage('docker-compose.e2e.yml');

        $this->assertNotContains(
            $sandbox,
            $this->images('docker-compose.yml'),
            "docker-compose.e2e.yml builds '{$sandbox}', and docker-compose.yml names that same "
            .'tag. The browser gate builds its app image on every run, so one tag for both files '
            .'means a gate run replaces the image production boots on its next recreate.'
        );
    }

    #[Test]
    public function the_browser_gate_runs_its_one_off_containers_from_the_sandbox_image(): void
    {
        $sandbox = $this->appImage('docker-compose.e2e.yml');

        preg_match_all('#\borbit/app:[A-Za-z0-9._-]+#', $this->commandsIn('scripts/e2e.sh'), $found);

        $this->assertSame(
            [$sandbox],
            array_values(array_unique($found[0])),
            "scripts/e2e.sh runs containers from an image other than the sandbox's '{$sandbox}'. "
            .'Its composer install is a one-off `docker run`, which reaches whatever tag it is '
            .'given — production\'s included.'
        );
    }

    /** @return list<string> */
    private function images(string $relative): array
    {
        preg_match_all('/^\s*image:\s*(\S+)\s*$/m', $this->read($relative), $found);

        $this->assertNotSame([], $found[1], "{$relative} names no images.");

        return $found[1];
    }

    private function appImage(string $relative): string
    {
        $file = $this->read($relative);

        if (preg_match('/^  app:$(.*?)(?=^  \S|\z)/ms', $file, $service) !== 1) {
            $this->fail("{$relative} declares no `app` service where this test looks for it.");
        }

        if (preg_match('/^\s*image:\s*(\S+)\s*$/m', $service[1], $found) !== 1) {
            $this->fail("{$relative}'s `app` service names no image, so its build is untagged.");
        }

        return $found[1];
    }

    private function commandsIn(string $relative): string
    {
        return preg_replace('/^\s*#.*$/m', '', $this->read($relative)) ?? '';
    }

    private function read(string $relative): string
    {
        $path = __DIR__.'/../../../'.$relative;

        $this->assertFileExists($path, "{$relative} is missing: the gate it describes cannot be checked.");

        $contents = file_get_contents($path);

        $this->assertIsString($contents, "{$relative} could not be read.");

        return $contents;
    }
}
