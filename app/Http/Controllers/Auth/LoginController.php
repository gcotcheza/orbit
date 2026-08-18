<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Session login. That is the whole auth surface.
 *
 * No registration, no password reset, no email verification, no "remember me"
 * checkbox that is really a second session policy. Orbit has exactly one user
 * (docs/PLAN.md), created by `php artisan db:seed`, and every route that does
 * not exist is a route that cannot be misconfigured.
 *
 * BOTH ACTIONS ANSWER JSON. The caller is the SPA's fetch/XHR from a page that
 * must not navigate; a redirect would be followed and handed back as the
 * shell's HTML with a 200, which reads as success whatever really happened.
 */
final class LoginController extends Controller
{
    /**
     * Bcrypt of a random string that was thrown away, at the same cost factor
     * the app hashes with. See equaliseUnknownEmailTiming() below.
     */
    private const DUMMY_HASH = '$2y$12$3dI1ta72HQ6lP8wpM5mkBezswDRjq4fl6dsun6wiubo8Nxi.X2P0.';

    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // `remember` is on and not user-selectable: this is a home-screen PWA on
        // one phone, and being signed out every two hours is how a deal tracker
        // stops being opened.
        if (! Auth::attempt($credentials, remember: true)) {
            $this->equaliseUnknownEmailTiming((string) $credentials['email'], (string) $credentials['password']);

            throw ValidationException::withMessages([
                // One message for both wrong-email and wrong-password: with a
                // single account, "no such user" would confirm the address.
                'email' => __('auth.failed'),
            ]);
        }

        // Against session fixation: the id the browser arrived with must not be
        // the id it leaves authenticated with.
        $request->session()->regenerate();

        return UserResource::make($request->user())->response();
    }

    public function destroy(Request $request): JsonResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // 204: there is nothing left to describe. The SPA clears its own store
        // and routes to /login.
        return new JsonResponse(status: 204);
    }

    /**
     * Make a wrong email cost the same as a wrong password.
     *
     * The message is already identical for both — see above — but the CLOCK was
     * not. `Auth::attempt()` only reaches bcrypt when it found a user, and
     * bcrypt at cost 12 is a couple of hundred milliseconds against a failed
     * index lookup's fraction of one. That gap is measurable over a handful of
     * requests, and it answers exactly the question the shared message refuses
     * to: is this the address the account uses? On a single-user app that is
     * the whole enumeration surface.
     *
     * So the unknown-email path pays for a bcrypt too, against a hash of a
     * value nobody knows. The constant authenticates nothing — no row carries
     * it, and it is a hash, not a password — and its embedded cost of 12 is the
     * app's own, which is what makes the two paths comparable rather than
     * merely both slow.
     *
     * Deliberately NOT asserted by a test. A timing assertion on a shared VPS
     * measures the neighbours, and a test that fails when something else is
     * busy teaches people to re-run the suite until it passes.
     */
    private function equaliseUnknownEmailTiming(string $email, string $password): void
    {
        if (User::query()->where('email', $email)->exists()) {
            // The address exists, so Auth::attempt already spent a real bcrypt
            // rejecting the password. Spending a second one here would only
            // move the tell to the other side.
            return;
        }

        Hash::check($password, self::DUMMY_HASH);
    }
}
