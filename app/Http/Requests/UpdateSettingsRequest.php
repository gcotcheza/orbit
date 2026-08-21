<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT sends the whole object; a PATCH could drop a toggle silently. Keys are the API's
 * camelCase, not the DB's snake_case (see toColumns()).
 */
final class UpdateSettingsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'emailAlerts'  => ['required', 'boolean'],
            'pushAlerts'   => ['required', 'boolean'],
            'weeklyDigest' => ['required', 'boolean'],

            'quietHours' => ['required', 'boolean'],
            // REQUIRED even when off, so toggling back on restores the stored window.
            // Why: docs/BUSINESS-LOGIC.md §36.

            // date_format:H:i (not a regex) rejects 24:00 and 22:60 that \d{2}:\d{2} would miss.
            'quietStart' => ['required', 'string', 'date_format:H:i'],
            'quietEnd'   => ['required', 'string', 'date_format:H:i'],

            // Validated against config('orbit.alerts.sensitivities') keys, not a fixed range.
            // Why: docs/BUSINESS-LOGIC.md §36.
            'sensitivity' => ['required', 'integer', Rule::in(self::levels())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quietStart.date_format' => 'Quiet hours start at a time like 22:00.',
            'quietEnd.date_format'   => 'Quiet hours end at a time like 08:00.',
            'sensitivity.in'         => 'Pick one of the three sensitivity levels.',
        ];
    }

    /**
     * @return array<string, bool|string|int>
     */
    public function toColumns(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return [
            'email_alerts'  => $this->boolean('emailAlerts'),
            'push_alerts'   => $this->boolean('pushAlerts'),
            'weekly_digest' => $this->boolean('weeklyDigest'),
            'quiet_hours'   => $this->boolean('quietHours'),
            'quiet_start'   => $this->string('quietStart')->toString(),
            'quiet_end'     => $this->string('quietEnd')->toString(),
            'sensitivity'   => (int) $validated['sensitivity'],
        ];
    }

    /**
     * @return list<int>
     */
    private static function levels(): array
    {
        /** @var array<int, array{name: string, tier: string, blurb: string}> $sensitivities */
        $sensitivities = config('orbit.alerts.sensitivities');

        return array_keys($sensitivities);
    }
}
