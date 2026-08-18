<?php

declare(strict_types=1);

namespace App\Application\Alerts;

use DateTimeInterface;
use Carbon\CarbonImmutable;
use App\Models\UserSettings;
use App\Domain\Alerts\QuietHours;

/**
 * When this account may be interrupted — the one place quiet hours stop being a
 * wall clock and become an instant.
 *
 * THE CONVERSION HAPPENS ONCE, HERE. `user_settings.quiet_start`/`quiet_end`
 * are wall-clock times in config('orbit.timezone') (see the migration for why
 * that is the one thing in this app not stored in UTC), the queue takes an
 * instant, and every step between the two is a chance to lose an hour twice a
 * year. App\Domain\Alerts\QuietHours does the arithmetic with no timezone in
 * sight; this class supplies the only timezone-aware line in the feature.
 *
 * IT ANSWERS WITH THE END OF THE WINDOW, NOT WITH A DURATION. `->delay()` takes
 * either, and a duration computed at 06:55 and used by a worker that picked the
 * job up at 07:10 would deliver at 08:15 — late by however long the queue was
 * busy. An instant cannot drift.
 *
 * THE END IS COMPUTED AS A WALL CLOCK AND NOT AS "NOW PLUS N MINUTES", for the
 * same reason: on the two nights a year the clocks move, ten hours after 22:00
 * is 07:00 or 09:00 local, and neither is what "until eight" means to the
 * person who typed it.
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
     * The instant an alert decided at `$now` may be delivered — or NULL when
     * that is right now, which is the ordinary answer at 06:55 in the morning.
     */
    public function opensAfter(DateTimeInterface $now): ?CarbonImmutable
    {
        $local = CarbonImmutable::instance($now)->setTimezone($this->timezone);

        if (! $this->quiet->covers($local->hour * 60 + $local->minute)) {
            return null;
        }

        $end = $local->setTime($this->quiet->endHour(), $this->quiet->endMinuteOfHour());

        /*
         * Inside a window that crosses midnight, "08:00" is TOMORROW's — which
         * is exactly the case the default 22:00–08:00 window is in every single
         * night, and the one an implementation that only ever used today's date
         * would deliver ten hours early on.
         */
        return $end <= $local ? $end->addDay() : $end;
    }
}
