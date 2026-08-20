<?php

declare(strict_types=1);

namespace App\Domain\Rules;

/**
 * What a rule actually asks for, once the English has been read.
 *
 * Every nullable/empty field means "no opinion", not "no results" (empty origins = all airports, null maxPriceCents =
 * any price) — removing a chip WIDENS the rule rather than narrowing it to nothing (docs/BUSINESS-LOGIC.md §11).
 *
 * Persisted as `deal_rules.criteria`; `from()` must survive JSON an older version wrote. Unreadable fields are
 * dropped, not thrown on (docs/BUSINESS-LOGIC.md §11).
 */
final readonly class RuleCriteria
{
    /**
     * @param  list<string>  $origins  IATA codes, subset of config('orbit.origins'); empty means all of them
     * @param  array{int, int}|null  $tripLengthNights  [min, max]
     * @param  list<int>  $departDows  ISO weekday numbers, 1 (Monday) to 7; empty means any day
     * @param  list<string>  $vibes  from the closed vocabulary in config('orbit.nlp.vibe_words'); empty means anywhere
     */
    public function __construct(
        public array $origins = [],
        public ?int $maxPriceCents = null,
        public ?array $tripLengthNights = null,
        public array $departDows = [],
        public ?MonthWindow $dateWindow = null,
        public array $vibes = [],
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function from(array $data): self
    {
        return new self(
            origins: self::strings($data['origins'] ?? null),
            maxPriceCents: self::positiveInt($data['maxPriceCents'] ?? null),
            tripLengthNights: self::nights($data['tripLengthNights'] ?? null),
            departDows: self::dows($data['departDows'] ?? null),
            dateWindow: self::window($data['dateWindow'] ?? null),
            vibes: self::strings($data['vibes'] ?? null),
        );
    }

    /**
     * Shape `from()` reads, `deal_rules.criteria` stores, and docs/API.md publishes field-for-field — one definition, not
     * three (docs/BUSINESS-LOGIC.md §11).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'origins'          => $this->origins,
            'maxPriceCents'    => $this->maxPriceCents,
            'tripLengthNights' => $this->tripLengthNights,
            'departDows'       => $this->departDows,
            'dateWindow'       => $this->dateWindow === null ? null : [
                'from'  => $this->dateWindow->from,
                'to'    => $this->dateWindow->to,
                'label' => $this->dateWindow->label(),
            ],
            'vibes' => $this->vibes,
        ];
    }

    /**
     * Nothing understood (empty sentence, or garbage). Create screen branches on this rather than an empty chip list —
     * same criteria, different message (docs/BUSINESS-LOGIC.md §11).
     */
    public function isEmpty(): bool
    {
        return $this->origins === []
            && $this->maxPriceCents === null
            && $this->tripLengthNights === null
            && $this->departDows === []
            && $this->dateWindow === null
            && $this->vibes === [];
    }

    /**
     * The origins this rule flies from, spelled out.
     *
     * @param  list<string>  $all  config('orbit.origins')
     * @return list<string>
     */
    public function originsOrAll(array $all): array
    {
        return $this->origins === [] ? $all : $this->origins;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item) && $item !== '',
        )));
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * @return array{int, int}|null
     */
    private static function nights(mixed $value): ?array
    {
        if (! is_array($value) || count($value) !== 2) {
            return null;
        }

        [$min, $max] = array_values($value);

        if (! is_int($min) || ! is_int($max) || $min < 0 || $max < $min) {
            return null;
        }

        return [$min, $max];
    }

    /**
     * @return list<int>
     */
    private static function dows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $days = array_filter(
            $value,
            static fn (mixed $day): bool => is_int($day) && $day >= 1 && $day <= 7,
        );

        sort($days);

        /** @var list<int> $unique */
        $unique = array_values(array_unique($days));

        return $unique;
    }

    private static function window(mixed $value): ?MonthWindow
    {
        if (! is_array($value)) {
            return null;
        }

        $from = $value['from'] ?? null;
        $to = $value['to'] ?? null;

        return is_int($from) && is_int($to) ? MonthWindow::of($from, $to) : null;
    }
}
