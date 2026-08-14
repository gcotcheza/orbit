<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * Is this a good price? — the question the whole app exists to answer.
 *
 * ZERO FRAMEWORK IMPORTS, by design (docs/PLAN.md): the scoring rule is the
 * one piece of Orbit that is genuinely ours rather than plumbing, and it is
 * kept where it can be read, reasoned about and unit-tested without a
 * container, a database or a clock. Everything it needs arrives as arguments.
 *
 * THE THREE COMPONENTS, each 0-100, combined at the weights in
 * config/orbit.php (60 / 25 / 15):
 *
 *   1. PERCENTILE (60) — where the fare sits in the route's own price
 *      distribution. This is the bulk of the answer because it is the only
 *      component that knows what OTHER prices on this route look like: €71 is
 *      a bargain to Reykjavík and a rip-off to Düsseldorf, and only the
 *      statistics know which route you are on.
 *
 *   2. TREND (25) — which way our own last 30 days are moving. It is what
 *      separates "cheap, book it" from "cheap and still falling, wait", and
 *      it is deliberately the smaller weight: a falling price is a reason to
 *      hesitate, not a reason to call something a deal.
 *
 *   3. ABSOLUTE (15) — how close to the route's own floor the fare is. The
 *      percentile component saturates near the bottom (everything below p25
 *      scores about the same), and this is what still separates "as cheap as
 *      this route has ever been" from "merely cheap".
 *
 * MISSING INPUTS SHRINK THE QUESTION RATHER THAN THE ANSWER. A route with no
 * history yet is scored on the two components that do not need it, with the
 * weights renormalised over what was computable — not scored out of 75 and
 * quietly capped. A route with nothing at all gets score 0 and
 * `confident: false`, which the UI is expected to render as "no opinion yet"
 * rather than as a bad deal.
 */
final readonly class DealScorer
{
    public function __construct(private ScoringPolicy $policy = new ScoringPolicy) {}

    /**
     * The answer for a route we have no price for at all — one that was added
     * an hour ago and has not been polled yet.
     *
     * A DISTINCT METHOD rather than `score(0, null, empty)`, because a price
     * of zero is the cheapest fare imaginable and would score 100. "We do not
     * know" and "it is free" are not the same answer and must not share a
     * code path.
     */
    public function noOpinion(): DealScore
    {
        $verdict = new Verdict('Not enough data yet', 'Normal', Verdict::TONE_NORMAL);

        return new DealScore(
            score: 0,
            tier: ScoringPolicy::TIER_NONE,
            verdict: $verdict,
            advice: new Advice(
                $verdict->label,
                'Orbit has only just started watching this route. A few more days of prices and this becomes a real verdict.',
                $verdict->tone,
            ),
            confident: false,
        );
    }

    public function score(int $currentCents, ?PriceStats $stats, PriceHistory $history): DealScore
    {
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

    /**
     * 50 is flat. Falling faster than the configured saturation pins it at
     * 100, rising that fast pins it at 0, and everything between is linear —
     * a curve here would be a claim about how fares behave that we have no
     * data to make.
     */
    private function trendComponent(float $drift): float
    {
        return max(0.0, min(100.0, 50.0 - ($drift / $this->policy->trendSaturationPerDay) * 50.0));
    }

    /**
     * 100 at the route's own floor, 0 at its usual price and above. NULL when
     * the floor and the usual price are the same number.
     *
     * The half of the distribution ABOVE the median is deliberately all zero:
     * this component's job is to grade bargains, and the percentile component
     * is already grading everything else.
     *
     * THE NULL IS NOT A DIVISION GUARD, it is an answer. A route whose min and
     * median coincide has never been cheaper than usual, so "how close to its
     * floor is this" has nothing to measure — and both 0 and 100 would be a
     * claim. Returning null lets the weight fall to the components that do
     * know something, the same way a missing history does.
     */
    private function absoluteComponent(int $currentCents, PriceStats $stats): ?float
    {
        $span = $stats->medianCents - $stats->minCents;

        if ($span <= 0) {
            return null;
        }

        return max(0.0, min(1.0, ($stats->medianCents - $currentCents) / $span)) * 100.0;
    }

    /**
     * A drift small enough that a person would call the price "steady".
     *
     * Tied to the saturation rather than being its own constant so that
     * turning the trend sensitivity up in config does not leave the WORD
     * "falling" meaning something different from the number next to it.
     */
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
            default => sprintf('%s sits close to its usual %s. No rush, and no bargain either.', $price, $usual),
        };

        return new Advice($verdict->label, $body, $verdict->tone);
    }

    /**
     * Cents to the string a person reads. Whole euros lose the decimals,
     * because every fare this app has ever shown is a whole number of euros
     * and "€52.00" in a sentence reads like a receipt.
     */
    private static function euros(int $cents): string
    {
        return $cents % 100 === 0
            ? '€'.intdiv($cents, 100)
            : '€'.number_format($cents / 100, 2, '.', '');
    }
}
