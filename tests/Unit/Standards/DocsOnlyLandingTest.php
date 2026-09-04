<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Runs scripts/docs-only.sh for real. It is what decides whether a merge lands
 * or deploys (docs/DECISIONS.md: a-docs-only-merge-lands-it-does-not-deploy).
 */
final class DocsOnlyLandingTest extends TestCase
{
    private const SCRIPT = 'scripts/docs-only.sh';

    private const RUNBOOK = '.claude/commands/deploy.md';

    /** @param  list<string>  $paths */
    #[Test]
    #[TestWith([['README.md']])]
    #[TestWith([['docs/API.md', 'design/README.md']])]
    #[TestWith([['CHANGELOG.md', 'LICENSE', 'docs/deep/nested/note.md']])]
    public function documentation_lands(array $paths): void
    {
        $result = $this->classify($paths);

        $this->assertSame(0, $result['status'], "Expected a landing for:\n".implode("\n", $paths));
        $this->assertStringContainsString('DOCS-ONLY: '.count($paths).' file(s)', $result['output']);
    }

    #[Test]
    #[TestWith(['docs/STANDARDS.md', 'It is loaded as agent instructions through .claude/rules/standards.md.'])]
    #[TestWith(['docs/GO-LIVE.md', 'It is a procedure, and running it is the only test it gets.'])]
    #[TestWith(['.claude/commands/deploy.md', 'A runbook is checked by being run, never by being read.'])]
    #[TestWith(['scripts/docs-only.sh', 'The script that decides this cannot decide its own landing.'])]
    #[TestWith(['composer.lock', 'A lockfile move is an install.'])]
    #[TestWith(['.env.example', 'It is read by the deploy, not by a person.'])]
    #[TestWith(['resources/js/App.vue', 'A screen is code.'])]
    #[TestWith(['README-assets/evil.php', 'A `*` in a case pattern spans `/`, so a README* arm would admit a whole subtree.'])]
    #[TestWith(['LICENSES/x.php', 'LICENSES/ is a directory, not the LICENSE file.'])]
    public function code_deploys(string $path, string $why): void
    {
        $result = $this->classify([$path]);

        $this->assertSame(1, $result['status'], "{$path} was allowed to land as documentation. {$why}");
        $this->assertStringContainsString("CODE: {$path}", $result['output']);
    }

    #[Test]
    public function one_code_file_makes_the_whole_merge_a_deploy(): void
    {
        $result = $this->classify(['README.md', 'app/Models/Route.php']);

        $this->assertSame(1, $result['status'], 'A merge carrying code alongside a README must deploy.');
        $this->assertStringContainsString('CODE: app/Models/Route.php', $result['output']);
        $this->assertStringNotContainsString(
            'README.md',
            $result['output'],
            'The script names the files that force a deploy. Naming the innocent ones too sends '
            .'the reader looking for what is wrong with a README.'
        );
    }

    #[Test]
    public function an_empty_change_set_is_neither(): void
    {
        $result = $this->classify([]);

        $this->assertSame(2, $result['status'], 'Nothing to land is its own answer, not a landing.');
        $this->assertStringContainsString('NOTHING TO LAND', $result['output']);
    }

    #[Test]
    public function the_runbook_decides_before_it_deploys(): void
    {
        $runbook = $this->read(self::RUNBOOK);

        $landing = strpos($runbook, "\n## Docs-only landing");
        $deploy = strpos($runbook, "\n## Deploy steps");

        $this->assertIsInt($landing, 'The deploy runbook has no "## Docs-only landing" section.');
        $this->assertIsInt($deploy, 'The deploy runbook has no "## Deploy steps" section.');
        $this->assertLessThan(
            $deploy,
            $landing,
            'The landing section must be read before the deploy steps, or it is read after the '
            .'gate it exists to skip has already run.'
        );

        preg_match_all('/^[ \t]*```[a-z]*$(.*?)^[ \t]*```$/ms', $runbook, $fences);

        $this->assertMatchesRegularExpression(
            '/^\s*(?:cd \S+ && )?\S*scripts\/docs-only\.sh \S+\s*$/m',
            implode("\n", $fences[1]),
            'The runbook describes the docs-only decision without running the script that makes '
            .'it. A file list read by a person is exactly what this replaces.'
        );
    }

    #[Test]
    public function the_landing_merges_the_sha_it_classified(): void
    {
        $commands = $this->landingCommands();

        $this->assertStringContainsString(
            'merge --ff-only "$sha"',
            $commands,
            'The landing section no longer runs the merge it classified. Classifying and '
            .'merging share one fenced block because each block runs in a fresh shell: split '
            .'them and $sha is empty by the time merge sees it, so the landing merges nothing.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\bgit (-C \S+ )?pull\b/',
            $commands,
            'The landing section pulls. A pull fetches again, so it can land a commit newer '
            .'than the one docs-only.sh classified — untested code, through the path that '
            .'exists precisely because nothing needed testing. Naming `git pull` in prose is '
            .'fine; only the fenced blocks are read here.'
        );
    }

    /** The fenced blocks between the landing heading and the deploy steps, not the prose. */
    private function landingCommands(): string
    {
        $runbook = $this->read(self::RUNBOOK);

        $from = strpos($runbook, "\n## Docs-only landing");
        $to = strpos($runbook, "\n## Deploy steps");

        $this->assertIsInt($from, 'The deploy runbook has no "## Docs-only landing" section.');
        $this->assertIsInt($to, 'The deploy runbook has no "## Deploy steps" section.');
        $this->assertGreaterThan($from, $to, 'The landing section runs past the deploy steps.');

        preg_match_all(
            '/^[ \t]*```[a-z]*$(.*?)^[ \t]*```$/ms',
            substr($runbook, $from, $to - $from),
            $fences
        );

        $this->assertNotSame([], $fences[1], 'The landing section has no fenced commands at all.');

        return implode("\n", $fences[1]);
    }

