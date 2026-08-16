<?php

declare(strict_types=1);

namespace App\Infrastructure\Discovery;

use App\Application\Ports\OriginSweepProvider;
use App\Domain\Discovery\SweptFare;
use App\Domain\Geo\Haversine;
use App\Infrastructure\Pricing\FakeFareModel;
use App\Models\Airport;
use Illuminate\Support\Facades\Date;

/**
 * An origin sweep, until there is a token to make a real one.
 *
 * NOT A TEST DOUBLE — the standing every fake in this app has.
 * `orbit.providers.sweep` defaults to `fake`, so this is what a box runs until
 * somebody sets `ORBIT_SWEEP_PROVIDER=travelpayouts`, and it is what fills the
 * discovery section in the browser sandbox (`scripts/e2e.sh`) and in every
 * screenshot taken of it. It therefore has to produce a list that looks like a
 * sweep, not `return []`.
 *
 * =============================================================================
 * IT SWEEPS THE AIRPORTS TABLE, WHICH IS THE ONE THING IT DOES DIFFERENTLY
 * =============================================================================
 * The other three fakes are pure functions of a route code and a date: hand
 * them any pair and they price it. A sweep cannot be, because the QUESTION it
 * answers is "which destinations exist" — and inventing three-letter codes
 * would produce discoveries that link to `/route/AMS-QZX`, a screen that can
 * only apologise. So this one reads `airports`, which is the same 3,270-row
 * table the real provider's answers are matched against, and prices a
 * deterministic subset of it through the shared FakeFareModel.
 *
 * READING THE DATABASE MAKES IT THE ONLY FAKE THAT CAN RETURN NOTHING, and that
 * is correct rather than a wart: an unseeded box has no airports, so it has no
 * sweep, and the discovery screen honestly says there is nothing today.
 *
 * =============================================================================
 * DELIBERATELY SPARSE, AND DELIBERATELY LUMPY
 * =============================================================================
 * The real sweep returned 562 destinations from AMS, 419 from DUS and 196 from
 * EIN — a third of the table at best, and a different third per origin. A fake
 * that answered for every airport would build the whole funnel on a density
 * production never has, and would hide the thing the funnel is actually for:
 * most of what comes back is ordinary.
 *
 * `COVERAGE_IN_HUNDREDTHS` is set from those measurements against the 3,270-row
 * table. The hash is over the ORIGIN and the destination together, so the three
 * home airports see overlapping but different worlds, exactly as they do live.
 *
 * THE PRICES COME FROM FakeFareModel, WHICH IS THE WHOLE POINT OF SHARING IT.
 * A discovery the sandbox surfaces for AMS-AGP is priced by the same generator
 * that will price AMS-AGP's calendar when the reader taps through to it — so the
 * verification stage finds the candidate sitting in its own window where it
 * ought to be, and the two screens tell one story. A separate generator here
 * would make every fake discovery fail its own percentile check, and the bug
 * would look like a bug in the scorer.
 *
 * THE DISCOUNT IS WHAT MAKES A DISCOVERY A DISCOVERY. FakeFareModel's floor is
 * €29 and its ceiling €180, which is a believable spread of ORDINARY fares and
 * contains nothing a person would call a surprise. A deterministic slice of the
 * swept destinations is therefore marked down — see `SALE_IN_HUNDREDTHS` — so
 * that the sandbox has something for the funnel to actually find. Without it
 * every screenshot of this feature would be an empty state.
 *
 * =============================================================================
 * ⚠ IT SWEEPS SHORT AND MEDIUM HAUL ONLY, AND THAT IS A LIMITATION OF THE
 *   SHARED MODEL RATHER THAN A CHOICE ABOUT WHAT IS INTERESTING
 * =============================================================================
 * FakeFareModel IS DISTANCE-BLIND. It prices every route into the same
 * €29–€180 band, because it was written for "EU short-haul from the
 * Netherlands" and every screen before this one asked it about ONE route at a
 * time, where the band is entirely believable.
 *
 * Discovery is the first feature that ranks routes AGAINST EACH OTHER by what a
 * kilometre costs, and a flat price band under that ranking means the answer is
 * simply "whichever destination is furthest away". Swept over the whole 3,270-
 * row table the fake produced, verbatim: €12 to Hokitika (New Zealand), €17 to
 * Île des Pins, €13 to Porto Velho. Every one of those passed the real funnel
 * honestly — they are absurd because the PRICES are absurd, not because the
 * scoring is.
 *
 * The real sweeps do not have this problem: an actual €287 Singapore scores
 * 27.3 m€/km against Málaga's 19.1, so genuine long-haul loses to genuine
 * short-haul on the ratio and the €120 ceiling removes what is left.
 *
 * Rather than teach FakeFareModel about distance — which would move every fare
 * on every other screen, and re-baseline sixty days of seeded history — the
 * fake sweeps only as far as its own price band is credible.
 * `MAX_SWEEP_KM` is 4,000: Europe, the Canaries, North Africa, Turkey and the
 * Levant, which is also exactly what a low-cost cache out of EIN and DUS looks
 * like in reality. Within that range the ranking lands where the real data
 * landed — the far edge of the budget network, which is to say Marrakesh,
 * Tangier, Antalya, Sharm el-Sheikh and the Canaries.
 *
 * WHAT IT COSTS, STATED: no fake discovery is ever long-haul, so the €120
 * ceiling is never the rule that bites in the sandbox. That rule is covered by
 * tests/Unit/Domain/Discovery/CandidateScorerTest, on the real Singapore fare.
 */
