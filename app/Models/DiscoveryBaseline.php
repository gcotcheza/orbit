<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use App\Domain\Discovery\RouteBaseline;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * What Orbit last measured a route's usual price to be (docs/BUSINESS-LOGIC.md §16).
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
     * Laravel would pluralise this correctly; named explicitly anyway, so an inflector
     * disagreement fails fast rather than as "relation does not exist".
     */
    protected $table = 'discovery_baselines';

    /**
     * The model is storage; RouteBaseline is the rule. Converting here, once, keeps the
     * selector's arithmetic testable without a database.
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
