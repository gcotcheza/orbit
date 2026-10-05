<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The container is the truth for every pin here; the files below only quote it
 * (docs/DECISIONS.md).
 */
final class ToolchainPinsTest extends TestCase
{
    #[Test]
    public function the_playwright_driver_matches_the_browser_image(): void
    {
        $this->assertSame(
            $this->matched('/^PLAYWRIGHT_VERSION=\'([^\']+)\'$/m', 'scripts/e2e.sh'),
            $this->package()['devDependencies']['@playwright/test'] ?? null,
            'The Playwright driver in package.json and the browser image in scripts/e2e.sh are '
            .'different versions. Playwright refuses to drive browsers it did not ship with, so '
            .'the browser gate cannot start until these two strings are identical.'
        );
    }

    #[Test]
    public function the_nvmrc_matches_the_node_image(): void
    {
        $this->assertSame(
            $this->matched('/^\s*image:\s*node:(\S+)-alpine\s*$/m', 'docker-compose.yml'),
            trim($this->read('.nvmrc')),
            '.nvmrc names a different Node than the assets container runs. The image tag is the '
            .'pin; .nvmrc exists to tell a person and their version manager what it is.'
        );
    }

    #[Test]
    public function the_browser_gate_runs_the_images_production_runs(): void
    {
        $production = $this->read('docker-compose.yml');
        $copies = [
            'node' => $this->read('scripts/e2e.sh'),
            'postgres' => $this->read('docker-compose.e2e.yml'),
            'redis' => $this->read('docker-compose.e2e.yml'),
        ];

        foreach ($copies as $image => $copy) {
            preg_match('/\b'.$image.':(\S+)-alpine\b/', $production, $pinned);
            preg_match_all('/\b'.$image.':(\S+)-alpine\b/', $copy, $quoted);

            $this->assertNotEmpty($quoted[1], "The browser gate no longer names a {$image} image.");
            $this->assertSame(
                [$pinned[1] ?? null],
                array_values(array_unique($quoted[1])),
                "The browser gate runs a different {$image} than docker-compose.yml pins, so it "
                .'tests a runtime production does not have.'
            );
        }
    }

    #[Test]
    public function the_declared_node_engine_matches_the_nvmrc(): void
    {
        $nvmrc = trim($this->read('.nvmrc'));
        $engine = $this->package()['engines']['node'] ?? null;

        $this->assertIsString($engine, 'package.json declares no engines.node.');
        $this->assertSame(
            explode('.', $nvmrc)[0].'.x',
            $engine,
            "package.json allows Node '{$engine}' where .nvmrc pins '{$nvmrc}'. npm warns against "
            .'the engines range, not against .nvmrc, so a widened range is how a wrong Node gets in.'
        );
    }

    #[Test]
    public function the_composer_platform_matches_the_php_image(): void
    {
        $platform = $this->composer()['config']['platform']['php'] ?? null;

        $this->assertIsString($platform, 'composer.json declares no config.platform.php.');
        $this->assertSame(
            $this->matched('/^FROM php:(\S+)-fpm-alpine\s*$/m', 'docker/app/Dockerfile'),
            $platform,
            "composer.json resolves for PHP '{$platform}', which is not the PHP the app "
            .'image is built from. Composer would pick packages for a PHP the site does not run.'
        );
    }

    private function matched(string $pattern, string $relative): string
    {
        if (preg_match($pattern, $this->read($relative), $found) !== 1) {
            $this->fail("{$relative} no longer states its version where {$pattern} looks for it.");
        }

        return $found[1];
    }

    /** @return array<string, mixed> */
    private function package(): array
    {
        return $this->decoded('package.json');
    }

    /** @return array<string, mixed> */
    private function composer(): array
    {
        return $this->decoded('composer.json');
    }

    /** @return array<string, mixed> */
    private function decoded(string $relative): array
    {
        $decoded = json_decode($this->read($relative), true);

        $this->assertIsArray($decoded, "{$relative} is not valid JSON.");

        return $decoded;
    }

    private function read(string $relative): string
    {
        $path = __DIR__.'/../../../'.$relative;

        $this->assertFileExists($path, "{$relative} is missing: the pin it carries is not declared anywhere.");

        $contents = file_get_contents($path);

        $this->assertIsString($contents, "{$relative} could not be read.");

        return $contents;
    }
}
