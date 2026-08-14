<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /api/settings` — the whole preferences object, every time.
 *
 * A PUT AND NOT A PATCH, and every field is `required`. The screen flips one
 * switch at a time, so a partial update looks like the smaller request — but
 * "the field is absent" and "the field is false" are indistinguishable in
 * JSON once a boolean is optional, and the failure mode is a quiet-hours
 * toggle that can be switched on and never off. Sending the object back whole
 * makes the request say exactly what the screen believes, which is also what
 * makes the optimistic UI's revert honest.
 *
 * THE KEYS ARE THE API's, NOT THE DATABASE's — `emailAlerts`, not
 * `email_alerts`. docs/API.md is camelCase throughout because its only reader
 * is JavaScript; toColumns() below is the single place the two vocabularies
 * meet, so no controller has to know both.
 */
final class UpdateSettingsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'emailAlerts' => ['required', 'boolean'],
            'pushAlerts' => ['required', 'boolean'],
            'weeklyDigest' => ['required', 'boolean'],

            'quietHours' => ['required', 'boolean'],
            /*
             * REQUIRED EVEN WHEN QUIET HOURS ARE OFF. The window is stored
             * either way — switching quiet hours back on has to restore the
             * times somebody chose, not reset them to 22:00 — so the client
             * always sends what it is showing.
             *
             * `date_format:H:i` and not a regex: it rejects 24:00 and 22:60,
             * which a naive \d{2}:\d{2} does not.
             */
            'quietStart' => ['required', 'string', 'date_format:H:i'],
            'quietEnd' => ['required', 'string', 'date_format:H:i'],

            /*
             * AGAINST THE CONFIG'D LEVELS rather than `between:0,2`. The scale
             * is config/orbit.php's `alerts.sensitivities`, so a fourth level
             * is one entry there and this rule follows it.
             */
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
            'quietEnd.date_format' => 'Quiet hours end at a time like 08:00.',
            'sensitivity.in' => 'Pick one of the three sensitivity levels.',
        ];
    }

    /**
     * The validated settings, keyed by column.
     *
     * @return array<string, bool|string|int>
     */
    public function toColumns(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return [
            'email_alerts' => $this->boolean('emailAlerts'),
            'push_alerts' => $this->boolean('pushAlerts'),
            'weekly_digest' => $this->boolean('weeklyDigest'),
            'quiet_hours' => $this->boolean('quietHours'),
            'quiet_start' => $this->string('quietStart')->toString(),
            'quiet_end' => $this->string('quietEnd')->toString(),
            'sensitivity' => (int) $validated['sensitivity'],
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
