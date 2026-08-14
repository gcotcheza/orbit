<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /api/watchlist/{code}` — the design's toggle (§5), and nothing else.
 *
 * ONE FIELD, AND IT IS `required`. A PATCH with an empty body is somebody's
 * bug, not a request to leave things as they are, and answering it with 200
 * would leave the screen's optimistic switch showing a state the server never
 * agreed to.
 *
 * `active: false` IS A PAUSE. It stops the polling and the alerts and keeps
 * the route, its history and its position — see the watchlist_items migration
 * for why that is a column and not a delete.
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
