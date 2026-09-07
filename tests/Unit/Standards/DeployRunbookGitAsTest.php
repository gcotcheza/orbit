<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * /var/www/orbit is owned by the orbit user and root's git has no safe.directory
 * entry for it, so a `git` left in the runbook fails there with "dubious
 * ownership" and nowhere else — not in this suite, not in a worktree.
 */
final class DeployRunbookGitAsTest extends TestCase
{
    private const RUNBOOK = '.claude/commands/deploy.md';

    private const SCRIPT = 'scripts/docs-only.sh';

    private const SEAM = 'git-as orbit -C /var/www/orbit';

    /** Measured on the runbook this test landed with: 15 lines carry the seam. */
    private const SEAM_LINES = 15;

    /**
     * `.claude/` is carved out because the orbit session's Claude Code runtime
     * writes root-owned files there; docs/DECISIONS.md says until when.
     */
    private const PROOF = "find /var/www/orbit -user root -not -path '/var/www/orbit/.claude/*'";

    /** The value is a command WITH FLAGS; recording it proves $GIT still splits. */
    private const SEAM_FLAG = '--as=orbit';

    /** Saying a dropped shape out loud is allowed; teaching it is not. */
    private const QUOTING_THE_OLD_SHAPE = ['used to', 'no longer'];

    #[Test]
    public function no_command_in_the_runbook_runs_git_as_root(): void
    {
        $offenders = [];
        $scanned = 0;

        foreach ($this->fencedBlocks() as $block) {
            if ($this->isPrivateClone($block)) {
                continue;
            }

            foreach ($block as $number => $line) {
                $scanned++;

                if (preg_match('#(?<![\w./-])git(?!-as\b)\b#', $line) === 1) {
                    $offenders[] = "{$number}: ".trim($line);
                }
            }
        }

        $this->assertGreaterThan(0, $scanned, 'No fenced command was scanned; the runbook lost its blocks.');
        $this->assertSame(
            [],
            $offenders,
            "Every git in this runbook runs inside /var/www/orbit, which root's git refuses:\n"
            .implode("\n", $offenders)
        );
    }

