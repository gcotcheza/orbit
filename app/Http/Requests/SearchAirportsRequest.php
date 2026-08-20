<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /api/airports?q=` — what somebody typed into the destination box. 2-60 char bounds are a cost decision, not a
 * validation list; see below (docs/BUSINESS-LOGIC.md §36).
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
            'q.min'      => 'Two letters is the shortest thing worth searching for.',
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
