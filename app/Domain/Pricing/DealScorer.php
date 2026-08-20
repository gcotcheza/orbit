<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

// Deal score = weighted blend of percentile (60), trend (25), absolute-floor (15) components (config/orbit.php), each renormalised over what's computable; missing/insufficient history (below `$policy->minTrackingDays`) returns noOpinion() rather than a false-confident 100.
// Why: docs/BUSINESS-LOGIC.md §7.
final readonly class DealScorer
{
    public function __construct(private ScoringPolicy $policy = new ScoringPolicy) {}

    // The "can't judge yet" answer, distinct from score(0,...) (0 cents would score 100); the same sentence covers zero history and a few days of it, per SpotlightCard.vue's `verdict.label` contract. "Pricing" not "Watching" since PR #29 (lookup can reach this without watching).
    // Why: docs/BUSINESS-LOGIC.md §7.
    public function noOpinion(): DealScore
    {
        // Pill label is 'New', not 'Normal': "no data yet" and "judged ordinary" used to share a label and were indistinguishable on the watchlist. 'New' is a state, not a verdict, so tone stays TONE_NORMAL.
        // Why: docs/BUSINESS-LOGIC.md §7.
        $verdict = new Verdict('Not enough data yet', 'New', Verdict::TONE_NORMAL);

        return new DealScore(
            score: 0,
            tier: ScoringPolicy::TIER_NONE,
            verdict: $verdict,
            advice: new Advice(
                $verdict->label,
                'Orbit has only just started pricing this route. A few more days of prices and this becomes a real verdict.',
                $verdict->tone,
            ),
            confident: false,
        );
    }

    /**
     * @param  int  $trackingDays  mornings of this route's own prices Orbit
     *                             actually holds — App\Application\Routes\
     *                             RouteSnapshot::$trackingDays, counted from the
     *                             first observation and not from when the route
     *                             was added
     */
    public function score(int $currentCents, ?PriceStats $stats, PriceHistory $history, int $trackingDays): DealScore
    {
        // `$trackingDays` is required, not defaulted: a default would silently claim an uncounted caller was looking at a mature route — the exact bug this guard prevents.
        // Why: docs/BUSINESS-LOGIC.md §7.
        if ($trackingDays < $this->policy->minTrackingDays) {
            return $this->noOpinion();
        }

        $drift = $history->lastDays($this->policy->trendDays)->dailyDrift();

        $components = [
            [$stats !== null ? (1.0 - $stats->percentileOf($currentCents)) * 100.0 : null, $this->policy->percentileWeight],
            [$drift !== null ? $this->trendComponent($drift) : null, $this->policy->trendWeight],
            [$stats !== null ? $this->absoluteComponent($currentCents, $stats) : null, $this->policy->absoluteWeight],
        ];

        $weighted = 0.0;
        $weight = 0.0;

        foreach ($components as [$value, $componentWeight]) {
            if ($value === null || $componentWeight <= 0.0) {
                continue;
            }

            $weighted += $value * $componentWeight;
            $weight += $componentWeight;
        }

        if ($weight <= 0.0) {
            return $this->noOpinion();
        }

        $score = max(0, min(100, (int) round($weighted / $weight)));
        $verdict = $this->verdict($score, $currentCents, $stats, $drift);

        return new DealScore(
            score: $score,
            tier: $this->policy->tierFor($score),
            verdict: $verdict,
            advice: $this->advice($verdict, $currentCents, $stats),
            confident: true,
        );
    }

    // Trend component is linear (50=flat, saturating to 0/100): a curve would claim knowledge about fare behaviour this app doesn't have.
    // Why: docs/BUSINESS-LOGIC.md §7.
    private function trendComponent(float $drift): float
    {
        return max(0.0, min(100.0, 50.0 - ($drift / $this->policy->trendSaturationPerDay) * 50.0));
    }

    // 100 at the floor, 0 at usual price and above, NULL when floor==usual (not a division guard — an answer meaning "nothing to measure", so weight falls to other components).
    // Why: docs/BUSINESS-LOGIC.md §7.
    private function absoluteComponent(int $currentCents, PriceStats $stats): ?float
    {
        $span = $stats->medianCents - $stats->minCents;

        if ($span <= 0) {
            return null;
        }

        return max(0.0, min(1.0, ($stats->medianCents - $currentCents) / $span)) * 100.0;
    }

    // Threshold is tied to trendSaturationPerDay, not its own constant, so tuning trend sensitivity can't make the word "falling" disagree with the number shown beside it.
    // Why: docs/BUSINESS-LOGIC.md §7.
    private function isFalling(?float $drift): bool
    {
        return $drift !== null && $drift <= -0.2 * $this->policy->trendSaturationPerDay;
    }

    private function verdict(int $score, int $currentCents, ?PriceStats $stats, ?float $drift): Verdict
    {
        $falling = $this->isFalling($drift);

        if ($score >= $this->policy->greatAt) {
            return $falling
                ? new Verdict('Cheap & still falling', 'Falling', Verdict::TONE_INFO)
                : new Verdict('Good price — book', 'Good', Verdict::TONE_GOOD);
        }

        if ($score >= $this->policy->goodAt) {
            return $falling
                ? new Verdict('Falling — worth watching', 'Falling', Verdict::TONE_INFO)
                : new Verdict('Around normal', 'Normal', Verdict::TONE_NORMAL);
        }

        if ($stats !== null && $currentCents > $stats->usualCents()) {
            return new Verdict('Above usual — wait', 'Wait', Verdict::TONE_WARN);
        }

        return new Verdict('Around normal', 'Normal', Verdict::TONE_NORMAL);
    }

    private function advice(Verdict $verdict, int $currentCents, ?PriceStats $stats): Advice
    {
        $price = self::euros($currentCents);

        if ($stats === null) {
            // Trend-only: we know which way it is going and nothing about
            // whether it is cheap, so the sentence must not imply otherwise.
            return new Advice($verdict->label, sprintf(
                'No usual price for this route yet, so this is a read on the direction alone: %s right now.',
                $price,
            ), $verdict->tone);
        }

        $usual = self::euros($stats->usualCents());
        $gap = abs($stats->percentUnderUsual($currentCents));

        $body = match ($verdict->tone) {
            Verdict::TONE_GOOD => sprintf('%s is %d%% under its usual %s — a solid time to lock it in.', $price, $gap, $usual),
            Verdict::TONE_INFO => sprintf('%s against a usual %s, and still sliding — waiting a few days could pay off.', $price, $usual),
            Verdict::TONE_WARN => sprintf('%s is %d%% above its usual %s. Hold off — fares this far up tend to settle back.', $price, $gap, $usual),
            default            => sprintf('%s sits close to its usual %s. No rush, and no bargain either.', $price, $usual),
        };

        return new Advice($verdict->label, $body, $verdict->tone);
    }

    // Whole euros lose the decimals: every fare this app has ever shown is a whole number, and "€52.00" in a sentence reads like a receipt.
    private static function euros(int $cents): string
    {
        return $cents % 100 === 0
            ? '€'.intdiv($cents, 100)
            : '€'.number_format($cents / 100, 2, '.', '');
    }
}
