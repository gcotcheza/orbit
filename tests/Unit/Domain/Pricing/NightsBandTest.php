<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Pricing;

use PHPUnit\Framework\TestCase;
use App\Domain\Pricing\NightsBand;
use PHPUnit\Framework\Attributes\Test;

/**
 * How long you would stay, and what that is called on screen
 * (docs/BUSINESS-LOGIC.md §15, docs/API.md `returns[].band.label`).
 */
final class NightsBandTest extends TestCase
{
    /** The four shipped bands, in the words design/README.md §2 prints. */
    #[Test]
    public function each_configured_band_is_named_the_way_the_screen_says_it(): void
    {
        $this->assertSame('A long weekend', (new NightsBand(2, 3))->label());
        $this->assertSame('A week away', (new NightsBand(6, 8))->label());
        $this->assertSame('A fortnight', (new NightsBand(13, 15))->label());
        $this->assertSame('Three to four weeks', (new NightsBand(21, 28))->label());
    }

    /** A retuned band has no name yet, and says its nights rather than another band's name. */
    #[Test]
    public function a_band_nobody_has_named_falls_back_to_its_own_nights(): void
    {
        $this->assertSame('9–12 nights', (new NightsBand(9, 12))->label());
        $this->assertSame('2–4 nights', (new NightsBand(2, 4))->label());
    }
}
