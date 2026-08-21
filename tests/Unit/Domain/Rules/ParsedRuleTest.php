<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Rules;

use App\Domain\Rules\RuleChip;
use PHPUnit\Framework\TestCase;
use App\Domain\Rules\ParsedRule;
use App\Domain\Rules\MonthWindow;
use App\Domain\Rules\RuleCriteria;
use App\Domain\Rules\RuleVocabulary;
use PHPUnit\Framework\Attributes\Test;

/**
 * The chips, and taking one off — chips and criteria are the same statement
 * twice, round-tripped (docs/BUSINESS-LOGIC.md §11).
 */
final class ParsedRuleTest extends TestCase
{
    private RuleVocabulary $vocabulary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vocabulary = new RuleVocabulary(
            origins: ['AMS', 'EIN', 'DUS'],
            originAliases: ['ams' => 'AMS'],
            vibeWords: ['sunny' => ['sunny'], 'beach' => ['beach']],
            vibeLabels: ['sunny' => '☀ Sunny', 'beach' => '🏖 Beach'],
        );
    }

    private function full(): RuleCriteria
    {
        return new RuleCriteria(
            origins: ['AMS', 'EIN'],
            maxPriceCents: 8000,
            tripLengthNights: [2, 3],
            departDows: [5],
            dateWindow: MonthWindow::of(3, 5),
            vibes: ['sunny'],
        );
    }

    #[Test]
    public function every_criterion_comes_back_out_of_its_chips_unchanged(): void
    {
        $criteria = $this->full();

        $this->assertEquals(
            $criteria->toArray(),
            ParsedRule::of($criteria, $this->vocabulary)->criteria()->toArray(),
        );
    }

    #[Test]
    public function chips_are_ordered_the_way_the_design_reads_them(): void
    {
        $ids = array_map(
            static fn (RuleChip $chip): string => $chip->id,
            ParsedRule::of($this->full(), $this->vocabulary)->chips,
        );

        $this->assertSame([
            'origin:AMS', 'origin:EIN', 'max_price', 'trip_length', 'depart', 'date_window', 'vibe:sunny',
        ], $ids);
    }

    /**
     * The id is kind+value, never a position — an index would silently
     * remove the wrong chip across a re-parse (docs/BUSINESS-LOGIC.md §11).
     */
    #[Test]
    public function a_chips_id_does_not_move_when_the_chips_around_it_do(): void
    {
        $before = array_column(ParsedRule::of($this->full(), $this->vocabulary)->chips, 'id');

        $after = array_column(ParsedRule::of(new RuleCriteria(
            origins: ['EIN'],
            maxPriceCents: 8000,
            vibes: ['sunny'],
        ), $this->vocabulary)->chips, 'id');

        /* Three chips fewer, and the two that survive are still called the same thing. */
        $this->assertSame(['origin:AMS', 'origin:EIN', 'max_price', 'trip_length', 'depart', 'date_window', 'vibe:sunny'], $before);
        $this->assertSame(['origin:EIN', 'max_price', 'vibe:sunny'], $after);
    }

    #[Test]
    public function removing_one_origin_leaves_every_other_chip_alone(): void
    {
        $criteria = ParsedRule::of($this->full(), $this->vocabulary)
            ->without(['origin:EIN'])
            ->criteria();

        $this->assertSame(['AMS'], $criteria->origins);
        $this->assertSame(8000, $criteria->maxPriceCents);
        $this->assertSame([5], $criteria->departDows);
        $this->assertSame(['sunny'], $criteria->vibes);
        $this->assertNotNull($criteria->dateWindow);
    }

    /**
     * Removing WIDENS: a price ceiling taken off means any price, not no
     * results (docs/BUSINESS-LOGIC.md §11).
     */
    #[Test]
    public function removing_the_price_chip_removes_the_ceiling(): void
    {
        $criteria = ParsedRule::of($this->full(), $this->vocabulary)
            ->without(['max_price'])
            ->criteria();

        $this->assertNull($criteria->maxPriceCents);
        $this->assertFalse($criteria->isEmpty());
    }

    #[Test]
    public function removing_every_chip_leaves_a_rule_that_asks_for_nothing(): void
    {
        $parsed = ParsedRule::of($this->full(), $this->vocabulary);

        $ids = array_map(static fn (RuleChip $chip): string => $chip->id, $parsed->chips);

        $this->assertTrue($parsed->without($ids)->criteria()->isEmpty());
    }

    /**
     * Removed ids outlive the parse they came from — a stale id is the
     * ordinary case, not a bad request (docs/BUSINESS-LOGIC.md §11).
     */
    #[Test]
    public function an_id_for_a_chip_that_no_longer_exists_is_ignored(): void
    {
        $parsed = ParsedRule::of($this->full(), $this->vocabulary)->without(['origin:LIS', 'nonsense']);

        $this->assertCount(7, $parsed->chips);
    }

    #[Test]
    public function nothing_understood_is_a_rule_with_no_chips_and_empty_criteria(): void
    {
        $parsed = ParsedRule::nothing();

        $this->assertSame([], $parsed->chips);
        $this->assertTrue($parsed->criteria()->isEmpty());
    }

    #[Test]
    public function labels_are_the_words_the_design_puts_on_the_chips(): void
    {
        $labels = [];

        foreach (ParsedRule::of($this->full(), $this->vocabulary)->chips as $chip) {
            $labels[$chip->id] = $chip->label;
        }

        $this->assertSame('€80', $labels['max_price']);
        $this->assertSame('2–3 nights', $labels['trip_length']);
        $this->assertSame('Fridays', $labels['depart']);
        $this->assertSame('Mar – May', $labels['date_window']);
        $this->assertSame('☀ Sunny', $labels['vibe:sunny']);
    }

    #[Test]
    public function a_price_with_cents_keeps_them_and_a_whole_one_does_not(): void
    {
        $whole = ParsedRule::of(new RuleCriteria(maxPriceCents: 8000), $this->vocabulary)->chips[0];
        $part = ParsedRule::of(new RuleCriteria(maxPriceCents: 8050), $this->vocabulary)->chips[0];

        $this->assertSame('€80', $whole->label);
        $this->assertSame('€80.50', $part->label);
    }

    #[Test]
    public function several_departure_days_are_shortened_to_fit_a_phone(): void
    {
        $chip = ParsedRule::of(new RuleCriteria(departDows: [5, 6]), $this->vocabulary)->chips[0];

        $this->assertSame('Fri & Sat', $chip->label);
    }

    #[Test]
    public function a_single_night_is_singular(): void
    {
        $chip = ParsedRule::of(new RuleCriteria(tripLengthNights: [1, 1]), $this->vocabulary)->chips[0];

        $this->assertSame('1 night', $chip->label);
    }
}
