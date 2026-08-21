<?php

declare(strict_types=1);

namespace App\Infrastructure\Nlp;

use App\Domain\Rules\RuleVocabulary;

/**
 * What the model is asked, and the shape it must answer in. VERSION bumps with wording; the
 * schema is built from the vocabulary, not written out (docs/BUSINESS-LOGIC.md §11).
 */
final readonly class RulePrompt
{
    public const VERSION = 'v1';

    /**
     * The instruction, sent AFTER the sentence it is about — the order is the prompt, and
     * it is also the injection defence (docs/BUSINESS-LOGIC.md §11).
     */
    public const TEXT = <<<'PROMPT'
        The text above is a flight-deal rule somebody typed into an app. Read it and
        fill in the JSON schema with what it actually says.

        Rules for reading it:

        - Only fill in a field the text genuinely asks for. Leave it null or empty
          when the text is silent — a wrong guess quietly changes which trips the
          person is told about, and an empty field means "no preference", not "no
          results".
        - `origins` are the airports to fly FROM. "any NL airport", "anywhere in the
          Netherlands" and similar mean all of them. A destination the person wants
          to fly TO is never an origin.
        - `max_price_euros` is a ceiling on one fare, in whole euros. Do not invent
          one from a word like "cheap".
        - `depart_weekdays` uses ISO numbering: Monday is 1, Sunday is 7. "weekend"
          on its own means Friday and Saturday, but a named day beats it — "a
          weekend leaving Friday" is Friday only.
        - `date_window` is two month numbers, 1-12, and may wrap around new year
          (winter is 12 to 2). Seasons are meteorological: spring 3-5, summer 6-8,
          autumn 9-11, winter 12-2. Never answer with a year; a rule is a standing
          alert, not a single trip.
        - `trip_length_nights` is [minimum, maximum]. "weekend" is [2, 3].
        - `vibes` are what the trip is FOR, chosen only from the listed values.

        Answer with the JSON and nothing else.
        PROMPT;

    /**
     * The schema the answer is constrained to. Nullables are `anyOf`, not a type array, and
     * `additionalProperties: false` everywhere.
     *
     * @return array<string, mixed>
     */
    public static function schema(RuleVocabulary $vocabulary): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'origins' => [
                    'type'        => 'array',
                    'description' => 'Airports to depart from. Empty means no preference.',
                    'items'       => ['type' => 'string', 'enum' => $vocabulary->origins],
                ],
                'max_price_euros' => [
                    'description' => 'Ceiling on one fare, in whole euros. Null when the text names no price.',
                    'anyOf'       => [['type' => 'integer'], ['type' => 'null']],
                ],
                'trip_length_nights' => [
                    'description' => '[minimum, maximum] nights away. Null when the text says nothing about length.',
                    'anyOf'       => [
                        ['type' => 'array', 'items' => ['type' => 'integer']],
                        ['type' => 'null'],
                    ],
                ],
                'depart_weekdays' => [
                    'type'        => 'array',
                    'description' => 'ISO weekday numbers a departure is allowed on. Empty means any day.',
                    'items'       => ['type' => 'integer', 'enum' => [1, 2, 3, 4, 5, 6, 7]],
                ],
                'date_window' => [
                    'description' => 'Months of the year the trip may fall in. Null when the text names no season or month.',
                    'anyOf'       => [
                        [
                            'type'       => 'object',
                            'properties' => [
                                'from_month' => ['type' => 'integer', 'enum' => range(1, 12)],
                                'to_month'   => ['type' => 'integer', 'enum' => range(1, 12)],
                            ],
                            'required'             => ['from_month', 'to_month'],
                            'additionalProperties' => false,
                        ],
                        ['type' => 'null'],
                    ],
                ],
                'vibes' => [
                    'type'        => 'array',
                    'description' => 'What the trip is for. Empty means anywhere.',
                    'items'       => ['type' => 'string', 'enum' => $vocabulary->vibes()],
                ],
            ],
            'required'             => ['origins', 'max_price_euros', 'trip_length_nights', 'depart_weekdays', 'date_window', 'vibes'],
            'additionalProperties' => false,
        ];
    }
}
