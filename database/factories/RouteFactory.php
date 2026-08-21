<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Route;
use App\Models\Airport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Route>
 */
final class RouteFactory extends Factory
{
    protected $model = Route::class;

    /**
     * @return array<model-property<Route>, mixed>
     */
    public function definition(): array
    {
        return [
            'origin_airport_id'      => Airport::factory()->origin(),
            'destination_airport_id' => Airport::factory(),

            // A closure, so it runs after the two nested factories above
            // resolve to real ids; the model owns the code's derivation.
            /** @param  array<string, mixed>  $attributes */
            'code' => fn (array $attributes): string => Route::codeFor(
                Airport::query()->whereKey($attributes['origin_airport_id'])->firstOrFail()->iata,
                Airport::query()->whereKey($attributes['destination_airport_id'])->firstOrFail()->iata,
            ),
        ];
    }

    /**
     * A route between two named airports, REUSING either end if it already
     * exists — several routes share AMS, and `airports.iata` is unique.
     */
    public function between(string $originIata, string $destinationIata): self
    {
        return $this->state(fn (): array => [
            'origin_airport_id'      => self::airport($originIata, isOrigin: true)->id,
            'destination_airport_id' => self::airport($destinationIata, isOrigin: false)->id,
            'code'                   => Route::codeFor($originIata, $destinationIata),
        ]);
    }

    private static function airport(string $iata, bool $isOrigin): Airport
    {
        $existing = Airport::query()->where('iata', $iata)->first();

        if ($existing !== null) {
            return $existing;
        }

        $factory = $isOrigin ? Airport::factory()->origin() : Airport::factory();

        return $factory->create(['iata' => $iata]);
    }
}
