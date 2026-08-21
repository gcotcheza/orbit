<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Routing\Route as RoutingRoute;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The whole authentication surface, including the parts that must not exist
 * (docs/BUSINESS-LOGIC.md §36).
 */
final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function owner(): User
    {
        return User::factory()->create([
            'email'    => 'owner@orbit.test',
            'password' => self::PASSWORD,
        ]);
    }

    #[Test]
    public function a_correct_password_starts_a_session(): void
    {
        $user = $this->owner();

        $response = $this->postJson('/login', [
            'email'    => $user->email,
            'password' => self::PASSWORD,
        ]);

        $response->assertOk()->assertExactJson([
            'data' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
        ]);

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function the_login_response_never_carries_the_password_hash(): void
    {
        $user = $this->owner();

        $response = $this->postJson('/login', [
            'email'    => $user->email,
            'password' => self::PASSWORD,
        ]);

        $response->assertJsonMissingPath('data.password');
        $this->assertStringNotContainsString('$2y$', $response->getContent() ?: '');
    }

    #[Test]
    public function a_wrong_password_is_rejected_and_starts_nothing(): void
    {
        $user = $this->owner();

        $this->postJson('/login', [
            'email'    => $user->email,
            'password' => 'not-the-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    /**
     * Same answer as a wrong password — a distinguishable "no such user" would
     * confirm the owner's address to a guesser.
     */
    #[Test]
    public function an_unknown_address_is_answered_exactly_like_a_wrong_password(): void
    {
        $user = $this->owner();

        $known = $this->postJson('/login', [
            'email'    => $user->email,
            'password' => 'not-the-password',
        ]);

        $unknown = $this->postJson('/login', [
            'email'    => 'nobody@orbit.test',
            'password' => 'not-the-password',
        ]);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('errors.email'), $unknown->json('errors.email'));
    }

    /**
     * Five a minute, keyed on email|ip — the app's entire brute-force surface
     * (docs/BUSINESS-LOGIC.md §36).
     */
    #[Test]
    public function the_sixth_attempt_in_a_minute_is_throttled(): void
    {
        $user = $this->owner();

        $attempt = fn (): TestResponse => $this->postJson('/login', [
            'email'    => $user->email,
            'password' => 'not-the-password',
        ]);

        // Five get the ordinary "those details did not work" answer.
        foreach (range(1, 5) as $ignored) {
            $attempt()->assertStatus(422);
        }

        $attempt()->assertStatus(429);

        // And the throttle is not a way IN: the right password does not get
        // past it either.
        $this->postJson('/login', [
            'email'    => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(429);

        $this->assertGuest();
    }

    #[Test]
    public function signing_out_ends_the_session(): void
    {
        $user = $this->owner();

        $this->actingAs($user)->postJson('/logout')->assertNoContent();

        $this->assertGuest();
    }

    #[Test]
    public function a_guest_cannot_sign_out(): void
    {
        $this->postJson('/logout')->assertStatus(401);
    }

    #[Test]
    public function the_current_user_endpoint_describes_the_signed_in_user(): void
    {
        $user = $this->owner();

        $this->actingAs($user)->getJson('/api/me')->assertOk()->assertExactJson([
            'data' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    /**
     * `fetch()` must not be redirected to the shell — 401 with JSON, not 302
     * (docs/BUSINESS-LOGIC.md §36).
     */
    #[Test]
    public function a_guest_is_told_no_in_json_rather_than_redirected(): void
    {
        $response = $this->get('/api/me');

        $response->assertStatus(401);
        $response->assertHeader('content-type', 'application/json');
        $response->assertJson(['message' => 'Unauthenticated.']);
    }

    /**
     * Sanctum would otherwise turn a stray bearer header into a 500 from a
     * missing table (docs/BUSINESS-LOGIC.md §36).
     */
    #[Test]
    public function a_bearer_token_is_not_a_credential_and_does_not_break_anything(): void
    {
        $this->owner();

        $this->getJson('/api/me', ['Authorization' => 'Bearer anything-at-all'])
            ->assertStatus(401);
    }

    /**
     * @return array<string, array{non-empty-string}>
     */
    public static function absentAuthPaths(): array
    {
        return [
            'registration'          => ['register'],
            'forgot password'       => ['forgot-password'],
            'reset password'        => ['reset-password'],
            'email verification'    => ['verify-email'],
            'password confirmation' => ['confirm-password'],
            'password update'       => ['password'],
        ];
    }

    /**
     * Asserted against the route table, not a 404 — the catch-all answers every
     * unclaimed GET with the shell (docs/BUSINESS-LOGIC.md §36).
     *
     * @param  non-empty-string  $path
     */
    #[Test]
    #[DataProvider('absentAuthPaths')]
    public function no_route_is_registered_for_the_multi_user_scaffolding(string $path): void
    {
        $registered = collect(Route::getRoutes()->getRoutes())
            ->map(fn (RoutingRoute $route): string => $route->uri())
            ->all();

        foreach ($registered as $uri) {
            $this->assertStringStartsNotWith(
                $path,
                $uri,
                "A route is registered at /{$uri}. Orbit is a single-user app: docs/PLAN.md rules out registration, password reset and verification."
            );
        }
    }

    /**
     * 405, not 404 — the catch-all claims the URI for GET, so the router
     * refuses the verb instead (docs/BUSINESS-LOGIC.md §36).
     *
     * @param  non-empty-string  $path
     */
    #[Test]
    #[DataProvider('absentAuthPaths')]
    public function the_multi_user_scaffolding_cannot_be_posted_to(string $path): void
    {
        $this->json('post', '/'.$path)->assertStatus(405);
    }

    /**
     * Sanctum registers this while the framework boots, before routes/web.php,
     * so the catch-all cannot swallow it.
     */
    #[Test]
    public function the_csrf_cookie_endpoint_is_reachable_and_is_not_the_spa_shell(): void
    {
        $response = $this->get('/sanctum/csrf-cookie');

        $response->assertNoContent();
        $response->assertCookie('XSRF-TOKEN');
    }
}
