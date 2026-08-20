<?php

declare(strict_types=1);

namespace App\Infrastructure\Nlp;

use DateTimeImmutable;
use App\Domain\Rules\ParsedRule;
use App\Domain\Rules\MonthWindow;
use App\Domain\Rules\RuleCriteria;
use App\Domain\Rules\RuleVocabulary;
use App\Application\Ports\RuleTextParser;

/**
 * Reading the sentence without asking anybody — the adapter production runs today,
 * and AnthropicRuleTextParser's fallback forever after (docs/BUSINESS-LOGIC.md §11).
 */
final readonly class RegexRuleTextParser implements RuleTextParser
{
    /**
     * Phrases naming the whole origin SET, not one airport — not aliases, so not in config.
     * A bare "anywhere" is deliberately absent: there it is the destination left open.
     */
    private const ALL_ORIGINS = '/\b(?:any(?:where)?\s+(?:nl|dutch|netherlands)|(?:nl|dutch|netherlands)\s+airports?|(?:any|all|either|every)\s+airports?)\b/u';

    private const WEEKDAYS = [
        1 => ['monday', 'mondays', 'mon'],
        2 => ['tuesday', 'tuesdays', 'tue', 'tues'],
        3 => ['wednesday', 'wednesdays', 'wed'],
        4 => ['thursday', 'thursdays', 'thu', 'thur', 'thurs'],
        5 => ['friday', 'fridays', 'fri'],
        6 => ['saturday', 'saturdays', 'sat'],
        /*
         * No "sun" abbreviation, unlike every other day: it is also the vibe word for
         * warm weather, and "a week in the sun" is not about Sundays.
         */
        7 => ['sunday', 'sundays'],
    ];

    private const MONTHS = [
        1  => ['january', 'jan'],
        2  => ['february', 'feb'],
        3  => ['march', 'mar'],
        4  => ['april', 'apr'],
        5  => ['may'],
        6  => ['june', 'jun'],
        7  => ['july', 'jul'],
        8  => ['august', 'aug'],
        9  => ['september', 'sept', 'sep'],
        10 => ['october', 'oct'],
        11 => ['november', 'nov'],
        12 => ['december', 'dec'],
    ];

    /** Meteorological, which is what a person means by "spring". */
    private const SEASONS = [
        'spring' => [3, 5],
        'summer' => [6, 8],
        'autumn' => [9, 11],
        'fall'   => [9, 11],
        'winter' => [12, 2],
    ];

    public function __construct(private RuleVocabulary $vocabulary) {}

    public function parse(string $text): ParsedRule
    {
        /*
         * mb_strtolower, not strtolower: the alias map has "düsseldorf" and the umlaut
         * would survive while the D lowered, matching neither spelling.
         */
        $text = mb_strtolower(trim($text));

        if ($text === '') {
            return ParsedRule::nothing();
        }

        $weekend = $this->mentions($text, ['weekend', 'weekends']);
        $days = $this->departureDays($text);

        return ParsedRule::of(
            new RuleCriteria(
                origins: $this->origins($text),
                maxPriceCents: $this->maxPriceCents($text),
                tripLengthNights: $this->nights($text) ?? ($weekend ? [2, 3] : null),
                /*
                 * A named day BEATS the weekend default rather than joining it:
                 * "weekend ... leaving Friday" is one instruction refining another.
                 */
                departDows: $days !== [] ? $days : ($weekend ? [5, 6] : []),
                dateWindow: $this->dateWindow($text),
                vibes: $this->vibes($text),
            ),
            $this->vocabulary,
        );
    }

    /**
     * Which airports to leave from. EMPTY WHEN THE SENTENCE SAYS NOTHING, and empty
     * means all three (RuleCriteria::originsOrAll) — a chip must be a claim about the text.
     *
     * @return list<string>
     */
    private function origins(string $text): array
    {
        $named = [];

        foreach ($this->vocabulary->originAliases as $alias => $iata) {
            if ($this->mentions($text, [$alias])) {
                $named[$iata] = true;
            }
        }

        if (preg_match(self::ALL_ORIGINS, $text) === 1) {
            /*
             * The SET wins over anything named alongside it, and keeps config's order
             * rather than the typed order — AMS, EIN, DUS everywhere (design/README.md §4).
             */
            return $this->vocabulary->origins;
        }

        return array_values(array_filter(
            $this->vocabulary->origins,
            static fn (string $iata): bool => isset($named[$iata]),
        ));
    }

    /**
     * "under €80", "below 80 euros", "max €80", "€80", "80 eur" — most explicit first.
     * A bare number is never a price; "2 nights" would otherwise become a €2 ceiling.
     */
    private function maxPriceCents(string $text): ?int
    {
        $patterns = [
            '/\b(?:under|below|less\s+than|cheaper\s+than|max(?:imum)?|up\s+to|no\s+more\s+than|at\s+most)\s*(?:€|eur\b|euros?\b)?\s*(\d{1,4})\b/u',
            '/€\s*(\d{1,4})\b/u',
            '/\b(\d{1,4})\s*(?:€|eur\b|euros?\b)/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match) === 1) {
                return ((int) $match[1]) * 100;
            }
        }

        return null;
    }

    /**
     * "3 nights", "3-5 nights", "3 to 5 nights", "a week", "long weekend".
     *
     * THE RANGE PATTERN RUNS FIRST or "3 to 5 nights" reads as three.
     *
     * @return array{int, int}|null
     */
    private function nights(string $text): ?array
    {
        if (preg_match('/\b(\d{1,2})\s*(?:-|–|to|or)\s*(\d{1,2})\s*nights?\b/u', $text, $match) === 1) {
            $min = (int) $match[1];
            $max = (int) $match[2];

            return $min <= $max ? [$min, $max] : [$max, $min];
        }

        if (preg_match('/\b(\d{1,2})\s*nights?\b/u', $text, $match) === 1) {
            $nights = (int) $match[1];

            return [$nights, $nights];
        }

        if ($this->mentions($text, ['long weekend'])) {
            return [3, 4];
        }

        /*
         * A bare "week" is seven nights, but "next week" is a departure, not a length.
         * `\bweek\b` does not match "weekend" — that boundary keeps the design at 2–3 nights.
         */
        if (preg_match('/\bweeks?\b/u', $text) === 1 && preg_match('/\b(?:next|this|last|per)\s+weeks?\b/u', $text) !== 1) {
            return [7, 7];
        }

        return null;
    }

    /**
     * The weekdays a departure is allowed on.
     *
     * @return list<int>
     */
    private function departureDays(string $text): array
    {
        $days = [];

        foreach (self::WEEKDAYS as $dow => $words) {
            if ($this->mentions($text, $words)) {
                $days[] = $dow;
            }
        }

        return $days;
    }

    /**
     * "spring", "in june", "march to may", "next month".
     * RANGES BEFORE SINGLE MONTHS: "march to may" contains "march".
     */
    private function dateWindow(string $text): ?MonthWindow
    {
        foreach (self::SEASONS as $season => [$from, $to]) {
            if ($this->mentions($text, [$season])) {
                return MonthWindow::of($from, $to);
            }
        }

        $range = $this->monthRange($text);

        if ($range !== null) {
            return $range;
        }

        if ($this->mentions($text, ['next month'])) {
            $next = (int) (new DateTimeImmutable)->modify('first day of next month')->format('n');

            return MonthWindow::of($next, $next);
        }

        $month = $this->firstMonth($text);

        return $month === null ? null : MonthWindow::of($month, $month);
    }

    private function monthRange(string $text): ?MonthWindow
    {
        foreach (self::MONTHS as $from => $fromWords) {
            foreach (self::MONTHS as $to => $toWords) {
                if ($from === $to) {
                    continue;
                }

                $pattern = sprintf(
                    '/\b(?:between\s+)?(?:%s)\b\s*(?:-|–|to|until|through|and)\s*\b(?:%s)\b/u',
                    implode('|', array_map('preg_quote', $fromWords)),
                    implode('|', array_map('preg_quote', $toWords)),
                );

                if (preg_match($pattern, $text) === 1) {
                    return MonthWindow::of($from, $to);
                }
            }
        }

        return null;
    }

    private function firstMonth(string $text): ?int
    {
        $earliest = null;
        $at = null;

        foreach (self::MONTHS as $month => $words) {
            foreach ($words as $word) {
                if (preg_match('/\b'.preg_quote($word, '/').'\b/u', $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }

                /*
                 * The month that appears FIRST in the sentence, not the lowest number:
                 * "a trip in October, or maybe March" is about October.
                 */
                $offset = (int) $match[0][1];

                if ($at === null || $offset < $at) {
                    $at = $offset;
                    $earliest = $month;
                }
            }
        }

        return $earliest;
    }

    /**
     * The vibes named in the sentence, in the vocabulary's order.
     *
     * @return list<string>
     */
    private function vibes(string $text): array
    {
        $found = [];

        foreach ($this->vocabulary->vibeWords as $vibe => $words) {
            if ($this->mentions($text, $words)) {
                $found[] = $vibe;
            }
        }

        return $found;
    }

    /**
     * Whole words only, never substrings: "sun" is inside "sunny", "ski" inside
     * "skiing", "mar" inside "market" — a substring search misreads the design's sentence.
     *
     * @param  list<string>  $words
     */
    private function mentions(string $text, array $words): bool
    {
        foreach ($words as $word) {
            /* Spaces in a phrase match any run of whitespace: "city  break". */
            $escaped = (string) preg_replace('/\s+/', '\s+', preg_quote($word, '/'));

            if (preg_match('/\b'.$escaped.'\b/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
