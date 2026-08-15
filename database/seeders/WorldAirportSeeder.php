<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Airport;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;
use SplFileObject;

/**
 * Every airport on Earth that sells a scheduled seat — the other 3,083 rows.
 *
 * WHY IT EXISTS. Orbit knew eighty airports, all of them European, and the
 * three-letter box in the add-route form was therefore a box that could refuse
 * "JFK". Looking up a route is not a commitment (routes/web.php,
 * `POST /api/routes/lookup`) and watching one is the owner's decision rather
 * than this list's, so the validation rule behind both — `exists:airports,iata`
 * in App\Http\Requests\RoutePairRequest — should be able to say yes to
 * anywhere. It is the same rule it always was; what changed is the table under
 * it.
 *
 * THE SOURCE IS A COMMITTED SNAPSHOT, NOT A DOWNLOAD. See
 * database/seeders/data/world_airports.README.md for the licence (public
 * domain), the filter that took 85,901 rows down to 3,270, and why a seeder
 * that runs on every deploy must not depend on somebody else's CDN.
 *
 * THE CURATED ROWS WIN, ALWAYS, and that is the one rule this seeder has. The
 * hundred and eighty-four airports in DestinationSeeder's data files have
 * names a person chose ("John F. Kennedy", not "John F. Kennedy International
 * Airport"), cities a person would say ("Sydney", not "Sydney (Mascot)") and,
 * in one case, a correction the snapshot has not made — Dakar's DKR closed in
 * 2017 and its flights leave from DSS. Those rows are skipped outright rather
 * than upserted with the snapshot's values, so re-running this seeder on every
 * deploy cannot walk any of it back. tests/Feature/SeedersTest asserts it.
 *
 * IT IS ALSO WHY THIS RUNS AFTER DestinationSeeder in DatabaseSeeder and not
 * before: skipping is cheap, but a fresh box that imported the world first
 * would still have to be corrected afterwards, and the order that needs no
 * correction is the one that cannot drift.
 *
 * WHAT IT NEVER TOUCHES is `is_origin`. An origin is one of the three airports
 * the owner can drive to (config('orbit.origins')), which is a fact about a
 * person and not about an airport; the column is left to its `false` default
 * on insert and left out of the update list entirely, so no snapshot refresh
 * can add a fourth origin or unset one of the three.
 */
final class WorldAirportSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Rows per INSERT.
     *
     * 250 × 9 bindings is 2,250, which is comfortably inside SQLite's
     * parameter ceiling (the suite runs on `:memory:`) and nowhere near
     * Postgres'. Thirteen statements for the whole file is not worth tuning
     * further — this is a deploy-time seeder, not a request.
     */
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
            /* The header, and the empty final line SplFileObject hands back. */
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
        /*
         * `is_origin` is in NEITHER list: not in the values, so a new row takes
         * the column's `false` default, and not in the update columns, so an
         * existing row keeps whatever it has. See the class note.
         */
        Airport::query()->upsert($rows, ['iata'], ['name', 'city', 'country', 'country_code', 'lat', 'lng']);
    }

    /**
     * One CSV line, checked rather than trusted.
     *
     * THE CHECKS ARE FOR THE NEXT SNAPSHOT, not for this one — today's file is
     * 3,270 rows of exactly this shape. A refreshed OurAirports export that
     * grew a column, lost a country name or carried a four-letter code would
     * otherwise reach Postgres as a `value too long for type character(3)`
     * halfway through a deploy's seed, which is a message that says nothing
     * about which file or which line.
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
            'iata' => mb_strtoupper($iata),
            'name' => $name,
            'city' => $city,
            'country' => $country,
            'country_code' => mb_strtoupper($countryCode),
            'lat' => (float) $lat,
            'lng' => (float) $lng,
        ];
    }
}
