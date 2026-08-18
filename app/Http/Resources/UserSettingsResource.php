<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\UserSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The alerts screen's seven switches (design/README.md §6).
 *
 * EVERY FIELD HERE IS WRITABLE AND EVERY WRITABLE FIELD IS HERE, which is what
 * lets `PUT /api/settings` take back exactly what `GET` handed out. Anything
 * DERIVED from these — the names of the sensitivity levels, the score each one
 * fires at, the sentence under the control — is `meta` on the response and is
 * built by App\Http\Controllers\SettingsController, so that a client which
 * PUTs the `data` object it was given can never accidentally send a label back
 * as a setting.
 *
 * `quietStart`/`quietEnd` are `HH:MM` strings in the OWNER's timezone, not
 * UTC and not a datetime — see the migration for why a bedtime is the one
 * thing this app stores as wall clock.
 */
final class UserSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var UserSettings $settings */
        $settings = $this->resource;

        return [
            'emailAlerts'  => $settings->email_alerts,
            'pushAlerts'   => $settings->push_alerts,
            'weeklyDigest' => $settings->weekly_digest,

            'quietHours' => $settings->quiet_hours,
            'quietStart' => $settings->quietStartAt(),
            'quietEnd'   => $settings->quietEndAt(),

            // 0 Relaxed | 1 Balanced | 2 Eager. What each one means is in
            // `meta.sensitivities`, from config/orbit.php.
            'sensitivity' => $settings->sensitivity,
        ];
    }
}
