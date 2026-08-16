<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Discovery;

use App\Domain\Discovery\GoogleVerdict;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rule that decides whether a card may say "verified", and the three real
 * measurements that decided what the rule had to be.
 *
 * THIS IS THE MOST IMPORTANT TEST IN THE FEATURE. Everything else here is a
 * ranking, and a bad ranking shows somebody a mediocre fare. This is the only
 * place Orbit puts ANOTHER COMPANY'S NAME on its own claim, and getting it
 * wrong means the badge means nothing — which is worse than not having one.
 */
final class GoogleVerdictTest extends TestCase
{
    /**
     * =========================================================================
     * THE REGRESSION THAT DEFINES THE RULE
     * =========================================================================
     * Three finalists, put to Google Flights on 2026-08-16, the same day the
     * sweep was recorded. Every one of them is under its typical-range low; not
     * one of them is a fare Google can actually sell.
     *
     * If this test ever goes green with `confirmsCheap()` returning true, the
     * verdict has been rewritten to read the candidate's own price — and the
     * badge has gone back to being Orbit vouching for itself.
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

    /**
     * The other half of the rule: Google has not used the word, but its own
     * cheapest seat is at or under the bottom of its typical band — which is
     * the same statement made with numbers.
     */
    #[Test]
    public function googles_own_cheapest_under_its_typical_low_is_also_enough(): void
    {
        $this->assertTrue((new GoogleVerdict('typical', 5000, 5500, 17500))->confirmsCheap());
        $this->assertTrue((new GoogleVerdict('typical', 5500, 5500, 17500))->confirmsCheap(), 'At the boundary counts.');
        $this->assertFalse((new GoogleVerdict('typical', 5600, 5500, 17500))->confirmsCheap());
    }

    /**
     * A thin route comes back with no `price_insights` at all — EIN-VNO's
     * sibling shape on the same afternoon. "No opinion" is a real answer and it
     * is not permission.
     */
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

    /**
     * `confirmed` is stored so that a rule retuned next month cannot silently
     * restate what last month's cards claimed — the same argument
     * App\Http\Resources\AlertResource makes for reading a stored payload.
     */
    #[Test]
    public function it_stores_the_facts_and_the_conclusion(): void
    {
        $verdict = new GoogleVerdict('low', 4800, 5500, 17500);

        $this->assertSame([
            'level' => 'low',
            'lowest' => 4800,
            'typical_low' => 5500,
            'typical_high' => 17500,
            'confirmed' => true,
        ], $verdict->toArray());
    }
}
