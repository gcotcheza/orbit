<?php

declare(strict_types=1);

namespace App\Application\Routes;

use App\Models\Route;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use App\Models\LivePriceCheck;
use Illuminate\Support\Facades\Date;
use App\Infrastructure\Verify\GoogleFlightsCheck;

/**
 * "Check live price" — the one place in Orbit where a person's tap spends a
 * SerpAPI search.
 *
 * =============================================================================
 * WHAT PROBLEM THIS IS
 * =============================================================================
 * Orbit's fares are Travelpayouts' CACHE of other people's searches. The owner
 * opened DUS→VCE at €36 — "Seen 3 days ago", usual €62 — tapped through to
 * Aviasales, and the live direct was about $150 with nothing anywhere near €36.
 * Every number on that screen was true and the headline was still a fare nobody
 * could buy. The small print said so; the €36 was in 42-point type.
 *
 * The other half of the answer is on the screen (a stale-cheap fare is demoted
 * rather than shouted — see App\Http\Resources\RouteDetailResource). THIS half
 * is the way to find out for certain, and it costs money, so the whole of this
 * class is about not spending it twice.
 *
 * =============================================================================
 * THE GUARDRAILS, WHICH ARE THE OWNER'S AND ARE BINDING
 * =============================================================================
 *   1. QUOTA FIRST, ALWAYS. `GoogleFlightsCheck::available()` reads SerpAPI's
 *      free `account.json` before a single search is spent and answers 0 on
 *      anything it cannot read. Nothing here assumes a budget, ever.
 *   2. THE 50-SEARCH RESERVE IS UNTOUCHED. It is the same
 *      `orbit.serpapi.reserve` discovery obeys, enforced in the same method —
 *      at or below it this refuses, and the screen says the budget is reserved
 *      rather than pretending the check failed.
 *   3. USER-INITIATED ONLY. The single caller is
 *      App\Http\Controllers\RouteController::liveCheck(), behind auth, CSRF and
 *      the `live-check` throttle. Nothing schedules this, no job dispatches it,
 *      and there is no method here that takes a list.
 *   4. A COOLDOWN, so a second tap is free. `orbit.live_check.cooldown_hours`
 *      of stored answer is served from the row and spends nothing — see
 *      `latest()`, which is also what a re-VIEW of the screen reads.
 *
 * =============================================================================
 * ONE SEARCH PER TAP, AND THE PROBE IS NOT ONE OF THEM
 * =============================================================================
 * `available()` costs a round trip to `account.json`, which SerpAPI does not
 * bill. It is asked on every accepted tap rather than cached, because the whole
 * point of the guardrail is that the budget is never assumed — and the tap that
 * would be wrong to let through is exactly the one made an hour after the last
 * reading was taken.
 *
 * IT IS NOT ASKED WHEN NOTHING WILL BE SPENT. A tap inside the cooldown answers
 * from the row before any of this runs, which is what makes re-tapping free
 * rather than merely cheap.
 */
final readonly class LivePriceChecks
{
    public function __construct(
        private GoogleFlightsCheck $google,
        private LoggerInterface $logger,
    ) {}

    /**
     * The stored answer for this route and departure, if there is one and it is
     * still inside the cooldown.
     *
     * READ ON EVERY VIEW OF THE DETAIL SCREEN, not only after a tap: a check
     * somebody paid for at lunchtime is what the screen shows at teatime, and a
     * button offering to buy the same answer again would be the app forgetting
     * what it knows. One row by unique index — see the migration.
     */
    public function latest(Route $route, DateTimeImmutable $departure): ?LivePriceCheck
    {
        $check = $this->row($route, $departure);

        if ($check === null) {
            return null;
        }

        return $check->isFresh(Date::now()->toImmutable(), self::cooldownHours()) ? $check : null;
    }

    /**
     * Ask Google about this route and date, unless the answer is already known
     * or the budget says no.
     *
     * NULL MEANS NOTHING WAS SPENT AND NOTHING IS CLAIMED — no key, no quota, a
     * probe that could not be read. The caller answers that with an explanation
     * rather than an error, and the cached price stays exactly where it was.
     * There is no configuration that turns a null into a price.
     *
     * A ROW IS WRITTEN EVEN WHEN GOOGLE SAYS NOTHING, because SerpAPI counted
     * that search either way. The cooldown therefore applies to a silent answer
     * as much as to a useful one — the alternative is a thin route re-asked, at
     * cost, every six hours forever.
     */
    public function check(Route $route, DateTimeImmutable $departure): ?LivePriceCheck
    {
        $existing = $this->latest($route, $departure);

        if ($existing !== null) {
            return $existing;
        }

        /*
         * THE BUDGET, READ BEFORE ANYTHING IS SPENT. `available()` is the whole
         * guardrail in one call: no key, an unreadable probe and a quota at or
         * below the reserve all answer 0, and it fails CLOSED on every error
         * path. One search is all this needs, so any positive answer is enough.
         */
        if ($this->google->available() < 1) {
            $this->logger->info('A live price check was refused — no SerpAPI budget.', [
                'route'     => $route->code,
                'departure' => $departure->format('Y-m-d'),
            ]);

            return null;
        }

        $verdict = $this->google->check(
            $route->origin->iata,
            $route->destination->iata,
            $departure,
        );

        /*
         * THE ROW IS FOUND AND FILLED RATHER THAN `updateOrCreate`d, and the
         * difference is a database bug rather than a style. The unique key
         * includes a DATE column, and the model's cast writes it as
         * `Y-m-d H:i:s`; an `updateOrCreate` matches on the bare `Y-m-d` it was
         * handed, which finds nothing on SQLite — where the value really is
         * stored as the string it was written with — and then inserts a second
         * row into a unique index. Postgres coerces both spellings and would
         * never show it. `whereDate()` is the form that survives both, and
         * App\Application\Routes\RouteSnapshots carries the long version of the
         * same warning.
         */
        $check = $this->row($route, $departure) ?? new LivePriceCheck([
            'route_id'       => $route->id,
            'departure_date' => $departure->format('Y-m-d'),
        ]);

        $check->fill([
            'checked_at'     => Date::now(),
            'google_verdict' => $verdict?->toArray(),
        ])->save();

        $this->logger->info('A live price check was made.', [
            'route'     => $route->code,
            'departure' => $departure->format('Y-m-d'),
            /* Null when Google had no `price_insights` for the pair, which is
               an ordinary answer on a thin route and is worth being able to
               count in the log rather than inferring from silence. */
            'lowest' => $check->lowestCents(),
        ]);

        return $check;
    }

    /**
     * The stored row for this route and departure, however old — one row, by
     * unique index. `latest()` is this plus the cooldown; the write path needs
     * the expired one too, because that is the row it overwrites.
     */
    private function row(Route $route, DateTimeImmutable $departure): ?LivePriceCheck
    {
        return LivePriceCheck::query()
            ->where('route_id', $route->id)
            ->whereDate('departure_date', $departure->format('Y-m-d'))
            ->first();
    }

    private static function cooldownHours(): int
    {
        return (int) config('orbit.live_check.cooldown_hours');
    }
}
