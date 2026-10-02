<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The overlay runner against a throwaway root whose docker makes each bind target it
 * is handed, as the real one does. docs/DECISIONS.md, the-overlay-gate-removes-the-mount-points-it-caused
 */
final class GateMountPointsTest extends TestCase
{
    use RunsGateScripts;

    private const CLEARS_THE_BOX = 'rm -rf /var/tmp/orbit-gate.*';

    private const HANDS_OVER = 'chown -R 115:119';

    #[Test]
    public function a_mount_point_the_run_created_is_gone_after_a_run_that_failed(): void
    {
        $run = $this->runOverlay(prepare: function (string $root): void {
            $this->assertTrue(mkdir($root.'/bootstrap/cache', 0o755, true));
            file_put_contents($root.'/bootstrap/cache/.gitignore', "*\n");
        });

        $this->assertSame(1, $run['status'], "The fake composer install has to fail the run mid-way.\n".$run['output']);
        $this->assertTrue($run['docker made vendor'], "The fake docker never made vendor/, so nothing was proved.\n".$run['output']);
        $this->assertFalse(
            $run['after']['vendor'],
            'vendor/ was absent when the gate started and is still there after it: the root-owned '
            ."empty directory scripts/e2e.sh then trips over.\n".$run['output']
        );
        $this->assertTrue($run['after']['bootstrap/cache/.gitignore'], 'A directory that was there before keeps what it held.');
    }

    #[Test]
    public function a_mount_point_that_was_there_before_is_left_alone_even_empty(): void
    {
        $run = $this->runOverlay(prepare: function (string $root): void {
            $this->assertTrue(mkdir($root.'/vendor', 0o755));
            $this->assertTrue(mkdir($root.'/node_modules', 0o755));
            $this->assertTrue(mkdir($root.'/bootstrap/cache', 0o755, true));
        });

        $this->assertSame(1, $run['status'], $run['output']);
        $this->assertTrue($run['after']['vendor'], "An empty vendor/ that predates the run is not the gate's to remove.\n".$run['output']);
        $this->assertTrue($run['after']['node_modules'], 'Nor is an empty node_modules/.');
        $this->assertTrue($run['after']['bootstrap/cache'], 'Nor an empty bootstrap/cache.');
    }

    #[Test]
    public function a_mount_point_that_filled_up_stays_and_the_gate_says_so(): void
    {
        $run = $this->runOverlay(fill: true, prepare: function (string $root): void {
            $this->assertTrue(mkdir($root.'/bootstrap/cache', 0o755, true));
        });

        $this->assertSame(1, $run['status'], $run['output']);
        $this->assertTrue($run['after']['vendor/autoload.php'], 'rmdir only: what was written into it is never deleted.');
        $this->assertStringContainsString(
            'check.sh: left '.$run['root'].'/vendor in place: absent when the gate started, and it could not rmdir it',
            $run['output']
        );
    }

    /**
     * @param  callable(string): void  $prepare
     * @return array{status: int, output: string, root: string, 'docker made vendor': bool, after: array<string, bool>}
     */
    private function runOverlay(callable $prepare, bool $fill = false): array
    {
        $sandbox = sys_get_temp_dir().'/orbit-mount-points-'.bin2hex(random_bytes(6));
        $bin = $sandbox.'/bin';
        $root = $sandbox.'/root';

        $this->assertTrue(mkdir($bin, 0o700, true), "Could not create {$bin}");
        $this->assertTrue(mkdir($root.'/scripts/lib/deploy', 0o700, true), "Could not create {$root}");

        try {
            $here = (string) realpath($root);

            $script = $this->read('scripts/check.sh');
            $script = str_replace(self::CLEARS_THE_BOX, ':', $script, $cleared);
            $script = str_replace(self::HANDS_OVER, ':', $script, $handed);
            $this->assertSame(1, $cleared, 'The box-wide sweep has to be neutralised here, by name.');
            $this->assertSame(2, $handed, 'Both hand-overs have to be neutralised here, by name.');

            file_put_contents($root.'/scripts/check.sh', $script);
            file_put_contents($root.'/scripts/lib/deploy/ledger.sh', $this->read('scripts/lib/deploy/ledger.sh'));
            $prepare($root);

            $this->writeFakeGit($bin.'/git');
            $this->writeFakeDocker($bin.'/docker', $bin.'/made.log', $fill);

            $result = $this->execute(
                ['bash', $root.'/scripts/check.sh', 'overlay'],
                [
                    'PATH'        => $bin.':'.(getenv('PATH') ?: '/usr/bin:/bin'),
                    'CI_GIT'      => $bin.'/git',
                    'GATE_LEDGER' => $sandbox.'/ledger',
                ],
                $root
            );

            $after = [];
            foreach (['vendor', 'node_modules', 'bootstrap/cache', 'bootstrap/cache/.gitignore', 'vendor/autoload.php'] as $path) {
                $after[$path] = file_exists($root.'/'.$path);
            }

            $made = in_array('vendor', $this->readLog($bin.'/made.log'), true);
        } finally {
            $this->remove($sandbox);
        }

        return [
            'status'             => $result['status'],
            'output'             => $result['output'],
            'root'               => $here,
            'docker made vendor' => $made,
            'after'              => $after,
        ];
    }

    private function writeFakeGit(string $path): void
    {
        $script = <<<'SH'
            #!/bin/sh
            case "$*" in
                *'-- scripts') printf 'scripts/check.sh\0' ;;
                *rev-parse*)   printf '%s\n' 0000000000000000000000000000000000000000 ;;
                *)             : ;;
            esac
            SH;

        file_put_contents($path, $script);
        chmod($path, 0o700);
    }

    /** Makes every `-v <src>:/var/www/html/<dir>` target, then fails the first `compose run`. */
    private function writeFakeDocker(string $path, string $log, bool $fill): void
    {
        $filling = $fill ? 'touch vendor/autoload.php' : ':';

        $script = <<<SH
            #!/bin/sh
            [ "\$1 \$2" = 'compose run' ] || exit 0
            for arg in "\$@"; do
                case "\$arg" in
                    *:/var/www/html/*)
                        dir=\${arg#*:/var/www/html/}
                        mkdir -p "\$dir"
                        printf '%s\\n' "\$dir" >> '{$log}'
                        ;;
                esac
            done
            {$filling}
            exit 1
            SH;

        file_put_contents($path, $script);
        chmod($path, 0o700);
    }
}
