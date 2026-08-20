<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Alerts;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use App\Domain\Alerts\QuietHours;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The window that crosses midnight.
 *
 * The default window is 22:00-08:00 (user_settings migration) — the ordinary case, not an edge case.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
final class QuietHoursTest extends TestCase
{
    private static function minute(int $hour, int $minute = 0): int
    {
        return $hour * 60 + $minute;
    }

    #[Test]
    #[DataProvider('nightHours')]
    public function the_default_window_covers_the_night(int $minuteOfDay, bool $quiet): void
    {
        $this->assertSame($quiet, QuietHours::between('22:00', '08:00')->covers($minuteOfDay));
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function nightHours(): array
    {
        return [
            '21:59 — one minute early'           => [self::minute(21, 59), false],
            '22:00 — the start is quiet'         => [self::minute(22), true],
            '23:40'                              => [self::minute(23, 40), true],
            'midnight'                           => [self::minute(0), true],
            '03:00 — the small hours'            => [self::minute(3), true],
            '07:59'                              => [self::minute(7, 59), true],
            '08:00 — the end is not'             => [self::minute(8), false],
            '12:00 — the middle of the day'      => [self::minute(12), false],
            '06:55 — when the alert run happens' => [self::minute(6, 55), true],
        ];
    }

    /**
     * The end is exclusive so held mail can go out AT 08:00 — inclusive would
     * defer delivery by a full day, every day.
     */
    #[Test]
    public function the_end_of_the_window_is_not_inside_it(): void
    {
        $quiet = QuietHours::between('22:00', '08:00');

        $this->assertTrue($quiet->covers(self::minute(7, 59)));
        $this->assertFalse($quiet->covers(self::minute(8)));
        $this->assertNull($quiet->minutesUntilEnd(self::minute(8)));
    }

    #[Test]
    #[DataProvider('daytimeHours')]
    public function a_window_inside_one_day_behaves_the_obvious_way(int $minuteOfDay, bool $quiet): void
    {
        $this->assertSame($quiet, QuietHours::between('09:00', '17:00')->covers($minuteOfDay));
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function daytimeHours(): array
    {
        return [
            '08:59'    => [self::minute(8, 59), false],
            '09:00'    => [self::minute(9), true],
            '13:00'    => [self::minute(13), true],
            '16:59'    => [self::minute(16, 59), true],
            '17:00'    => [self::minute(17), false],
            'midnight' => [self::minute(0), false],
        ];
    }

    #[Test]
    #[DataProvider('waits')]
    public function it_says_how_long_until_the_window_opens(int $minuteOfDay, ?int $wait): void
    {
        $this->assertSame($wait, QuietHours::between('22:00', '08:00')->minutesUntilEnd($minuteOfDay));
    }

    /**
     * @return array<string, array{int, int|null}>
     */
    public static function waits(): array
    {
        return [
            '22:00 waits ten hours'  => [self::minute(22), 600],
            '23:00 waits nine'       => [self::minute(23), 540],
            'midnight waits eight'   => [self::minute(0), 480],
            '03:00 waits five'       => [self::minute(3), 300],
            '07:59 waits a minute'   => [self::minute(7, 59), 1],
            'noon waits for nothing' => [self::minute(12), null],
        ];
    }

    #[Test]
    public function quiet_hours_switched_off_cover_nothing(): void
    {
        $off = QuietHours::off();

        $this->assertFalse($off->covers(self::minute(3)));
        $this->assertFalse($off->covers(self::minute(23)));
        $this->assertNull($off->minutesUntilEnd(self::minute(3)));
    }

    /**
     * A zero-length window means "not finished setting it up", not a request
     * for 24 hours of silence.
     */
    #[Test]
    public function a_zero_length_window_covers_nothing(): void
    {
        $this->assertFalse(QuietHours::between('22:00', '22:00')->covers(self::minute(22)));
        $this->assertFalse(QuietHours::between('22:00', '22:00')->covers(self::minute(3)));
    }

    /**
     * Same Postgres/SQLite `time`-precision difference App\Models\UserSettings trims for. Both must parse here too.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    #[Test]
    public function it_reads_both_precisions_the_two_engines_produce(): void
    {
        $trimmed = QuietHours::between('22:00', '08:00');
        $full = QuietHours::between('22:00:00', '08:00:00');

        $this->assertSame($trimmed->startMinute, $full->startMinute);
        $this->assertSame($trimmed->endMinute, $full->endMinute);
    }

    #[Test]
    public function it_hands_back_the_end_as_a_wall_clock(): void
    {
        $quiet = QuietHours::between('22:00', '08:30');

        $this->assertSame(8, $quiet->endHour());
        $this->assertSame(30, $quiet->endMinuteOfHour());
    }

    #[Test]
    #[DataProvider('nonsense')]
    public function anything_that_is_not_a_wall_clock_is_refused(string $clock): void
    {
        $this->expectException(InvalidArgumentException::class);

        QuietHours::between($clock, '08:00');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonsense(): array
    {
        return [
            'empty'               => [''],
            'words'               => ['tonight'],
            'no minutes'          => ['22'],
            'a twenty-fifth hour' => ['25:00'],
            'a sixtieth minute'   => ['22:60'],
        ];
    }

    /**
     * A caller adding to a minute it already had can produce 1500 or -30;
     * this class still has an opinion about both.
     */
    #[Test]
    public function minutes_outside_a_day_wrap_rather_than_confuse_it(): void
    {
        $quiet = QuietHours::between('22:00', '08:00');

        /* 25:00 is 01:00, which is inside the window. */
        $this->assertTrue($quiet->covers(self::minute(25)));
        /* -60 is 23:00, likewise. */
        $this->assertTrue($quiet->covers(-60));
        /* 1440 is midnight. */
        $this->assertTrue($quiet->covers(1440));
    }
}
