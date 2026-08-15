<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/airports?q=` — what somebody has typed into the destination box.
 *
 * TWO CHARACTERS IS THE FLOOR, and it is a cost decision rather than a taste
 * one. A single letter matches something like a third of 3,270 airports, and
 * the ten rows that come back from it are ten arbitrary rows — a suggestion
 * list that is worse than no suggestion list, bought with a round trip per
 * keystroke. The client (resources/js/stores/airports.js) does not ask below
 * two either; this is the server saying the same thing so that the rule is
 * true of the endpoint rather than of one caller.
 *
 * SIXTY CHARACTERS IS THE CEILING, which is longer than the longest city name
 * in the snapshot and short enough that the `LIKE` behind it cannot be handed
 * a kilobyte.
 *
 * THE SEARCH IS NOT A VALIDATION LIST, exactly as `GET /api/destinations` is
 * not: App\Http\Requests\RoutePairRequest accepts any code in `airports`,
 * which since the world import is any scheduled airport on Earth, whether or
 * not this endpoint would have offered it.
 */
final class SearchAirportsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.required' => 'Say what to look for.',
            'q.min' => 'Two letters is the shortest thing worth searching for.',
        ];
    }

    /**
     * What was typed, trimmed. Only meaningful after validation.
     */
    public function term(): string
    {
        return $this->string('q')->toString();
    }

    /**
     * Trimmed BEFORE the rules run, so that "  a  " is one character rather
     * than five and is refused for the reason it is actually too short.
     */
    protected function prepareForValidation(): void
    {
        $value = $this->input('q');

        if (is_string($value)) {
            $this->merge(['q' => trim($value)]);
        }
    }
}
