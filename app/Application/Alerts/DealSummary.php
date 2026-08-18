<?php

declare(strict_types=1);

namespace App\Application\Alerts;

use App\Application\Rules\RuleMatch;
use App\Application\Routes\BookingLink;
use App\Application\Routes\RouteSnapshot;

/**
 * One trip, as an alert states it — and, character for character, the JSON that
 * goes into `alerts.payload`.
 *
 * ONE SHAPE FOR THE MAIL AND FOR THE LEDGER, which is the whole point. The
 * alternative is a mail built from today's models and a payload assembled
 * beside it, and the two drift the first time a line is added to the template:
 * the inbox says one thing and `GET /api/alerts` remembers another, with no way
 * left to find out which was true. Here the mail renders this object, the
 * ledger stores `toArray()` of the same object, and `from()` reads it back
 * months later exactly as it was sent.
 *
 * THE STORED COPY IS FROZEN AND MUST STAY THAT WAY. A row written in March
 * quotes March's usual price and March's percentage. Re-deriving those from the
 * route when the ledger is read would quietly rewrite history to agree with
 * today's statistics — and the one thing a person wants from an alert history
 * is what the alert actually said.
 *
 * `from()` IS DEFENSIVE for the reason App\Domain\Rules\RuleCriteria::from() is:
 * it parses JSON that an earlier version of this class wrote, and a payload
 * with one unreadable field should cost that field rather than the whole
 * screen.
 */
