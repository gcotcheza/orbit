<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Rules\RuleCriteria;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A standing alert described in English (design/README.md §4).
 *
 * PLAIN CRUD, WHICH IS WHY IT IS AN ELOQUENT MODEL AND NOT A REPOSITORY —
 * docs/PLAN.md is explicit that Eloquent is used directly for this half. The
 * one thing worth a method is `criteria()`, which hands the JSON column to the
 * value object rather than letting every caller re-learn the shape.
 *
 * @property int $id
 * @property int $user_id
 * @property string $raw_text
 * @property array<string, mixed> $criteria
 * @property bool $active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'raw_text', 'criteria', 'active'])]
final class DealRule extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What this rule is asking for.
     *
     * Through RuleCriteria::from() every time rather than a cached property:
     * the column is JSON somebody's browser once produced, and the value
     * object is the only thing that knows which of its shapes are real.
     */
    public function criteria(): RuleCriteria
    {
        return RuleCriteria::from($this->criteria);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
