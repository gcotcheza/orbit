<?php

declare(strict_types=1);

namespace App\Infrastructure\Nlp;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Messages\JSONOutputFormat;
use Anthropic\Messages\Message;
use Anthropic\Messages\MessageParam;
use Anthropic\Messages\OutputConfig;
use Anthropic\Messages\StopReason;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\TextBlockParam;
use App\Application\Ports\RuleTextParser;
use App\Domain\Rules\ParsedRule;
use App\Domain\Rules\RuleCriteria;
use App\Domain\Rules\RuleVocabulary;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reading the sentence by asking Claude.
 *
 * ---------------------------------------------------------------------------
 * THE THINGS THAT ARE NOT OBVIOUS
 *
 * 1. EVIDENCE FIRST, THEN THE INSTRUCTION. Content blocks are ordered, so the
 *    owner's sentence goes in its own block and RulePrompt::TEXT closes the
 *    message. That ordering is also the prompt-injection defence: untrusted
 *    text never becomes part of our instruction, and the final word is ours.
 *
 * 2. THE SCHEMA IS ENFORCED SERVER-SIDE. `outputConfig.format` with a
 *    json_schema means the first text block IS the JSON — no prose to strip,
 *    no fenced block to unwrap, and no "sometimes it apologises first" branch.
 *    Nothing here hunts for a `{`.
 *
 * 3. `stop_reason` IS CHECKED BEFORE `content` IS READ. A refusal is a
 *    perfectly successful HTTP 200 with an empty content array, so code that
 *    indexes content[0] first crashes on exactly the case it most needs to
 *    explain. `max_tokens` is checked for the same reason: truncated JSON is
 *    not a small problem, it is unparseable.
 *
 * 4. NO temperature / top_p / top_k. They are removed on current models and a
 *    request carrying one is rejected outright rather than ignored.
 *
 * 5. EVERY FAILURE FALLS BACK TO THE REGEX PARSER, and this is where this
 *    class deliberately differs from health-tracker's analyzer, which reports
 *    failures to the user. The difference is what the caller can do about it:
 *    a meal photo that could not be analysed has a "log it by hand" button
 *    behind it, and this is a screen that re-parses on a 500 ms debounce while
 *    somebody is mid-sentence. There is no useful error message to show
 *    between two keystrokes. So a refusal, a truncation, an unreadable answer
 *    and an unreachable API all produce the same thing — a slightly less
 *    clever parse of the same sentence — and the only place the difference is
 *    visible is the log (see ParseFailure). The owner's create screen never
 *    breaks because a third party did.
 * ---------------------------------------------------------------------------
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
     * The call, reduced to "the document, or nothing".
     *
     * Returns the decoded JSON, or NULL after logging which of ParseFailure's
     * cases happened. The caller does not get to care which — see the class
     * comment — but the log does.
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
            /*
             * The message of an SDK exception is the API's own error text. It
             * never contains the key, and it is the difference between "the
             * model is down" and "the model is not configured".
             */
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
            /*
             * A safety classifier declined. Not an error, not a bug, and not
             * something a retry fixes — so it is not retried.
             *
             * `isset()` AND NOT `?->`. The SDK declares `stopDetails` as a
             * typed property with no default and only initialises it when the
             * key was in the response — so reading it off a refusal that came
             * back without one is a fatal "must not be accessed before
             * initialization", inside the branch whose entire job is to keep
             * this class from crashing. The null-safe operator does not help:
             * uninitialised is not null.
             */
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
            /*
             * Structured outputs should make this unreachable. It is handled
             * anyway because "should" is not a guarantee, and the alternative
             * to handling it is a 500 on the create screen.
             */
            return $this->failed(ParseFailure::Unreadable, $e->getMessage());
        }

        return $decoded;
    }

    /**
     * The model's JSON, in this app's own words.
     *
     * EVERY FIELD GOES THROUGH RuleCriteria::from(), which is the same
     * validation the database's stored criteria get. The schema already
     * constrains the answer, so this is not distrust of the model — it is
     * refusing to have two definitions of what a criteria field may hold.
     *
     * @param  array<string, mixed>  $decoded
     */
    private function criteria(array $decoded): RuleCriteria
    {
        $euros = $decoded['max_price_euros'] ?? null;
        $window = $decoded['date_window'] ?? null;

        return RuleCriteria::from([
            'origins' => $decoded['origins'] ?? [],
            'maxPriceCents' => is_int($euros) ? $euros * 100 : null,
            'tripLengthNights' => $decoded['trip_length_nights'] ?? null,
            'departDows' => $decoded['depart_weekdays'] ?? [],
            'dateWindow' => is_array($window)
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
            'model' => $this->model,
            'prompt' => RulePrompt::VERSION,
            'detail' => $detail,
        ]);

        return null;
    }
}
