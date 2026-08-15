<?php

declare(strict_types=1);

namespace App\Domain\Rules;

/**
 * A sentence, read: the chips design/README.md §4 draws, and the criteria they
 * add up to.
 *
 * ONE DIRECTION ONLY — criteria in, chips out, criteria back. Both adapters
 * (the model and the regexes) answer with a RuleCriteria and hand it to
 * `of()`; nothing builds chips by hand. That is what guarantees the chips on
 * screen and the rule that gets saved can never describe different trips,
 * which is the failure this screen is most able to hide: a chip nobody notices
 * is missing is a rule that quietly does not do what it says.
 *
 * `without()` IS THE WHOLE POINT OF THE CLASS. Removing a chip re-derives the
 * criteria from what is left rather than re-reading edited text, so taking
 * "From EIN" off leaves every other chip exactly where it was, and putting it
 * back (Reset) is the same parse again.
 */
final readonly class ParsedRule
{
    /**
     * @param  list<RuleChip>  $chips
     */
    private function __construct(public array $chips) {}

    /**
     * The chips for a set of criteria, in the design's order: where from, how
     * much, how long, which day, when, what for.
     */
    public static function of(RuleCriteria $criteria, RuleVocabulary $vocabulary): self
    {
        $chips = [];

        foreach ($criteria->origins as $origin) {
            $chips[] = RuleChip::each(ChipKind::Origin, $origin, $origin, $origin);
        }

        if ($criteria->maxPriceCents !== null) {
            $chips[] = RuleChip::only(
                ChipKind::MaxPrice,
                '€'.self::euros($criteria->maxPriceCents),
                $criteria->maxPriceCents,
            );
        }

        if ($criteria->tripLengthNights !== null) {
            $chips[] = RuleChip::only(
                ChipKind::TripLength,
                self::nights($criteria->tripLengthNights),
                $criteria->tripLengthNights,
            );
        }

        if ($criteria->departDows !== []) {
            $chips[] = RuleChip::only(
                ChipKind::Depart,
                self::days($criteria->departDows),
                $criteria->departDows,
            );
        }

        if ($criteria->dateWindow !== null) {
            $chips[] = RuleChip::only(
                ChipKind::DateWindow,
                $criteria->dateWindow->label(),
                $criteria->dateWindow,
            );
        }

        foreach ($criteria->vibes as $vibe) {
            $chips[] = RuleChip::each(ChipKind::Vibe, $vibe, $vocabulary->labelFor($vibe), $vibe);
        }

        return new self($chips);
    }

    /**
     * Nothing was understood. Not an error — see RuleCriteria::isEmpty().
     */
    public static function nothing(): self
    {
        return new self([]);
    }

    /**
     * The same parse with some chips taken off.
     *
     * UNKNOWN IDS ARE IGNORED rather than rejected. The client holds its
     * removed-ids list across re-parses of a sentence somebody is still
     * typing, so an id for a chip the current text no longer produces is the
     * normal case and not a bad request.
     *
     * @param  list<string>  $removedIds
     */
    public function without(array $removedIds): self
    {
        if ($removedIds === []) {
            return $this;
        }

        return new self(array_values(array_filter(
            $this->chips,
            static fn (RuleChip $chip): bool => ! in_array($chip->id, $removedIds, true),
        )));
    }

    /**
     * What the surviving chips add up to.
     *
     * The type guards are not decoration: `$chip->value` is `mixed` because
     * one list holds six shapes, and this is the one place that has to know
     * which is which.
     */
    public function criteria(): RuleCriteria
    {
        $origins = [];
        $maxPriceCents = null;
        $tripLengthNights = null;
        $departDows = [];
        $dateWindow = null;
        $vibes = [];

        foreach ($this->chips as $chip) {
            $value = $chip->value;

            if ($chip->kind === ChipKind::Origin && is_string($value)) {
                $origins[] = $value;
            } elseif ($chip->kind === ChipKind::MaxPrice && is_int($value)) {
                $maxPriceCents = $value;
            } elseif ($chip->kind === ChipKind::TripLength && is_array($value)) {
                $tripLengthNights = $value;
            } elseif ($chip->kind === ChipKind::Depart && is_array($value)) {
                $departDows = $value;
            } elseif ($chip->kind === ChipKind::DateWindow && $value instanceof MonthWindow) {
                $dateWindow = $value;
            } elseif ($chip->kind === ChipKind::Vibe && is_string($value)) {
                $vibes[] = $value;
            }
        }

        /*
         * Back through RuleCriteria::from() rather than into the constructor,
         * so the shapes a chip carries are validated by the same code that
         * validates the shapes the database carries. One definition of what a
         * criteria field may hold.
         */
        return RuleCriteria::from([
            'origins' => $origins,
            'maxPriceCents' => $maxPriceCents,
            'tripLengthNights' => $tripLengthNights,
            'departDows' => $departDows,
            'dateWindow' => $dateWindow === null ? null : ['from' => $dateWindow->from, 'to' => $dateWindow->to],
            'vibes' => $vibes,
        ]);
    }

    /**
     * Cents to the euros a chip shows. Whole euros lose the decimals, which is
     * every price anybody types.
     */
    private static function euros(int $cents): string
    {
        return $cents % 100 === 0
            ? (string) intdiv($cents, 100)
            : number_format($cents / 100, 2, '.', '');
    }

    /**
     * "2–3 nights", with the design's en dash. A single night is singular.
     *
     * @param  array{int, int}  $nights
     */
    private static function nights(array $nights): string
    {
        [$min, $max] = $nights;

        $span = $min === $max ? (string) $min : $min.'–'.$max;

        return $span.' '.($max === 1 ? 'night' : 'nights');
    }

    /**
     * "Fridays" for one day — the design's chip — and "Fri & Sat" for more,
     * because "Fridays & Saturdays" does not fit a 352 px screen.
     *
     * @param  list<int>  $dows  ISO weekday numbers
     */
    private static function days(array $dows): string
    {
        if (count($dows) === 1) {
            return self::dayName($dows[0]).'s';
        }

        $short = array_map(static fn (int $dow): string => mb_substr(self::dayName($dow), 0, 3), $dows);
        $last = array_pop($short);

        return implode(', ', $short).' & '.$last;
    }

    private static function dayName(int $dow): string
    {
        return match ($dow) {
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            default => 'Sunday',
        };
    }
}
