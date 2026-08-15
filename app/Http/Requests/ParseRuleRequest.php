<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/rules/parse` and `POST /api/rules` — the sentence, and the chips
 * the owner took off it.
 *
 * ONE REQUEST CLASS FOR BOTH, because they take the same two fields and the
 * create screen sends the same object to both: whatever was last parsed is
 * exactly what should be saved. A second class differing only in its name is
 * two places to add the next field to.
 *
 * `text` MAY BE EMPTY ON THE PARSE ENDPOINT and may not on the create one, but
 * that is not this class's rule — an empty box is a normal state of a screen
 * that re-parses while somebody types, and the create endpoint refuses it for
 * a better reason than emptiness (see App\Http\Controllers\RuleController).
 *
 * `removed` IS A LIST OF CHIP IDS, and unknown ids are accepted deliberately:
 * the client holds them across re-parses of a sentence that is still being
 * edited, so an id for a chip the current text no longer produces is the
 * ordinary case (App\Domain\Rules\ParsedRule::without explains it in full).
 * Validating them against the current parse would mean parsing twice and
 * rejecting somebody for typing.
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
             * 500 characters is about six lines in the design's textarea and
             * far more than anybody types. It is here because this endpoint
             * may reach a metered API one day (config('orbit.nlp.parser')) and
             * an unbounded string is an unbounded bill.
             */
            /*
             * `nullable` NEXT TO `present`, which reads like a contradiction
             * and is not: Laravel's ConvertEmptyStringsToNull middleware turns
             * an empty textarea into NULL before any rule runs, so without it
             * a screen that re-parses while somebody deletes their sentence
             * gets "The text field must be a string." for the ordinary act of
             * clearing the box. `present` still requires the key.
             */
            'text' => ['present', 'nullable', 'string', 'max:500'],
            'removed' => ['sometimes', 'array', 'max:50'],
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
            'text.max' => 'That is longer than a rule needs to be — 500 characters is the limit.',
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