final readonly class DealSummary
{
    public function __construct(
        /** `AMS-OPO`, the code every other endpoint keys on. */
        public string $routeCode,
        public string $origin,
        public string $destination,
        public int $priceCents,
        public ?int $usualCents,
        /** Whole percent under usual; negative above it. NULL when unknowable. */
        public ?int $percentUnderUsual,
        /** 0-100 for a watched route; NULL for a rule match, which has no score. */
        public ?int $score,
        /** The app's own one-liner: "Cheap & still falling". */
        public ?string $verdict,
        /** `Y-m-d` — the day you would FLY, not the day we looked. */
        public ?string $departureDate,
        public string $bookingUrl,
    ) {}

    /**
     * A watched route, from the snapshot the score was computed on.
     *
     * THE PRICE IS THE SNAPSHOT'S `currentCents` and not the cheapest calendar
     * fare, because that is the number the score was computed from — an alert
     * whose headline price and whose score came from two different fares would
     * be defending a claim it had not made. The DATE comes from the calendar,
     * because "the cheapest fare in the next 90 days" is not a day anybody can
     * book without being told which one it is.
     */
    public static function forRoute(RouteSnapshot $snapshot, int $priceCents): self
    {
        $route = $snapshot->route;

        return new self(
            routeCode: $route->code,
            origin: $route->origin->city,
            destination: $route->destination->city,
            priceCents: $priceCents,
            usualCents: $snapshot->usualCents(),
            percentUnderUsual: $snapshot->percentUnderUsual(),
            score: $snapshot->deal->score,
            verdict: $snapshot->deal->verdict->label,
            departureDate: $snapshot->cheapest?->departureDate->format('Y-m-d'),
            /*
             * THE PRIMARY HAND-OFF, which is now Aviasales — the search Orbit's
             * fares come out of. A mail is the one place a reader cannot see
             * two links and pick; it gets the one site that can be expected to
             * hold the price in the subject line. See App\Application\Routes\
             * BookingLink.
             */
            bookingUrl: BookingLink::aviasales($route, $snapshot->cheapest?->departureDate),
        );
    }

    /**
     * A fare a standing rule matched.
     *
     * NO SCORE AND NO USUAL PRICE, and neither is an omission. The matching
     * engine works from the rule's own maximum price and the calendar
     * (App\Application\Rules\RuleMatches) — it never asks for statistics, and
     * inventing them here would mean a second query per matched route on a run
     * that already fans out over thirty of them. What the mail says about a
     * rule match is what the rule asked for: a route, a date and a price.
     */
    public static function forMatch(RuleMatch $match): self
    {
        return new self(
            routeCode: $match->route->code,
            origin: $match->route->origin->city,
            destination: $match->route->destination->city,
            priceCents: $match->cheapest->cents,
            usualCents: null,
            percentUnderUsual: null,
            score: null,
            verdict: null,
            departureDate: $match->cheapest->departureDate->format('Y-m-d'),
            /* The primary hand-off — see forRoute() above. */
            bookingUrl: BookingLink::aviasales($match->route, $match->cheapest->departureDate),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function from(array $payload): self
    {
        return new self(
            routeCode: self::string($payload['routeCode'] ?? null) ?? '',
            origin: self::string($payload['origin'] ?? null) ?? '',
            destination: self::string($payload['destination'] ?? null) ?? '',
            priceCents: self::int($payload['priceCents'] ?? null) ?? 0,
            usualCents: self::int($payload['usualCents'] ?? null),
            percentUnderUsual: self::int($payload['percentUnderUsual'] ?? null),
            score: self::int($payload['score'] ?? null),
            verdict: self::string($payload['verdict'] ?? null),
            departureDate: self::string($payload['departureDate'] ?? null),
            bookingUrl: self::string($payload['bookingUrl'] ?? null) ?? '',
        );
    }

    /**
     * THE HEADLINE IS STORED rather than re-derived on read, unlike everything
     * else `from()` reads. It is the SUBJECT LINE that landed on somebody's
     * phone, and the ledger's job is to remember what was said — re-rendering
     * it through today's code would make a history that silently agrees with
     * whatever the copy has since become.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'routeCode'         => $this->routeCode,
            'origin'            => $this->origin,
            'destination'       => $this->destination,
            'priceCents'        => $this->priceCents,
            'usualCents'        => $this->usualCents,
            'percentUnderUsual' => $this->percentUnderUsual,
            'score'             => $this->score,
            'verdict'           => $this->verdict,
            'departureDate'     => $this->departureDate,
            'bookingUrl'        => $this->bookingUrl,
            'headline'          => $this->headline(),
        ];
    }

    /**
     * "AMS→OPO €44 — 53% below usual" — the subject line, and the one line
     * `GET /api/alerts` shows per row.
     *
     * WRITTEN FOR A LOCK SCREEN. It is read in a notification shade before it
     * is read anywhere else, which is perhaps forty characters: the two ends,
     * the price, and the single fact that makes it worth opening. The arrow is
     * the route code's own hyphen turned into something a person reads as a
     * direction.
     *
     * THE SECOND CLAUSE IS WHICHEVER ONE IS TRUE. A watched route has a usual
     * price to be under; a rule match has a date it flies on and nothing to
     * compare against, and a subject that said "0% below usual" because the
     * statistics were missing would be worse than one that said nothing.
     */
    public function headline(): string
    {
        $line = $this->pair().' '.self::euros($this->priceCents);

        if ($this->percentUnderUsual !== null && $this->percentUnderUsual > 0) {
            return $line.sprintf(' — %d%% below usual', $this->percentUnderUsual);
        }

        if ($this->departureDate !== null) {
            return $line.' — '.$this->departureDay();
        }

        return $line;
    }

    /**
     * "€44" — the fare, as a sentence says it rather than as JSON does.
     */
    public function price(): string
    {
        return self::euros($this->priceCents);
    }

    /**
     * "€93", or an empty string when this route has no usual price yet.
     */
    public function usual(): string
    {
        return $this->usualCents === null ? '' : self::euros($this->usualCents);
    }

    /**
     * "AMS→OPO".
     */
    public function pair(): string
    {
        return str_replace('-', '→', $this->routeCode);
    }

    /**
     * "Amsterdam → Porto", for the body of a mail, where there is room for the
     * names of places rather than the codes airlines file them under.
     */
    public function journey(): string
    {
        return $this->origin.' → '.$this->destination;
    }

    /**
     * "Fri 12 Jun", or an empty string when there is no date.
     *
     * FORMATTED FROM THE STORED STRING, not from a date object: the value is a
     * bare `Y-m-d` that means a day and not an instant, and handing it to a
     * timezone-aware parser is how a departure moves to the evening before.
     */
    public function departureDay(): string
    {
        if ($this->departureDate === null) {
            return '';
        }

        $parts = explode('-', $this->departureDate);

        if (count($parts) !== 3) {
            return $this->departureDate;
        }

        $stamp = mktime(12, 0, 0, (int) $parts[1], (int) $parts[2], (int) $parts[0]);

        return $stamp === false ? $this->departureDate : date('D j M', $stamp);
    }

    /**
     * Cents to the string a person reads.
     *
     * NOT App\Http\Resources\Euros, which converts cents to a JSON NUMBER at
     * the HTTP boundary and is right there and wrong here — a mail needs a
     * currency symbol and a decision about decimals. App\Domain\Pricing\
     * DealScorer and App\Domain\Rules\ParsedRule each carry the same six lines
     * for the same reason: neither the domain nor this layer may import from
     * App\Http.
     */
    private static function euros(int $cents): string
    {
        return $cents % 100 === 0
            ? '€'.intdiv($cents, 100)
            : '€'.number_format($cents / 100, 2, '.', '');
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
