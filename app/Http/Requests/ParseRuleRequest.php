<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/rules/parse` and `POST /api/rules` — one request class for both. `removed`
 * accepts unknown chip ids deliberately (docs/BUSINESS-LOGIC.md §11).
 */
final class ParseRuleRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            /* 500 chars: more than anybody types, capped because this may reach a metered API. */
            /* `nullable` beside `present`: an empty textarea arrives as NULL, not ''. */
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
