<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The catch-all, and the four things it must never swallow: a route that is one regex character too greedy turns /up,
 * /api or /horizon into a 200 of HTML that reads as success (docs/BUSINESS-LOGIC.md §36).
 *
 * `withoutVite()` throughout — these tests are about routing, not about requiring a prior `npm run build`
 * (docs/BUSINESS-LOGIC.md §36).
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
     * Including for a GUEST, deliberately: the shell is identical for everyone,
     * with nothing to leak. Auth happens client-side, against GET /api/me.
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
     * A miss under /api must stay a miss — if the catch-all claimed it, a
     * mistyped endpoint's fetch() would read a 200-HTML page as success.
     */
    #[Test]
    public function unknown_api_paths_are_not_the_shell(): void
    {
        $this->getJson('/api/nothing-here')->assertNotFound();
    }

    /**
     * The health endpoint registered by bootstrap/app.php — the shell must
     * never answer /up with a 200 for a dead application.
     */
    #[Test]
    public function the_health_endpoint_is_not_the_shell(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
        $response->assertDontSee('<div id="app">', false);
    }

    /**
     * Horizon's dashboard is 404'd at the host vhost in production, but must
     * not be QUIETLY replaced by the shell — that would hide it being missing.
     */
    #[Test]
    public function the_queue_dashboard_is_not_the_shell(): void
    {
        $response = $this->get('/horizon');

        $response->assertDontSee('<div id="app">', false);
    }
}
