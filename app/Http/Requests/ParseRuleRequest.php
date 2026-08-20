<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/rules/parse` and `POST /api/rules` — the sentence, and the
 * chips the owner took off it.
 *
 * One request class for both: they share the same two fields, and a second class differing only in name is two places
 * to add the next field to.
 *
 * `text` may be empty on parse but not on create — that's the create endpoint's rule, not this class's. See
 * App\Http\Controllers\RuleController.
 *
 * `removed` accepts unknown chip ids deliberately — the client holds them across re-parses of a sentence still being
 * edited (docs/BUSINESS-LOGIC.md §11).
 */
final class ParseRuleRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /*
             * 500 chars: far more than anybody types, capped because this endpoint
             * may reach a metered API one day (config('orbit.nlp.parser')).
             */
            /*
             * `nullable` next to `present` isn't a contradiction: Laravel's ConvertEmptyStringsToNull turns an empty textarea into
             * NULL first.
             */
            'text'      => ['present', 'nullable', 'string', 'max:500'],
            'removed'   => ['sometimes', 'array', 'max:50'],
            'removed.*' => ['string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'text.present' => 'Send the text to read, even if it is empty.',
            'text.max'     => 'That is longer than a rule needs to be — 500 characters is the limit.',
        ];
    }

    public function text(): string
    {
        return $this->string('text')->toString();
    }

    /**
     * The chip ids to leave out, as a clean list of strings.
     *
     * @return list<string>
     */
    public function removed(): array
    {
        /** @var array<mixed> $removed */
        $removed = $this->validated()['removed'] ?? [];

        return array_values(array_filter($removed, 'is_string'));
    }
}
