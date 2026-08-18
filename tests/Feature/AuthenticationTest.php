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
 * The whole authentication surface, including the parts of it that must not
 * exist.
 *
 * Orbit is a single-user app (docs/PLAN.md): sign in, sign out, and one
 * endpoint that says who you are. Every OTHER thing an auth scaffold usually
 * brings — registration, password reset, email verification — is absent, and
 * the tests at the bottom of this file are what keep it absent. A `composer
 * require` of a starter kit, or a well-meaning `route:list` tidy-up, would
 * otherwise put a public signup form on a private app without anybody noticing.
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

    // ---------------------------------------------------------------- sign in

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
     * An unknown address gets the SAME answer as a wrong password. With one
     * account, a distinguishable "no such user" would confirm the owner's
     * email address to anybody who guessed it.
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
     * Five a minute, keyed on email|ip — see AppServiceProvider. One account
     * means this route is the entire brute-force surface, so the sixth attempt
     * inside a minute has to be refused rather than merely wrong.
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

    // --------------------------------------------------------------- sign out

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

    // ------------------------------------------------------------------ /api/me

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
     * The SPA calls this from fetch() on a page that must not navigate, so the
     * guest answer has to be a 401 with a JSON body — NOT a 302 that fetch()
     * follows and hands back as the shell's HTML with a 200, i.e. as a
     * successful call that returns a page instead of a user.
     *
     * Asserted with a plain `get`, deliberately: bootstrap/app.php renders
     * `api/*` as JSON on the path prefix alone, so this holds even for a caller
     * that forgot to ask for JSON.
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
     * Orbit issues no API tokens and has no `personal_access_tokens` table.
     * Sanctum's guard would nonetheless go looking for that table the moment a
     * request carried a bearer token, turning a 401 into a 500 from a missing
     * relation — see the callback in AppServiceProvider that closes it.
     */
    #[Test]
    public function a_bearer_token_is_not_a_credential_and_does_not_break_anything(): void
    {
        $this->owner();

        $this->getJson('/api/me', ['Authorization' => 'Bearer anything-at-all'])
            ->assertStatus(401);
    }

    // ------------------------------------------------- the routes that must not exist

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
     * ASSERTED AGAINST THE ROUTE TABLE, NOT AGAINST A 404, and the difference
     * is forced by this app's shape rather than chosen.
     *
     * routes/web.php answers every unclaimed GET with the SPA shell, because
     * that is what a client-side router needs. So `GET /register` is a 200 —
     * the shell, which then routes to the home screen — and it cannot be a 404
     * without teaching the catch-all a list of URLs that do not exist, which is
     * a list nobody would maintain.
     *
     * What actually matters is that NOTHING IS REGISTERED at those paths, and
     * that is what this checks, directly and without ambiguity. It is the
     * stronger assertion of the two: if a starter kit or a helpful refactor
     * ever registers a real registration route, this fails immediately and
     * names it, where a status-code test would only notice once the response
     * happened to change.
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
     * And the behavioural half: a POST to any of them is refused.
     *
     * 405 rather than 404 because the catch-all above claims the URI for GET,
     * so the router answers "not with that verb" — see the test above for why
     * that is the honest answer here. Either way nothing runs, nothing is
     * created and nothing is emailed.
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
     * Sanctum's own route, which the SPA calls before signing in. It is
     * registered by the package while the framework boots — i.e. BEFORE
     * routes/web.php — so the catch-all at the bottom of that file cannot
     * swallow it. This is the assertion that keeps that ordering true.
     */
    #[Test]
    public function the_csrf_cookie_endpoint_is_reachable_and_is_not_the_spa_shell(): void
    {
        $response = $this->get('/sanctum/csrf-cookie');

        $response->assertNoContent();
        $response->assertCookie('XSRF-TOKEN');
    }
}
