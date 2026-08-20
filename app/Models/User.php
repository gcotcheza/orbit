<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The routes this account asked to be told about, in the order the
     * watchlist screen shows them.
     *
     * @return HasMany<WatchlistItem, $this>
     */
    public function watchlistItems(): HasMany
    {
        return $this->hasMany(WatchlistItem::class)->orderBy('position')->orderBy('id');
    }

    /**
     * The trips this account described in English (design/README.md §4),
     * newest first — a rule somebody just wrote is the one they want to see.
     *
     * Separate from `watchlistItems`, not a kind of it (docs/PLAN.md): a rule finds unwatched routes; promoting a match is
     * a deliberate tap, not automatic (docs/BUSINESS-LOGIC.md §11).
     *
     * @return HasMany<DealRule, $this>
     */
    public function dealRules(): HasMany
    {
        return $this->hasMany(DealRule::class)->latest('created_at')->latest('id');
    }

    /**
     * Everything Orbit has decided to tell this account, newest first.
     *
     * Ordered by `triggered_at`, not `created_at`: they diverge on a retried run, and this must reflect when the DECISION
     * was made, not written (docs/BUSINESS-LOGIC.md §10).
     *
     * @return HasMany<Alert, $this>
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class)->latest('triggered_at')->latest('id');
    }

    /**
     * How and when this account wants to be told about a deal.
     *
     * MAY NOT EXIST YET — use UserSettings::for($user), which creates the row on first read. This relation exists for that
     * method and for eager-loading (docs/BUSINESS-LOGIC.md §36).
     *
     * @return HasOne<UserSettings, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(UserSettings::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }
}
