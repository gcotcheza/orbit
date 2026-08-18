<?php

declare(strict_types=1);

namespace App\Domain\Rules;

/**
 * What a rule actually asks for, once the English has been read.
 *
 * SIX NULLABLE-OR-EMPTY FIELDS, and every one of them means "no opinion"
 * rather than "no results". An empty `origins` is all three airports, an empty
 * `vibes` is anywhere Orbit knows, a null `maxPriceCents` is any price. That
 * is what makes the create screen's chip removal work: taking a chip away
 * WIDENS the rule, and a criteria object that treated absence as a filter
 * would narrow it to nothing instead.
 *
 * IT IS PERSISTED AS `deal_rules.criteria`, so `from()` is reading JSON that a
 * previous version of this class wrote and has to survive a shape it does not
 * recognise. Every field is validated on the way in and silently dropped if it
 * is wrong — a rule with one unreadable field is a slightly wider rule, and a
 * rule that throws on load is a screen that cannot be opened at all.
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
     * The shape `from()` reads and `deal_rules.criteria` stores. Also, field
     * for field, the `criteria` object docs/API.md publishes — one definition
     * of the rule's shape rather than one for the column and one for the JSON.
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
     * Nothing was understood — the sentence was empty, or it was garbage.
     *
     * The create screen branches on this rather than on an empty chip list,
     * because "we read nothing out of that" and "you removed every chip" are
     * the same criteria and a different thing to say to somebody.
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