final readonly class FakeSweepProvider implements OriginSweepProvider
{
    /**
     * How far the fake will sweep, in kilometres — see the docblock. It is a
     * bound on the SIMULATION's credibility and has nothing to do with
     * `orbit.discovery.min_kilometres`, which is a product rule at the other
     * end of the same axis.
     */
    private const MAX_SWEEP_KM = 4000.0;

    /**
     * How many of a hundred airports a given origin has a cached fare to.
     *
     * TWELVE, from the live measurements: 562, 419 and 196 destinations against
     * a 3,270-row table is 17%, 13% and 6%. Twelve sits in the middle of that
     * and yields ~390 candidates per origin, which is the same order as the
     * real answer — enough for the ranking to have something to rank.
     */
    private const COVERAGE_IN_HUNDREDTHS = 12;

    /**
     * How many of a hundred swept fares are marked down at all.
     *
     * IT IS NOT THE PASS RATE AND MUST NOT BE TUNED AS IF IT WERE. On the real
     * 2026-08-16 sweep, 53 of 1,086 candidates (4.9%) cleared all four of
     * App\Domain\Discovery\DiscoveryPolicy's cheap rules — but that figure is
     * what came OUT of the funnel, and this number goes IN. A marked-down fare
     * still has to beat 30 m€/km, which most short hops never do however cheap
     * they are: at 4% the whole sandbox produced a single discovery.
     *
     * TWENTY-TWO, chosen by running the real funnel against the seeded table until
     * the shortlist filled. It lands ~5 discoveries a run, which is
     * `orbit.discovery.shortlist` and therefore what a good day looks like.
     */
    private const SALE_IN_HUNDREDTHS = 22;

    /**
     * What a marked-down fare is multiplied by.
     *
     * 0.45 RATHER THAN SOMETHING SMALLER, and the constraint is believability
     * rather than the thresholds. Anything under about 0.3 puts €8 fares on the
     * screen, which no airline has ever charged and which makes every
     * screenshot of this feature look like a bug. At 0.45 a €29–180 base
     * becomes €13–81 — the range the real sweep's discoveries actually sat in
     * (€16 Pescara to €69 Sharm el-Sheikh on 2026-08-16) — and it is still
     * comfortably under both the 30 m€/km floor and the median of the route's
     * own window, which is what the funnel checks.
     */
    private const SALE_MULTIPLIER = 0.45;

    /**
     * How far ahead a swept departure date can fall, in days.
     *
     * A YEAR, because that is what `period_type=year` answers with — the
     * recorded AMS sweep ran to 2027-07-27, and a discovery in next March is
     * exactly the kind nobody would have paged a calendar to find.
     */
    private const HORIZON_DAYS = 350;

    public function __construct(private FakeFareModel $model = new FakeFareModel) {}

    /**
     * @return list<SweptFare>
     */
    public function cheapestFromOrigin(string $originIata): array
    {
        /*
         * "As of now" through the Date facade rather than `new
         * DateTimeImmutable`, because that is the clock `Date::setTestNow()`
         * moves. A real adapter reads a wall clock it cannot move.
         */
        $now = Date::now();
        $observedAt = $now->toDateTimeImmutable();

        /*
         * ONLY THE COLUMNS THIS NEEDS. The table is 3,270 rows and this runs
         * once per origin inside a queued job; hydrating full models for a
         * string, two floats and a discard would be three thousand objects to
         * build and throw away, three times a night.
         */
        $origin = Airport::query()->where('iata', $originIata)->first(['iata', 'lat', 'lng']);

        if ($origin === null) {
            /* An unseeded box has no airports and therefore honestly no sweep. */
            return [];
        }

        $airports = Airport::query()
            ->where('iata', '!=', $originIata)
            ->orderBy('iata')
            ->get(['iata', 'lat', 'lng']);

        $fares = [];

        foreach ($airports as $airport) {
            $destination = $airport->iata;

            /*
             * THE RANGE LIMIT, which is about FakeFareModel rather than about
             * discovery — see the class docblock. Sweeping the whole table with
             * a distance-blind price band ranks by distance alone and puts €12
             * fares to New Zealand at the top of the strip.
             */
            if (Haversine::kilometres($origin->lat, $origin->lng, $airport->lat, $airport->lng) > self::MAX_SWEEP_KM) {
                continue;
            }

            /*
             * THE HOLE, AND IT IS STABLE. crc32 of the origin and destination
             * together — so the same destination is missing from the same
             * origin's sweep on this box, in CI and after
             * `docker compose down -v`, which is what lets a feature test
             * assert that a particular discovery is or is not found.
             */
            if (crc32($originIata.':sweep:'.$destination) % 100 >= self::COVERAGE_IN_HUNDREDTHS) {
                continue;
            }

            $routeCode = $originIata.'-'.$destination;

            /*
             * A DEPARTURE DATE SPREAD OVER THE YEAR, from the same hash family.
             * The real sweep's dates are wherever somebody else happened to
             * search, which is to say arbitrary — so an arbitrary but stable
             * date is the honest simulation. `+1` keeps it off today, which is
             * a departure nobody can act on by the time a nightly job has run.
             */
            $offset = (int) (crc32($routeCode.':sweep-date') % self::HORIZON_DAYS) + 1;
            $departure = $now->copy()->startOfDay()->addDays($offset)->toDateTimeImmutable();

            $cents = $this->model->priceCents($routeCode, $departure, $observedAt);

            /*
             * THE SALE. FakeFareModel's floor is €29 and its ceiling €180 — a
             * believable spread of ORDINARY fares, and nothing in it would ever
             * clear 30 m€/km on a long enough hop. Without this the sandbox
             * would exercise the funnel's every rejection path and none of its
             * acceptance ones, and the discovery section would be permanently
             * empty in the one place anybody looks at it.
             */
            if (crc32($routeCode.':sweep-sale') % 100 < self::SALE_IN_HUNDREDTHS) {
                $cents = (int) round($cents * self::SALE_MULTIPLIER);
            }

            /*
             * FOUND RECENTLY, BUT NOT ALL AT THE SAME MOMENT — which is the one
             * place this fake is careful where its siblings are not.
             * FakePriceProvider stamps `now` on everything, because it really
             * did just invent them. A SWEEP is a seven-day-deep cache of other
             * people's searches (the recorded spread was 116 rows found that
             * day, 108 the day before, 3 a week old), and
             * App\Domain\Discovery\DiscoveryPolicy's whole freshness rule is
             * aimed at that spread. A fake with no spread would leave the rule
             * untested everywhere it is actually looked at, and would make the
             * "seen 2 days ago" line on the card impossible to screenshot.
             *
             * Hours rather than days, so the ages land on both sides of the
             * three-day threshold and read naturally on the card.
             */
            $ageHours = (int) (crc32($routeCode.':sweep-age') % 168);

            $fares[] = new SweptFare(
                $destination,
                $departure,
                $cents,
                $now->copy()->subHours($ageHours)->toDateTimeImmutable(),
            );
        }

        return $fares;
    }
}
