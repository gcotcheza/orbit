<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The gate's secrets step is the one check that reads the checkout rather than a
 * container, and the deploy runs it against /var/www/orbit as root — where git
 * refuses the tree. That call goes through CI_GIT (docs/DECISIONS.md,
 * the-gate-scans-for-secrets-over-gits-view-of-the-tree).
 */
final class CheckGitSeamTest extends TestCase
{
    private const SCRIPT = 'scripts/check.sh';

    /** The value is a command WITH FLAGS; recording it proves $GIT still splits. */
    private const SEAM_FLAG = '--as=orbit';

    private const LISTING = 'ls-files -z --cached --others --exclude-standard';

    #[Test]
    public function the_secrets_step_lists_the_tree_through_the_seam(): void
    {
        $result = $this->runScript();

        $this->assertSame(1, $result['status'], "The stubbed Pint step should stop the run.\n".$result['output']);
        $this->assertStringContainsString('Gitleaks (secrets)', $result['output']);
        $this->assertStringContainsString('Pint (code style)', $result['output']);

        $this->assertSame(
            [self::LISTING],
            $result['seam'],
            'The secrets step must reach the seam, and it may not add a -C of its own: CI_GIT '
            ."carries the directory, and git-as refuses a second one.\n".$result['output']
        );
        $this->assertSame([], $result['plain'], 'A call site is still on plain `git`: '.implode(', ', $result['plain']));
    }

    #[Test]
    public function without_the_variable_the_gate_uses_plain_git(): void
    {
        $result = $this->runScript(seam: false);

        $this->assertSame(1, $result['status'], "The stubbed Pint step should stop the run.\n".$result['output']);
        $this->assertSame([], $result['seam'], 'Nothing may reach the seam when CI_GIT is unset.');
        $this->assertSame(
            [self::LISTING],
            $result['plain'],
            'The default has to stay plain `git`, or every developer checkout needs an environment '
            .'variable to run the gate at all.'
        );
    }

    #[Test]
    public function a_seam_pointing_at_another_tree_is_refused(): void
    {
        $elsewhere = $this->runScript(seamDirectory: '/elsewhere');

        $this->assertSame(
            2,
            $elsewhere['status'],
            "A seam naming another tree has to stop the run before the copy.\n".$elsewhere['output']
        );
        $this->assertStringContainsString(
            'check.sh: CI_GIT points at /elsewhere but this script runs in',
            $elsewhere['output'],
            'The refusal names both directories, because the whole failure is that they differ.'
        );
        $this->assertSame(
            [],
            $elsewhere['seam'],
            'The refusal comes before the listing: nothing may be scanned against the wrong tree.'
        );

        $matching = $this->runScript(seamDirectory: dirname(__DIR__, 3));

        $this->assertSame(
            1,
            $matching['status'],
            "The runbook's own pair names one tree twice and must pass this guard.\n".$matching['output']
        );
        $this->assertSame([self::LISTING], $matching['seam'], 'The matching pair still lists through the seam.');
    }

    #[Test]
    public function the_usage_names_the_seam(): void
    {
        $result = $this->execute(
            ['bash', dirname(__DIR__, 3).'/'.self::SCRIPT],
            ['PATH' => (string) (getenv('PATH') ?: '/usr/bin:/bin')],
            dirname(__DIR__, 3)
        );

        $this->assertSame(2, $result['status'], 'A mode-less call still has to print the usage and stop.');
        $this->assertStringContainsString(
            'CI_GIT',
            $result['output'],
            'An operator whose gate died on "dubious ownership" reads this text first. It has to '
            .'name the variable that fixes it.'
        );
    }

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

    /**
     * @return array{status: int, output: string, seam: list<string>, plain: list<string>}
     */
    private function runScript(bool $seam = true, ?string $seamDirectory = null): array
    {
        $root = dirname(__DIR__, 3);
        $bin = sys_get_temp_dir().'/orbit-check-seam-'.bin2hex(random_bytes(6));

        $this->assertTrue(mkdir($bin, 0o700), "Could not create {$bin}");

        $this->writeFakeGit($bin.'/git', $bin.'/plain.log', '');
        $this->writeFakeGit($bin.'/fake-git', $bin.'/seam.log', self::SEAM_FLAG);
        $this->writeFakeDocker($bin.'/docker');

        $value = $bin.'/fake-git '.self::SEAM_FLAG.($seamDirectory === null ? '' : ' -C '.$seamDirectory);

        // `dev` on purpose: the overlay runner's first act is `rm -rf
        // /var/tmp/orbit-gate.*`, which is a real directory on the box.
        $environment = [
            'PATH'   => $bin.':'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'CI_GIT' => $seam || $seamDirectory !== null ? $value : '',
        ];

        $result = $this->execute(['bash', $root.'/'.self::SCRIPT, 'dev'], $environment, $root);

        $seamCalls = $this->readLog($bin.'/seam.log');
        $plainCalls = $this->readLog($bin.'/plain.log');

        $this->remove($bin);

        return [
            'status' => $result['status'],
            'output' => $result['output'],
            'seam'   => $seamCalls,
            'plain'  => $plainCalls,
        ];
    }

    /** A git that records its argv and answers the one subcommand the gate asks for. */
    private function writeFakeGit(string $path, string $log, string $expectedFirstArgument): void
    {
        $script = <<<SH
            #!/bin/sh
            if [ -n '{$expectedFirstArgument}' ]; then
                if [ "\$1" != '{$expectedFirstArgument}' ]; then
                    printf 'FAKE GIT: expected {$expectedFirstArgument} first, got %s\\n' "\$1" >&2
                    exit 97
                fi
                shift
            fi
            case "\$1" in
                -C) shift 2 ;;
            esac
            printf '%s\\n' "\$*" >> '{$log}'
            case "\$1" in
                ls-files) printf 'composer.json\\0scripts/check.sh\\0' ;;
                *)
                    printf 'FAKE GIT: unexpected subcommand %s\\n' "\$1" >&2
                    exit 98
                    ;;
            esac
            SH;

        file_put_contents($path, $script);
        chmod($path, 0o700);
    }

    /** A docker that answers every call; Pint is where the run is stopped. */
    private function writeFakeDocker(string $path): void
    {
        $script = <<<'SH'
            #!/bin/sh
            case "$*" in
                *pint*) exit 1 ;;
            esac
            exit 0
            SH;

        file_put_contents($path, $script);
        chmod($path, 0o700);
    }

    /** @return list<string> */
    private function readLog(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $lines === false ? [] : $lines;
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     * @return array{status: int, output: string}
     */
    private function execute(array $command, array $environment, string $cwd): array
    {
        $pipes = [];

        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            $environment + array_map('strval', getenv()),
        );

        if ($process === false) {
            $this->fail('Could not start '.implode(' ', $command));
        }

        fclose($pipes[0]);

        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'output' => $output];
    }

    private function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path.'/'.$entry);
            }
        }

        rmdir($path);
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
