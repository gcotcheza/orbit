<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `PUT /api/profile/password` — three promises: the old password stops
 * working, this device stays in, every other device is out (docs/BUSINESS-LOGIC.md §36).
 */
final class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT = 'the-password-it-has-now';

    private const NEXT = 'a-longer-replacement-1';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'email'    => 'owner@orbit.test',
            'password' => self::CURRENT,
        ]);
    }

    /**
     * A complete, valid body.
     *
     * @return array<string, string>
     */
    private static function body(string $current = self::CURRENT, string $next = self::NEXT, ?string $confirmation = null): array
    {
        return [
            'current_password'      => $current,
            'password'              => $next,
            'password_confirmation' => $confirmation ?? $next,
        ];
    }

    /**
     * @param  array<string, string>  $body
     * @return TestResponse<Response>
     */
    private function change(array $body): TestResponse
    {
        return $this->actingAs($this->owner)->putJson('/api/profile/password', $body);
    }

    #[Test]
    public function a_guest_is_refused_with_json_and_changes_nothing(): void
    {
        $before = $this->owner->password;

        $this->putJson('/api/profile/password', self::body())
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->assertSame($before, $this->owner->fresh()?->password);
    }

    /**
     * Plain `put`, deliberately: `bootstrap/app.php` renders `api/*` as JSON
     * on the path prefix alone (docs/BUSINESS-LOGIC.md §36).
     */
    #[Test]
    public function a_guest_is_told_no_in_json_rather_than_redirected(): void
    {
        $response = $this->put('/api/profile/password', self::body());

        $response->assertStatus(401);
        $response->assertHeader('content-type', 'application/json');
    }

    /**
     * 422, not 401 — a 401 would send `http.js`'s interceptor to the login
     * screen and lose a half-filled form (docs/BUSINESS-LOGIC.md §36).
     */
    #[Test]
    public function the_wrong_current_password_is_a_validation_error_not_a_401(): void
    {
        $response = $this->change(self::body(current: 'not-the-password'));

        $response->assertStatus(422)
            ->assertJsonValidationErrors('current_password')
            ->assertJsonPath('errors.current_password.0', 'That is not your current password.');

        $this->assertTrue(Hash::check(self::CURRENT, (string) $this->owner->fresh()?->password));
    }

    #[Test]
    public function a_missing_current_password_is_refused(): void
    {
        $this->change(['password' => self::NEXT, 'password_confirmation' => self::NEXT])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertPasswordUnchanged();
    }

    #[Test]
    public function a_confirmation_that_does_not_match_is_refused(): void
    {
        $this->change(self::body(confirmation: 'something-else-entirely'))
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'The new password and its confirmation do not match.');

        $this->assertPasswordUnchanged();
    }

    #[Test]
    public function a_password_under_twelve_characters_is_refused(): void
    {
        $this->change(self::body(next: 'eleven-char'))
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'Use at least 12 characters.');

        $this->assertPasswordUnchanged();
    }

    /**
     * Twelve is the floor and not a suggestion: the character on the boundary
     * decides whether the message the owner is shown is true.
     */
    #[Test]
    public function exactly_twelve_characters_is_accepted(): void
    {
        $twelve = 'abcdefghijkl';

        $this->change(self::body(next: $twelve))->assertOk();

        $this->assertTrue(Hash::check($twelve, (string) $this->owner->fresh()?->password));
    }

    /**
     * Re-submitting the same password is refused — accepting it would report
     * success for a rotation that did not happen.
     */
    #[Test]
    public function the_current_password_cannot_be_chosen_again(): void
    {
        $this->change(self::body(next: self::CURRENT))
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'That is already your password. Choose a different one.');

        $this->assertPasswordUnchanged();
    }

    #[Test]
    public function a_valid_change_replaces_the_hash_and_says_so(): void
    {
        $before = (string) $this->owner->password;

        $this->change(self::body())
            ->assertOk()
            ->assertExactJson(['data' => ['changed' => true]]);

        $after = (string) $this->owner->fresh()?->password;

        $this->assertNotSame($before, $after);
        $this->assertTrue(Hash::check(self::NEXT, $after));
        $this->assertFalse(Hash::check(self::CURRENT, $after));
    }

    #[Test]
    public function the_response_never_carries_the_password_or_its_hash(): void
    {
        $response = $this->change(self::body());

        $content = $response->getContent() ?: '';

        $this->assertStringNotContainsString('$2y$', $content);
        $this->assertStringNotContainsString(self::NEXT, $content);
    }

    /**
     * The half that a hash comparison cannot prove: the login route itself has
     * to have changed its mind about both passwords.
     */
    #[Test]
    public function the_old_password_stops_signing_in_and_the_new_one_starts(): void
    {
        $this->change(self::body())->assertOk();

        // Back to being a guest first — the test client keeps ONE session store
        // across calls, or the sign-in attempts below arrive already authenticated.
        $this->flushSession();
        Auth::forgetGuards();

        $this->postJson('/login', ['email' => $this->owner->email, 'password' => self::CURRENT])
            ->assertStatus(422);
        $this->assertGuest();

        $this->postJson('/login', ['email' => $this->owner->email, 'password' => self::NEXT])
            ->assertOk();
        $this->assertAuthenticatedAs($this->owner);
    }

    /**
     * The device that made the change stays signed in, with a new session id —
     * driven through the real cookie, not `actingAs` (docs/BUSINESS-LOGIC.md §36).
     */
    #[Test]
    public function the_session_is_rotated_and_the_caller_stays_signed_in(): void
    {
        $cookie = (string) config('session.cookie');

        $login = $this->postJson('/login', [
            'email'    => $this->owner->email,
            'password' => self::CURRENT,
        ])->assertOk();

        $sessionBefore = $login->getCookie($cookie)?->getValue();
        $this->assertNotNull($sessionBefore);

        $response = $this->withCookie($cookie, (string) $login->getCookie($cookie, false)?->getValue())
            ->putJson('/api/profile/password', self::body());

        $response->assertOk();

        $sessionAfter = $response->getCookie($cookie)?->getValue();
        $this->assertNotNull($sessionAfter);
        $this->assertNotSame($sessionBefore, $sessionAfter, 'The session id must be rotated by a password change.');

        // Still signed in, on the session that came back — an authenticated call
        // immediately afterwards is the thing the screen does next.
        $this->withCookie($cookie, (string) $response->getCookie($cookie, false)?->getValue())
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', $this->owner->email);
    }

    /**
     * Every other device is signed out — its session is captured from a real
     * authenticated request, not hand-built (docs/BUSINESS-LOGIC.md §36).
     */
    #[Test]
    public function a_session_on_another_device_stops_working_after_the_change(): void
    {
        $this->actingAs($this->owner)->getJson('/api/me')->assertOk();

        $otherDevice = session()->all();

        $this->assertArrayHasKey(
            'password_hash_web',
            $otherDevice,
            'AuthenticateSession is not in the web group — nothing is comparing session hashes, and logoutOtherDevices() is a no-op.'
        );

        $this->asANewProcessWould();

        $this->change(self::body())->assertOk();

        $this->asANewProcessWould();

        // The other device's very next request. 401 rather than 200, and the
        // SPA's interceptor turns that into the login screen (lib/http.js).
        $this->withSession($otherDevice)
            ->actingAs($this->owner)
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    /**
     * Puts back what php-fpm resets between requests but this test process
     * shares: session, guards, and the DEFAULT GUARD NAME (docs/BUSINESS-LOGIC.md §36).
     */
    private function asANewProcessWould(): void
    {
        $this->flushSession();

        Auth::forgetGuards();
        Auth::shouldUse('web');
    }

    /**
     * The XSRF cookie the SPA's next write needs — `regenerate()` also rotates
     * the CSRF token.
     */
    #[Test]
    public function the_response_hands_back_a_csrf_token_to_carry_on_with(): void
    {
        $this->change(self::body())
            ->assertOk()
            ->assertCookie('XSRF-TOKEN', Session::token());
    }

    /**
     * Every recall-me cookie stops validating — it's checked against this
     * column, not the password (docs/BUSINESS-LOGIC.md §36).
     */
    #[Test]
    public function every_remember_me_cookie_is_invalidated(): void
    {
        $this->owner->forceFill(['remember_token' => 'the-token-every-device-holds'])->save();

        $this->change(self::body())->assertOk();

        $this->assertNotSame('the-token-every-device-holds', $this->owner->fresh()?->getRememberToken());
    }

    /**
     * Five a minute, keyed on the account — a secret behind a session left
     * open on an unattended phone (docs/BUSINESS-LOGIC.md §36).
     */
    #[Test]
    public function the_sixth_attempt_in_a_minute_is_throttled(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->change(self::body(current: 'not-the-password'))->assertStatus(422);
        }

        $this->change(self::body(current: 'not-the-password'))->assertStatus(429);

        // And the throttle is not a way past the gate: the correct body does not
        // get through it either.
        $this->change(self::body())->assertStatus(429);

        $this->assertPasswordUnchanged();
    }

    private function assertPasswordUnchanged(): void
    {
        $this->assertTrue(
            Hash::check(self::CURRENT, (string) $this->owner->fresh()?->password),
            'The stored password should not have changed.'
        );
    }
}
