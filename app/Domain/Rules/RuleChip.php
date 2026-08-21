<?php

declare(strict_types=1);

namespace App\Domain\Rules;

/**
 * One removable piece of a rule (design/README.md §4). It carries its own criteria fragment,
 * and `$id` is stable across parses — never a position (docs/BUSINESS-LOGIC.md §11).
 */
final readonly class RuleChip
{
    /**
     * @param  mixed  $value  a string for Origin and Vibe, cents for MaxPrice, [min, max] for
     *                        TripLength, an ISO weekday for Depart, a MonthWindow for DateWindow
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
