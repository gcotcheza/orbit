<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Airport;
use App\Models\DealRule;
use App\Models\Destination;
use App\Models\Route;
use App\Models\User;

/**
 * Fixtures for the rules engine's tests.
 *
 * A HANDFUL OF PLACES RATHER THAN THE SEEDER'S SEVENTY-SEVEN. The seeder has
 * its own tests; these are about whether a rule finds the right routes, and
 * that is a question best asked of four destinations whose vibes and climates
 * a reader can hold in their head — "FAO is sunny and warm, OSL is a cold
 * city" is an expectation somebody can check, and "the eleventh of the med-
 * south group" is not.
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
            'vibes' => $vibes,
            /* One rating for the whole year keeps the climate gate out of the way unless a test is about it. */
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
            'user_id' => $user->id,
            'raw_text' => $text,
            'criteria' => $criteria,
            'active' => $active,
        ]);
    }
}
