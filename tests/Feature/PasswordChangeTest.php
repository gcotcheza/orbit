<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `PUT /api/profile/password` — the owner rotating their own password.
 *
 * THE ENDPOINT IS TWO PROMISES AND THIS FILE HOLDS BOTH. That the old password
 * stops working and the new one starts (the point), and that the device which
 * made the change is still signed in when it lands (the thing that makes it
 * usable from a phone). A password change that logs the owner out of the screen
 * they changed it on is a bug that reads as a security feature, and it is the
 * one this suite would otherwise never notice.
 *
 * The guard-level facts — that the current password is required, that it is
 * checked rather than merely present — are asserted through the HTTP surface,
 * because that is where a refactor breaks them.
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
            'email' => 'owner@orbit.test',
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
            'current_password' => $current,
            'password' => $next,
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

    // ------------------------------------------------------------- who may call

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
     * Asserted with a plain `put` as well, deliberately: bootstrap/app.php
     * renders `api/*` as JSON on the path prefix alone, so a caller that forgot
     * to ask for JSON still gets a 401 body rather than a redirect to the login
     * screen — which fetch() would follow and hand back as a 200 of HTML.
     */
    #[Test]
    public function a_guest_is_told_no_in_json_rather_than_redirected(): void
    {
        $response = $this->put('/api/profile/password', self::body());

        $response->assertStatus(401);
        $response->assertHeader('content-type', 'application/json');
    }

    // ------------------------------------------------------------- the refusals

    /**
     * The wrong current password is a 422 in Laravel's standard shape and NOT a
     * 401. The distinction is the whole reason this is worth asserting: a 401
     * would send resources/js/lib/http.js's interceptor to the login screen and
     * throw away a form the owner is halfway through, because that interceptor
     * reads a 401 as "the session ended" — which here it has not.
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
     * Re-submitting the password it already has is refused, which is what makes
     * this a CHANGE. Accepting it would report success for a rotation that did
     * not happen — the worst possible answer to somebody changing a password
     * because they think somebody else has it.
     */
    #[Test]
    public function the_current_password_cannot_be_chosen_again(): void
    {
        $this->change(self::body(next: self::CURRENT))
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'That is already your password. Choose a different one.');

        $this->assertPasswordUnchanged();
    }

    // -------------------------------------------------------------- the success

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

        /*
         * Back to being a guest before asking the login route anything. The test
         * client keeps ONE session store across calls and merges into it rather
         * than replacing it, so without this the sign-in attempts below arrive at
         * `guest` middleware already authenticated — from `actingAs`, and from
         * the re-login the controller performs — and are answered with a 302
         * instead of ever reaching the credentials check.
         */
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
     * THE DEVICE THAT MADE THE CHANGE STAYS SIGNED IN, and its session id is a
     * different one afterwards. Both halves matter and they pull in opposite
     * directions — the naive rotation (`invalidate()`, or a
     * `logoutOtherDevices()` that also caught this device) ends the session, and
     * the naive "keep them signed in" leaves the pre-change session id valid.
     *
     * DRIVEN THROUGH THE REAL COOKIE, not through `actingAs`. The session id is
     * only observable across requests if the request actually carries one, so
     * this signs in for real, hands the encrypted session cookie back to the
     * next call, and compares what comes out. Under `actingAs` the guard answers
     * from memory and this test would pass without the endpoint doing anything.
     */
    #[Test]
    public function the_session_is_rotated_and_the_caller_stays_signed_in(): void
    {
        $cookie = (string) config('session.cookie');

        $login = $this->postJson('/login', [
            'email' => $this->owner->email,
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
     * The XSRF cookie the SPA's NEXT write will send back. `regenerate()` also
     * regenerates the CSRF token, so without this cookie riding along on the
     * response every subsequent write from the still-open page would be a 419.
     */
    #[Test]
    public function the_response_hands_back_a_csrf_token_to_carry_on_with(): void
    {
        $this->change(self::body())
            ->assertOk()
            ->assertCookie('XSRF-TOKEN', Session::token());
    }

    /**
     * Every recaller cookie in existence stops validating, including the ones on
     * devices this request has never seen. See PasswordController: the cookie is
     * checked against this column and not against the password, so leaving it
     * alone would change the secret without changing who can get in.
     */
    #[Test]
    public function every_remember_me_cookie_is_invalidated(): void
    {
        $this->owner->forceFill(['remember_token' => 'the-token-every-device-holds'])->save();

        $this->change(self::body())->assertOk();

        $this->assertNotSame('the-token-every-device-holds', $this->owner->fresh()?->getRememberToken());
    }

    // ------------------------------------------------------------- the throttle

    /**
     * Five a minute, keyed on the account — see AppServiceProvider. The endpoint
     * checks a secret, so a session left open on an unattended phone must not be
     * a place to guess the current password at machine speed.
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
