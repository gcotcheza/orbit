<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Route;
use App\Models\WatchlistItem;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/watchlist` — start watching a city pair.
 *
 * FIVE WAYS TO GET THIS WRONG AND FIVE DIFFERENT SENTENCES BACK. The add form
 * (design/README.md §5) is three buttons and a three-letter box, so every
 * rejection has to say which of the two fields is the problem and what would
 * fix it; "The given data was invalid." in a form that small is just a form
 * that does nothing.
 *
 *   - an origin that is not one of the three   -> not somewhere to fly FROM
 *   - an origin/destination Orbit has no airport for
 *   - the same code twice
 *   - a code that is not three letters
 *   - a pair already on the watchlist
 *
 * THE INPUT IS UPPER-CASED BEFORE ANY RULE RUNS. A person types `lis`, route
 * codes are `AMS-LIS`, and normalising in prepareForValidation means the
 * uniqueness check, the `exists` lookup and the row that gets written all see
 * the same string. Doing it in the controller instead would leave the rules
 * comparing the raw input.
 */
final class AddWatchedRouteRequest extends FormRequest
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
     * The pair is not already on this account's watchlist.
     *
     * AN `after` CALLBACK RATHER THAN A `unique` RULE, because what has to be
     * unique is not a column: it is the (user, route) pair, reached through a
     * route that is looked up by the code the two fields spell. It runs only
     * once the fields themselves are valid — telling somebody they are already
     * watching `AM-LIS` would be answering a question they did not ask.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $code = Route::codeFor($this->iata('origin'), $this->iata('destination'));

                $watched = WatchlistItem::query()
                    ->where('user_id', $this->user()?->getAuthIdentifier())
                    ->whereHas('route', function (Builder $route) use ($code): void {
                        /** @var Builder<Route> $route */
                        $route->where('code', $code);
                    })
                    ->exists();

                if ($watched) {
                    $validator->errors()->add('destination', "You are already watching {$code}.");
                }
            },
        ];
    }

    /**
     * The validated code, upper case. Only meaningful after validation.
     */
    public function iata(string $field): string
    {
        return $this->string($field)->toString();
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
    private static function allowedOrigins(): array
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
