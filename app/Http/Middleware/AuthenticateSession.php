<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Session\Middleware\AuthenticateSession as FrameworkAuthenticateSession;

/**
 * Laravel's session-eviction middleware, pinned to the session guard.
 *
 * ===========================================================================
 * WHAT THE FRAMEWORK'S ONE DOES
 *
 * It keeps a copy of the user's password hash in their session and compares it
 * against the real one on every request. Change the password and every OTHER
 * session's copy is stale, so each of those is logged out the next time it asks
 * for anything — which is the only thing that makes `Auth::logoutOtherDevices()`
 * (App\Http\Controllers\Auth\PasswordController) more than a successful-looking
 * no-op.
 *
 * ===========================================================================
 * WHY IT NEEDS A SUBCLASS HERE, WHEN health-tracker REGISTERS IT AS SHIPPED
 *
 * That app has no Sanctum. This one guards the SPA's endpoints with
 * `auth:sanctum` (routes/web.php), and Illuminate\Auth\Middleware\Authenticate
 * calls `$this->auth->shouldUse('sanctum')` the moment it authenticates
 * somebody — which rewrites `auth.defaults.guard` FOR THE REST OF THE REQUEST.
 * The framework middleware reads the default guard on every line that matters
 * and caches it nowhere, so registering it as shipped breaks in two ways, both
 * severe:
 *
 *  1. `$this->guard()->viaRemember()`. Sanctum's guard is an
 *     Illuminate\Auth\RequestGuard, which has no `viaRemember()` — so every
 *     authenticated API request answers 500 with a BadMethodCallException. That
 *     one at least fails loudly.
 *
 *  2. The session key, `'password_hash_'.$this->auth->getDefaultDriver()`, is
 *     read on the way IN and written again on the way OUT — and `auth:sanctum`
 *     runs between those two moments. They are therefore two DIFFERENT keys:
 *     the copy refreshed after a password change is not the copy the next
 *     request compares against, so the device that made the change is signed
 *     out by its own password change on its next page load. That is the exact
 *     behaviour PasswordController exists to prevent, reintroduced by the fix
 *     for something else, and it fails silently.
 *
 * The two overrides below answer both. `guard()` names the session guard, so
 * the recaller check and the hash-for-cookie HMAC always come from the guard
 * that owns them. `storePasswordHashInSession()` keeps `password_hash_web`
 * current whatever the default guard has become, so the key written on the way
 * out is always the key read on the way in.
 *
 * NOTHING GLOBAL IS MUTATED. An earlier attempt pinned `auth.defaults.guard`
 * itself with `shouldUse()`, which works and is worse: the guard the rest of
 * the pipeline sees then depends on a middleware that has no business having an
 * opinion about it.
 *
 * ===========================================================================
 * THE GUARD NAME IS A CONSTANT AND config/auth.php HAS EXACTLY ONE GUARD
 *
 * `web`, the session guard, is the only guard this app defines; `sanctum` is a
 * driver Sanctum adds that DELEGATES to it for first-party cookie requests (see
 * bootstrap/app.php's Sanctum note). A session, a recaller cookie and a stored
 * password hash are all things only the session guard has, so naming it here is
 * not a configuration decision deferred — it is the subject of the class.
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
     *
     * The parent writes `'password_hash_'.getDefaultDriver()`, which is
     * `password_hash_sanctum` on any request that has been through
     * `auth:sanctum` by the time this runs. Copying the value it just wrote
     * across to `password_hash_web` — the key the next request will read, before
     * any authentication middleware has had a chance to move the default guard —
     * is what keeps the two ends of the comparison talking about the same thing.
     *
     * Copied rather than recomputed on purpose: whatever format the framework
     * chose (today an HMAC of the hash, with a fall-back to the raw hash for
     * sessions written by older versions) is then the format in both keys,
     * without this class knowing what it is.
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
