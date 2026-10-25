<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The gate's secrets step is the one check that reads the checkout rather than a
 * container, and the deploy runs it against /var/www/orbit as root — where git
 * refuses the tree. That call goes through CI_GIT (docs/DECISIONS.md,
 * the-gate-scans-for-secrets-over-gits-view-of-the-tree); scripts/check-test.sh runs it.
 */
final class CheckGitSeamTest extends TestCase
{
    use RunsGateScripts;

    private const SCRIPT = 'scripts/check.sh';

    #[Test]
    public function no_call_site_in_the_gate_is_left_on_plain_git(): void
    {
        $script = $this->read(self::SCRIPT);

        $this->assertSame(
            1,
            preg_match_all('/^GIT=\$\{CI_GIT:-git\}$/m', $script),
            'The seam is defined once, near the top, and nothing else redefines it.'
        );

        $bare = [];
        $seamed = 0;

        foreach (explode("\n", $script) as $index => $line) {
            $code = (string) preg_replace('/(^|\s)#.*$/', '', $line);

            if (trim($code) === 'GIT=${CI_GIT:-git}') {
                continue;
            }

            $seamed += (int) preg_match_all('/\$GIT\s/', $code);

            if (preg_match('/(^|[|&;]\s*|\$\(|<\(|\bexec\s+)git\s/', $code) === 1) {
                $bare[] = ($index + 1).': '.trim($line);
            }
        }

        $this->assertSame(
            [],
            $bare,
            "A call site is on plain `git`, so the deploy's gate dies in its first step against a "
            ."checkout root cannot read:\n".implode("\n", $bare)
        );

        $this->assertGreaterThanOrEqual(
            1,
            $seamed,
            'The gate reads its checkout with git in exactly one place today — the secrets step. '
            .'None at all means this test is clearing a script that no longer lists the tree.'
        );
    }
}
