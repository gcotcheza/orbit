<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Who may look at Horizon: an unset HORIZON_DASHBOARD_TOKEN means DENY, never "no secret
 * required", and two more layers sit in front of this gate (docs/BUSINESS-LOGIC.md §36).
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
     * Shared-secret access for a tunnelled look at the dashboard. hash_equals, not `===`:
     * a plain comparison leaks the matching prefix length through timing.
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
