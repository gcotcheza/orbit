<?php

declare(strict_types=1);

namespace App\Domain\Rules;

/**
 * The six things design/README.md §4 can put on a chip. The case NAMES the criteria field
 * it folds back into, which is what makes removing a chip exact (docs/BUSINESS-LOGIC.md §11).
 */
enum ChipKind: string
{
    case Origin = 'origin';
    case MaxPrice = 'max_price';
    case TripLength = 'trip_length';
    case Depart = 'depart';
    case DateWindow = 'date_window';
    case Vibe = 'vibe';

    /**
     * The eyebrow above the value, word for word from the design. Sentence case here and
     * upper-cased by the stylesheet: "MAX PRICE" in an API response is a shout.
     */
    public function category(): string
    {
        return match ($this) {
            self::Origin     => 'From',
            self::MaxPrice   => 'Max price',
            self::TripLength => 'Trip length',
            self::Depart     => 'Depart',
            self::DateWindow => 'Date window',
            self::Vibe       => 'Vibe',
        };
    }
}
