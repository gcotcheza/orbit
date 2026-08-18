<?php

declare(strict_types=1);

namespace Database\Seeders;

use RuntimeException;
use App\Models\Airport;
use App\Models\Destination;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

/**
 * The places Orbit has an OPINION about.
 *
 * Three origin airports and a hundred and eighty-four destinations, from the
 * two checked-in data files below — see either of them for where the vibes and
 * the monthly warmth ratings come from and why they are a list rather than an
 * API call.
 *
 * TWO FILES, ONE SEEDER, AND THE SPLIT IS EDITORIAL RATHER THAN TECHNICAL.
 * european_destinations.php is the short-haul list the app was built around;
 * world_destinations.php is the long-haul tranche added with world flights.
 * They have the same shape and are seeded identically; they are separate
 * because they are argued with separately, and because a single 300-row file
 * is one nobody reads before editing.
 *
 * WHAT THIS SEEDER IS NOT is the airports table. Since world flights, that
 * table also holds 3,270 rows from an OurAirports snapshot
 * (WorldAirportSeeder) so that any IATA code on Earth can be looked up and
 * watched. THESE rows are the ones the rule engine may match — they are the
 * only ones that carry a `destinations` row — and they must therefore win any
 * disagreement with the snapshot about a name or a city. They do: DatabaseSeeder
 * runs this first, and WorldAirportSeeder leaves everything named here alone.
 * That is what `curatedCodes()` is for.
 *
 * IDEMPOTENT AND NON-DESTRUCTIVE. It runs on every deploy, so it updates the
 * facts (a corrected coordinate, a new vibe tag) and creates what is missing,
 * and it never deletes: an airport that leaves this list still has routes,
 * price history and possibly a watchlist row hanging off it.
 *
 * @phpstan-type AirportRow array{0: string, 1: string, 2: string, 3: string, 4: string, 5: float, 6: float}
 * @phpstan-type DestinationRow array{0: string, 1: string, 2: string, 3: string, 4: string, 5: float, 6: float, 7: string, 8: list<string>}
 * @phpstan-type CuratedFile array{climates: array<string, list<int>>, origins: list<AirportRow>, destinations: list<DestinationRow>}
 */
final class DestinationSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * The curated data files, in the order they are seeded.
     *
     * ORDER IS COSMETIC HERE and load-bearing nowhere: the rows are upserted by
     * IATA and the two files share no codes (SeedersTest asserts it). Europe is
     * first because it is the list this app was drawn for.
     *
     * @var list<string>
     */
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
     * Every IATA code a human being has written down, origins included.
     *
     * WHAT IT IS FOR: WorldAirportSeeder imports 3,270 airports from a
     * third-party snapshot and must not overwrite any of these — the snapshot
     * calls JFK "John F. Kennedy International Airport" and Sydney's city
     * "Sydney (Mascot)", and it still believes Dakar flies from DKR. Reading
     * the answer out of the data files rather than out of the database is what
     * makes that rule true on a FRESH box as well as on a re-seed, where "the
     * rows that were already there" would be nothing at all.
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
     * Both files' climate profiles in one map.
     *
     * A NAME MAY BE REUSED ACROSS FILES AND MAY NOT BE REDEFINED. New York's
     * winter really is Prague's, so world_destinations.php names `continental`
     * rather than writing the same twelve numbers again — which is the whole
     * argument for having profiles instead of per-row ratings, applied one
     * level up. What that buys has to be paid for by refusing the other case:
     * two files that both define `continental`, differently, would give the
     * same word two meanings and the file that happened to be read second
     * would silently win.
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
