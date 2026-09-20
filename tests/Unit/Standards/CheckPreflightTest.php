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
 * What scripts/check.sh settles before its first containerised check: the stack
 * it is about to gate is one this directory started, and the shell it is made of.
 */
final class CheckPreflightTest extends TestCase
{
    private const SCRIPT = 'scripts/check.sh';

    private const CONTAINER = 'c0ffee1234';

    private const RECIPE = 'COMPOSE_PROJECT_NAME=orbit-<name> docker compose up -d postgres redis app';

    private const REFUSAL = 'Refusing to run the gate against it.';

    private const FIRST_STEP = 'ShellCheck (shell scripts)';

    private const IMAGE = 'koalaman/shellcheck:v0.11.0';

    #[Test]
    public function a_sandbox_stack_started_from_this_directory_is_gated(): void
    {
        $result = $this->runGate($this->prints(self::CONTAINER), $this->prints($this->root()));

        $this->assertStringNotContainsString(
            self::REFUSAL,
            $result['output'],
            'A stack brought up from this very directory is the sandbox the runbook asks for, and '
            ."the guard refused it. A guard that refuses everything is not run.\n".$result['output']
        );
        $this->assertStringContainsString(
            self::FIRST_STEP,
            $result['output'],
            "The run must reach its first step once the stack is this directory's.\n".$result['output']
        );
        $this->assertSame(
            1,
            $result['status'],
            "The stubbed lint fails this run; a 2 is the guard refusing instead.\n".$result['output']
        );
    }

    #[Test]
    public function a_stack_started_from_another_directory_is_refused(): void
    {
        $result = $this->runGate($this->prints(self::CONTAINER), $this->prints('/var/www/orbit'));

        $this->assertRefused($result, 'has a container started from /var/www/orbit');
    }

    #[Test]
    public function a_container_list_docker_will_not_give_is_refused(): void
    {
        $result = $this->runGate('exit 1', $this->prints($this->root()));

        $this->assertRefused($result, "docker did not list this project's containers (exited 1)");
    }

    #[Test]
    public function a_container_docker_will_not_describe_is_refused(): void
    {
        $result = $this->runGate($this->prints(self::CONTAINER), 'exit 1');

        $this->assertRefused($result, 'docker did not say where container '.self::CONTAINER.' was started from (exited 1)');
    }

    #[Test]
    public function a_container_with_no_working_directory_label_is_refused(): void
    {
        $result = $this->runGate($this->prints(self::CONTAINER), $this->prints(''));

        $this->assertRefused($result, 'container '.self::CONTAINER.' carries no working-directory label');
    }

    #[Test]
    public function a_docker_that_hangs_is_refused_instead_of_waited_on(): void
    {
        $result = $this->runGate('exec sleep 40', $this->prints($this->root()));

        $this->assertRefused($result, "docker did not list this project's containers (timed out)");
        $this->assertLessThan(
            25.0,
            $result['seconds'],
            'The guard waited for the hung docker rather than timing it out, and a gate that hangs '
            .'is a gate nobody waits for.'
        );
    }

    #[Test]
    public function the_shell_lint_runs_every_shell_script_from_a_pinned_image(): void
    {
        $result = $this->runGate($this->prints(self::CONTAINER), $this->prints($this->root()));

        $call = $this->lintCall($result);

        $this->assertStringContainsString(
            self::IMAGE,
            $call,
            'The lint must run from a version-pinned image; `stable` is a moving tag, and a gate '
            ."whose linter changes under it proves nothing tomorrow (docs/STANDARDS.md S5).\n".$call
        );
        $this->assertStringContainsString(
            '-S warning',
            $call,
            "The threshold is a policy, recorded in docs/DECISIONS.md, not a flag to drop.\n".$call
        );
        $this->assertStringContainsString(
            $this->root().':/mnt:ro',
            $call,
            "The linter reads the tree and writes nothing to it, so it mounts it read-only.\n".$call
        );

        $linted = $this->linted($result);
        $onDisk = $this->shellFilesOnDisk();
        sort($onDisk);

        $this->assertSame(
            $onDisk,
            $linted,
            'The lint must cover every shell file under scripts/, the hooks and the sourced '
            .'libraries included: a lint over a subset cannot see the reads that happen across '
            .'those files, and reports variables as unused that the library it was not given uses.'
        );

        foreach ($this->shellScriptsByExtension() as $script) {
            $this->assertContains(
                $script,
                $linted,
                "{$script} is a shell script by its name and the lint did not get it. This check "
                .'reads the tree by extension alone, on purpose: the list the gate builds and the '
                .'expectation above it both select by a declaration in the first two lines, so a '
                .'file that declares nothing would be missing from both and exempt from the lint '
                .'with the suite still green.'
            );
        }
    }

