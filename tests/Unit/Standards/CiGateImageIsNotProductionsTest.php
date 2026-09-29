<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The code gate's compose overlay, and that every call in the gate reads it
 * (docs/DECISIONS.md: the-ci-gate-builds-its-own-image-tag).
 */
final class CiGateImageIsNotProductionsTest extends TestCase
{
    private const OVERLAY = 'docker-compose.ci.yml';

    private const PRODUCTION = 'docker-compose.yml';

    private const GATE = 'scripts/check.sh';

    #[Test]
    public function the_overlay_retags_every_service_production_builds(): void
    {
        $images = $this->images(self::PRODUCTION);
        $building = $this->buildingServices(self::PRODUCTION);

        $this->assertNotSame(
            [],
            $building,
            self::PRODUCTION.' declares no service that builds an image where this test reads it, '
            .'so nothing above was compared.'
        );

        foreach ($building as $service) {
            $tag = $this->serviceImage(self::OVERLAY, $service);

            $this->assertNotContains(
                $tag,
                $images,
                self::OVERLAY." gives {$service} the tag '{$tag}', and ".self::PRODUCTION.' names '
                .'that same tag. Compose builds a service\'s image when it is absent and writes it '
                .'under the name the file gives it, so one tag for both files means a gate run '
                .'replaces the image production boots on its next recreate.'
            );
        }
    }

    #[Test]
    public function every_compose_call_in_the_gate_reads_the_overlay(): void
    {
        $code = $this->commandsIn(self::GATE);

        if (preg_match('/\bexport COMPOSE_FILE=.*/', $code, $found, PREG_OFFSET_CAPTURE) !== 1) {
            $this->fail(
                self::GATE.' exports no COMPOSE_FILE, so its compose calls resolve '
                .self::PRODUCTION.' alone — and that file names the tag production boots.'
            );
        }

        $export = (string) $found[0][0];
        $exported = (int) $found[0][1];

        $this->assertSame(
            1,
            preg_match_all('/\bCOMPOSE_FILE=/', $code),
            self::GATE.' assigns COMPOSE_FILE more than once. The last assignment before a call is '
            .'the one compose reads, so a second one — a narrowing, a reset, a `-f` written as an '
            ."environment variable — takes the overlay away from every step after it:\n  "
            .trim($export)
        );

        foreach ([self::PRODUCTION, self::OVERLAY] as $file) {
            $this->assertStringContainsString(
                $file,
                $export,
                "The exported COMPOSE_FILE does not name {$file}, so the gate's compose calls read "
                ."something other than the two files it is meant to:\n  ".trim($export)
            );
        }

        preg_match_all('/\bdocker compose\b/', $code, $calls, PREG_OFFSET_CAPTURE);

        $this->assertNotSame(
            [],
            $calls[0],
            self::GATE.' runs no `docker compose` at all, so this test compared nothing.'
        );

        foreach ($calls[0] as $call) {
            $at = (int) $call[1];

            $this->assertGreaterThan(
                $exported,
                $at,
                'A `docker compose` in '.self::GATE.' comes before the COMPOSE_FILE export, so it '
                .'resolves '.self::PRODUCTION." alone:\n  ".trim($this->lineAt($code, $at))
            );
        }

        foreach (explode("\n", $code) as $line) {
            if (! str_contains($line, 'docker compose') || preg_match('/\s--?f(ile)?[ =]/', $line) !== 1) {
                continue;
            }

            foreach ([self::PRODUCTION, self::OVERLAY] as $file) {
                $this->assertStringContainsString(
                    $file,
                    $line,
                    'A compose command in '.self::GATE." names its own files and leaves {$file} out; "
                    ."a `-f` on the command line replaces the export rather than adding to it:\n  "
                    .trim($line)
                );
            }
        }
    }

    #[Test]
    public function the_overlay_runner_builds_the_tag_it_then_runs(): void
    {
        $code = $this->commandsIn(self::GATE);

        if (preg_match('/^if \[ "\$mode" = overlay \]; then$(.*?)^fi$/ms', $code, $branch) !== 1) {
            $this->fail(self::GATE.' has no overlay-only branch where this test reads it.');
        }

        $this->assertStringContainsString(
            'docker compose build app',
            $branch[1],
            'The overlay runner does not build its own image. Compose builds a MISSING image and '
            .'never a stale one, so a tag of its own without a build of its own leaves every step '
            .'running whatever the box holds under that name — which is how a gate goes green '
            .'against code it never ran.'
        );
    }

    /** @return list<string> */
    private function images(string $relative): array
    {
        preg_match_all('/^\s*image:\s*(\S+)\s*$/m', $this->read($relative), $found);

        $this->assertNotSame([], $found[1], "{$relative} names no images.");

        return array_map($this->unquote(...), $found[1]);
    }

    /** @return list<string> */
    private function buildingServices(string $relative): array
    {
        $building = [];

        foreach ($this->services($relative) as $service => $body) {
            if (preg_match('/^\s+build\s*:/m', $body) === 1) {
                $building[] = $service;
            }
        }

        return $building;
    }

    private function serviceImage(string $relative, string $service): string
    {
        $body = $this->services($relative)[$service] ?? null;

        if ($body === null) {
            $this->fail(
                "{$relative} declares no `{$service}` service, and ".self::PRODUCTION.' builds one '
                .'of that name: a service the overlay leaves out keeps the tag production boots.'
            );
        }

        if (preg_match('/^\s*image:\s*(\S+)\s*$/m', $body, $found) !== 1) {
            $this->fail("{$relative}'s `{$service}` service names no image, so its build is untagged.");
        }

        return $this->unquote($found[1]);
    }

    /** @return array<string, string> service name => its body */
    private function services(string $relative): array
    {
        $file = $this->read($relative);

        if (preg_match('/^services:$(.*?)(?=^\S|\z)/ms', $file, $block) !== 1) {
            $this->fail("{$relative} has no `services:` block where this test reads it.");
        }

        preg_match_all('/^  ([A-Za-z0-9_.-]+):$(.*?)(?=^  \S|\z)/ms', $block[1], $found, PREG_SET_ORDER);

        $this->assertNotSame([], $found, "{$relative} declares no service where this test reads it.");

        $services = [];

        foreach ($found as $service) {
            $services[$service[1]] = $service[2];
        }

        return $services;
    }

    /** A compose value may be quoted; 'orbit/app:ci' and orbit/app:ci are one tag. */
    private function unquote(string $value): string
    {
        foreach (['\'', '"'] as $quote) {
            if (str_starts_with($value, $quote) && str_ends_with($value, $quote)) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }

    private function lineAt(string $code, int $offset): string
    {
        $before = strrpos(substr($code, 0, $offset), "\n");

        return explode("\n", substr($code, $before === false ? 0 : $before + 1))[0];
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
