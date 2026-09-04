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

    /**
     * @param  list<string>  $paths
     * @return array{status: int, output: string}
     */
    private function classify(array $paths): array
    {
        $root = dirname(__DIR__, 3);
        $pipes = [];

        $process = proc_open(
            ['bash', $root.'/'.self::SCRIPT, '--paths'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root
        );

        if ($process === false) {
            $this->fail('Could not start '.self::SCRIPT);
        }

        fwrite($pipes[0], $paths === [] ? '' : implode("\n", $paths)."\n");
        fclose($pipes[0]);

        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'output' => $output];
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
