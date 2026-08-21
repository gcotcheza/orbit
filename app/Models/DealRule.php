<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use App\Domain\Rules\RuleCriteria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A standing alert described in English (design/README.md §4); plain CRUD, hence a model.
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
     * What this rule is asking for — via RuleCriteria::from() every time,
     * not cached, since only the value object knows which stored shapes are real.
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
            'criteria'   => 'array',
            'active'     => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