    #[Test]
    public function a_script_without_a_declaration_is_linted_rather_than_exempted(): void
    {
        $result = $this->runGate($this->prints(self::CONTAINER), $this->prints($this->root()), [
            'scripts/check.sh',
            'scripts/lib/deploy/VERSION',
            'scripts/undeclared.sh',
        ]);

        $this->assertSame(
            ['scripts/check.sh', 'scripts/undeclared.sh'],
            $this->linted($result),
            'A .sh is linted whether or not it declares a shell — ShellCheck answers an undeclared '
            .'one with SC2148 at error level, so the gate says so instead of skipping it. A file '
            .'that is neither named .sh nor declares a shell, like the libraries\' VERSION, is not '
            .'shell and is not linted.'
        );
    }

    #[Test]
    public function a_project_with_nothing_up_is_the_deploys_own_gate_and_is_not_refused(): void
    {
        $result = $this->runGate('', $this->prints($this->root()));

        $this->assertStringNotContainsString(
            self::REFUSAL,
            $result['output'],
            'docker answered, and the answer was that this project has no containers at all. That '
            .'is the deploy\'s own recipe (.claude/commands/deploy.md: a fresh worktree, '
            .'COMPOSE_PROJECT_NAME=orbit-gate-pr<N>, the overlay runner, nothing brought up), so '
            ."refusing an empty list refuses every deploy's gate.\n".$result['output']
        );
        $this->assertStringContainsString(
            self::FIRST_STEP,
            $result['output'],
            "An empty list is an answer, so the run must reach its first step.\n".$result['output']
        );
        $this->assertSame(
            1,
            $result['status'],
            "The stubbed lint fails this run; a 2 is the guard refusing instead.\n".$result['output']
        );
    }

    #[Test]
    public function the_stack_guard_is_not_the_browser_gates_guard(): void
    {
        $check = $this->withoutComments($this->read(self::SCRIPT));
        $e2e = $this->withoutComments($this->read('scripts/e2e.sh'));

        $this->assertStringContainsString(
            'stack_is_foreign() {',
            $check,
            'The gate has lost the guard that keeps it off a stack another directory started.'
        );
        $this->assertStringContainsString(
            'checkout_is_live() {',
            $e2e,
            'The browser gate has lost the guard that keeps it out of the served checkout.'
        );

        $merged = 'Each guard\'s name has turned up in the other script, which is the visible half '
            .'of merging them; a merge under some third name would walk straight past this test, and '
            .'what holds the two apart is docs/DECISIONS.md, the-two-stack-guards-are-not-one. They '
            .'only look alike (docs/STANDARDS.md C2): scripts/check.sh asks whether the stack it is '
            .'about to gate was started from this directory, scripts/e2e.sh whether this checkout is '
            .'the one being served. Sharing them makes one caller inherit the other question.';

        $mention = $merged."\nA pointer to the other guard is allowed in a FULL-LINE comment, which "
            .'is all this test strips; a trailing # on a line of code fails it.';

        $this->assertStringNotContainsString('checkout_is_live', $check, $mention);
        $this->assertStringNotContainsString('stack_is_foreign', $e2e, $mention);

        foreach ($this->shellFilesOnDisk() as $file) {
            if ($file === self::SCRIPT || $file === 'scripts/e2e.sh') {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                '/^(stack_is_foreign|checkout_is_live)\(\) \{/m',
                $this->read($file),
                $merged."\n".$file.' now defines one of them.'
            );
        }
    }

    #[Test]
    public function every_docker_call_in_the_stack_guard_has_a_timeout(): void
    {
        if (preg_match('/^stack_is_foreign\(\) \{$(.*?)^\}$/ms', $this->read(self::SCRIPT), $found) !== 1) {
            $this->fail('scripts/check.sh no longer has a stack_is_foreign function to read.');
        }

        foreach (explode("\n", $found[1]) as $line) {
            $code = (string) preg_replace('/(^|\s)#.*$/', '', $line);
            $code = (string) preg_replace('/"[^"]*"|\'[^\']*\'/', ' ', $code);
            $calls = (int) preg_match_all('/\bdocker\s+[a-z]/', $code);

            if ($calls === 0) {
                continue;
            }

            $this->assertSame(
                $calls,
                (int) preg_match_all('/\btimeout\s+\d+\s+docker\s+[a-z]/', $code),
                'A docker call in the guard has no timeout — this counts every subcommand, not a '
                .'list of the ones somebody thought of, so a later `docker info` is covered too. A '
                .'daemon that REFUSES leaves the guard fail-closed, which is correct; a daemon that '
                ."HANGS hangs the gate:\n  ".trim($line)
            );
        }
    }

    /**
     * @param  array{status: int, output: string, docker: list<string>, seconds: float}  $result
     */
    private function assertRefused(array $result, string $reason): void
    {
        $this->assertSame(
            2,
            $result['status'],
            "docker could not answer, so the gate has to refuse rather than run.\n".$result['output']
        );
        $this->assertStringContainsString(
            $reason,
            $result['output'],
            "The refusal must name the condition that fired, or nobody can act on it.\n".$result['output']
        );
        $this->assertStringContainsString(
            self::RECIPE,
            $result['output'],
            "The refusal must keep the recipe that gets the reader a stack it will accept.\n".$result['output']
        );
        $this->assertStringNotContainsString(
            self::FIRST_STEP,
            $result['output'],
            "A refused run may not have started gating anything.\n".$result['output']
        );
    }

