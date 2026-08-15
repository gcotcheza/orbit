<?php

declare(strict_types=1);

namespace App\Domain\Rules;

/**
 * One removable piece of a rule (design/README.md §4).
 *
 * IT CARRIES ITS OWN CRITERIA FRAGMENT in `$value`, and that is the point.
 * Removing a chip on the create screen must not mean re-parsing edited text —
 * the sentence has not changed, and a parser asked the same question twice
 * would give the same answer back. Instead the chips ARE the parse, the client
 * sends the ids it does not want, and App\Domain\Rules\ParsedRule folds what
 * is left. "From EIN" comes off and nothing else moves.
 *
 * `$id` IS STABLE ACROSS PARSES OF THE SAME SENTENCE — it is the kind plus the
 * value, never a position — because the client holds a list of removed ids
 * across a re-parse it did not ask for (every keystroke re-parses, 500 ms
 * behind). An index-based id would silently start removing a different chip
 * the moment somebody edited a word earlier in the sentence.
 */
final readonly class RuleChip
{
    /**
     * @param  mixed  $value  the fragment this chip contributes: a string for
     *                        Origin and Vibe, cents for MaxPrice, [min, max]
     *                        for TripLength, an ISO weekday for Depart, a
     *                        MonthWindow for DateWindow
     */
    public function __construct(
        public ChipKind $kind,
        public string $id,
        public string $label,
        public mixed $value,
    ) {}

    /**
     * A chip whose id is just its kind — the one-of-its-kind chips (a price, a
     * window, a trip length).
     */
    public static function only(ChipKind $kind, string $label, mixed $value): self
    {
        return new self($kind, $kind->value, $label, $value);
    }

    /**
     * A chip there can be several of — an origin, a vibe, a departure day —
     * keyed by the value so two of them never collide.
     */
    public static function each(ChipKind $kind, string $key, string $label, mixed $value): self
    {
        return new self($kind, $kind->value.':'.$key, $label, $value);
    }

    public function category(): string
    {
        return $this->kind->category();
    }
}
