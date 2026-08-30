<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use FilesystemIterator;
use RecursiveIteratorIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use PHPUnit\Framework\Attributes\Test;

/**
 * The two things deptrac cannot see for itself (docs/DECISIONS.md:
 * the-layer-rule-is-executed-not-reviewed).
 */
final class LayerCoverageTest extends TestCase
{
    #[Test]
    public function every_class_under_app_belongs_to_a_layer(): void
    {
        [$status, $output] = $this->shell('vendor/bin/deptrac debug:unassigned --no-cache 2>&1');

        $this->assertSame(
            0,
            $status,
            "deptrac has classes it files in no layer, so nothing it does applies to them:\n"
            .$output."\nA new directory under app/ needs a layer in deptrac.yaml before it is "
            .'used; --fail-on-uncovered only sees the dependencies pointing AT it.'
        );
    }

    #[Test]
    public function the_domain_calls_no_function_php_does_not_ship(): void
    {
        $called = [];

        foreach ($this->domainFiles() as $path) {
            foreach ($this->bareCalls((string) file_get_contents($path)) as $function) {
                if (! function_exists($function) || ! $this->isInternal($function)) {
                    $called[$function][] = basename($path);
                }
            }
        }

        $this->assertSame(
            [],
            $called,
            'app/Domain calls a global function PHP does not ship — config(), now() and collect() '
            ."are the framework arriving through a door deptrac cannot see:\n"
            .var_export($called, true)
        );
    }

    /** @return list<string> */
    private function bareCalls(string $php): array
    {
        $tokens = token_get_all($php);
        $skip = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_ATTRIBUTE, T_CONST];
        $found = [];

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || ($token[0] !== T_STRING && $token[0] !== T_NAME_FULLY_QUALIFIED)) {
                continue;
            }
            if (in_array($this->neighbour($tokens, $i, -1)[0] ?? null, $skip, true)) {
                continue;
            }
            if ($this->neighbour($tokens, $i, 1) !== '(') {
                continue;
            }

            $found[] = ltrim($token[1], '\\');
        }

        return $found;
    }

    /**
     * @param  array<int, array{int, string, int}|string>  $tokens
     * @return array{int, string, int}|string|null
     */
    private function neighbour(array $tokens, int $from, int $step): array|string|null
    {
        for ($i = $from + $step; isset($tokens[$i]); $i += $step) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $tokens[$i];
        }

        return null;
    }

    private function isInternal(string $function): bool
    {
        static $internal = null;

        $internal ??= array_flip(array_map('strtolower', get_defined_functions()['internal']));

        return isset($internal[strtolower($function)]);
    }

    /** @return list<string> */
    private function domainFiles(): array
    {
        $found = [];

        $tree = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/app/Domain', FilesystemIterator::SKIP_DOTS)
        );

        foreach ($tree as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }

        $this->assertNotSame([], $found, 'No Domain files were read, so this test asserted nothing.');

        return $found;
    }

    /** @return array{int, string} */
    private function shell(string $command): array
    {
        $output = [];
        $status = 0;

        exec('cd '.escapeshellarg($this->root()).' && '.$command, $output, $status);

        return [$status, implode("\n", $output)];
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
