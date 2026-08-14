<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How and when this account wants to be told about a deal.
 *
 * ONE ROW PER ACCOUNT, CREATED ON FIRST READ — see for() below. There is no
 * seeder for it and deliberately so: a seeded row is a second place the
 * defaults live, and the one that is wrong after a deploy is always the copy.
 * The migration's column defaults are the only list, and this class re-reads
 * the row it just inserted rather than restating them.
 *
 * READ BY THE ALERT ENGINE, not only by the settings screen. minimumScore()
 * is the whole point of `sensitivity`: PR11 asks this object what score is
 * worth a notification instead of switching on 0/1/2 itself.
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
     * Eloquent's pluraliser would very likely land on `user_settings` anyway —
     * "settings" is already plural — but "very likely" is not a thing to leave
     * a table name to, and every other model in this app with a name that is
     * not its table says so out loud.
     */
    protected $table = 'user_settings';

    /**
     * This account's settings, creating the row the first time anybody asks.
     *
     * `first()` then `create()` rather than `firstOrCreate([...defaults])`,
     * because the defaults belong to the migration and passing them here would
     * copy them. The insert therefore carries nothing but the foreign key, the
     * database fills the rest in, and refresh() reads back what it chose — one
     * extra query, once per account, ever.
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
     * The deal score at or above which this account wants to hear about it.
     *
     * THE MAPPING IS CONFIG, NOT CODE. `sensitivity` is an ordered scale and
     * config/orbit.php names the tier each level fires on; the tier's actual
     * number is `score.tiers`, which is also what the API publishes as `tier`.
     * So the sensitivity a person picks and the badge they see on a route can
     * never mean different things.
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
     * The start of the quiet window as `HH:MM`.
     *
     * TRIMMED RATHER THAN PARSED. Postgres hands a `time` column back as
     * `22:00:00` and SQLite hands back whatever string was written to it, so
     * the stored value has one of two precisions depending on which engine is
     * underneath — the same difference App\Jobs\PollRoutePrices documents for
     * dates. Cutting at five characters normalises both without inventing a
     * date to hang the time off, which is what Carbon would have to do.
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
            'email_alerts' => 'boolean',
            'push_alerts' => 'boolean',
            'weekly_digest' => 'boolean',
            'quiet_hours' => 'boolean',
            'sensitivity' => 'integer',
        ];
    }
}
