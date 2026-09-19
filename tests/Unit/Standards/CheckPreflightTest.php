<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use SplFileInfo;
use FilesystemIterator;
use RecursiveIteratorIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use PHPUnit\Framework\Attributes\Test;

/**
 * What scripts/check.sh settles before its first containerised check: the shell
 * the gate itself is made of.
 */
final class CheckPreflightTest extends TestCase
{
    private const SCRIPT = 'scripts/check.sh';

    private const IMAGE = 'koalaman/shellcheck:v0.11.0';

    #[Test]
    public function the_shell_lint_runs_every_shell_script_from_a_pinned_image(): void
    {
        $result = $this->runGate($this->prints('c0ffee1234'), $this->prints($this->root()));

        $calls = [];

        foreach ($result['docker'] as $line) {
            if (str_contains($line, 'shellcheck')) {
                $calls[] = $line;
            }
        }

        $this->assertCount(1, $calls, "The gate must lint the shell exactly once.\n".$result['output']);

        $this->assertStringContainsString(
            self::IMAGE,
            $calls[0],
            'The lint must run from a version-pinned image; `stable` is a moving tag, and a gate '
            ."whose linter changes under it proves nothing tomorrow (docs/STANDARDS.md S5).\n".$calls[0]
        );
        $this->assertStringContainsString(
            '-S warning',
            $calls[0],
            "The threshold is a policy, recorded in docs/DECISIONS.md, not a flag to drop.\n".$calls[0]
        );
        $this->assertStringContainsString(
            $this->root().':/mnt:ro',
            $calls[0],
            "The linter reads the tree and writes nothing to it, so it mounts it read-only.\n".$calls[0]
        );

        $linted = explode(' ', trim(explode(' -S warning ', $calls[0])[1] ?? ''));
        $onDisk = $this->shellFilesOnDisk();
        sort($linted);
        sort($onDisk);

        $this->assertSame(
            $onDisk,
            $linted,
            'The lint must cover every shell file under scripts/, the hooks and the sourced '
            .'libraries included: a lint over a subset cannot see the reads that happen across '
            .'those files, and reports variables as unused that the library it was not given uses.'
        );
    }

    /**
     * @return array{status: int, output: string, docker: list<string>, seconds: float}
     */
    private function runGate(string $listBody, string $inspectBody): array
    {
        $bin = sys_get_temp_dir().'/orbit-check-preflight-'.bin2hex(random_bytes(6));

        $this->assertTrue(mkdir($bin, 0o700), "Could not create {$bin}");

        $this->writeFakeDocker($bin.'/docker', $bin.'/docker.log', $listBody, $inspectBody);

        $started = microtime(true);
        $result = $this->execute(
            ['bash', $this->root().'/'.self::SCRIPT, 'dev'],
            [
                'PATH'        => $bin.':'.(getenv('PATH') ?: '/usr/bin:/bin'),
                'GATE_LEDGER' => $bin.'/ledger',
            ],
            $this->root()
        );
        $seconds = microtime(true) - $started;

        $log = is_file($bin.'/docker.log')
            ? (file($bin.'/docker.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
            : [];

        $this->remove($bin);

        return [
            'status'  => $result['status'],
            'output'  => $result['output'],
            'docker'  => $log,
            'seconds' => $seconds,
        ];
    }

    /** A docker that records its argv, answers the guard as the test asks, and fails the lint. */
    private function writeFakeDocker(string $path, string $log, string $listBody, string $inspectBody): void
    {
        $script = <<<SH
            #!/bin/sh
            printf '%s\\n' "\$*" >> '{$log}'
            case "\$*" in
                'compose ps -aq')
                    {$listBody}
                    ;;
                *'project.working_dir'*)
                    {$inspectBody}
                    ;;
                *'com.docker.compose.project"'*)
                    printf 'orbit-elsewhere\\n'
                    ;;
                *shellcheck*)
                    exit 1
                    ;;
            esac
            exit 0
            SH;

        file_put_contents($path, $script);
        chmod($path, 0o700);
    }

    private function prints(string $answer): string
    {
        return "printf '%s\\n' '{$answer}'";
    }

    /**
     * Every file under scripts/ that declares a shell, selected the way the gate selects them.
     *
     * @return list<string>
     */
    private function shellFilesOnDisk(): array
    {
        $files = [];

        /** @var iterable<string, SplFileInfo> $tree */
        $tree = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/scripts', FilesystemIterator::SKIP_DOTS)
        );

        foreach ($tree as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $lines = file($file->getPathname());

            if ($lines === false) {
                $this->fail($file->getPathname().' could not be read.');
            }

            if (preg_match('/^#!.*sh|^# shellcheck shell=/m', implode('', array_slice($lines, 0, 2))) === 1) {
                $files[] = substr($file->getPathname(), strlen($this->root()) + 1);
            }
        }

        sort($files);

        return $files;
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

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
