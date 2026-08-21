<?php

declare(strict_types=1);

namespace Database\Seeders;

use SplFileObject;
use RuntimeException;
use App\Models\Airport;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

/**
 * Every airport on Earth that sells a scheduled seat — the other 3,083 rows,
 * so a look-up can say yes to anywhere (docs/BUSINESS-LOGIC.md §36).
 */
final class WorldAirportSeeder extends Seeder
{
    use WithoutModelEvents;

    // 250 × 9 bindings stays inside SQLite's parameter ceiling (:memory: suite).
    private const CHUNK = 250;

    public function run(): void
    {
        $curated = array_flip(DestinationSeeder::curatedCodes());

        $file = new SplFileObject(database_path('seeders/data/world_airports.csv'));
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        $chunk = [];
        $imported = 0;
        $skipped = 0;

        foreach ($file as $line => $row) {
            // The header, and the empty final line SplFileObject hands back.
            if ($line === 0 || ! is_array($row) || $row === [null] || $row === []) {
                continue;
            }

            $airport = self::row($row, $line + 1);

            if (isset($curated[$airport['iata']])) {
                $skipped++;

                continue;
            }

            $chunk[] = $airport;
            $imported++;

            if (count($chunk) === self::CHUNK) {
                self::upsert($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            self::upsert($chunk);
        }

        $this->command?->info(sprintf(
            '%d world airports imported, %d curated rows left alone. %d airports in total.',
            $imported,
            $skipped,
            Airport::query()->count(),
        ));
    }

    /**
     * @param  list<array{iata: string, name: string, city: string, country: string, country_code: string, lat: float, lng: float}>  $rows
     */
    private static function upsert(array $rows): void
    {
        // `is_origin` is in NEITHER list (docs/BUSINESS-LOGIC.md §36).
        Airport::query()->upsert($rows, ['iata'], ['name', 'city', 'country', 'country_code', 'lat', 'lng']);
    }

    /**
     * One CSV line, checked rather than trusted — for the NEXT snapshot,
     * not this one (docs/BUSINESS-LOGIC.md §36).
     *
     * @param  array<int, string|null>  $row
     * @return array{iata: string, name: string, city: string, country: string, country_code: string, lat: float, lng: float}
     */
    private static function row(array $row, int $line): array
    {
        if (count($row) !== 7) {
            throw new RuntimeException(sprintf('world_airports.csv line %d has %d columns, not 7.', $line, count($row)));
        }

        [$iata, $name, $city, $country, $countryCode, $lat, $lng] = array_map(
            static fn (?string $value): string => trim((string) $value),
            $row,
        );

        if (mb_strlen($iata) !== 3 || mb_strlen($countryCode) !== 2) {
            throw new RuntimeException(sprintf('world_airports.csv line %d: [%s] is not an IATA code, or [%s] is not a country code.', $line, $iata, $countryCode));
        }

        if ($name === '' || $city === '' || $country === '' || ! is_numeric($lat) || ! is_numeric($lng)) {
            throw new RuntimeException(sprintf('world_airports.csv line %d (%s) is missing a name, a city, a country or a coordinate.', $line, $iata));
        }

        return [
            'iata'         => mb_strtoupper($iata),
            'name'         => $name,
            'city'         => $city,
            'country'      => $country,
            'country_code' => mb_strtoupper($countryCode),
            'lat'          => (float) $lat,
            'lng'          => (float) $lng,
        ];
    }
}
