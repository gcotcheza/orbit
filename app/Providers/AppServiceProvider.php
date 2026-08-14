<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /*
         * The login throttle, keyed on the email AND the ip.
         *
         * On the email so that one address cannot be ground through a word
         * list from a botnet, and on the ip so that one host cannot walk a
         * list of addresses — either key alone leaves the other attack free.
         *
         * Five a minute. Orbit has ONE account, so this route is the entire
         * brute-force surface, and five is far more than a person mistypes
         * their own password while being a hard ceiling on a script.
         *
         * The email is lower-cased because addresses are case-insensitive in
         * practice and `Ghie@` / `ghie@` must not be two buckets.
         */
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(5)
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));

        /*
         * ORBIT ISSUES NO API TOKENS, so a bearer token is never a credential
         * here — see bootstrap/app.php for why Sanctum is in cookie/session
         * mode.
         *
         * Saying so explicitly is not decoration. Sanctum's guard falls through
         * to token authentication whenever a request carries an Authorization
         * header, and that path reads `personal_access_tokens` — a table this
         * app never publishes a migration for. Left alone, any request with a
         * `Authorization: Bearer x` header would turn a 401 into a 500 from a
         * missing relation. This closes it at the guard instead of by creating
         * a table nothing writes to.
         */
        Sanctum::getAccessTokenFromRequestUsing(fn (): ?string => null);
    }
}