    #[Test]
    public function nothing_in_the_runbook_pushes_from_the_served_tree(): void
    {
        $offenders = [];

        foreach ($this->fencedLines() as $number => $line) {
            if (! str_contains($line, '/var/www')) {
                continue;
            }

            if (preg_match('/\bgit(?:-as)?\b.*\bpush\b/', $line) === 1) {
                $offenders[] = "{$number}: ".trim($line);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "git-as pushes with the app's deploy key, which GitHub registered read-only — "
            .'`ERROR: The key you are authenticating with has been marked as read only` — and a '
            .'worktree under the served tree is orbit-owned too, so it is the same refused key '
            ."while root's git cannot enter either. The revert PR comes from a private clone:\n"
            .implode("\n", $offenders)
        );
    }

    #[Test]
    public function the_rollback_resets_on_disk_and_reverts_by_pull_request(): void
    {
        $start = strpos($this->read(self::RUNBOOK), "\n## Rollback");

        $this->assertIsInt($start, 'The runbook has no "## Rollback" section.');

        $section = substr($this->read(self::RUNBOOK), $start);

        $this->assertStringContainsString(
            self::SEAM.' reset --hard',
            $section,
            'The on-disk rollback can no longer be a revert plus a push, because the box cannot '
            .'push. What puts the checkout back is a reset to the sha pre-flight check 3 printed.'
        );
        $this->assertMatchesRegularExpression(
            '#git clone \S+ /srv/worker-scratch/\S+#',
            $section,
            'The revert for the record is a pull request from a root-owned private clone. Naming '
            .'where it is made is the whole point: the two trees that cannot make it are the '
            .'served checkout and any worktree under it.'
        );
        $this->assertStringContainsString(
            'git revert -m 1 --no-edit',
            $section,
            "The revert itself is unchanged — -m 1 keeps main's side of the merge — only where it "
            .'is made has moved.'
        );
        $this->assertMatchesRegularExpression(
            '/^git switch -c revert\/<sha>/m',
            $section,
            'A fresh clone is on main, so a revert committed there has main as its head and '
            .'`gh pr create --base main` is asked for a pull request from a branch into itself. '
            .'The revert needs its own branch before it is made.'
        );
        $this->assertMatchesRegularExpression(
            '/^gh pr create .*--fill.*--head revert\/<sha>/m',
            $section,
            'gh takes its head from the current branch and prompts for a title when neither '
            .'--fill nor --title is given — which hangs the non-interactive shell a runbook is '
            .'read into. Name the head and fill the body from the commit.'
        );
    }

    #[Test]
    public function the_agent_runtimes_local_settings_are_ignored(): void
    {
        $this->assertMatchesRegularExpression(
            '#^\.claude/settings\.local\.json$#m',
            $this->read('.gitignore'),
            'Claude Code writes .claude/settings.local.json into its working directory, which for '
            .'the orbit session IS the served checkout. Untracked and unignored it shows up as '
            .'`?? .claude/settings.local.json` in pre-flight check 2, whose expected output is '
            .'nothing at all — so every deploy stops on a per-machine permissions file. It is '
            .'local by definition and belongs in .gitignore, not in a runbook exception.'
        );
    }

    #[Test]
    public function a_classifier_seam_pointing_at_another_tree_is_refused(): void
    {
        $elsewhere = $this->runScript([], seamDirectory: '/elsewhere');

        $this->assertSame(
            3,
            $elsewhere['status'],
            "A seam naming another tree has to be refused, not classified.\n".$elsewhere['output']
        );
        $this->assertStringContainsString(
            'DOCS_ONLY_GIT points at /elsewhere but this script runs in',
            $elsewhere['output'],
            'The refusal names both directories, because the whole failure is that they differ.'
        );
        $this->assertSame(
            [],
            $elsewhere['seam'],
            'The refusal comes before the first git call: nothing may be classified against the '
            .'wrong tree.'
        );

        $matching = $this->runScript(
            ['STUB_DIFF_PATHS' => 'docs/API.md'],
            seamDirectory: dirname(__DIR__, 3)
        );

        $this->assertSame(
            0,
            $matching['status'],
            "The runbook's own export names one tree twice and must pass this guard.\n".$matching['output']
        );
        $this->assertStringContainsString('DOCS-ONLY: 1 file(s)', $matching['output']);
    }

    #[Test]
    public function no_document_teaches_the_shape_the_runbook_dropped(): void
    {
        $offenders = [];
        $scanned = 0;

        foreach ($this->pagesThatTeach() as $relative => $contents) {
            foreach (explode("\n", $contents) as $index => $line) {
                $scanned++;

                if (preg_match('#(?<![\w./-])git -C /var/www/orbit|chown -R orbit:orbit#', $line) !== 1) {
                    continue;
                }

                foreach (self::QUOTING_THE_OLD_SHAPE as $marker) {
                    if (str_contains($line, $marker)) {
                        continue 2;
                    }
                }

                $offenders[] = "{$relative}:".($index + 1).': '.trim($line);
            }
        }

        $this->assertGreaterThan(0, $scanned, 'No page was scanned, so this test vets nothing.');
        $this->assertSame(
            [],
            $offenders,
            "Root's git cannot enter /var/www/orbit and the blanket chown repairs a problem the "
            .'deploy no longer has, so a page still teaching either shape hands its reader a '
            .'command that fails or a repair that hides the next one. Write the current shape, or '
            .'say on the same line that it is what this "used to"/"no longer" be:'."\n"
            .implode("\n", $offenders)
        );
    }

    #[Test]
    public function every_git_in_the_runbook_names_the_app_and_the_checkout(): void
    {
        $carrying = 0;
        $partial = [];

        foreach ($this->fencedLines() as $number => $line) {
            if (! str_contains($line, 'git-as')) {
                continue;
            }

            if (substr_count($line, self::SEAM) === substr_count($line, 'git-as')) {
                $carrying++;

                continue;
            }

            $partial[] = "{$number}: ".trim($line);
        }

        $this->assertSame(
            [],
            $partial,
            "git-as takes the app and the directory or it runs somewhere this check never saw:\n"
            .implode("\n", $partial)
        );
        $this->assertGreaterThanOrEqual(
            self::SEAM_LINES,
            $carrying,
            'The runbook landed with '.self::SEAM_LINES.' lines through the seam. Fewer means a git '
            .'step was dropped or put back on root git, and the count is what makes this test '
            .'unable to pass on an empty file.'
        );
    }

    #[Test]
    public function no_chown_repairs_a_git_step(): void
    {
        $offenders = [];
        $blocksWithGit = 0;

        foreach ($this->fencedBlocks() as $block) {
            $git = null;

            foreach ($block as $number => $line) {
                if (str_contains($line, 'git')) {
                    $git ??= $number;

                    continue;
                }

                if ($git !== null && str_contains($line, 'chown')) {
                    $offenders[] = "{$number}: ".trim($line);
                }
            }

            $blocksWithGit += $git === null ? 0 : 1;
        }

        $this->assertGreaterThan(0, $blocksWithGit, 'No fenced block runs git; there is nothing to guard.');
        $this->assertSame(
            [],
            $offenders,
            'git-as leaves nothing root-owned, so a chown after a git step is repairing a problem '
            ."this runbook no longer has — and it hides the day it comes back:\n".implode("\n", $offenders)
        );
    }

    #[Test]
    public function the_runbook_hands_the_classifier_the_same_git(): void
    {
        $this->assertMatchesRegularExpression(
            "/^export DOCS_ONLY_GIT='".preg_quote(self::SEAM, '/')."'$/m",
            $this->read(self::RUNBOOK),
            'The runbook fetches and merges through git-as but would leave the classifier on '
            ."root's git, which cannot read that checkout at all. The seam needs its caller."
        );
    }

    #[Test]
    public function the_runbook_hands_the_gate_the_same_git(): void
    {
        $exported = "export CI_GIT='".self::SEAM."'";
        $unexported = [];
        $calls = 0;

        foreach ($this->fencedBlocks() as $block) {
            $seen = false;

            foreach ($block as $number => $line) {
                if (str_contains($line, $exported)) {
                    $seen = true;
                }

                if (! str_contains($line, '/var/www/orbit/scripts/check.sh')) {
                    continue;
                }

                $calls++;

                if (! $seen) {
                    $unexported[] = "{$number}: ".trim($line);
                }
            }
        }

        $this->assertGreaterThan(
            0,
            $calls,
            'No fenced block runs the gate against /var/www/orbit, so this test guards nothing.'
        );
        $this->assertSame(
            [],
            $unexported,
            "The gate's secrets step lists the deployed checkout with git, and root's git refuses "
            ."it. Without CI_GIT, earlier in the same block, the run stops in its first step:\n"
            .implode("\n", $unexported)
        );
    }

    #[Test]
    public function every_ownership_proof_carves_out_the_agent_runtime(): void
    {
        $narrow = 0;
        $blunt = [];

        foreach ($this->fencedLines() as $number => $line) {
            if (! str_contains($line, 'find /var/www/orbit -user root')) {
                continue;
            }

            if (str_contains($line, self::PROOF)) {
                $narrow++;

                continue;
            }

            $blunt[] = "{$number}: ".trim($line);
        }

        $this->assertSame(
            [],
            $blunt,
            'The orbit session runs Claude Code with /var/www/orbit as its working directory and '
            .'leaves root-owned runtime files under .claude/ (scheduled_tasks.lock today). They are '
            .'in .git/info/exclude and never deploy, so a count that includes them reads as a '
            ."repair due on every deploy and trains the reader to ignore the number:\n"
            .implode("\n", $blunt)
        );
        $this->assertGreaterThanOrEqual(
            5,
            $narrow,
            'The runbook proves its ownership claim in five places (the landing block, the '
            .'landing check, deploy step 2, the post-deploy battery and the rollback). Fewer '
            .'means a proof was dropped, and the count is what stops this passing on prose.'
        );
    }

    #[Test]
    public function the_classifier_runs_every_git_call_through_the_seam(): void
    {
        $result = $this->runScript(['STUB_DIFF_PATHS' => 'docs/API.md README.md']);

        $this->assertSame(0, $result['status'], "Expected a docs-only landing.\n".$result['output']);
        $this->assertStringContainsString('DOCS-ONLY: 2 file(s)', $result['output']);
        $this->assertSame(
            [
                'rev-parse --verify --quiet deadbee^{commit}',
                'rev-parse HEAD',
                'merge-base --is-ancestor 1111111 2222222',
                'diff --no-renames --name-only 1111111 2222222',
            ],
            $result['seam'],
            'Every git call on the landing path must reach the seam, in this order.'
        );
        $this->assertSame([], $result['plain'], 'A call site is still on plain `git`: '.implode(', ', $result['plain']));
    }

    #[Test]
    public function the_refusal_path_reaches_the_call_sites_the_landing_never_runs(): void
    {
        $result = $this->runScript(['STUB_ANCESTOR_EXIT' => '1']);

        $this->assertSame(3, $result['status'], 'A HEAD the merge does not contain is refused.');
        $this->assertStringContainsString('not an ancestor', $result['output']);
        $this->assertSame(
            [
                'rev-parse --verify --quiet deadbee^{commit}',
                'rev-parse HEAD',
                'merge-base --is-ancestor 1111111 2222222',
                'rev-parse --short HEAD',
                'rev-parse --short 2222222',
            ],
            $result['seam'],
            'The refusal message builds two shas of its own. Those run only here.'
        );
        $this->assertSame([], $result['plain'], 'A call site is still on plain `git`: '.implode(', ', $result['plain']));
    }

    #[Test]
    public function without_the_variable_the_classifier_uses_plain_git(): void
    {
        $result = $this->runScript(['STUB_DIFF_PATHS' => 'docs/API.md'], seam: false);

        $this->assertSame(0, $result['status'], "Expected a docs-only landing.\n".$result['output']);
        $this->assertSame([], $result['seam'], 'Nothing may reach the seam when DOCS_ONLY_GIT is unset.');
        $this->assertSame(
            [
                'rev-parse --verify --quiet deadbee^{commit}',
                'rev-parse HEAD',
                'merge-base --is-ancestor 1111111 2222222',
                'diff --no-renames --name-only 1111111 2222222',
            ],
            $result['plain'],
            'The default has to stay plain `git`, or the gate and every developer checkout need an '
            .'environment variable to run the script at all.'
        );
    }

    /**
     * The runbook's fenced blocks, keyed by line number so a failure names the line
     * a reader has to open. Runnable lines only: a `#` comment is not a command.
     *
     * @return list<array<int, string>>
     */
    private function fencedBlocks(): array
    {
        $blocks = [];
        $block = null;

        foreach (explode("\n", $this->read(self::RUNBOOK)) as $index => $line) {
            if (preg_match('/^[ \t]*```/', $line) === 1) {
                if ($block === null) {
                    $block = [];
                } else {
                    $blocks[] = $block;
                    $block = null;
                }

                continue;
            }

            if ($block !== null && trim($line) !== '' && ! str_starts_with(trim($line), '#')) {
                $block[$index + 1] = $line;
            }
        }

        return $blocks;
    }

    /** @return array<int, string> */
    private function fencedLines(): array
    {
        return array_replace([], ...$this->fencedBlocks());
    }

    /**
     * A block that works in a private clone and never names the served tree. Root's
     * git is refused by /var/www/orbit and by nothing else, so plain git belongs here.
     *
     * @param  array<int, string>  $block
     */
    private function isPrivateClone(array $block): bool
    {
        $joined = implode("\n", $block);

        return str_contains($joined, '/srv/worker-scratch') && ! str_contains($joined, '/var/www/orbit');
    }

    /**
     * The pages an operator or a contributor copies commands out of.
     *
     * @return array<string, string>
     */
    private function pagesThatTeach(): array
    {
        $root = dirname(__DIR__, 3);
        $pages = [];

        foreach ([...(glob($root.'/docs/*.md') ?: []), $root.'/scripts/e2e.sh'] as $path) {
            $relative = substr($path, strlen($root) + 1);

            $this->assertFileExists($path, "{$relative} is missing.");

            $contents = file_get_contents($path);

            $this->assertIsString($contents, "{$relative} could not be read.");

            $pages[$relative] = $contents;
        }

        return $pages;
    }

    /**
     * @param  array<string, string>  $stub
     * @return array{status: int, output: string, seam: list<string>, plain: list<string>}
     */
    private function runScript(array $stub, bool $seam = true, ?string $seamDirectory = null): array
    {
        $root = dirname(__DIR__, 3);
        $bin = sys_get_temp_dir().'/orbit-git-seam-'.bin2hex(random_bytes(6));

        $this->assertTrue(mkdir($bin, 0o700), "Could not create {$bin}");

        $this->writeFake($bin.'/git', $bin.'/plain.log', '');
        $this->writeFake($bin.'/fake-git', $bin.'/seam.log', self::SEAM_FLAG);

        $environment = ['PATH' => $bin.':'.(getenv('PATH') ?: '/usr/bin:/bin')] + $stub;

        if ($seam || $seamDirectory !== null) {
            $environment['DOCS_ONLY_GIT'] = $bin.'/fake-git '.self::SEAM_FLAG
                .($seamDirectory === null ? '' : ' -C '.$seamDirectory);
        }

        $result = $this->execute(['bash', $root.'/'.self::SCRIPT, 'deadbee'], $environment, $root);
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

    /** A git that records its argv and then answers the four subcommands the script asks for. */
    private function writeFake(string $path, string $log, string $expectedFirstArgument): void
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
            sub=\$1
            shift
            case "\$sub" in
                rev-parse)
                    case "\$*" in
                        --short\\ HEAD)  printf '1111111\\n' ;;
                        --short*)       printf '2222222\\n' ;;
                        *HEAD*)         printf '1111111\\n' ;;
                        *)              printf '2222222\\n' ;;
                    esac
                    ;;
                merge-base) exit "\${STUB_ANCESTOR_EXIT:-0}" ;;
                diff)
                    for path in \${STUB_DIFF_PATHS:-}; do printf '%s\\n' "\$path"; done
                    ;;
                *)
                    printf 'FAKE GIT: unexpected subcommand %s\\n' "\$sub" >&2
                    exit 98
                    ;;
            esac
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
