<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AirportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $iata
 * @property string $name
 * @property string $city
 * @property string $country
 * @property string $country_code
 * @property float $lat
 * @property float $lng
 * @property bool $is_origin
 * @property-read Destination|null $destination
 */
#[Fillable(['iata', 'name', 'city', 'country', 'country_code', 'lat', 'lng', 'is_origin'])]
final class Airport extends Model
{
    /** @use HasFactory<AirportFactory> */
    use HasFactory;

    /**
     * @return HasOne<Destination, $this>
     */
    public function destination(): HasOne
    {
        return $this->hasOne(Destination::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'is_origin' => 'boolean',
        ];
    }
}
