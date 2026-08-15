<?php

declare(strict_types=1);

namespace App\Domain\Rules;

/**
 * A place, as the matcher needs it: what it is for and how warm it gets.
 *
 * The two editorial judgements from
 * database/seeders/data/european_destinations.php, lifted off the Eloquent
 * model so App\Domain\Rules\RuleMatcher can stay pure PHP. Built by
 * App\Application\Rules\RuleMatches from App\Models\Destination.
 */
final readonly class DestinationProfile
{
    /**
     * @param  list<string>  $vibes  from the closed nine-word vocabulary
     * @param  array<int, int>  $warmth  month number (1-12) => 1 (cold) to 5 (beach)
     */
    public function __construct(
        public string $iata,
        public array $vibes,
        public array $warmth,
    ) {}

    /**
     * How many of the asked-for vibes this place carries.
     *
     * A COUNT AND NOT A BOOLEAN, because it is also the sort key: asked for
     * "sunny beach", a place tagged both should be swept and shown before a
     * place tagged one of them. See RuleMatcher::rank().
     *
     * @param  list<string>  $vibes
     */
    public function vibeOverlap(array $vibes): int
    {
        return count(array_intersect($vibes, $this->vibes));
    }

    /**
     * The best this place gets across some months. Zero for a month nobody
     * rated, which sorts below every real answer rather than above it.
     *
     * @param  list<int>  $months
     */
    public function bestWarmth(array $months): int
    {
        $ratings = array_map(fn (int $month): int => $this->warmth[$month] ?? 0, $months);

        return $ratings === [] ? 0 : max($ratings);
    }
}
