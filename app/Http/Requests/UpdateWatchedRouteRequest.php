<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /api/watchlist/{code}` — one field, and it is `required`: an empty body is a bug,
 * not "leave things as they are". `active: false` is a PAUSE, not a delete.
 */
final class UpdateWatchedRouteRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
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
            'active.required' => 'Say whether the route should be on or off.',
        ];
    }
}
