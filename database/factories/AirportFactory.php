<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Airport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Airport>
 */
final class AirportFactory extends Factory
{
    protected $model = Airport::class;

    /**
     * @return array<model-property<Airport>, mixed>
     */
    public function definition(): array
    {
        // Generated, not a word list — `iata` is unique in the schema.
        return [
            'iata'         => mb_strtoupper($this->faker->unique()->lexify('???')),
            'name'         => $this->faker->city().' Airport',
            'city'         => $this->faker->city(),
            'country'      => $this->faker->country(),
            'country_code' => mb_strtoupper($this->faker->lexify('??')),
            'lat'          => $this->faker->latitude(35, 65),
            'lng'          => $this->faker->longitude(-10, 30),
            'is_origin'    => false,
        ];
    }

    public function origin(): self
    {
        return $this->state(['is_origin' => true]);
    }
}
