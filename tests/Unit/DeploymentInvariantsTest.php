<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use PHPUnit\Framework\Attributes\Test;

/**
 * The deployment invariants, asserted rather than remembered: silent
 * docker-compose.yml misconfigurations (capabilities, exposed ports, uid
 * drift) are invisible at runtime until the day someone reads the file.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
final class DeploymentInvariantsTest extends TestCase
{
    /** Services that run as the non-root `orbit` uid and hold no capabilities. */
    private const UNPRIVILEGED = ['app', 'horizon', 'scheduler', 'web', 'assets'];

    /** Services whose official entrypoint starts as root and drops privileges itself. */
    private const ROOT_ENTRYPOINT = ['postgres', 'redis'];

    private const EXPECTED_USER = '115:119';

    #[Test]
    public function every_service_forbids_privilege_escalation(): void
    {
        foreach ($this->services() as $name => $service) {
            $this->assertArrayHasKey('security_opt', $service, "{$name} has no security_opt");

            $options = $service['security_opt'];
            $this->assertIsArray($options);
            $this->assertContains(
                'no-new-privileges:true',
                $options,
                "{$name} does not set no-new-privileges — it is free on every service, including the ones whose entrypoint needs capabilities."
            );
        }
    }

    #[Test]
    public function the_unprivileged_services_drop_all_capabilities_and_run_as_orbit(): void
    {
        $services = $this->services();

        foreach (self::UNPRIVILEGED as $name) {
            $this->assertArrayHasKey($name, $services);
            $service = $services[$name];

            $this->assertArrayHasKey('cap_drop', $service, "{$name} must drop capabilities");
            $capabilities = $service['cap_drop'];
            $this->assertIsArray($capabilities);
            $this->assertSame(['ALL'], $capabilities, "{$name} must drop ALL capabilities");

            $this->assertArrayHasKey('user', $service, "{$name} must not run as root");
            $this->assertSame(
                self::EXPECTED_USER,
                $service['user'],
                "{$name} must run as the host orbit user, or files written through the bind mount come back owned by somebody else."
            );
        }
    }

    /**
     * Deliberate exception: both entrypoints start as root and need CHOWN,
     * SETUID, SETGID, DAC_OVERRIDE, FOWNER — `cap_drop: ALL` would not boot.
     */
    #[Test]
    public function the_datastores_keep_the_capabilities_their_entrypoints_need(): void
    {
        $services = $this->services();

        foreach (self::ROOT_ENTRYPOINT as $name) {
            $this->assertArrayHasKey($name, $services);
            $this->assertArrayNotHasKey(
                'cap_drop',
                $services[$name],
                "{$name} drops capabilities its entrypoint needs; it will not boot."
            );
        }
    }

    #[Test]
    public function nothing_is_published_off_the_loopback_interface(): void
    {
        $published = [];

        foreach ($this->services() as $name => $service) {
            if (! array_key_exists('ports', $service)) {
                continue;
            }

            $ports = $service['ports'];
            $this->assertIsArray($ports);

            foreach ($ports as $mapping) {
                $this->assertIsString($mapping, "{$name} publishes a port in long syntax; this test only understands the short one.");
                $this->assertStringStartsWith(
                    '127.0.0.1:',
                    $mapping,
                    "{$name} publishes {$mapping} on a non-loopback address. The host nginx is the only thing that may reach this stack."
                );

                $published[$name][] = $mapping;
            }
        }

        $this->assertSame(
            ['web' => ['127.0.0.1:3085:8080']],
            $published,
            'The nginx sidecar on 127.0.0.1:3085 is the whole public surface of this stack.'
        );
    }

    /**
     * postgres:18 moved PGDATA under /var/lib/postgresql/18/docker; mounting
     * the deeper path silently loses all data on the next recreate.
     */
    #[Test]
    public function the_postgres_volume_mounts_the_parent_of_pgdata(): void
    {
        $services = $this->services();
        $this->assertArrayHasKey('postgres', $services);
        $this->assertArrayHasKey('volumes', $services['postgres']);

        $volumes = $services['postgres']['volumes'];
        $this->assertIsArray($volumes);
        $this->assertSame(['orbit-pgdata:/var/lib/postgresql'], $volumes);
    }

    /**
     * The image builds a user with these ids; compose runs the container as
     * them. Drift = a uid that owns nothing in its own image.
     */
    #[Test]
    public function the_image_builds_the_uid_that_compose_runs_as(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 2).'/docker/app/Dockerfile');
        $this->assertIsString($dockerfile);

        [$uid, $gid] = explode(':', self::EXPECTED_USER);

        $this->assertMatchesRegularExpression('/^ARG APP_UID='.$uid.'$/m', $dockerfile);
        $this->assertMatchesRegularExpression('/^ARG APP_GID='.$gid.'$/m', $dockerfile);

        foreach (['app', 'horizon', 'scheduler'] as $name) {
            $service = $this->services()[$name];
            $this->assertArrayHasKey('build', $service);
            $this->assertIsArray($service['build']);
            $this->assertSame(
                ['APP_UID' => $uid, 'APP_GID' => $gid],
                $service['build']['args'],
                "{$name} builds the image with different ids than it runs as."
            );
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function services(): array
    {
        $compose = Yaml::parseFile(dirname(__DIR__, 2).'/docker-compose.yml');

        $this->assertIsArray($compose);
        $this->assertArrayHasKey('services', $compose);
        $this->assertIsArray($compose['services']);

        /** @var array<string, array<string, mixed>> $services */
        $services = $compose['services'];

        return $services;
    }
}
