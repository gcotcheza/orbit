<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * The policy the browser gate exercises is the one the deploy serves, and there
 * is one of it (docs/DECISIONS.md: the-content-security-policy-lives-on-the-app-nginx).
 */
final class ContentSecurityPolicyTest extends TestCase
{
    private const SIDECAR = 'docker/web/nginx.conf';

    private const VHOST = 'deploy/nginx/flights-ghiecode.conf';

    #[Test]
    public function the_sidecar_serves_exactly_one_policy(): void
    {
        $this->assertCount(
            1,
            $this->policyLines(self::SIDECAR),
            'Two policies on one response are enforced as their intersection, which is a policy '
            .'nobody wrote down; none at all is a gate that cannot tell enforcing from absent.'
        );
    }

    #[Test]
    public function the_policy_survives_a_location_that_sets_a_header_of_its_own(): void
    {
        $this->assertStringEndsWith(
            '" always;',
            trim($this->policyLines(self::SIDECAR)[0] ?? ''),
            'Without `always` nginx drops the header on every response the app did not generate '
            .'itself — a 304 from the browser cache included, which is most of them.'
        );
    }

    #[Test]
    #[TestWith(["script-src 'self'", 'an inline script is what a cross-site injection is'])]
    #[TestWith(["object-src 'none'", 'a plugin document bypasses script-src entirely'])]
    public function the_policy_carries(string $directive, string $why): void
    {
        $this->assertStringContainsString(
            $directive,
            $this->policyLines(self::SIDECAR)[0] ?? '',
            "The served policy no longer carries `{$directive}`: {$why}."
        );
    }

    #[Test]
    public function scripts_are_neither_inline_nor_evaluated(): void
    {
        $clause = $this->scriptSrc();

        foreach (["'unsafe-inline'", "'unsafe-eval'"] as $escape) {
            $this->assertStringNotContainsString(
                $escape,
                $clause,
                "script-src grants {$escape}, which is the whole of what this policy is for. "
                .'e2e/specs/csp.spec.js would go green against a policy that stops nothing.'
            );
        }
    }

    #[Test]
    public function nothing_the_app_serves_is_report_only(): void
    {
        foreach ($this->filesUnder('docker/web') as $relative => $contents) {
            $this->assertDoesNotMatchRegularExpression(
                '/report-only/i',
                $contents,
                "{$relative} serves a report-only policy. Reporting and enforcing look identical "
                .'from the outside, and only one of them refuses the script.'
            );
        }
    }

    #[Test]
    public function the_host_vhost_carries_no_policy_of_its_own(): void
    {
        $this->assertSame(
            [],
            $this->policyLines(self::VHOST),
            'The vhost has a policy again. Two copies drift, and the one the browser gate can '
            .'exercise is the sidecar, so this one is the copy that goes.'
        );
    }

    /** @return list<string> */
    private function policyLines(string $relative): array
    {
        return array_values(array_filter(
            explode("\n", $this->read($relative)),
            static fn (string $line): bool => str_contains($line, 'add_header Content-Security-Policy "')
        ));
    }

    private function scriptSrc(): string
    {
        $policy = $this->policyLines(self::SIDECAR)[0] ?? '';

        foreach (explode(';', $policy) as $clause) {
            if (str_starts_with(trim($clause), 'script-src ')) {
                return $clause;
            }
        }

        $this->fail('The served policy has no script-src clause, so default-src decides scripts.');
    }

    /** @return array<string, string> */
    private function filesUnder(string $relative): array
    {
        $root = dirname(__DIR__, 3);
        $paths = glob($root.'/'.$relative.'/*') ?: [];

        $this->assertNotSame([], $paths, "{$relative}/ is empty, so this test reads nothing.");

        $files = [];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[substr($path, strlen($root) + 1)] = $this->read(substr($path, strlen($root) + 1));
            }
        }

        return $files;
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3).'/'.$relative;

        $this->assertFileExists($path, "{$relative} is missing.");

        $contents = file_get_contents($path);

        $this->assertIsString($contents, "{$relative} could not be read.");

        return $contents;
    }
}
