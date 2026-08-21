<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How and when this account wants to be told about a deal (docs/BUSINESS-LOGIC.md §36).
 *
 * @property int $id
 * @property int $user_id
 * @property bool $email_alerts
 * @property bool $push_alerts
 * @property bool $weekly_digest
 * @property bool $quiet_hours
 * @property string $quiet_start wall clock in config('orbit.timezone')
 * @property string $quiet_end wall clock in config('orbit.timezone')
 * @property int $sensitivity 0 Relaxed | 1 Balanced | 2 Eager
 * @property-read User $user
 */
#[Fillable(['email_alerts', 'push_alerts', 'weekly_digest', 'quiet_hours', 'quiet_start', 'quiet_end', 'sensitivity'])]
final class UserSettings extends Model
{
    /*
     * Explicit, though Eloquent's pluraliser would likely guess right anyway —
     * "very likely" is not something to leave a table name to.
     */
    protected $table = 'user_settings';

    /**
     * This account's settings, creating the row the first time anybody asks.
     * `first()` then `create()`: defaults belong to the migration (docs/BUSINESS-LOGIC.md §36).
     */
    public static function for(User $user): self
    {
        $settings = $user->settings()->first();

        if ($settings !== null) {
            return $settings;
        }

        $created = $user->settings()->create();
        $created->refresh();

        return $created;
    }

    /**
     * The deal score at or above which this account wants to hear about it. The mapping is
     * config, tying sensitivity to the tier the API publishes (docs/BUSINESS-LOGIC.md §36).
     */
    public function minimumScore(): int
    {
        return self::minimumScoreFor($this->sensitivity);
    }

    public static function minimumScoreFor(int $level): int
    {
        /** @var array<int, array{name: string, tier: string, blurb: string}> $levels */
        $levels = config('orbit.alerts.sensitivities');
        /** @var array<string, int> $tiers */
        $tiers = config('orbit.score.tiers');

        return $tiers[$levels[$level]['tier']];
    }

    /**
     * The start of the quiet window as `HH:MM`. Trimmed, not parsed — Postgres and SQLite
     * return a `time` column at different precisions (docs/BUSINESS-LOGIC.md §36).
     */
    public function quietStartAt(): string
    {
        return self::clock($this->quiet_start);
    }

    public function quietEndAt(): string
    {
        return self::clock($this->quiet_end);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    private static function clock(string $stored): string
    {
        return mb_substr($stored, 0, 5);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_alerts'  => 'boolean',
            'push_alerts'   => 'boolean',
            'weekly_digest' => 'boolean',
            'quiet_hours'   => 'boolean',
            'sensitivity'   => 'integer',
        ];
    }
}
