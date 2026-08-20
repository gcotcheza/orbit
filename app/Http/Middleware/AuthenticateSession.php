<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Session\Middleware\AuthenticateSession as FrameworkAuthenticateSession;

/**
 * Laravel's session-eviction middleware, pinned to the session guard.
 *
 * DO NOT register the framework's version directly: `auth:sanctum` rewrites the default guard mid-request, silently logging out the wrong session on password change.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
final class AuthenticateSession extends FrameworkAuthenticateSession
{
    private const GUARD = 'web';

    protected function guard(): Guard
    {
        return $this->auth->guard(self::GUARD);
    }

    /**
     * Store the hash under this middleware's own key as well as the framework's.
     * Copied, not recomputed, from `password_hash_sanctum` to `password_hash_web` — whatever format the framework used stays intact in both keys.
     * Why: docs/BUSINESS-LOGIC.md §36.
     *
     * @param  Request  $request
     */
    protected function storePasswordHashInSession($request): void
    {
        parent::storePasswordHashInSession($request);

        $written = $request->session()->get('password_hash_'.$this->auth->getDefaultDriver());

        if ($written === null) {
            // The parent returns early for a request with no user; there is
            // nothing to mirror and nothing to invalidate.
            return;
        }

        $request->session()->put('password_hash_'.self::GUARD, $written);
    }
}
