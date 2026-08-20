<?php

declare(strict_types=1);

namespace App\Infrastructure\Nlp;

use Throwable;
use JsonException;
use Anthropic\Client;
use Psr\Log\LoggerInterface;
use Anthropic\Messages\Message;
use App\Domain\Rules\ParsedRule;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\StopReason;
use App\Domain\Rules\RuleCriteria;
use Anthropic\Messages\MessageParam;
use Anthropic\Messages\OutputConfig;
use App\Domain\Rules\RuleVocabulary;
use Anthropic\Messages\TextBlockParam;
use Anthropic\Messages\JSONOutputFormat;
use App\Application\Ports\RuleTextParser;
use Anthropic\Core\Exceptions\APIException;

/**
 * Reading the sentence by asking Claude.
 *
 * 1. Evidence first, then the instruction — untrusted text can never become part of our instruction; this is the
 * prompt-injection defence (docs/BUSINESS-LOGIC.md §11).
 *
 * 2. Schema enforced server-side — the first text block IS the JSON; nothing here hunts for a `{`
 * (docs/BUSINESS-LOGIC.md §11).
 *
 * 3. `stop_reason` checked before `content` is read — a refusal is a 200 with empty content, so indexing content[0]
 * first crashes on that case (docs/BUSINESS-LOGIC.md §11).
 *
 * 4. No temperature/top_p/top_k — removed on current models, and a
 * request carrying one is rejected outright rather than ignored.
 *
 * 5. Every failure falls back to the regex parser, unlike health-tracker's analyzer — there's no useful error to show
 * mid-sentence; only the log sees why (docs/BUSINESS-LOGIC.md §11).
 */
final readonly class AnthropicRuleTextParser implements RuleTextParser
{
    public function __construct(
        private Client $client,
        private RuleTextParser $fallback,
        private LoggerInterface $logger,
        private RuleVocabulary $vocabulary,
        private string $model,
        private int $maxTokens,
    ) {}

    public function parse(string $text): ParsedRule
    {
        if (trim($text) === '') {
            /* Nothing to ask about, and nothing to pay for. */
            return ParsedRule::nothing();
        }

        $decoded = $this->document($text);

        if (! is_array($decoded)) {
            return $this->fallback->parse($text);
        }

        return ParsedRule::of($this->criteria($decoded), $this->vocabulary);
    }

    /**
     * The call, reduced to "the document, or nothing" — NULL after logging
     * which ParseFailure case happened; the caller doesn't get to care which.
     *
     * @return array<string, mixed>|null
     */
    private function document(string $text): ?array
    {
        try {
            $message = $this->client->messages->create(
                maxTokens: $this->maxTokens,
                messages: [
                    MessageParam::with(
                        content: [
                            TextBlockParam::with(text: $text),
                            TextBlockParam::with(text: RulePrompt::TEXT),
                        ],
                        role: 'user',
                    ),
                ],
                model: $this->model,
                outputConfig: OutputConfig::with(
                    format: JSONOutputFormat::with(schema: RulePrompt::schema($this->vocabulary)),
                ),
            );
        } catch (APIException $e) {
            // SDK exception's message is the API's own error text — never the key — telling apart "the model is down" from "the
            // model is not configured" (docs/BUSINESS-LOGIC.md §11).
            return $this->failed(ParseFailure::Unreachable, $e->getMessage());
        } catch (Throwable $e) {
            return $this->failed(ParseFailure::Unreachable, $e::class.': '.$e->getMessage());
        }

        return $this->interpret($message);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function interpret(Message $message): ?array
    {
        if ($message->stopReason === StopReason::REFUSAL->value) {
            // A safety classifier declined; not retried. `isset()`, not `?->` — `stopDetails` is uninitialised when absent, and
            // null-safe doesn't help (docs/BUSINESS-LOGIC.md §11).
            return $this->failed(
                ParseFailure::Refused,
                isset($message->stopDetails) ? $message->stopDetails->explanation : null,
            );
        }

        if ($message->stopReason === StopReason::MAX_TOKENS->value) {
            return $this->failed(ParseFailure::Truncated, 'maxTokens='.$this->maxTokens);
        }

        $json = null;

        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                $json = $block->text;

                break;
            }
        }

        if ($json === null) {
            return $this->failed(ParseFailure::Empty);
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // Structured outputs should make this unreachable; handled anyway because
            // "should" isn't a guarantee, and the alternative is a 500 on the create screen.
            return $this->failed(ParseFailure::Unreadable, $e->getMessage());
        }

        return $decoded;
    }

    /**
     * The model's JSON, in this app's own words.
     *
     * Every field goes through RuleCriteria::from() — the same validation stored criteria get; not distrust, just one
     * definition of a valid field (docs/BUSINESS-LOGIC.md §11).
     *
     * @param  array<string, mixed>  $decoded
     */
    private function criteria(array $decoded): RuleCriteria
    {
        $euros = $decoded['max_price_euros'] ?? null;
        $window = $decoded['date_window'] ?? null;

        return RuleCriteria::from([
            'origins'          => $decoded['origins'] ?? [],
            'maxPriceCents'    => is_int($euros) ? $euros * 100 : null,
            'tripLengthNights' => $decoded['trip_length_nights'] ?? null,
            'departDows'       => $decoded['depart_weekdays'] ?? [],
            'dateWindow'       => is_array($window)
                ? ['from' => $window['from_month'] ?? null, 'to' => $window['to_month'] ?? null]
                : null,
            'vibes' => $decoded['vibes'] ?? [],
        ]);
    }

    /**
     * Says why, in the log, and answers NULL so a caller can write
     * `return $this->failed(...)` on the line that noticed.
     */
    private function failed(ParseFailure $failure, ?string $detail = null): null
    {
        $this->logger->warning('Rule parse fell back to the regex parser.', [
            'failure' => $failure->value,
            'model'   => $this->model,
            'prompt'  => RulePrompt::VERSION,
            'detail'  => $detail,
        ]);

        return null;
    }
}
