<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user, as the SPA is allowed to see them.
 *
 * Three columns. Not `$user->toArray()`, because that is a list of whatever
 * `users` happens to hold — today `email_verified_at`, `remember_token`'s
 * absence and two timestamps, tomorrow whatever a migration adds — and an
 * endpoint whose response shape is decided by the schema is one that leaks the
 * next column somebody adds. This names what the client needs.
 *
 * It is shared by POST /login and GET /api/me so the two cannot drift: the SPA
 * stores the same object whether it just signed in or was already signed in.
 *
 * Laravel's `data` wrapper is left ON. It costs one level of nesting and it is
 * what leaves room for `meta` on the collection endpoints the price screens
 * will need, rather than a second response convention appearing later.
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
        ];
    }
}
