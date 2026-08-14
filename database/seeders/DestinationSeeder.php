<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Airport;
use App\Models\Destination;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The places Orbit knows about.
 *
 * Three origin airports and seventy-seven destinations, from
 * database/seeders/data/european_destinations.php — see that file for where
 * the vibes and the monthly warmth ratings come from and why they are a
 * checked-in list rather than an API call.
 *
 * IDEMPOTENT AND NON-DESTRUCTIVE. It runs on every deploy, so it updates the
 * facts (a corrected coordinate, a new vibe tag) and creates what is missing,
 * and it never deletes: an airport that leaves this list still has routes,
 * price history and possibly a watchlist row hanging off it.
 *
 * @phpstan-type AirportRow array{0: string, 1: string, 2: string, 3: string, 4: string, 5: float, 6: float}
 * @phpstan-type DestinationRow array{0: string, 1: string, 2: string, 3: string, 4: string, 5: float, 6: float, 7: string, 8: list<string>}
 */
final class DestinationSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        /** @var array{climates: array<string, list<int>>, origins: list<AirportRow>, destinations: list<DestinationRow>} $data */
        $data = require database_path('seeders/data/european_destinations.php');

        foreach ($data['origins'] as $row) {
            $this->airport($row, isOrigin: true);
        }

        foreach ($data['destinations'] as $row) {
            [, , , , , , , $climate, $vibes] = $row;

            $airport = $this->airport([$row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6]], isOrigin: false);

            if (! isset($data['climates'][$climate])) {
                throw new RuntimeException(sprintf('Destination %s names an unknown climate profile [%s].', $row[0], $climate));
            }

            Destination::query()->updateOrCreate(
                ['airport_id' => $airport->id],
                ['vibes' => $vibes, 'warmth' => self::warmth($data['climates'][$climate])],
            );
        }

        $this->command?->info(sprintf(
            '%d airports, %d of them destinations.',
            Airport::query()->count(),
            Destination::query()->count(),
        ));
    }

    /**
     * @param  AirportRow  $row
     */
    private function airport(array $row, bool $isOrigin): Airport
    {
        [$iata, $name, $city, $country, $countryCode, $lat, $lng] = $row;

        return Airport::query()->updateOrCreate(
            ['iata' => $iata],
            [
                'name' => $name,
                'city' => $city,
                'country' => $country,
                'country_code' => $countryCode,
                'lat' => $lat,
                'lng' => $lng,
                'is_origin' => $isOrigin,
            ],
        );
    }

    /**
     * Twelve ratings keyed by month number, 1-12.
     *
     * They are written as PHP integers and json_encode turns them into the
     * object keys `"1"`..`"12"`; json_decode turns those back into integers on
     * the way out. Both ends agree, which is why App\Models\Destination reads
     * them with an int — see the note there.
     *
     * @param  list<int>  $profile  January to December
     * @return array<int, int>
     */
    private static function warmth(array $profile): array
    {
        if (count($profile) !== 12) {
            throw new RuntimeException('A climate profile must have twelve monthly ratings.');
        }

        $warmth = [];

        foreach ($profile as $index => $rating) {
            $warmth[$index + 1] = $rating;
        }

        return $warmth;
    }
}
