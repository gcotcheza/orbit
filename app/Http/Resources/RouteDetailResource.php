<?php

declare(strict_types=1);

namespace App\Http\Resources;

use DateTimeZone;
use Illuminate\Http\Request;
use App\Domain\Pricing\PricePoint;
use Illuminate\Support\Facades\Date;
use App\Application\Routes\BookingLink;

/**
 * Everything the route detail screen draws (design/README.md §2): the summary,
 * the series behind the chart, the reference line, the callout and the button.
 *
 * `history` IS THE CHART and `sparkline` (inherited from the summary) is its
 * last fortnight. Both are sent because the watchlist needs only the short one
 * and this screen needs both — the header re-uses the summary shape exactly.
 *
 * `stats` IS THE DASHED "usual price" LINE, and null when the provider has no
 * statistics for the pair. The chart then draws without a reference, which is
 * the honest picture rather than a line at zero.
 *
 * `cheapest.mayBeGone` IS THE ONE FIELD HERE THAT IS A JUDGEMENT rather than a
 * fact — whether the headline fare is old enough AND cheap enough that drawing
 * it at full volume would be a claim this app cannot support. See the note on
 * it below, and config/orbit.php's `live_check` for the fare that bought it.
 */
final class RouteDetailResource extends RouteSummaryResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->snapshot();
        $stats = $snapshot->stats;
        $cheapest = $snapshot->cheapest;

        $summary = parent::toArray($request);

        return [
            ...$summary,

            /*
             * Oldest first, up to config('orbit.history.chart_days'). Dates are
             * the OBSERVATION dates — when we looked — not departure dates.
             * The calendar endpoint is the other axis.
             */
            'history' => array_map(static fn (PricePoint $point): array => [
                'date'  => $point->on->format('Y-m-d'),
                'price' => Euros::from($point->cents),
            ], $snapshot->history->points),

            'stats' => $stats === null ? null : [
                'min'    => Euros::from($stats->minCents),
                'p25'    => Euros::from($stats->p25Cents),
                'median' => Euros::from($stats->medianCents),
                'p75'    => Euros::from($stats->p75Cents),
                'max'    => Euros::from($stats->maxCents),
            ],

            'advice' => [
                'title' => $snapshot->deal->advice->title,
                'body'  => $snapshot->deal->advice->body,
                'tone'  => $snapshot->deal->advice->tone,
            ],

            /*
             * `cheapest` — the cheapest DEPARTURE still on offer in the poll
             * window — is INHERITED from the summary, because the screens that
             * read the summary needed it too (see the note there). THIS
             * RESOURCE ADDS ONE FIELD TO IT rather than restating the shape:
             * how old that price is.
             *
             * ONLY HERE AND NOT ON THE SUMMARY, deliberately. The three screens
             * that read the summary — the globe's spotlight card, the watchlist
             * rows, the chips — print a fare in a space that has room for a
             * number and nothing else; a freshness line they cannot draw would
             * be a field they all had to ignore. This is the screen with room
             * to explain itself, and the one with a Book button under it.
             */
            'cheapest' => $summary['cheapest'] === null ? null : [
                ...$summary['cheapest'],
                'foundAt' => $cheapest?->foundAt?->setTimezone(
                    new DateTimeZone((string) config('orbit.timezone')),
                )->format('c'),

                /*
                 * ⚠ AND WHETHER THIS SCREEN SHOULD BE SHOUTING IT.
                 *
                 * TRUE MEANS OLD AND WELL UNDER USUAL — the combination that
                 * produced DUS→VCE at €36 against a live market of about $150,
                 * three days after anybody had seen the €36. The client demotes
                 * the headline and says "may be gone" instead of drawing the
                 * app's most confident number over a fare that has probably
                 * already sold. config/orbit.php, `live_check`, is the rule and
                 * both of its numbers; App\Application\Routes\RouteSnapshot is
                 * where it is applied.
                 *
                 * THE SERVER'S JUDGEMENT AND NOT THE CLIENT'S, for the reason
                 * `confident` is: the two thresholds live in config, the same
                 * answer has to reach a future alert as reaches this screen, and
                 * a rule re-derived in a Vue component is a rule that goes on
                 * being applied the day the config is retuned. The client styles
                 * on this flag; it does not recompute it.
                 *
                 * FALSE IS THE ORDINARY ANSWER, including on every fare whose
                 * age is unknown. See the snapshot for why not-knowing is never
                 * demoted.
                 */
                'mayBeGone' => $snapshot->cheapestMayBeGone(
                    Date::now()->toDateTimeImmutable(),
                    (int) config('orbit.live_check.stale_after_hours'),
                    (int) config('orbit.live_check.under_usual_percent'),
                ),
            ],

            /*
             * WHERE "BOOK IT" GOES — both of them, dated to the cheapest
             * departure. Null before the first poll, and then each link points
             * at the route without a date, which is still a useful place to
             * land (Aviasales' pre-filled search form, Skyscanner's whole
             * month).
             *
             * `aviasales` IS THE PRIMARY and the screen draws it as such: it is
             * the search Orbit's own fares come out of, so it is the only site
             * that can be expected to be holding the price shown above it. See
             * App\Application\Routes\BookingLink for the €29 that was really
             * €68 and made that a correctness matter rather than a preference.
             */
            'booking' => [
                'aviasales'  => BookingLink::aviasales($snapshot->route, $cheapest?->departureDate),
                'skyscanner' => BookingLink::skyscanner($snapshot->route, $cheapest?->departureDate),
            ],
        ];
    }
}
