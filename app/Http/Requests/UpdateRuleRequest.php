<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /api/rules/{id}` — pause a rule or start it again.
 *
 * `active` IS REQUIRED, exactly like UpdateWatchedRouteRequest's: once a
 * boolean is optional, "absent" and "false" are the same request, and the
 * failure mode is a switch that can be turned on and never off.
 */
final class UpdateRuleRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'active.required' => 'Say whether the rule should be on or off.',
            'active.boolean' => 'Say whether the rule should be on or off.',
        ];
    }
}
