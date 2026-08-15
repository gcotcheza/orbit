<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Airport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A city pair, named by two IATA codes — the body of both writes that take one.
 *
 * FOUR WAYS TO GET IT WRONG AND FOUR DIFFERENT SENTENCES BACK. The add form
 * (design/README.md §5) is three buttons and one box, so every rejection has to
 * say which of the two fields is the problem and what would fix it; "The given
 * data was invalid." in a form that small is just a form that does nothing.
 *
 *   - an origin that is not one of the three   -> not somewhere to fly FROM
 *   - an origin/destination Orbit has no airport for
 *   - the same code twice
 *   - a code that is not three letters
 *
 * THE INPUT IS UPPER-CASED BEFORE ANY RULE RUNS. A person types `lis`, route
 * codes are `AMS-LIS`, and normalising in prepareForValidation means the
 * `exists` lookup, any subclass's own check and the row that gets written all
 * see the same string. Doing it in the controller instead would leave the rules
 * comparing the raw input.
 *
 * TWO SUBCLASSES, AND THE DIFFERENCE BETWEEN THEM IS THE FEATURE.
 * AddWatchedRouteRequest adds a fifth rule — the pair is not already on the
 * watchlist — because adding something twice is a mistake. LookupRouteRequest
 * adds nothing at all, deliberately: looking up a route you already watch is a
 * perfectly ordinary thing to do, and refusing it would be the app arguing with
 * somebody who tapped a link.
 */
abstract class RoutePairRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /*
             * `size:3` before `in`/`exists` only affects which message comes
             * back first; all of them run. The order reads as the question a
             * person would ask: is it a code, is it one of ours, do we know it.
             */
            'origin' => [
                'required', 'string', 'size:3',
                Rule::in(self::allowedOrigins()),
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
        $origins = self::allowedOrigins();
        $last = array_pop($origins);

        return [
            'origin.size' => 'An airport code is three letters.',
            'origin.in' => sprintf('Orbit only tracks departures from %s or %s.', implode(', ', $origins), $last),
            'origin.exists' => 'Orbit does not know that airport yet.',

            'destination.size' => 'An airport code is three letters, like LIS.',
            'destination.different' => 'A route needs two different airports.',
            'destination.exists' => 'Orbit does not know an airport with that code.',
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
     * Validation has already established that it exists — this is the lookup,
     * not the check, which is why it is `firstOrFail` and not an abort with a
     * sentence: reaching this with an unknown code would be a bug in the rules
     * above rather than a request worth answering politely.
     */
    public function airport(string $field): Airport
    {
        return Airport::query()->where('iata', $this->iata($field))->firstOrFail();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'origin' => $this->normalise('origin'),
            'destination' => $this->normalise('destination'),
        ]);
    }

    /**
     * @return list<string>
     */
    protected static function allowedOrigins(): array
    {
        /** @var list<string> $origins */
        $origins = config('orbit.origins');

        return $origins;
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
