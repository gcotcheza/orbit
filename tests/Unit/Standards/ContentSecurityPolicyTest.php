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

    /** Anchored: a commented-out add_header reads like a policy and serves none. */
    private const SERVED = '/^\s*add_header Content-Security-Policy "/';

    private const COMMENTED = '/^\s*#.*add_header Content-Security-Policy/';

    private const EITHER_SPELLING = '/^\s*add_header Content-Security-Policy(?:-Report-Only)? "/';

    #[Test]
    public function the_sidecar_serves_exactly_one_policy(): void
    {
        $this->assertCount(
            1,
            $this->linesMatching(self::SIDECAR, self::SERVED),
            'Two policies on one response are enforced as their intersection, which is a policy '
            .'nobody wrote down; none at all is a gate that cannot tell enforcing from absent.'
        );
    }

    #[Test]
    public function a_commented_out_policy_is_not_a_policy(): void
    {
        $this->assertSame(
            [],
            $this->linesMatching(self::SIDECAR, self::COMMENTED),
            'A commented-out add_header serves nothing, so every other case here would stay green '
            .'while the browser was handed no policy at all.'
        );
    }

    #[Test]
    public function the_policy_reaches_a_response_the_app_did_not_answer_200_to(): void
    {
        $this->assertStringEndsWith(
            '" always;',
            trim($this->linesMatching(self::SIDECAR, self::SERVED)[0] ?? ''),
            'add_header is conditional on the response status, and the default list is 2xx/3xx '
            .'only: without `always` a 4xx/5xx document goes out with no policy at all — a 401, '
            .'419, 422 or 500 from the app, the =404 from try_files, the dotfile 403, or the 502 '
            .'nginx answers with while php-fpm is down.'
        );
    }

    #[Test]
    #[TestWith(["script-src 'self'", 'an inline script is what a cross-site injection is'])]
    #[TestWith(["object-src 'none'", 'a plugin document bypasses script-src entirely'])]
    public function the_policy_carries(string $directive, string $why): void
    {
        $this->assertStringContainsString(
            $directive,
            $this->policy(),
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
                '/add_header\s+\S*Report-Only/i',
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
            $this->linesMatching(self::VHOST, self::EITHER_SPELLING),
            'The vhost carries a policy again, enforcing or report-only. Two copies drift, and the '
            .'one the browser gate can exercise is the sidecar, so this is the copy that goes.'
        );
    }

    /** @return list<string> */
    private function linesMatching(string $relative, string $pattern): array
    {
        return array_values(preg_grep($pattern, explode("\n", $this->read($relative))) ?: []);
    }

    /** The quoted value, so a clause is never read out of the directive around it. */
    private function policy(): string
    {
        preg_match('/"([^"]*)"/', $this->linesMatching(self::SIDECAR, self::SERVED)[0] ?? '', $quoted);

        return $quoted[1] ?? '';
    }

    private function scriptSrc(): string
    {
        foreach (explode(';', $this->policy()) as $clause) {
            if (str_starts_with(trim($clause), 'script-src ')) {
                return $clause;
            }
        }

        $this->fail('The served policy has no script-src clause, so default-src decides scripts.');
    }

    /** @return array<string, string> */
    private function filesUnder(string $relative): array
    {
        $found = [];

        foreach (scandir(dirname(__DIR__, 3).'/'.$relative) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $relative.'/'.$entry;

            if (is_dir(dirname(__DIR__, 3).'/'.$path)) {
                $found += $this->filesUnder($path);

                continue;
            }

            $found[$path] = $this->read($path);
        }

        $this->assertNotSame([], $found, "{$relative}/ holds no file, so this test reads nothing.");

        return $found;
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
