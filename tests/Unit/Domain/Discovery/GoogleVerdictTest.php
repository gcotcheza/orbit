<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Discovery;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use App\Domain\Discovery\GoogleVerdict;

/**
 * The rule that decides whether a card may say "verified" — the only place
 * Orbit puts another company's name on its own claim (docs/BUSINESS-LOGIC.md §16).
 */
final class GoogleVerdictTest extends TestCase
{
    /**
     * DO NOT let `confirmsCheap()` return true here — that means the badge
     * is vouching for itself again (docs/BUSINESS-LOGIC.md §16).
     */
    #[Test]
    public function it_refuses_to_confirm_a_fare_google_cannot_find(): void
    {
        $measured = [
            /* route, our price, Google's own cheapest, level, typical low, typical high */
            ['DUS-AGP', 2900, 7000, 'typical', 5500, 17500],
            ['DUS-RAK', 2700, 16800, 'typical', 10000, 20000],
            ['EIN-VNO', 1800, 3000, 'typical', 2000, 24500],
        ];

        foreach ($measured as [$route, $ours, $googleLowest, $level, $low, $high]) {
            $verdict = new GoogleVerdict($level, $googleLowest, $low, $high);

            $this->assertLessThan(
                $low,
                $ours,
                sprintf('%s: the naive rule would have confirmed this.', $route),
            );

            $this->assertFalse(
                $verdict->confirmsCheap(),
                sprintf('%s: we say €%d, Google itself cannot go below €%d.', $route, $ours / 100, $googleLowest / 100),
            );
        }
    }

    #[Test]
    public function google_saying_low_is_enough_on_its_own(): void
    {
        $verdict = new GoogleVerdict('low', 4800, 5500, 17500);

        $this->assertTrue($verdict->confirmsCheap());
    }

    // The other half: Google's own cheapest at/under its typical-band low
    // counts as confirmation too (docs/BUSINESS-LOGIC.md §16).
    #[Test]
    public function googles_own_cheapest_under_its_typical_low_is_also_enough(): void
    {
        $this->assertTrue((new GoogleVerdict('typical', 5000, 5500, 17500))->confirmsCheap());
        $this->assertTrue((new GoogleVerdict('typical', 5500, 5500, 17500))->confirmsCheap(), 'At the boundary counts.');
        $this->assertFalse((new GoogleVerdict('typical', 5600, 5500, 17500))->confirmsCheap());
    }

    // A thin route with no `price_insights` at all — "no opinion" is a real
    // answer, not permission to confirm (docs/BUSINESS-LOGIC.md §16).
    #[Test]
    public function no_opinion_confirms_nothing(): void
    {
        $this->assertFalse((new GoogleVerdict(null, null, null, null))->confirmsCheap());
        $this->assertFalse((new GoogleVerdict('typical', null, 5500, 17500))->confirmsCheap());
        $this->assertFalse((new GoogleVerdict('typical', 5000, null, null))->confirmsCheap());
    }

    #[Test]
    public function high_is_never_confirmation(): void
    {
        $this->assertFalse((new GoogleVerdict('high', 20000, 5500, 17500))->confirmsCheap());
    }

    // `confirmed` is stored, not recomputed, so a rule retuned next month
    // can't silently restate what last month's cards claimed.
    #[Test]
    public function it_stores_the_facts_and_the_conclusion(): void
    {
        $verdict = new GoogleVerdict('low', 4800, 5500, 17500);

        $this->assertSame([
            'level'        => 'low',
            'lowest'       => 4800,
            'typical_low'  => 5500,
            'typical_high' => 17500,
            'confirmed'    => true,
        ], $verdict->toArray());
    }
}
