<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $airport_id
 * @property list<string> $vibes
 * @property array<int, int> $warmth month number (1-12) => 1 (cold) to 5 (beach)
 * @property-read Airport $airport
 */
#[Fillable(['airport_id', 'vibes', 'warmth'])]
final class Destination extends Model
{
    /**
     * @return BelongsTo<Airport, $this>
     */
    public function airport(): BelongsTo
    {
        return $this->belongsTo(Airport::class);
    }

    /**
     * How warm this place is in a given month, 1-5. Zero for a month nobody
     * rated, which sorts below every real answer rather than above it.
     */
    public function warmthIn(int $month): int
    {
        /*
         * KEYED BY INT, not by the '1'..'12' strings the JSON column holds:
         * json_decode turns numeric object keys back into PHP integers, so the
         * array that comes out of the cast is not the shape that went in.
         */
        return $this->warmth[$month] ?? 0;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vibes' => 'array',
            'warmth' => 'array',
        ];
    }
}
