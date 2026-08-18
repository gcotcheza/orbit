<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Rules;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use App\Domain\Rules\MonthWindow;
use PHPUnit\Framework\Attributes\Test;

/**
 * "Mar – May", and what it means on a given morning.
 *
 * THE WRAPPING CASE IS WHY THIS FILE EXISTS. Winter is 12 to 2, so every
 * method here has to mean the right thing when `to` is smaller than `from` —
 * and a `for` loop over months that assumed otherwise would not fail, it would
 * hang.
 */
final class MonthWindowTest extends TestCase
{
    #[Test]
    public function a_month_outside_the_year_is_not_a_window(): void
    {
        $this->assertNull(MonthWindow::of(0, 5));
        $this->assertNull(MonthWindow::of(3, 13));
        $this->assertNotNull(MonthWindow::of(12, 2));
    }

    /**
     * A window that definitely exists.
     *
     * `MonthWindow::of()` answers NULL for a month outside the year — which is
     * its own test above — so every other case here would otherwise be written
     * through `?->` and assert nothing when the constructor silently changed.
     */
    private function window(int $from, int $to): MonthWindow
    {
        $window = MonthWindow::of($from, $to);

        $this->assertNotNull($window);

        return $window;
    }

    #[Test]
    public function it_lists_the_months_it_covers_in_order(): void
    {
        $this->assertSame([3, 4, 5], $this->window(3, 5)->months());
        $this->assertSame([7], $this->window(7, 7)->months());
        $this->assertSame([12, 1, 2], $this->window(12, 2)->months());
    }

    #[Test]
    public function a_wrapping_window_covers_january_and_not_june(): void
    {
        $winter = $this->window(12, 2);

        $this->assertTrue($winter->covers(1));
        $this->assertTrue($winter->covers(12));
        $this->assertFalse($winter->covers(6));
    }

    #[Test]
    public function it_labels_itself_the_way_the_design_writes_it(): void
    {
        $this->assertSame('Mar – May', $this->window(3, 5)->label());
        $this->assertSame('Jul', $this->window(7, 7)->label());
        $this->assertSame('Dec – Feb', $this->window(12, 2)->label());
    }

    /**
     * Asked in the middle of the window, the answer is the window we are
     * standing in — rolling forward to next March would hide every fare
     * currently on offer.
     */
    #[Test]
    public function a_window_already_running_resolves_to_this_year(): void
    {
        [$start, $end] = $this->window(3, 5)->resolve(new DateTimeImmutable('2026-04-15'));

        $this->assertSame('2026-03-01', $start->format('Y-m-d'));
        $this->assertSame('2026-05-31', $end->format('Y-m-d'));
    }

    #[Test]
    public function a_window_that_has_ended_resolves_to_next_year(): void
    {
        [$start, $end] = $this->window(3, 5)->resolve(new DateTimeImmutable('2026-08-15'));

        $this->assertSame('2027-03-01', $start->format('Y-m-d'));
        $this->assertSame('2027-05-31', $end->format('Y-m-d'));
    }

    #[Test]
    public function a_window_still_ahead_resolves_to_this_year(): void
    {
        [$start, $end] = $this->window(9, 11)->resolve(new DateTimeImmutable('2026-08-15'));

        $this->assertSame('2026-09-01', $start->format('Y-m-d'));
        $this->assertSame('2026-11-30', $end->format('Y-m-d'));
    }

    /**
     * December to February asked in January is the winter around us, so it
     * STARTED last year. A window that quietly began next December would make
     * a January rule match nothing for eleven months.
     */
    #[Test]
    public function a_wrapping_window_resolves_across_new_year(): void
    {
        [$start, $end] = $this->window(12, 2)->resolve(new DateTimeImmutable('2027-01-10'));

        $this->assertSame('2026-12-01', $start->format('Y-m-d'));
        $this->assertSame('2027-02-28', $end->format('Y-m-d'));
    }

    #[Test]
    public function february_gets_its_extra_day_in_a_leap_year(): void
    {
        [, $end] = $this->window(2, 2)->resolve(new DateTimeImmutable('2028-01-05'));

        $this->assertSame('2028-02-29', $end->format('Y-m-d'));
    }
}
