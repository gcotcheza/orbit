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
 * Reading the sentence without asking anybody.
 *
 * THIS IS THE ADAPTER PRODUCTION RUNS, not a stub for one. docs/PLAN.md still
 * lists "dedicated Anthropic API key" as a pending owner action, so until one
 * exists this class IS the rule parser — the same relationship
 * App\Infrastructure\Pricing\FakePriceProvider has to Travelpayouts. It is
 * written to that standard: it reads the design's own sentence exactly, and
 * about a dozen ways of saying the same things.
 *
 * IT IS ALSO THE FALLBACK FOREVER AFTER. AnthropicRuleTextParser composes this
 * one and hands over whenever the model refuses, runs out of room, or cannot
 * be reached — so this file is what stands between a bad afternoon at a third
 * party and a create screen that does nothing.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT DELIBERATELY DOES NOT DO
 *
 * It does not try to be a parser in the computer-science sense. There is no
 * grammar, no tokeniser and no ambiguity resolution — six independent readers
 * each look for the one thing they know about and say nothing when they do not
 * find it. That is why garbage input is `ParsedRule::nothing()` rather than a
 * wrong rule: a reader that finds nothing contributes nothing.
 *
 * The one place order matters is inside a reader (a month RANGE has to be
 * tried before a single month, or "march to may" reads as "march"), and each
 * one says so where it happens.
 * ---------------------------------------------------------------------------
 */
final readonly class RegexRuleTextParser implements RuleTextParser
{
    /**
     * Phrases that name the whole origin SET rather than one airport.
     *
     * Not aliases, which is why they are here and not in config: "any NL
     * airport" does not mean an airport, it means all of them, and putting it
     * in the alias map would make it a fourth airport that does not exist.
     *
     * A BARE "anywhere" IS NOT ON THE LIST, deliberately. In "fly anywhere
     * under €50" it is the DESTINATION being left open, and reading it as an
     * origin would draw three "From" chips for a sentence that never mentioned
     * where to leave from.
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
         * NO "sun" ABBREVIATION, unlike every other day. It is also the vibe
         * word for warm weather, and "a week in the sun" is a sentence about
         * sunshine rather than about Sundays. Losing an abbreviation nobody
         * types is cheaper than reading that one wrong.
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
         * Lower-cased once, and with the multibyte function: the alias map has
         * "düsseldorf" in it and strtolower() would leave the umlaut alone
         * while lowering the D, producing a word neither spelling matches.
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
                 * A NAMED DAY BEATS THE WEEKEND DEFAULT rather than joining
                 * it. "weekend ... leaving Friday" is one instruction refining
                 * another, not two: answering Friday AND Saturday would be
                 * reading a preference the sentence contradicts — and it is
                 * the design's own example sentence, whose chip says Fridays.
                 */
                departDows: $days !== [] ? $days : ($weekend ? [5, 6] : []),
                dateWindow: $this->dateWindow($text),
                vibes: $this->vibes($text),
            ),
            $this->vocabulary,
        );
    }

    /**
     * Which airports to leave from.
     *
     * EMPTY WHEN THE SENTENCE SAYS NOTHING, and empty means all three (see
     * RuleCriteria::originsOrAll). The design prototype always drew three
     * "From" chips even for a sentence that never mentioned an airport; this
     * does not, because "Here's what we understood" has to be a claim about
     * what was written. A chip nobody can explain is one somebody removes to
     * see what it does.
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
             * The SET wins over anything named alongside it, and keeps
             * config's order rather than the order somebody typed — the design
             * draws AMS, EIN, DUS in that order (§4) because that is the order
             * they are offered in everywhere else in the app.
             */
            return $this->vocabulary->origins;
        }

        return array_values(array_filter(
            $this->vocabulary->origins,
            static fn (string $iata): bool => isset($named[$iata]),
        ));
    }

    /**
     * "under €80", "below 80 euros", "max €80", "€80", "80 eur".
     *
     * THREE PATTERNS AND THE FIRST ONE THAT HITS, most explicit first. A bare
     * number is never a price: "2 nights" and "3 to 5 nights" are in the same
     * sentences, and a reader that took any two-digit number would turn every
     * trip length into a €3 ceiling. Something has to say "money" — a
     * comparison word, a currency symbol, or the word euro.
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
         * A BARE "week" IS SEVEN NIGHTS — "a ski week", "a week in the sun" —
         * but only when nothing in front of it turns it into a date instead.
         * "next week" is when somebody wants to leave, not how long they want
         * to stay, and reading it as a length would quietly put a 7-night
         * filter on a rule about next Tuesday.
         *
         * `\bweek\b` does not match "weekend"; the trailing boundary is what
         * keeps the design's own sentence at 2–3 nights.
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
     *
     * RANGES BEFORE SINGLE MONTHS, for the same reason the night patterns are
     * ordered: "march to may" contains "march".
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
                 * The one that appears FIRST in the sentence, not the one with
                 * the lowest month number: "a trip in October, or maybe March"
                 * is about October.
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
     * Whole words only, never substrings.
     *
     * `\b` IS DOING REAL WORK HERE and not being careful for its own sake: the
     * vibe list has "sun" in it and the text has "sunny" in it, "ski" is
     * inside "skiing" but also inside nothing else this app should react to,
     * and "mar" is inside "market". A substring search would read the design's
     * own example sentence wrong.
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
