<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The browser gate installs into whatever checkout it is run from, and the
 * deploy runs it from the live one (docs/DECISIONS.md).
 */
final class BrowserGateInstallsTest extends TestCase
{
    private const SCRIPT = 'scripts/e2e.sh';

    /** Presence tests an interrupted install passes, and the marker that replaces each. */
    private const MARKERS = [
        '-d vendor'       => 'vendor/autoload.php',
        '-d node_modules' => 'node_modules/.package-lock.json',
    ];

    #[Test]
    public function the_installs_wait_for_a_finished_install_not_a_directory(): void
    {
        $script = $this->read(self::SCRIPT);

        foreach (self::MARKERS as $presence => $marker) {
            $this->assertStringNotContainsString(
                "[ ! -f {$presence}",
                $script,
                "{$presence} is a test for a directory. An empty one — an interrupted install, a "
                .'mkdir in a Dockerfile, an ownership fix — satisfies it, the install is skipped '
                ."and the step then runs against nothing. Guard on {$marker} instead."
            );
            $this->assertStringContainsString(
                $marker,
                $script,
                "The browser gate must wait on {$marker}, the marker its installer writes when it "
                .'finishes.'
            );
        }
    }

    #[Test]
    public function the_composer_install_refuses_the_deployed_checkout(): void
    {
        $branch = $this->composerBranch();

        $this->assertStringContainsString(
            'checkout_is_live',
            $branch,
            'The browser gate installs dev dependencies into the checkout it is run from, and the '
            .'deploy runs it from /var/www/orbit. Unguarded, package:discover writes dev-only '
            .'providers into the bootstrap/cache the live app boots from and every request 500s '
            .'on the next restart — the outage docs/DECISIONS.md already records once.'
        );

        $this->assertLessThan(
            strpos($branch, 'composer install'),
            strpos($branch, 'checkout_is_live'),
            'The guard must be reached before the install runs, not after it.'
        );
    }

    private function composerBranch(): string
    {
        $pattern = '/^if \[ ! -f vendor\/autoload\.php \]; then$(.*?)^fi$/ms';

        if (preg_match($pattern, $this->read(self::SCRIPT), $found) !== 1) {
            $this->fail('scripts/e2e.sh no longer has a composer-install branch to read.');
        }

        return $found[1];
    }

    private function read(string $relative): string
    {
        $path = __DIR__.'/../../../'.$relative;

        $this->assertFileExists($path, "{$relative} is missing.");

        $contents = file_get_contents($path);

        $this->assertIsString($contents, "{$relative} could not be read.");

        return $contents;
    }
}
