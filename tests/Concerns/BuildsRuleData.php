<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\User;
use App\Models\Route;
use App\Models\Airport;
use App\Models\DealRule;
use App\Models\Destination;

/**
 * Fixtures for the rules engine's tests: a handful of destinations, not the
 * seeder's 77 (docs/BUSINESS-LOGIC.md §36).
 */
trait BuildsRuleData
{
    /**
     * The three airports the owner leaves from, matching config('orbit.origins').
     */
    protected function makeOrigins(): void
    {
        foreach (['AMS' => 'Amsterdam', 'EIN' => 'Eindhoven', 'DUS' => 'Düsseldorf'] as $iata => $city) {
            Airport::factory()->origin()->create(['iata' => $iata, 'city' => $city]);
        }
    }

    /**
     * A place, with what it is for and how warm it is all year.
     *
     * @param  list<string>  $vibes
     */
    protected function makeDestination(string $iata, array $vibes, int $warmth = 5): Airport
    {
        $airport = Airport::factory()->create(['iata' => $iata]);

        Destination::query()->create([
            'airport_id' => $airport->id,
            'vibes'      => $vibes,
            /* Same rating all year: keeps the climate gate out of the way. */
            'warmth' => array_fill(1, 12, $warmth),
        ]);

        return $airport;
    }

    /**
     * A route with fares already on it — a pair Orbit has been round once.
     *
     * @param  array<string, int>  $pricesByDate  'Y-m-d' => cents
     */
    protected function makeRouteWithFares(string $origin, string $destination, array $pricesByDate): Route
    {
        $route = Route::factory()->between($origin, $destination)->create();

        $this->offer($route, $pricesByDate);

        return $route;
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    protected function makeRule(User $user, string $text, array $criteria, bool $active = true): DealRule
    {
        return DealRule::query()->create([
            'user_id'  => $user->id,
            'raw_text' => $text,
            'criteria' => $criteria,
            'active'   => $active,
        ]);
    }
}
