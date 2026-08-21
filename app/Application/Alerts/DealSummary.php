<?php

declare(strict_types=1);

namespace App\Application\Alerts;

use App\Application\Rules\RuleMatch;
use App\Application\Routes\BookingLink;
use App\Application\Routes\RouteSnapshot;

/**
 * One trip as an alert states it, and character for character the JSON in `alerts.payload`.
 * The stored copy is FROZEN and must stay that way (docs/BUSINESS-LOGIC.md §10).
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
     * A watched route, from the snapshot the score was computed on: the PRICE is the
     * snapshot's, the DATE is the calendar's cheapest (docs/BUSINESS-LOGIC.md §10).
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
             * The primary hand-off, Aviasales — a mail reader cannot see two links and pick,
             * so it gets the site the price came out of. See BookingLink.
             */
            bookingUrl: BookingLink::aviasales($route, $snapshot->cheapest?->departureDate),
        );
    }

    /**
     * A fare a standing rule matched. No score and no usual price, and neither is an
     * omission: a rule match is a route, a date and a price (docs/BUSINESS-LOGIC.md §10).
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
     * THE HEADLINE IS STORED rather than re-derived: it is the subject line that landed on
     * somebody's phone, and the ledger remembers what was said.
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
     * "AMS→OPO €44 — 53% below usual": written for a lock screen, and the second clause is
     * whichever one is true (docs/BUSINESS-LOGIC.md §10).
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
     * "Fri 12 Jun", or an empty string when there is no date. FORMATTED FROM THE STORED
     * STRING: a bare Y-m-d means a day, and a timezone-aware parser moves it a night.
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
     * Cents to the string a person reads. NOT App\Http\Resources\Euros — neither the domain
     * nor this layer may import from App\Http, so the six lines are repeated.
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
