<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use App\Domain\Discovery\RouteBaseline;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * What Orbit last measured a route's usual price to be — the relative lane's
 * memory.
 *
 * THE ONLY THING IN DISCOVERY THAT PERSISTS ACROSS RUNS. A `discoveries` row is
 * deliberately ephemeral (36 hours, then gone); this is the opposite and has to
 * be, because it is the entire reason the lane gets better rather than repeating
 * itself. Every window the lane fetches leaves one of these behind, including —
 * especially — the windows that produced no card at all.
 *
 * IT IS NOT A CACHE OF `calendar_fares` AND MUST NOT GROW INTO ONE. See the
 * migration for the three things that break if this becomes a `routes`-keyed
 * window: the watchlist's notion of "pairs this app knows about", the 201 from
 * `POST /api/routes/lookup`, and — the serious one — the rule engine reading
 * discovery's data and mailing somebody about it. One number per route, plus the
 * two facts that say whether it may be believed.
 *
 * NO USER, LIKE `discoveries` AND FOR THE SAME REASON (docs/BUSINESS-LOGIC.md
 * §1). What a route usually costs is a fact about the world.
 *
 * @property int $id
 * @property string $code "AMS-DUB"
 * @property int $median_cents
 * @property int $sample_days
 * @property CarbonImmutable $measured_at
 */
#[Fillable(['code', 'median_cents', 'sample_days', 'measured_at'])]
final class DiscoveryBaseline extends Model
{
    /**
     * Laravel would pluralise this correctly, and it is named anyway.
     *
     * The same one-line insurance App\Models\Discovery buys: the table is named
     * in a migration, a job and three tests, and an inflector disagreeing with
     * any of them is a "relation does not exist" on the first deploy.
     */
    protected $table = 'discovery_baselines';

    /**
     * The domain's shape of this row.
     *
     * THE MODEL IS THE STORAGE AND App\Domain\Discovery\RouteBaseline IS THE
     * RULE — the same split every other pure type in this app has from the table
     * behind it. The selector does arithmetic on baselines and must be testable
     * without a database, so the conversion happens here, once, at the boundary.
     */
    public function toDomain(): RouteBaseline
    {
        return new RouteBaseline(
            code: $this->code,
            medianCents: $this->median_cents,
            sampleDays: $this->sample_days,
            measuredAt: $this->measured_at->toDateTimeImmutable(),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'median_cents' => 'integer',
            'sample_days'  => 'integer',
            'measured_at'  => 'immutable_datetime',
        ];
    }
}
