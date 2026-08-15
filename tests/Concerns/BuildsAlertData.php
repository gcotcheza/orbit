<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Alerts\AlertType;
use App\Models\Alert;
use App\Models\DealRule;
use App\Models\Route;
use App\Models\User;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Date;
use Symfony\Component\Mime\Email;

/**
 * Fixtures for the alert pipeline's tests.
 *
 * ONE SET OF STATISTICS FOR EVERY ROUTE, so that the score a route earns is a
 * function of its price alone and a reader can check it: against the knots
 * below, €44 scores 94, €60 scores 72 and €93 scores 40 — one on each side of
 * every sensitivity. The arithmetic is App\Domain\Pricing\DealScorer's and has
 * its own tests; what these fixtures buy is being able to say "a route the
 * owner would want to hear about" in one line.
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
     * A route this account watches, priced at `$cents` today.
     *
     * ONE OBSERVATION AND NOT A SERIES, deliberately: two prices give the
     * scorer a trend to fold in and the expected score stops being something a
     * reader can work out from the knots above. The trend component has its own
     * tests.
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

    protected function priceRoute(Route $route, int $cents, string $departure = '2026-09-04'): void
    {
        $this->observe($route, [$cents], Date::now((string) config('orbit.timezone'))->toDateString());
        $this->summarise($route, ...self::USUAL);
        $this->offer($route, [$departure => $cents]);
    }

    /**
     * A ledger row for something Orbit already said — the cooldown's input.
     *
     * DELIVERED BY DEFAULT, because that is what an alert from yesterday
     * normally is, and because the digest's "this week" callout only counts
     * rows a channel actually took.
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
            'user_id' => $user->id,
            'route_id' => $route?->id,
            'deal_rule_id' => $rule?->id,
            'type' => $type,
            'score' => $type === AlertType::RouteDeal ? 94 : null,
            'price_cents' => $cents,
            'payload' => [
                'routeCode' => $route?->code,
                'origin' => 'Amsterdam',
                'destination' => 'Porto',
                'priceCents' => $cents,
                'headline' => 'AMS→OPO €'.intdiv($cents, 100),
            ],
            'channel' => Alert::CHANNEL_MAIL,
            'triggered_at' => $triggeredAt,
            'delivered_at' => $delivered ? $triggeredAt : null,
        ]);

        return $alert;
    }

    /**
     * Everything that actually reached the transport.
     *
     * THE ARRAY MAILER RATHER THAN `Mail::fake()`. A fake replaces the channel
     * and never fires NotificationSent, which is the event
     * App\Infrastructure\Notify\MarkAlertsDelivered stamps `delivered_at` from
     * — so a test that faked it could not tell a delivered alert from an
     * undelivered one. phpunit.xml already pins MAIL_MAILER to `array`, so this
     * is the whole pipeline with only the socket missing.
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