    #[Test]
    public function a_root_readme_lands_whatever_it_is_called(): void
    {
        $result = $this->classify(['README.php']);

        $this->assertSame(
            0,
            $result['status'],
            'The three root files are matched by prefix and the extension is not read, so a root '
            .'README.php lands. That is the deliberate choice: nothing loads a root-level PHP file '
            .'— the only entry point is public/index.php — and narrowing this to a list of '
            .'documentation extensions buys nothing against a file that has to be added on purpose. '
            .'Change it here, and in docs/DECISIONS.md, if that stops being true.'
        );
    }

    #[Test]
    public function a_file_moved_out_of_the_app_is_not_documentation(): void
    {
        $result = $this->classify(['app/Foo.php', 'docs/Foo.php']);

        $this->assertSame(1, $result['status'], 'A rename out of app/ into docs/ must deploy.');
        $this->assertStringContainsString('CODE: app/Foo.php', $result['output']);
    }

    #[Test]
    public function the_diff_is_asked_not_to_detect_renames(): void
    {
        $this->assertMatchesRegularExpression(
            '/git diff --no-renames --name-only/',
            $this->read(self::SCRIPT),
            'Without --no-renames git reports `git mv app/Foo.php docs/Foo.php` as the destination '
            .'alone, the change set reads as one Markdown file, and a PHP file lands with no gate '
            .'and no restart.'
        );
    }

    #[Test]
    public function a_rename_into_docs_is_code_in_a_real_repository(): void
    {
        if (trim((string) shell_exec('command -v git 2>/dev/null')) === '') {
            $this->markTestSkipped('No git in this image, so the rename cannot be staged for real here.');
        }

        $sandbox = sys_get_temp_dir().'/orbit-docs-only-'.bin2hex(random_bytes(6));

        $script = str_replace(
            ['{SANDBOX}', '{SCRIPT}'],
            [$sandbox, dirname(__DIR__, 3).'/'.self::SCRIPT],
            <<<'SH'
                set -e
                export GIT_CONFIG_NOSYSTEM=1 HOME={SANDBOX}
                mkdir -p {SANDBOX}/repo/app {SANDBOX}/repo/scripts
                cd {SANDBOX}/repo
                git init -q
                git config user.email t@example.test
                git config user.name t
                cp {SCRIPT} scripts/docs-only.sh
                printf '<?php\n' > app/Foo.php
                printf 'x\n' > README.md
                git add app/Foo.php README.md scripts/docs-only.sh
                git commit -qm base
                git rev-parse HEAD > {SANDBOX}/base
                mkdir -p docs README-assets LICENSES
                git mv app/Foo.php docs/Foo.php
                printf '<?php\n' > README-assets/evil.php
                printf '<?php\n' > LICENSES/x.php
                git add docs/Foo.php README-assets/evil.php LICENSES/x.php
                git commit -qm moved
                git rev-parse HEAD > {SANDBOX}/tip
                git checkout -q "$(cat {SANDBOX}/base)"
                bash scripts/docs-only.sh "$(cat {SANDBOX}/tip)"
            SH
        );

        $result = $this->execute(['bash', '-c', $script]);

        $this->assertSame(
            1,
            $result['status'],
            "A rename out of app/ and two files under README-/LICENSE- directories all landed:\n"
            .$result['output']
        );
        $this->assertStringContainsString('CODE: app/Foo.php', $result['output']);
        $this->assertStringContainsString('CODE: README-assets/evil.php', $result['output']);
        $this->assertStringContainsString('CODE: LICENSES/x.php', $result['output']);

        $this->remove($sandbox);
    }

    #[Test]
    public function every_document_loaded_as_agent_instructions_is_excluded(): void
    {
        $root = dirname(__DIR__, 3);
        $rules = glob($root.'/.claude/rules/*') ?: [];

        $this->assertNotSame([], $rules, '.claude/rules/ is empty, so this test vets nothing.');

        $script = $this->read(self::SCRIPT);
        $checked = 0;

        foreach ($rules as $rule) {
            $target = realpath($rule);

            if ($target === false || ! str_starts_with($target, $root.'/')) {
                continue;
            }

            $relative = substr($target, strlen($root) + 1);

            if (! str_starts_with($relative, 'docs/') && ! str_starts_with($relative, 'design/')) {
                continue;
            }

            $checked++;

            $this->assertStringContainsString(
                $relative,
                $script,
                "{$relative} is loaded as agent instructions through .claude/rules/, so a change to "
                .'it changes how the next change gets built — but the classifier would land it as '
                .'documentation with no gate. Add it to the exclusion arm.'
            );
        }

        $this->assertGreaterThan(0, $checked, 'No rule resolved into docs/ or design/ to vet.');
    }

    /**
     * @param  list<string>  $paths
     * @return array{status: int, output: string}
     */
    private function classify(array $paths): array
    {
        return $this->execute(
            ['bash', dirname(__DIR__, 3).'/'.self::SCRIPT, '--paths'],
            $paths === [] ? '' : implode("\n", $paths)."\n"
        );
    }

    /**
     * @param  list<string>  $command
     * @return array{status: int, output: string}
     */
    private function execute(array $command, string $stdin = ''): array
    {
        $pipes = [];

        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 3)
        );

        if ($process === false) {
            $this->fail('Could not start '.implode(' ', $command));
        }

        fwrite($pipes[0], $stdin);
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
