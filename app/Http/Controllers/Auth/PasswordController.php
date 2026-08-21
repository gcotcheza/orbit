<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\UpdatePasswordRequest;

/**
 * Password change: the session is ROTATED, not ended, and the remember-me token is cycled while
 * this device is re-issued one (docs/BUSINESS-LOGIC.md §36).
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

        // `hashed` cast bcrypts on save — assigning plaintext is correct; hashing here first would
        // double-hash it and lock the one account out.
        $user->password = $password;

        // Kill every recaller cookie ever issued for this account. See above.
        $user->setRememberToken(Str::random(60));

        $user->save();

        /**
         * Must run BEFORE the re-login below: it re-hashes the password, and the recaller cookie
         * the re-login queues must carry that FINAL hash.
         */
        Auth::logoutOtherDevices($password);

        // Re-issue THIS device's recaller against the token just written, so it keeps the long
        // session LoginController deliberately gives it.
        Auth::login($user, remember: true);

        // Against fixation, and it is what hands the SPA its new CSRF token.
        $request->session()->regenerate();

        /**
         * 200 with a body, not 204, so the screen can render "Password changed" without inferring
         * it. Deliberately not the user object — one refactor from a hash leak.
         */
        return new JsonResponse(['data' => ['changed' => true]]);
    }
}
