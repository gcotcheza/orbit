<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\UserSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The alerts screen's seven switches (design/README.md §6). EVERY FIELD HERE IS WRITABLE AND
 * EVERY WRITABLE FIELD IS HERE; derived values are `meta` (docs/BUSINESS-LOGIC.md §36).
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
