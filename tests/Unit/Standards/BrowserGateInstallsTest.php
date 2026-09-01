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

    private const COMPOSE = 'docker-compose.yml';

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
                "[ ! {$presence}",
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

    #[Test]
    public function the_guard_names_the_deployed_project_from_the_file_that_pins_it(): void
    {
        $script = $this->read(self::SCRIPT);

        $this->assertMatchesRegularExpression(
            '/^name:\s*\S+/m',
            $this->read(self::COMPOSE),
            'docker-compose.yml no longer pins a project name, so the guard has nothing to read '
            .'and refuses every install rather than none.'
        );

        $this->assertStringNotContainsString(
            'com.docker.compose.project=orbit',
            $script,
            'The guard has the deployed project name typed into it. docker-compose.yml pins that '
            .'name; a rename there would leave this filter matching nothing, and a guard that '
            .'matches nothing reports the deployed checkout as safe to install into.'
        );

        $this->assertStringContainsString(
            self::COMPOSE,
            $script,
            'The guard must read the project name from docker-compose.yml.'
        );
    }

    #[Test]
    public function the_guards_docker_calls_cannot_hang(): void
    {
        foreach (explode("\n", $this->guardBody()) as $line) {
            if (preg_match('/\bdocker\s+(?:ps|inspect|volume|compose|run|network)\b/', $line) !== 1) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/timeout \d+ docker /',
                $line,
                'A docker call in the guard has no timeout. A daemon that REFUSES leaves the guard '
                ."fail-closed, which is correct; a daemon that HANGS hangs the deploy:\n  "
                .trim($line)
            );
        }
    }

    private function guardBody(): string
    {
        return $this->between('/^checkout_is_live\(\) \{$(.*?)^\}$/ms', 'a checkout_is_live function');
    }

    private function composerBranch(): string
    {
        return $this->between(
            '/^if \[ ! -f vendor\/autoload\.php \]; then$(.*?)^fi$/ms',
            'a composer-install branch'
        );
    }

    private function between(string $pattern, string $what): string
    {
        if (preg_match($pattern, $this->read(self::SCRIPT), $found) !== 1) {
            $this->fail('scripts/e2e.sh no longer has '.$what.' to read.');
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
