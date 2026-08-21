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
     * The trips this account described in English, newest first. Separate from
     * `watchlistItems`, not a kind of it (docs/BUSINESS-LOGIC.md §11).
     *
     * @return HasMany<DealRule, $this>
     */
    public function dealRules(): HasMany
    {
        return $this->hasMany(DealRule::class)->latest('created_at')->latest('id');
    }

    /**
     * Everything Orbit has decided to tell this account, newest first. Ordered by
     * `triggered_at`, not `created_at` — the decision, not the write (docs/BUSINESS-LOGIC.md §10).
     *
     * @return HasMany<Alert, $this>
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class)->latest('triggered_at')->latest('id');
    }

    /**
     * How and when this account wants to be told about a deal. MAY NOT EXIST YET — use
     * UserSettings::for($user), which creates the row on first read.
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
