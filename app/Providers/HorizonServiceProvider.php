<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Horizon, and specifically who is allowed to look at it.
 *
 * This app has no user accounts yet — PR3 brings auth — so the stock gate
 * (`in_array($user->email, [...])`) would evaluate against a null user and, in
 * a single-user app with no login, would be the only thing between the public
 * internet and a dashboard that lists every queued job, retries them, and
 * exposes job payloads. The dashboard is not worth an open door.
 *
 * THREE layers, because one of them will eventually be misconfigured:
 *
 *  1. This gate. Denies unless the app is running locally, or the request
 *     carries HORIZON_DASHBOARD_TOKEN. Unset token => deny, always: an absent
 *     secret must never read as "no secret required". Denial renders 403.
 *  2. The host vhost (deploy/nginx/flights-ghiecode.conf) returns 404 for
 *     /horizon before the request ever reaches PHP, so the internet cannot
 *     reach it even if this gate is wrong.
 *  3. The in-stack nginx publishes on 127.0.0.1:3085 only, so the token path
 *     is usable through an SSH tunnel and from nowhere else.
 */
final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', function (mixed $user = null): bool {
            if ($this->app->environment('local')) {
                return true;
            }

            return $this->hasValidToken(request());
        });
    }

    /**
     * Shared-secret access for a tunnelled look at the dashboard.
     *
     * hash_equals, not `===`: a plain comparison leaks the length of the
     * matching prefix through timing, which is enough to recover the token
     * given patience.
     */
    private function hasValidToken(?Request $request): bool
    {
        $expected = (string) config('horizon.dashboard_token', '');

        if ($expected === '' || $request === null) {
            return false;
        }

        $provided = (string) ($request->header('X-Horizon-Token') ?? $request->query('token', ''));

        return $provided !== '' && hash_equals($expected, $provided);
    }
}
