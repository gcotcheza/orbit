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
 * Session login. That is the whole auth surface — no registration, reset,
 * verification, or "remember me" toggle; Orbit has exactly one user.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * Both actions answer JSON, never a redirect, so the SPA's fetch never
 * reads a followed redirect's 200 as a login that didn't happen.
 * Why: docs/BUSINESS-LOGIC.md §36.
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

        // `remember` is on and not user-selectable: this is a home-screen PWA
        // on one phone that must not sign itself out.
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
     * Equalises timing between wrong-email and wrong-password by paying a
     * bcrypt against DUMMY_HASH even when no user was found.
     * Deliberately NOT unit-tested — timing asserts flake on a shared VPS.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    private function equaliseUnknownEmailTiming(string $email, string $password): void
    {
        if (User::query()->where('email', $email)->exists()) {
            // The address exists, so Auth::attempt already spent a real bcrypt on
            // it — a second one here would only move the tell to the other side.
            return;
        }

        Hash::check($password, self::DUMMY_HASH);
    }
}
