<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

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
     * SEPARATE FROM `watchlistItems` AND NOT A KIND OF IT. docs/PLAN.md is
     * explicit that rules and the watchlist are different concepts: a rule
     * finds routes nobody is watching yet, and promoting one of its matches to
     * the watchlist is a deliberate tap rather than something a rule does on
     * the owner's behalf.
     *
     * @return HasMany<DealRule, $this>
     */
    public function dealRules(): HasMany
    {
        return $this->hasMany(DealRule::class)->latest('created_at')->latest('id');
    }

    /**
     * How and when this account wants to be told about a deal.
     *
     * MAY NOT EXIST YET, which is why almost nothing should use this relation
     * directly: UserSettings::for($user) is the accessor that creates the row
     * on first read. The relation is here because that method needs it, and
     * because a later screen wanting settings eager-loaded should be able to
     * ask for them by name.
     *
     * @return HasOne<UserSettings, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(UserSettings::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
