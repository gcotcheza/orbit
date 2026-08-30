<?php

declare(strict_types=1);

namespace App\Application\Alerts;

use DateTimeInterface;
use Carbon\CarbonImmutable;
use App\Models\UserSettings;
use App\Domain\Alerts\QuietHours;

/**
 * When this account may be interrupted — the one place quiet hours stop being a wall clock and
 * become an instant, computed once, here (docs/BUSINESS-LOGIC.md §10).
 */
final readonly class DeliveryWindow
{
    private function __construct(
        private QuietHours $quiet,
        private string $timezone,
    ) {}

    public static function for(UserSettings $settings): self
    {
        return new self(
            $settings->quiet_hours
                ? QuietHours::between($settings->quietStartAt(), $settings->quietEndAt())
                : QuietHours::off(),
            (string) config('orbit.timezone'),
        );
    }

    /**
     * The instant an alert decided at `$now` may be delivered — or NULL when that is right now,
     * which is the ordinary answer at 07:35 in the morning.
     */
    public function opensAfter(DateTimeInterface $now): ?CarbonImmutable
    {
        $local = CarbonImmutable::instance($now)->setTimezone($this->timezone);

        if (! $this->quiet->covers($local->hour * 60 + $local->minute)) {
            return null;
        }

        $end = $local->setTime($this->quiet->endHour(), $this->quiet->endMinuteOfHour());

        /*
         * Crossing midnight, "08:00" is TOMORROW's — true every night for the default 22:00–08:00
         * window; using today's date would fire ten hours early.
         */
        return $end <= $local ? $end->addDay() : $end;
    }
}
