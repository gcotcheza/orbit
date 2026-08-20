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
 * The only thing in Discovery that persists across runs (a `discoveries` row is ephemeral, 36 hours then gone); every
 * window the lane fetches leaves one behind.
 *
 * Not a cache of `calendar_fares`: stays one number per route, not a `routes`-keyed window. See the migration for what
 * breaks if it grows into one (docs/BUSINESS-LOGIC.md §16).
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
     * Laravel would pluralise this correctly; named explicitly anyway, so an inflector disagreement with the
     * migration/job/tests fails fast, not as "relation does not exist" (docs/BUSINESS-LOGIC.md §16).
     */
    protected $table = 'discovery_baselines';

    /**
     * The model is storage; RouteBaseline is the rule — conversion happens here, once, so the selector's arithmetic stays
     * testable without a database (docs/BUSINESS-LOGIC.md §16).
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
