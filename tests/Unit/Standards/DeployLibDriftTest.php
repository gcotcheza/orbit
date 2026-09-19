<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * PHPUnit's TestCase, not Tests\TestCase: this only reads shell files off disk.
 * docs/DECISIONS.md: the-deploy-script-is-the-runbook.
 */
final class DeployLibDriftTest extends TestCase
{
    private const HEADER = '/^# fleet-deploy-lib (\S+) sha256:([0-9a-f]{64})$/';

    private const FILES = ['summary.sh', 'resolve.sh', 'ledger.sh', 'preflight.sh'];

    /** @return array<string, array{string}> */
    public static function files(): array
    {
        return array_combine(self::FILES, array_map(static fn (string $f): array => [$f], self::FILES));
    }

    #[Test]
    #[DataProvider('files')]
    public function a_vendored_file_matches_the_hash_in_its_own_header(string $file): void
    {
        [, $declared] = $this->header($file);

        $this->assertSame(
            $declared,
            hash('sha256', $this->body($file)),
            "scripts/lib/deploy/{$file} has been edited here. The deploy library is vendored, "
            .'not authored in this repository: change it in engineering-standards, re-stamp its '
            .'header there, and re-vendor the whole file.',
        );
    }

    #[Test]
    public function every_vendored_file_declares_the_same_version(): void
    {
        $declared = [];

        foreach (self::FILES as $file) {
            [$version] = $this->header($file);
            $declared[$version][] = $file;
        }

        $this->assertCount(
            1,
            $declared,
            'The vendored deploy library is half one version and half another: '
            .json_encode($declared, JSON_THROW_ON_ERROR).'. Vendor all of it at once.',
        );

        $this->assertSame(
            [trim($this->contents('VERSION'))],
            array_keys($declared),
            'scripts/lib/deploy/VERSION and the headers beside it disagree.',
        );
    }

    #[Test]
    public function the_declared_version_is_a_date(): void
    {
        $version = trim($this->contents('VERSION'));

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}$/',
            $version,
            "scripts/lib/deploy/VERSION declares '{$version}'; the library's version is a date.",
        );
    }

    #[Test]
    public function the_deploy_script_sources_every_vendored_file(): void
    {
        $deploy = $this->contents('../../deploy.sh');

        foreach (self::FILES as $file) {
            $this->assertStringContainsString(
                '. "$(dirname "$0")/lib/deploy/'.$file.'"',
                $deploy,
                "scripts/deploy.sh does not source {$file}, so a vendored file is dead code here.",
            );
        }
    }

    #[Test]
    public function both_gates_record_through_the_vendored_ledger(): void
    {
        foreach (['check.sh', 'e2e.sh'] as $gate) {
            $this->assertStringContainsString(
                'lib/deploy/ledger.sh',
                $this->contents('../../'.$gate),
                "scripts/{$gate} does not source the vendored ledger, so the gate it runs cannot "
                .'be read back by scripts/deploy.sh and every deploy would have to be by hand.',
            );
        }
    }

    /** @return array{string, string} the declared version and sha256 */
    private function header(string $file): array
    {
        $first = strstr($this->contents($file), "\n", true);

        $this->assertIsString($first, "scripts/lib/deploy/{$file} is a single line and cannot be vendored.");

        if (preg_match(self::HEADER, $first, $found) !== 1) {
            $this->fail("scripts/lib/deploy/{$file}'s first line is not the vendoring header: {$first}");
        }

        return [$found[1], $found[2]];
    }

    private function body(string $file): string
    {
        $contents = $this->contents($file);
        $break = strpos($contents, "\n");

        $this->assertIsInt($break);

        return substr($contents, $break + 1);
    }

    private function contents(string $file): string
    {
        $path = __DIR__.'/../../../scripts/lib/deploy/'.$file;

        $this->assertFileExists($path, "scripts/lib/deploy/{$file} is missing: the deploy library is not vendored here.");

        $contents = file_get_contents($path);

        $this->assertIsString($contents, "scripts/lib/deploy/{$file} could not be read.");

        return $contents;
    }
}