    /**
     * @param  list<string>|null  $listed  what the stubbed git reports under scripts/
     * @return array{status: int, output: string, docker: list<string>, seconds: float}
     */
    private function runGate(string $listBody, string $inspectBody, ?array $listed = null): array
    {
        $bin = sys_get_temp_dir().'/orbit-check-preflight-'.bin2hex(random_bytes(6));

        $this->assertTrue(mkdir($bin, 0o700), "Could not create {$bin}");

        $this->writeFakeDocker($bin.'/docker', $bin.'/docker.log', $listBody, $inspectBody);
        $this->writeFakeGit($bin.'/git', $bin.'/listing', $listed ?? $this->everythingUnderScripts());

        $started = microtime(true);
        $result = $this->execute(
            ['bash', $this->root().'/'.self::SCRIPT, 'dev'],
            [
                'PATH'        => $bin.':'.(getenv('PATH') ?: '/usr/bin:/bin'),
                'CI_GIT'      => $bin.'/git',
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

    /**
     * A git that lists what the test asked for and answers the ledger's two questions.
     *
     * @param  list<string>  $files
     */
    private function writeFakeGit(string $path, string $listing, array $files): void
    {
        file_put_contents($listing, $files === [] ? '' : implode("\0", $files)."\0");

        $script = <<<SH
            #!/bin/sh
            case "\$*" in
                *ls-files*) cat '{$listing}' ;;
                *rev-parse*) printf '%s\\n' 0000000000000000000000000000000000000000 ;;
                *status*) : ;;
                *)
                    printf 'FAKE GIT: unexpected subcommand %s\\n' "\$*" >&2
                    exit 98
                    ;;
            esac
            SH;

        file_put_contents($path, $script);
        chmod($path, 0o700);
    }

    private function prints(string $answer): string
    {
        return "printf '%s\\n' '{$answer}'";
    }

    /**
     * @param  array{status: int, output: string, docker: list<string>, seconds: float}  $result
     * @return list<string>
     */
    private function linted(array $result): array
    {
        $files = explode(' ', trim(explode(' -S warning ', $this->lintCall($result))[1] ?? ''));

        sort($files);

        return $files;
    }

    /**
     * @param  array{status: int, output: string, docker: list<string>, seconds: float}  $result
     */
    private function lintCall(array $result): string
    {
        $calls = [];

        foreach ($result['docker'] as $line) {
            if (str_contains($line, 'shellcheck')) {
                $calls[] = $line;
            }
        }

        $this->assertCount(1, $calls, "The gate must lint the shell exactly once.\n".$result['output']);

        return $calls[0];
    }

    /**
     * Every file under scripts/, which is what the stubbed git reports by default.
     *
     * @return list<string>
     */
    private function everythingUnderScripts(): array
    {
        $files = [];

        foreach ($this->treeUnderScripts() as $file) {
            $files[] = substr($file->getPathname(), strlen($this->root()) + 1);
        }

        sort($files);

        return $files;
    }

    /**
     * Shell scripts by their name alone — no first-two-lines rule, which is the point.
     *
     * @return list<string>
     */
    private function shellScriptsByExtension(): array
    {
        $files = [];

        foreach ($this->everythingUnderScripts() as $file) {
            if (str_ends_with($file, '.sh')) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Every file under scripts/ the gate must lint: a .sh, or a shell declared in its first
     * two lines.
     *
     * @return list<string>
     */
    private function shellFilesOnDisk(): array
    {
        $files = [];

        foreach ($this->treeUnderScripts() as $file) {
            $path = substr($file->getPathname(), strlen($this->root()) + 1);

            if (str_ends_with($path, '.sh')) {
                $files[] = $path;

                continue;
            }

            $lines = file($file->getPathname());

            if ($lines === false) {
                $this->fail($file->getPathname().' could not be read.');
            }

            if (preg_match('/^#!.*sh|^# shellcheck shell=/m', implode('', array_slice($lines, 0, 2))) === 1) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    /** @return list<SplFileInfo> */
    private function treeUnderScripts(): array
    {
        $files = [];

        /** @var iterable<string, SplFileInfo> $tree */
        $tree = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/scripts', FilesystemIterator::SKIP_DOTS)
        );

        foreach ($tree as $file) {
            if ($file->isFile()) {
                $files[] = $file;
            }
        }

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

    private function withoutComments(string $script): string
    {
        return implode("\n", preg_grep('/^\s*#/', explode("\n", $script), PREG_GREP_INVERT) ?: []);
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function read(string $relative): string
    {
        $path = $this->root().'/'.$relative;

        $this->assertFileExists($path, "{$relative} is missing.");

        $contents = file_get_contents($path);

        $this->assertIsString($contents, "{$relative} could not be read.");

        return $contents;
    }
}
