<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Airport;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A city pair, named by two IATA codes — the body of both writes that take one.
 *
 * THREE WAYS TO GET IT WRONG AND THREE DIFFERENT SENTENCES BACK. The search
 * screen is two boxes and two buttons, so every rejection has to say which of
 * the two fields is the problem and what would fix it; "The given data was
 * invalid." in a form that small is just a form that does nothing.
 *
 *   - an origin/destination Orbit has no airport for
 *   - the same code twice
 *   - a code that is not three letters
 *
 * =============================================================================
 * THE ORIGIN IS NO LONGER ONE OF THREE, and the asymmetry that leaves is
 * deliberate — asked for by the owner on 2026-08-16, after a first day of use
 * that produced thirty-two lookups and no rules at all
 * =============================================================================
 * There used to be a fourth rule here: `Rule::in(config('orbit.origins'))`, so
 * that only AMS, EIN and DUS could be departed from. The argument for it was
 * that a fare from Málaga is not a flight this person can take — which is true
 * of the MORNING POLL, whose budget is the owner's own routes, and is not true
 * of a QUESTION. "What does Barcelona to Palermo cost while I am already in
 * Barcelona" is an ordinary thing to ask an app that can price any pair on
 * Earth, and this rule was the only reason it could not be asked.
 *
 * SO BOTH ENDS ARE NOW `exists:airports,iata` AND NOTHING ELSE, which is the
 * same rule the destination has always had: any of the 3,270 airports in the
 * table (docs/BUSINESS-LOGIC.md §1, tier 1). The pair still has to be two
 * DIFFERENT airports.
 *
 * WHAT DID NOT MOVE, AND MUST NOT. `config('orbit.origins')` is untouched and
 * still means exactly what it meant: the three airports a deal RULE may fire
 * from, and therefore the size of the nightly sweep's budget
 * (App\Application\Rules\RuleMatches, App\Jobs\SweepRuleFares,
 * App\Domain\Rules\RuleVocabulary). Those read the config directly and never
 * came through this class, so widening a request has not widened a sweep by a
 * single poll. A lookup is one pair somebody asked about; a rule is a standing
 * question Orbit answers on its own every night, and the second one is the one
 * with a bill attached. See the comment on `origins` in config/orbit.php.
 *
 * THE INPUT IS UPPER-CASED BEFORE ANY RULE RUNS. A person types `lis`, route
 * codes are `AMS-LIS`, and normalising in prepareForValidation means the
 * `exists` lookup, any subclass's own check and the row that gets written all
 * see the same string. Doing it in the controller instead would leave the rules
 * comparing the raw input.
 *
 * TWO SUBCLASSES, AND THE DIFFERENCE BETWEEN THEM IS THE FEATURE.
 * AddWatchedRouteRequest adds a fourth rule — the pair is not already on the
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
             * `size:3` before `exists` only affects which message comes back
             * first; both run. The order reads as the question a person would
             * ask: is it a code, and do we know it.
             *
             * THE TWO LISTS ARE THE SAME LIST NOW. They differ only in
             * `different:origin`, which has nowhere else to live — a rule that
             * compares two fields belongs on the second of them.
             */
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
