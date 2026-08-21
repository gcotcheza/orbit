<?php

declare(strict_types=1);

namespace Database\Seeders;

use RuntimeException;
use App\Models\Airport;
use App\Models\Destination;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

/**
 * The places Orbit has an OPINION about — three origins and 184 destinations,
 * the only rows the rule engine may match (docs/BUSINESS-LOGIC.md §36).
 *
 * @phpstan-type AirportRow array{0: string, 1: string, 2: string, 3: string, 4: string, 5: float, 6: float}
 * @phpstan-type DestinationRow array{0: string, 1: string, 2: string, 3: string, 4: string, 5: float, 6: float, 7: string, 8: list<string>}
 * @phpstan-type CuratedFile array{climates: array<string, list<int>>, origins: list<AirportRow>, destinations: list<DestinationRow>}
 */
final class DestinationSeeder extends Seeder
{
    use WithoutModelEvents;

    /** @var list<string> */
    private const FILES = [
        'european_destinations.php',
        'world_destinations.php',
    ];

    public function run(): void
    {
        $climates = self::climates();

        foreach (self::FILES as $file) {
            $data = self::read($file);

            foreach ($data['origins'] as $row) {
                $this->airport($row, isOrigin: true);
            }

            foreach ($data['destinations'] as $row) {
                [, , , , , , , $climate, $vibes] = $row;

                $airport = $this->airport([$row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6]], isOrigin: false);

                if (! isset($climates[$climate])) {
                    throw new RuntimeException(sprintf('Destination %s names an unknown climate profile [%s].', $row[0], $climate));
                }

                Destination::query()->updateOrCreate(
                    ['airport_id' => $airport->id],
                    ['vibes' => $vibes, 'warmth' => self::warmth($climates[$climate])],
                );
            }
        }

        $this->command?->info(sprintf(
            '%d curated airports, %d of them destinations.',
            Airport::query()->count(),
            Destination::query()->count(),
        ));
    }

    /**
     * Read from the data files, not the database, so this is true on a FRESH
     * box too (docs/BUSINESS-LOGIC.md §36).
     *
     * @return list<string>
     */
    public static function curatedCodes(): array
    {
        $codes = [];

        foreach (self::FILES as $file) {
            $data = self::read($file);

            foreach ($data['origins'] as $row) {
                $codes[] = $row[0];
            }

            foreach ($data['destinations'] as $row) {
                $codes[] = $row[0];
            }
        }

        return $codes;
    }

    /**
     * A name may be reused across files, never redefined (docs/BUSINESS-LOGIC.md §36).
     *
     * @return array<string, list<int>>
     */
    private static function climates(): array
    {
        $climates = [];

        foreach (self::FILES as $file) {
            foreach (self::read($file)['climates'] as $name => $profile) {
                if (isset($climates[$name]) && $climates[$name] !== $profile) {
                    throw new RuntimeException(sprintf(
                        'The climate profile [%s] is defined twice with different ratings; %s disagrees with a file read before it.',
                        $name,
                        $file,
                    ));
                }

                $climates[$name] = $profile;
            }
        }

        return $climates;
    }

    /**
     * @return CuratedFile
     */
    private static function read(string $file): array
    {
        /** @var CuratedFile $data */
        $data = require database_path('seeders/data/'.$file);

        return $data;
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
                'name'         => $name,
                'city'         => $city,
                'country'      => $country,
                'country_code' => $countryCode,
                'lat'          => $lat,
                'lng'          => $lng,
                'is_origin'    => $isOrigin,
            ],
        );
    }

    /**
     * Keyed by month number, 1-12 — see App\Models\Destination::warmthIn().
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
