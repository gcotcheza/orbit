<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\User;
use App\Models\Alert;
use App\Models\Route;
use App\Models\DealRule;
use Illuminate\Mail\Mailer;
use App\Domain\Alerts\AlertType;
use Symfony\Component\Mime\Email;
use Illuminate\Support\Facades\Date;
use Illuminate\Mail\Transport\ArrayTransport;

/**
 * Fixtures for the alert pipeline's tests, sharing one set of price knots.
 *
 * Why: docs/BUSINESS-LOGIC.md §10.
 */
trait BuildsAlertData
{
    /**
     * min / p25 / median / p75 / max, in cents. The median is the "usual €93"
     * every alert in these tests quotes.
     *
     * @var list<int>
     */
    protected const USUAL = [4000, 6000, 9300, 12000, 15000];

    /** Scores 94 — an insane deal at every sensitivity, Relaxed included. */
    protected const INSANE_CENTS = 4400;

    /** Scores 72 — a great deal, but not one Relaxed would mention. */
    protected const GREAT_CENTS = 6000;

    /** Scores 40 — the usual price, which is not news at any setting. */
    protected const ORDINARY_CENTS = 9300;

    /**
     * A route this account watches, priced at `$cents` today — one price, deliberately, not a trend series
     * (docs/BUSINESS-LOGIC.md §10).
     */
    protected function watchedRoute(User $user, string $destination, int $cents, bool $active = true): Route
    {
        $route = $this->makeRoute('AMS', $destination);

        $this->watch($user, $route, $active);
        $this->priceRoute($route, $cents);

        return $route;
    }

    /**
     * A route that already exists, by code. `routes.code` is unique, so a test
     * that wants the one a fixture already made cannot ask the factory for it.
     */
    protected function existingRoute(string $code): Route
    {
        return Route::query()->where('code', $code)->sole();
    }

    /**
     * Today's price on a route old enough to have a score — `trackedSince()` is what clears the maturity gate
     * (docs/BUSINESS-LOGIC.md §10).
     */
    protected function priceRoute(Route $route, int $cents, string $departure = '2026-09-04'): void
    {
        $this->trackedSince($route, $cents);
        $this->observe($route, [$cents], Date::now((string) config('orbit.timezone'))->toDateString());
        $this->summarise($route, ...self::USUAL);
        $this->offer($route, [$departure => $cents]);
    }

    /**
     * The same route, priced today and watched since this morning — the state
     * every route on the watchlist was in on the day the statistics went live.
     */
    protected function brandNewRoute(User $user, string $destination, int $cents): Route
    {
        $route = $this->makeRoute('AMS', $destination);

        $this->watch($user, $route);
        $this->observe($route, [$cents], Date::now((string) config('orbit.timezone'))->toDateString());
        $this->summarise($route, ...self::USUAL);
        $this->offer($route, ['2026-09-04' => $cents]);

        return $route;
    }

    /**
     * A ledger row for something Orbit already said. Delivered by default,
     * since the digest only counts rows a channel actually took.
     */
    protected function alerted(
        User $user,
        AlertType $type,
        ?Route $route,
        ?DealRule $rule,
        int $cents,
        string $triggeredAt,
        bool $delivered = true,
    ): Alert {
        $alert = Alert::query()->create([
            'user_id'      => $user->id,
            'route_id'     => $route?->id,
            'deal_rule_id' => $rule?->id,
            'type'         => $type,
            'score'        => $type === AlertType::RouteDeal ? 94 : null,
            'price_cents'  => $cents,
            'payload'      => [
                'routeCode'   => $route?->code,
                'origin'      => 'Amsterdam',
                'destination' => 'Porto',
                'priceCents'  => $cents,
                'headline'    => 'AMS→OPO €'.intdiv($cents, 100),
            ],
            'channel'      => Alert::CHANNEL_MAIL,
            'triggered_at' => $triggeredAt,
            'delivered_at' => $delivered ? $triggeredAt : null,
        ]);

        return $alert;
    }

    /**
     * Everything that actually reached the transport — array mailer, not `Mail::fake()`, so `delivered_at` assertions
     * still work (docs/BUSINESS-LOGIC.md §10).
     *
     * @return list<Email>
     */
    protected function sentMail(): array
    {
        $mailer = $this->app->make('mailer');
        $this->assertInstanceOf(Mailer::class, $mailer);

        $transport = $mailer->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        $emails = [];

        foreach ($transport->messages() as $sent) {
            $message = $sent->getOriginalMessage();
            $this->assertInstanceOf(Email::class, $message);

            $emails[] = $message;
        }

        return $emails;
    }
}
