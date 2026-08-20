<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Domain\Rules\RuleChip;
use PHPUnit\Framework\TestCase;
use App\Domain\Rules\RuleVocabulary;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Infrastructure\Nlp\RegexRuleTextParser;

/**
 * Reading English without a key.
 *
 * The first test is the contract — design/README.md §4's example sentence must produce its six chips exactly; this app
 * ships that sentence pre-typed.
 *
 * Loads the real config/orbit.php, not a test vocabulary — the claim is that production's words can read it, not just
 * some regex can.
 *
 * A plain PHPUnit TestCase, no database — the whole reason App\Domain\Rules\RuleVocabulary exists
 * (docs/BUSINESS-LOGIC.md §11).
 */
final class RegexRuleTextParserTest extends TestCase
{
    /** The sentence the create screen opens with. */
    private const DESIGN_SENTENCE = 'cheap weekend somewhere sunny in spring, leaving Friday from any NL airport, under €80';

    private RegexRuleTextParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var array{origins: list<string>, nlp: array{origin_aliases: array<string, string>, vibe_words: array<string, list<string>>, vibe_labels: array<string, string>}} $config */
        $config = require __DIR__.'/../../../config/orbit.php';

        $this->parser = new RegexRuleTextParser(new RuleVocabulary(
            $config['origins'],
            $config['nlp']['origin_aliases'],
            $config['nlp']['vibe_words'],
            $config['nlp']['vibe_labels'],
        ));
    }

    #[Test]
    public function the_designs_own_sentence_produces_the_designs_own_chips(): void
    {
        $chips = $this->parser->parse(self::DESIGN_SENTENCE)->chips;

        $this->assertSame([
            ['From', 'AMS'],
            ['From', 'EIN'],
            ['From', 'DUS'],
            ['Max price', '€80'],
            ['Trip length', '2–3 nights'],
            ['Depart', 'Fridays'],
            ['Date window', 'Mar – May'],
            ['Vibe', '☀ Sunny'],
        ], array_map(
            static fn (RuleChip $chip): array => [$chip->category(), $chip->label],
            $chips,
        ));
    }

    #[Test]
    public function the_designs_own_sentence_produces_the_criteria_behind_those_chips(): void
    {
        $criteria = $this->parser->parse(self::DESIGN_SENTENCE)->criteria();

        $this->assertSame(['AMS', 'EIN', 'DUS'], $criteria->origins);
        $this->assertSame(8000, $criteria->maxPriceCents);
        $this->assertSame([2, 3], $criteria->tripLengthNights);
        $this->assertSame(['sunny'], $criteria->vibes);
        // FRIDAY ALONE: a named day refines "weekend" rather than joining it, so weekend + Friday narrows to Friday, not
        // Friday-and-Saturday (docs/BUSINESS-LOGIC.md §11).
        $this->assertSame([5], $criteria->departDows);
        $this->assertNotNull($criteria->dateWindow);
        $this->assertSame(3, $criteria->dateWindow->from);
        $this->assertSame(5, $criteria->dateWindow->to);
    }

    /**
     * @return array<string, array{string, int|null}>
     */
    public static function prices(): array
    {
        return [
            'under with a symbol'      => ['sunny under €80', 8000],
            'below in words'           => ['somewhere sunny below 80 euros', 8000],
            'max with a symbol'        => ['beach, max €80', 8000],
            'maximum spelled out'      => ['beach maximum 80 eur', 8000],
            'less than'                => ['city break less than 65 euros', 6500],
            'a bare symbol'            => ['ski trip €150', 15000],
            'a trailing currency word' => ['ski trip 150 euros', 15000],

            // A BARE NUMBER IS NEVER A PRICE: "3 nights" shares sentences with real prices, so a naive reader would turn trip
            // length into a price (docs/BUSINESS-LOGIC.md §11).
            'a night count is not a price'   => ['somewhere sunny for 3 nights', null],
            'a weekday is not a price'       => ['leaving Friday', null],
            'the word cheap is not a number' => ['somewhere cheap and sunny', null],
        ];
    }

    #[Test]
    #[DataProvider('prices')]
    public function it_reads_a_price_ceiling_only_when_the_sentence_names_money(string $text, ?int $cents): void
    {
        $this->assertSame($cents, $this->parser->parse($text)->criteria()->maxPriceCents);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function origins(): array
    {
        return [
            'the whole set'                   => ['sunny, from any NL airport', ['AMS', 'EIN', 'DUS']],
            'the country'                     => ['beach from any dutch airport', ['AMS', 'EIN', 'DUS']],
            'one code'                        => ['sunny from AMS', ['AMS']],
            'one city'                        => ['sunny from Eindhoven', ['EIN']],
            'an airport by name'              => ['sunny from Schiphol', ['AMS']],
            'two of them, in config order'    => ['city break from DUS or AMS', ['AMS', 'DUS']],
            'an umlaut somebody did not type' => ['ski from dusseldorf', ['DUS']],

            // Silence is not "all three chips": empty means all-three once MATCHED (RuleCriteria::originsOrAll), but understanding
            // must not overclaim (docs/BUSINESS-LOGIC.md §11).
            'silence claims nothing'            => ['somewhere sunny under €80', []],
            'anywhere is about the destination' => ['fly anywhere under €50', []],
        ];
    }

    /**
     * @param  list<string>  $origins
     */
    #[Test]
    #[DataProvider('origins')]
    public function it_reads_which_airports_to_leave_from(string $text, array $origins): void
    {
        $this->assertSame($origins, $this->parser->parse($text)->criteria()->origins);
    }

    /**
     * @return array<string, array{string, array{int, int}|null}>
     */
    public static function windows(): array
    {
        return [
            'spring'                     => ['somewhere sunny in spring', [3, 5]],
            'summer'                     => ['beach in summer', [6, 8]],
            'autumn'                     => ['city break in autumn', [9, 11]],
            'fall'                       => ['city break in the fall', [9, 11]],
            'winter wraps the year'      => ['ski in winter', [12, 2]],
            'one month'                  => ['beach in July', [7, 7]],
            'an abbreviated month'       => ['beach in sept', [9, 9]],
            'a range with a word'        => ['sunny march to may', [3, 5]],
            'a range with a dash'        => ['sunny jun - aug', [6, 8]],
            'between'                    => ['sunny between june and august', [6, 8]],
            'the first month named wins' => ['a trip in October, or maybe March', [10, 10]],
            'silence'                    => ['somewhere sunny', null],
        ];
    }

    /**
     * @param  array{int, int}|null  $window
     */
    #[Test]
    #[DataProvider('windows')]
    public function it_reads_seasons_months_and_ranges(string $text, ?array $window): void
    {
        $parsed = $this->parser->parse($text)->criteria()->dateWindow;

        if ($window === null) {
            $this->assertNull($parsed);

            return;
        }

        $this->assertNotNull($parsed);
        $this->assertSame($window, [$parsed->from, $parsed->to]);
    }

    /**
     * @return array<string, array{string, array{int, int}|null, list<int>}>
     */
    public static function lengths(): array
    {
        return [
            'a weekend is two or three nights, leaving Friday or Saturday' => ['a cheap weekend', [2, 3], [5, 6]],
            'a long weekend is longer'                                     => ['a long weekend somewhere sunny', [3, 4], [5, 6]],
            'a named day refines the weekend'                              => ['a weekend leaving Saturday', [2, 3], [6]],
            'a week is seven nights'                                       => ['a week in the sun', [7, 7], []],
            'a ski week too'                                               => ['ski week in winter', [7, 7], []],
            'next week is a date, not a length'                            => ['somewhere sunny next week', null, []],
            'an exact count'                                               => ['5 nights somewhere warm', [5, 5], []],
            'a single night'                                               => ['1 night in Berlin', [1, 1], []],
            'a range'                                                      => ['3 to 5 nights somewhere sunny', [3, 5], []],
            'a dashed range'                                               => ['3-5 nights somewhere sunny', [3, 5], []],
            'two days named'                                               => ['leaving Monday or Thursday', null, [1, 4]],
            'silence'                                                      => ['somewhere sunny', null, []],
        ];
    }

    /**
     * @param  array{int, int}|null  $nights
     * @param  list<int>  $dows
     */
    #[Test]
    #[DataProvider('lengths')]
    public function it_reads_trip_length_and_departure_days(string $text, ?array $nights, array $dows): void
    {
        $criteria = $this->parser->parse($text)->criteria();

        $this->assertSame($nights, $criteria->tripLengthNights);
        $this->assertSame($dows, $criteria->departDows);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function vibes(): array
    {
        return [
            'sunny'                                => ['somewhere sunny', ['sunny']],
            'warm means sunny'                     => ['somewhere warm in may', ['sunny']],
            'beach'                                => ['a beach holiday', ['beach']],
            'a phrase beats the word inside it'    => ['a city break', ['city']],
            'snow means ski'                       => ['somewhere with snow', ['ski']],
            'several at once, in vocabulary order' => ['a sunny beach with nightlife', ['sunny', 'beach', 'party']],

            // "sun" is vocabulary and "sunny" contains it — whole-word matching keeps that from being two vibes (and "mar" out of
            // "market") (docs/BUSINESS-LOGIC.md §11).
            'a substring is not a word' => ['a trip to the supermarket', []],
            'silence'                   => ['under €80 from AMS', []],
        ];
    }

    /**
     * @param  list<string>  $vibes
     */
    #[Test]
    #[DataProvider('vibes')]
    public function it_reads_what_the_trip_is_for(string $text, array $vibes): void
    {
        $this->assertSame($vibes, $this->parser->parse($text)->criteria()->vibes);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonsense(): array
    {
        return [
            'empty'                             => [''],
            'whitespace'                        => ["  \n\t "],
            'keyboard mashing'                  => ['asdf qwerty zzz'],
            'punctuation'                       => ['!!! ??? ...'],
            'a different language entirely'     => ['なにもない'],
            'an unclosed regex somebody pasted' => ['(?<broken['],
            'html'                              => ['<script>alert(1)</script>'],
        ];
    }

    #[Test]
    #[DataProvider('nonsense')]
    public function nonsense_reads_as_nothing_rather_than_as_something(string $text): void
    {
        $parsed = $this->parser->parse($text);

        $this->assertSame([], $parsed->chips);
        $this->assertTrue($parsed->criteria()->isEmpty());
    }
}
