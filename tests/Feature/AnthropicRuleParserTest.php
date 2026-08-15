<?php

declare(strict_types=1);

namespace Tests\Feature;

use Anthropic\Client as AnthropicClient;
use App\Application\Ports\RuleTextParser;
use App\Domain\Rules\RuleVocabulary;
use App\Infrastructure\Nlp\AnthropicRuleTextParser;
use App\Infrastructure\Nlp\RegexRuleTextParser;
use App\Infrastructure\Nlp\RulePrompt;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\NetworkExceptionInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * The parser that will run the day a key exists.
 *
 * NOTHING HERE MAKES A CALL. The SDK is driven through a mock PSR-18
 * transporter carrying canned HTTP responses, which is deliberately a level
 * lower than mocking the SDK's own client would be: the cases worth testing —
 * a refusal, a truncation — are things the SDK DESERIALISES, so a test that
 * mocked `messages->create()` would be asserting that the mock returns what
 * the mock was told to return. Driving the real deserialiser is what proves
 * this class reads `stop_reason` off a real response the way it thinks it
 * does.
 *
 * EVERY FAILURE FALLS BACK, and that is the property under test throughout:
 * the create screen re-parses on a 500 ms debounce, so there is no useful
 * error to show between two keystrokes and a refusal must cost a less clever
 * reading rather than the screen.
 */
final class AnthropicRuleParserTest extends TestCase
{
    private const SENTENCE = 'somewhere sunny under €80 leaving Friday';

    /**
     * @param  list<PsrResponse|RuntimeException>  $responses
     */
    private function parser(array $responses): AnthropicRuleTextParser
    {
        $vocabulary = $this->app->make(RuleVocabulary::class);

        return new AnthropicRuleTextParser(
            client: new AnthropicClient(
                apiKey: 'test-key-not-a-real-one',
                requestOptions: [
                    'transporter' => new GuzzleClient([
                        'handler' => HandlerStack::create(new MockHandler($responses)),
                        'http_errors' => false,
                    ]),
                    /* No retries, so one canned response is one call. */
                    'maxRetries' => 0,
                ],
            ),
            fallback: new RegexRuleTextParser($vocabulary),
            logger: Log::getLogger(),
            vocabulary: $vocabulary,
            model: 'claude-haiku-4-5-20251001',
            maxTokens: 1024,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function message(array $overrides = []): PsrResponse
    {
        return new PsrResponse(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'id' => 'msg_01',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-haiku-4-5-20251001',
            'content' => [],
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 120, 'output_tokens' => 40],
            ...$overrides,
        ]));
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function answering(array $answer): PsrResponse
    {
        return $this->message([
            'content' => [['type' => 'text', 'text' => (string) json_encode($answer)]],
        ]);
    }

    // -- The happy path ------------------------------------------------------

    #[Test]
    public function it_reads_the_models_json_into_criteria(): void
    {
        $criteria = $this->parser([$this->answering([
            'origins' => ['AMS', 'EIN'],
            'max_price_euros' => 80,
            'trip_length_nights' => [2, 3],
            'depart_weekdays' => [5],
            'date_window' => ['from_month' => 3, 'to_month' => 5],
            'vibes' => ['sunny'],
        ])])->parse(self::SENTENCE)->criteria();

        $this->assertSame(['AMS', 'EIN'], $criteria->origins);
        /* Euros on the wire, cents everywhere below HTTP. */
        $this->assertSame(8000, $criteria->maxPriceCents);
        $this->assertSame([2, 3], $criteria->tripLengthNights);
        $this->assertSame([5], $criteria->departDows);
        $this->assertSame(['sunny'], $criteria->vibes);
        $this->assertNotNull($criteria->dateWindow);
        $this->assertSame(3, $criteria->dateWindow->from);
        $this->assertSame(5, $criteria->dateWindow->to);
    }

    #[Test]
    public function the_models_answer_becomes_the_same_chips_any_other_reading_would(): void
    {
        $chips = $this->parser([$this->answering([
            'origins' => [],
            'max_price_euros' => 80,
            'trip_length_nights' => null,
            'depart_weekdays' => [],
            'date_window' => null,
            'vibes' => ['sunny'],
        ])])->parse(self::SENTENCE)->chips;

        $this->assertSame(['max_price', 'vibe:sunny'], array_column($chips, 'id'));
        $this->assertSame(['€80', '☀ Sunny'], array_column($chips, 'label'));
    }

    #[Test]
    public function an_empty_sentence_is_never_sent_anywhere(): void
    {
        /* No canned responses at all: a call would fail the MockHandler. */
        $this->assertSame([], $this->parser([])->parse('   ')->chips);
    }

    // -- The failures, all of which fall back --------------------------------

    /**
     * A refusal is a successful HTTP 200 with an empty content array, which is
     * exactly why `stop_reason` is checked before `content` is read — code
     * that indexed content[0] first would crash on the case it most needs to
     * survive.
     */
    #[Test]
    public function a_refusal_falls_back_to_the_regex_parser(): void
    {
        $criteria = $this->parser([$this->message([
            'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => 'cyber', 'explanation' => 'declined'],
        ])])->parse(self::SENTENCE)->criteria();

        /* The regexes read the same sentence unaided. */
        $this->assertSame(8000, $criteria->maxPriceCents);
        $this->assertSame(['sunny'], $criteria->vibes);
        $this->assertSame([5], $criteria->departDows);
    }

