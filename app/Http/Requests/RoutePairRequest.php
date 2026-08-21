<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Airport;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A city pair named by two IATA codes. Both ends are plain `exists:airports,iata` since
 * 2026-08-16; `config('orbit.origins')` MUST STAY untouched (docs/BUSINESS-LOGIC.md §36).
 */
abstract class RoutePairRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // `size:3` before `exists` only changes which message comes back first; both run.
            // The two lists differ only in `different:origin` (docs/BUSINESS-LOGIC.md §36).
            'origin' => [
                'required', 'string', 'size:3',
                'exists:airports,iata',
            ],
            'destination' => [
                'required', 'string', 'size:3',
                'different:origin',
                'exists:airports,iata',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'origin.size'   => 'An airport code is three letters.',
            'origin.exists' => 'Orbit does not know that airport yet.',

            'destination.size'      => 'An airport code is three letters, like LIS.',
            'destination.different' => 'A route needs two different airports.',
            'destination.exists'    => 'Orbit does not know an airport with that code.',
        ];
    }

    /**
     * The validated code, upper case. Only meaningful after validation.
     */
    public function iata(string $field): string
    {
        return $this->string($field)->toString();
    }

    /**
     * The airport row behind one of the two fields. `firstOrFail`, not a polite abort:
     * validation already established it exists, so reaching here unknown is a bug.
     */
    public function airport(string $field): Airport
    {
        return Airport::query()->where('iata', $this->iata($field))->firstOrFail();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'origin'      => $this->normalise('origin'),
            'destination' => $this->normalise('destination'),
        ]);
    }

    /**
     * Trim and upper-case, and leave anything that is not a string alone so
     * the `string` rule is what rejects it rather than a cast to "".
     */
    private function normalise(string $field): mixed
    {
        $value = $this->input($field);

        return is_string($value) ? mb_strtoupper(trim($value)) : $value;
    }
}
