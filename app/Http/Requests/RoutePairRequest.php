<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Airport;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A city pair, named by two IATA codes — the body of both writes that take one.
 *
 * Three ways to get it wrong (unknown airport, same code twice, not three
 * letters), each answered with its own sentence, not a generic one.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * The origin is no longer restricted to the three home airports (removed
 * 2026-08-16) — both ends are now plain `exists:airports,iata`.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * `config('orbit.origins')` is untouched and MUST STAY that way — it still
 * bounds the nightly sweep's budget; widening this request never widens a sweep.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * Input is upper-cased before any rule runs (prepareForValidation), so the
 * `exists` lookup, subclass checks, and the stored row all agree.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * Two subclasses, and the difference IS the feature: AddWatchedRouteRequest
 * refuses a duplicate watch; LookupRouteRequest refuses nothing.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
abstract class RoutePairRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // `size:3` before `exists` only changes which message comes back first;
            // both run — reads as "is it a code, then do we know it".
            // Why: docs/BUSINESS-LOGIC.md §36.
            //
            // The two lists are the same list now — they differ only in
            // `different:origin`, which belongs on the second field being compared.
            // Why: docs/BUSINESS-LOGIC.md §36.
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
     * The airport row behind one of the two fields.
     *
     * `firstOrFail`, not a polite abort — validation already established it
     * exists, so reaching here unknown would be a bug in the rules above.
     * Why: docs/BUSINESS-LOGIC.md §36.
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
