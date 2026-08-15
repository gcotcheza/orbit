<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\Routes\BookingLink;
use App\Models\Route;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsRouteData;
use Tests\TestCase;

/**
 * Where "book it" goes — both destinations, and the one digit that is easy to
 * get backwards.
 *
 * WHY THIS IS WORTH ITS OWN FILE. Every failure here is silent. A link with the
 * day and the month swapped opens perfectly, shows real flights, and searches
 * the wrong date; a lower-cased IATA pair searches a different place entirely
 * (Travelpayouts' own docs give the trap: `ROc1` is Romania in business class,
 * `ROC1` is Rochester airport in economy); a missing passenger digit produces a
 * page that simply does not search. None of those is a 500 and none of them
 * would ever appear in a log.
 *
 * A Laravel TestCase and not a plain one, because the shapes are half
 * config/orbit.php's — which is the point of them being there.
 */
final class BookingLinkTest extends TestCase
{
    use BuildsRouteData;
    use RefreshDatabase;

    private const MARKER = '123456';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('orbit.travelpayouts.marker', self::MARKER);
    }

    // ------------------------------------------------------ the primary hand-off

    /**
     * THE SHAPE, AND THE DIGITS ARE THE ASSERTION.
     *
     * `AMS1509OPO1` is Amsterdam to Porto on the FIFTEENTH OF SEPTEMBER: day
     * first, two digits each, upper case, one adult in economy (no class
     * letter). Every part of that is verified against Travelpayouts'
     * documentation and two live pages rather than remembered — see
     * App\Application\Routes\BookingLink.
     */
    #[Test]
    public function the_aviasales_link_is_origin_then_day_then_month_then_destination(): void
    {
        $url = BookingLink::aviasales($this->route(), new DateTimeImmutable('2026-09-15'));

        $this->assertSame(
            'https://www.aviasales.com/search/AMS1509OPO1?marker='.self::MARKER,
            $url,
        );
    }

    /**
     * THE DAY AND THE MONTH, TOLD APART.
     *
     * `0509` and `0905` are both plausible and only one is 5 September, so a
     * date whose two halves cannot be confused is the only assertion that
     * proves the order. The 15th cannot be a month.
     */
    #[Test]
    public function the_day_comes_before_the_month(): void
    {
        $url = BookingLink::aviasales($this->route(), new DateTimeImmutable('2026-09-15'));

        $this->assertStringContainsString('AMS1509OPO', $url);
        $this->assertStringNotContainsString('AMS0915OPO', $url);
    }

    /**
     * A single-digit day and month are PADDED. `2026-01-05` is `0501` and not
     * `51`, which would run into the destination code and search nowhere.
     */
    #[Test]
    public function a_single_digit_day_and_month_are_padded_to_two_each(): void
    {
        $url = BookingLink::aviasales($this->route(), new DateTimeImmutable('2026-01-05'));

        $this->assertStringContainsString('/search/AMS0501OPO1?', $url);
    }

    /**
     * NO DATE MEANS THE PRE-FILLED SEARCH FORM, not a results page for no day.
     * It is the documented shape for dateless params and the honest destination
     * for a route Orbit has no fares for yet: there is nothing to show results
     * for, so the reader gets the box with the route already in it.
     */
    #[Test]
    public function a_route_with_no_fares_yet_lands_on_the_pre_filled_search_form(): void
    {
        $this->assertSame(
            'https://www.aviasales.com/?params=AMSOPO1&marker='.self::MARKER,
            BookingLink::aviasales($this->route()),
        );
    }

    /** The passenger count is mandatory — the link does not work without it. */
    #[Test]
    public function every_aviasales_link_ends_its_params_with_a_passenger_count(): void
    {
        $dated = BookingLink::aviasales($this->route(), new DateTimeImmutable('2026-09-15'));

        $this->assertStringContainsString('OPO1?', $dated);
        $this->assertStringContainsString('OPO1&', BookingLink::aviasales($this->route()));
    }

    // ------------------------------------------------------------- the marker

    /**
     * THE ATTRIBUTION, WHICH FINALLY GOES SOMEWHERE. `travelpayouts.marker` sat
     * unused since the day it was added; it rides on the Aviasales hand-off and
     * on nothing else.
     */
    #[Test]
    public function the_marker_is_on_the_aviasales_link_and_on_no_other(): void
    {
        $route = $this->route();
        $date = new DateTimeImmutable('2026-09-15');

        $this->assertStringContainsString('marker='.self::MARKER, BookingLink::aviasales($route, $date));
        $this->assertStringContainsString('marker='.self::MARKER, BookingLink::aviasalesTemplate($route));

        /* Skyscanner has never been monetised and still is not. */
        $this->assertStringNotContainsString('marker', BookingLink::skyscanner($route, $date));
        $this->assertStringNotContainsString('marker', BookingLink::skyscannerTemplate($route));
    }

    /**
     * ABSENT RATHER THAN EMPTY on a box with no marker. `?marker=` with nothing
     * after it is a parameter the other end has to interpret, and the answer to
     * "whose link is this" is better left unsaid than said blank.
     */
    #[Test]
    #[DataProvider('missingMarkers')]
    public function an_unset_marker_leaves_the_parameter_off_entirely(?string $marker): void
    {
        config()->set('orbit.travelpayouts.marker', $marker);

        $route = $this->route();

        $this->assertSame(
            'https://www.aviasales.com/search/AMS1509OPO1',
            BookingLink::aviasales($route, new DateTimeImmutable('2026-09-15')),
        );

        /* And the form URL keeps its own `?params=` rather than gaining a stray `&`. */
        $this->assertSame('https://www.aviasales.com/?params=AMSOPO1', BookingLink::aviasales($route));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function missingMarkers(): array
    {
        return [
            'never set' => [null],
            'set to nothing' => [''],
            'set to whitespace' => ['   '],
        ];
    }

    // ---------------------------------------------------- the second opinion

    #[Test]
    public function the_skyscanner_link_keeps_the_shape_it_always_had(): void
    {
        $this->assertSame(
            'https://www.skyscanner.nl/transport/flights/ams/opo/260915/',
            BookingLink::skyscanner($this->route(), new DateTimeImmutable('2026-09-15')),
        );

        $this->assertSame(
            'https://www.skyscanner.nl/transport/flights/ams/opo/',
            BookingLink::skyscanner($this->route()),
        );
    }

    /**
     * THE TWO CASINGS ARE OPPOSITE AND BOTH MATTER. Skyscanner's path is
     * lower-case; Aviasales' params are upper-case and case-SENSITIVE in a way
     * that silently changes which place is searched.
     */
    #[Test]
    public function the_two_links_case_their_codes_in_opposite_directions(): void
    {
        $route = $this->route();
        $date = new DateTimeImmutable('2026-09-15');

        $this->assertStringContainsString('/ams/opo/', BookingLink::skyscanner($route, $date));
        $this->assertStringContainsString('AMS1509OPO', BookingLink::aviasales($route, $date));
    }

    // -------------------------------------------------------- the templates

    /**
     * THE HOLES ARE NAMED AFTER THEIR DATE FORMAT, so the client fills them by
     * name and never has to know which URL belongs to which site (docs/API.md).
     * Each template is its own dated link with the date lifted out.
     */
    #[Test]
    public function each_template_is_its_own_link_with_a_named_hole_where_the_date_goes(): void
    {
        $route = $this->route();

        $this->assertSame(
            'https://www.aviasales.com/search/AMS{ddmm}OPO1?marker='.self::MARKER,
            BookingLink::aviasalesTemplate($route),
        );

        $this->assertSame(
            'https://www.skyscanner.nl/transport/flights/ams/opo/{yymmdd}/',
            BookingLink::skyscannerTemplate($route),
        );
    }

    /**
     * AND SUBSTITUTING THE HOLE REPRODUCES THE DATED LINK EXACTLY — which is the
     * property the day sheet depends on and the one that would break silently
     * if either form were edited without the other. Written as a substitution
     * rather than as two literals so it cannot be satisfied by a copy-paste.
     */
    #[Test]
    public function filling_a_template_gives_the_same_url_as_asking_for_that_date(): void
    {
        $route = $this->route();
        $date = new DateTimeImmutable('2026-09-15');

        $this->assertSame(
            BookingLink::aviasales($route, $date),
            str_replace('{ddmm}', '1509', BookingLink::aviasalesTemplate($route)),
        );

        $this->assertSame(
            BookingLink::skyscanner($route, $date),
            str_replace('{yymmdd}', '260915', BookingLink::skyscannerTemplate($route)),
        );
    }

    /**
     * MEMOISED, because `routes.code` is unique and several of these tests ask
     * for the route more than once. One route per test, made on first use.
     */
    private ?Route $route = null;

    private function route(): Route
    {
        return $this->route ??= $this->makeRoute('AMS', 'OPO')->load(['origin', 'destination']);
    }
}