    /**
     * Truncated JSON is not partial, it is unparseable — so the truncation is
     * caught by `stop_reason` rather than by json_decode failing later.
     */
    #[Test]
    public function running_out_of_room_falls_back_to_the_regex_parser(): void
    {
        $criteria = $this->parser([$this->message([
            'stop_reason' => 'max_tokens',
            'content' => [['type' => 'text', 'text' => '{"origins":["AM']],
        ])])->parse(self::SENTENCE)->criteria();

        $this->assertSame(8000, $criteria->maxPriceCents);
        $this->assertSame(['sunny'], $criteria->vibes);
    }

    #[Test]
    public function an_answer_with_no_text_in_it_falls_back(): void
    {
        $criteria = $this->parser([$this->message()])->parse(self::SENTENCE)->criteria();

        $this->assertSame(8000, $criteria->maxPriceCents);
    }

    #[Test]
    public function text_that_is_not_json_falls_back(): void
    {
        $criteria = $this->parser([$this->message([
            'content' => [['type' => 'text', 'text' => 'I am afraid I cannot do that']],
        ])])->parse(self::SENTENCE)->criteria();

        $this->assertSame(8000, $criteria->maxPriceCents);
    }

    #[Test]
    public function an_api_error_falls_back(): void
    {
        $criteria = $this->parser([
            new PsrResponse(500, ['Content-Type' => 'application/json'], (string) json_encode([
                'type' => 'error',
                'error' => ['type' => 'api_error', 'message' => 'Internal server error'],
            ])),
        ])->parse(self::SENTENCE)->criteria();

        $this->assertSame(8000, $criteria->maxPriceCents);
    }

    #[Test]
    public function a_connection_that_never_opens_falls_back(): void
    {
        $criteria = $this->parser([
            new class('could not connect', new PsrRequest('POST', 'https://api.anthropic.com/v1/messages')) extends RuntimeException implements NetworkExceptionInterface
            {
                public function __construct(string $message, private readonly PsrRequest $request)
                {
                    parent::__construct($message);
                }

                public function getRequest(): PsrRequest
                {
                    return $this->request;
                }
            },
        ])->parse(self::SENTENCE)->criteria();

        $this->assertSame(8000, $criteria->maxPriceCents);
    }

    /**
     * A rule the regexes cannot read either. The point is that it is still an
     * answer rather than an exception — App\Application\Ports\RuleTextParser
     * says implementations never throw, and the create screen is asked about
     * half-finished English constantly.
     */
    #[Test]
    public function a_failure_on_a_sentence_nobody_can_read_is_still_not_an_exception(): void
    {
        $parsed = $this->parser([$this->message(['stop_reason' => 'refusal'])])->parse('asdf qwerty');

        $this->assertSame([], $parsed->chips);
        $this->assertTrue($parsed->criteria()->isEmpty());
    }

    // -- Wiring --------------------------------------------------------------

    #[Test]
    public function without_a_key_the_container_hands_out_the_regex_parser(): void
    {
        config(['orbit.nlp.parser' => 'regex']);

        $this->assertInstanceOf(RegexRuleTextParser::class, $this->app->make(RuleTextParser::class));
    }

    #[Test]
    public function with_a_key_the_container_hands_out_the_anthropic_one(): void
    {
        config(['orbit.nlp.parser' => 'anthropic', 'orbit.nlp.api_key' => 'test-key-not-a-real-one']);

        $this->assertInstanceOf(AnthropicRuleTextParser::class, $this->app->make(RuleTextParser::class));
    }

    /**
     * A typo in .env must not silently downgrade the parser — the same rule
     * the fare providers are bound under, for the same reason: a box quietly
     * doing something dumber than it was paid to looks exactly like a box that
     * is working.
     */
    #[Test]
    public function an_unknown_parser_name_throws_rather_than_falling_back(): void
    {
        config(['orbit.nlp.parser' => 'anthropc']);

        $this->expectExceptionMessage('Unknown rule parser [anthropc].');

        $this->app->make(RuleTextParser::class);
    }

    // -- The prompt ----------------------------------------------------------

    /**
     * The schema is built from the vocabulary rather than written out, so the
     * model is structurally unable to answer with an airport this app does not
     * fly from or a vibe no destination carries.
     */
    #[Test]
    public function the_schema_only_permits_words_this_app_knows(): void
    {
        $schema = RulePrompt::schema($this->app->make(RuleVocabulary::class));

        $this->assertSame(config('orbit.origins'), $schema['properties']['origins']['items']['enum']);
        $this->assertSame(
            array_keys((array) config('orbit.nlp.vibe_words')),
            $schema['properties']['vibes']['items']['enum'],
        );
        $this->assertFalse($schema['additionalProperties']);
    }
}
