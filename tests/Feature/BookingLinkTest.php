<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Route;
use DateTimeImmutable;
use Tests\Concerns\BuildsRouteData;
use PHPUnit\Framework\Attributes\Test;
use App\Application\Routes\BookingLink;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Where "book it" goes — both destinations, and the one digit that is easy to
 * get backwards (docs/BUSINESS-LOGIC.md §12, docs/BUSINESS-LOGIC.md §36).
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

    /**
     * `AMS1509OPO1`: day before month, upper-case, one adult economy
     * (docs/BUSINESS-LOGIC.md §12).
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
     * `0509`/`0905` are both plausible — only 15-can't-be-a-month actually
     * proves the order (docs/BUSINESS-LOGIC.md §12).
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
     * No date means the pre-filled search form, not empty results
     * (docs/BUSINESS-LOGIC.md §12).
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

    /**
     * `travelpayouts.marker` rides on the Aviasales hand-off only, never
     * Skyscanner (docs/BUSINESS-LOGIC.md §12).
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
     * Absent rather than empty — `?marker=` blank is a param the other end
     * must interpret (docs/BUSINESS-LOGIC.md §12).
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
            'never set'         => [null],
            'set to nothing'    => [''],
            'set to whitespace' => ['   '],
        ];
    }

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
     * Both casings matter: Skyscanner lower-case, Aviasales upper-case and
     * case-sensitive (docs/BUSINESS-LOGIC.md §12).
     */
    #[Test]
    public function the_two_links_case_their_codes_in_opposite_directions(): void
    {
        $route = $this->route();
        $date = new DateTimeImmutable('2026-09-15');

        $this->assertStringContainsString('/ams/opo/', BookingLink::skyscanner($route, $date));
        $this->assertStringContainsString('AMS1509OPO', BookingLink::aviasales($route, $date));
    }

    /**
     * Holes are named after their date format, so the client fills them
     * without knowing which URL belongs to which site (docs/BUSINESS-LOGIC.md §12).
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
     * Substituting the hole must reproduce the dated link exactly — what the
     * day sheet depends on (docs/BUSINESS-LOGIC.md §12).
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
