<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The catch-all, and the four things it must never swallow.
 *
 * routes/web.php answers every unclaimed path with one HTML document and lets
 * vue-router decide what it meant. That is what makes a route detail
 * bookmarkable and what lets the PWA be launched straight into one — and it is
 * also a route that matches almost everything, which is the risk. A regex that
 * is one character too greedy turns the health endpoint, the JSON API or the
 * queue dashboard into a 200 of HTML, and every one of those failures looks
 * like success to whatever is asking.
 *
 * `withoutVite()` throughout: these tests are about ROUTING, and without it
 * every one of them would additionally depend on `npm run build` having been
 * run, so a fresh checkout would fail them for a reason that has nothing to do
 * with what they check.
 */
final class SpaShellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function clientRoutes(): array
    {
        return [
            'home'                    => ['/'],
            'login'                   => ['/login'],
            'calendar'                => ['/calendar'],
            'create'                  => ['/create'],
            'watchlist'               => ['/watch'],
            'alerts'                  => ['/alerts'],
            'route detail'            => ['/route/AMS-LIS'],
            'a path no screen claims' => ['/something-nobody-built'],
        ];
    }

    /**
     * Including for a GUEST, deliberately: the shell is identical for everyone
     * and carries no user data, so there is nothing to leak and nothing to
     * vary. Authentication happens in the client, against GET /api/me.
     */
    #[Test]
    #[DataProvider('clientRoutes')]
    public function every_client_route_is_served_the_same_shell(string $path): void
    {
        $response = $this->get($path);

        $response->assertOk();
        $response->assertSee('<div id="app">', false);
        $response->assertSee('<title>Orbit</title>', false);
    }

    #[Test]
    public function the_shell_carries_what_the_client_boots_from(): void
    {
        $response = $this->get('/');

        // The CSRF token, the browser-chrome colour and the theme the
        // stylesheet defaults to. See resources/views/app.blade.php.
        $response->assertSee('name="csrf-token"', false);
        $response->assertSee('name="theme-color"', false);
        $response->assertSee('data-theme="dark"', false);
    }

    /**
     * A miss under /api must stay a miss. If the catch-all claimed it, a
     * mistyped endpoint would answer 200 with a page of HTML, and the client's
     * fetch() would report success and then fail somewhere else entirely.
     */
    #[Test]
    public function unknown_api_paths_are_not_the_shell(): void
    {
        $this->getJson('/api/nothing-here')->assertNotFound();
    }

    /**
     * The health endpoint registered by bootstrap/app.php. Whatever monitors
     * this app asks for /up and reads the answer; the shell would be a 200 for
     * a dead application.
     */
    #[Test]
    public function the_health_endpoint_is_not_the_shell(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
        $response->assertDontSee('<div id="app">', false);
    }

    /**
     * Horizon's dashboard comes from its own service provider. It is 404'd at
     * the host vhost in production, but it must not be QUIETLY replaced by the
     * shell either — a dashboard that renders as the app is a dashboard nobody
     * can tell is missing.
     */
    #[Test]
    public function the_queue_dashboard_is_not_the_shell(): void
    {
        $response = $this->get('/horizon');

        $response->assertDontSee('<div id="app">', false);
    }
}
