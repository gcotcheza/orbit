<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Change your own password. The second thing this app's auth surface does, and
 * the only one that is about the account rather than the session.
 *
 * ===========================================================================
 * WHY IT EXISTS WHEN THE SEEDER GENERATES THE PASSWORD
 *
 * The account is created by Database\Seeders\SingleUserSeeder, which generates a
 * random password and prints it once. That is the right first boot and a bad
 * forever: changing it afterwards meant re-running the seeder with
 * SEED_USER_PASSWORD set — a deploy-time chore, on a box, for an app whose whole
 * point is being opened on a phone. This is that missing in-app path.
 *
 * NOTHING ABOUT routes/web.php's ABSENCES CHANGES. There is still no
 * registration, no reset, no mail-borne token, no verification; the endpoint is
 * behind `auth` AND behind the current password (App\Http\Requests\
 * UpdatePasswordRequest), so it adds no surface a guest can reach.
 *
 * ===========================================================================
 * WHY THE SESSION SURVIVES
 *
 * The caller is a phone the owner is holding, mid-tap, on a screen that must not
 * navigate. Signing them out of the device they just changed the password on
 * would be a correct-looking security gesture that costs the one thing this
 * change is for — so the session is ROTATED rather than ended: a new session id
 * (against fixation, exactly as LoginController does) and a new CSRF token.
 *
 * THE NEW CSRF TOKEN REACHES THE CLIENT BY ITSELF and that is not luck. Laravel's
 * CSRF middleware writes the XSRF-TOKEN cookie from the session's token on the
 * way OUT of every request in the `web` group, i.e. after this method has run, so
 * the response to this very request carries the regenerated value — and
 * resources/js/lib/http.js reads that cookie per request rather than a token
 * captured at page load. Without the second half the next write from the open
 * SPA would be a 419, which is the failure this comment exists to prevent
 * somebody "simplifying" back into place.
 *
 * ===========================================================================
 * THE REMEMBER-ME COOKIE HAS TO GO TOO
 *
 * LoginController signs in with `remember: true` unconditionally and says why —
 * a deal tracker that logs you out every two hours stops being opened. The price
 * is that every device that has ever signed in holds a recaller cookie good for
 * roughly four hundred days, and that cookie is checked against the
 * `remember_token` column, NOT against the password. Changing the password
 * without cycling that token changes the secret while leaving every existing way
 * in exactly as valid as it was, which is the opposite of what somebody
 * rotating a password after losing a phone is asking for.
 *
 * So the token is cycled, which invalidates every recaller cookie in existence
 * at once — including this device's — and this device is then re-issued one
 * against the new token by signing it back in. The order matters: the re-login
 * has to run after the save, so the cookie it queues carries the token that was
 * just written.
 *
 * WHAT THIS DOES NOT DO IS EVICT OTHER SESSIONS. `Auth::logoutOtherDevices()`
 * would, but only with Illuminate\Session\Middleware\AuthenticateSession in the
 * `web` group — it is the middleware that compares each session's stored copy of
 * the password hash against the real one, and this app does not register it
 * (bootstrap/app.php). Calling it without that middleware is the silent no-op
 * health-tracker shipped for months. A live session elsewhere therefore survives
 * this change until it expires; the long-lived cookies that would have RE-created
 * one do not.
 */
final class PasswordController extends Controller
{
    public function update(UpdatePasswordRequest $request): JsonResponse
    {
        /** @var string $password */
        $password = $request->validated('password');

        $user = $request->user();
        // The route is behind `auth`; the request cannot resolve without one.
        assert($user instanceof User);

        // The model's `hashed` cast bcrypts this on save. Assigning the
        // plaintext is correct — hashing it here first would double-hash it and
        // lock the one account out.
        $user->password = $password;

        // Kill every recaller cookie ever issued for this account. See above.
        $user->setRememberToken(Str::random(60));

        $user->save();

        // Re-issue THIS device's recaller against the token just written, so the
        // one device that proved it knows the password keeps the long session
        // LoginController deliberately gives it.
        Auth::login($user, remember: true);

        // Against fixation, and it is what hands the SPA its new CSRF token.
        $request->session()->regenerate();

        /*
         * 200 with a body rather than 204, because the screen renders a state
         * from it ("Password changed") and a body that says which thing changed
         * is what lets it do that without inferring success from a status code.
         * The body is deliberately not the user: nothing about the user's
         * DESCRIPTION changed, and a password endpoint that answers with an
         * account object is one refactor away from answering with a hash.
         */
        return new JsonResponse(['data' => ['changed' => true]]);
    }
}
