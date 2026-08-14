<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The one account this app will ever have.
 *
 *   docker compose exec app php artisan db:seed --force
 *
 * The password is GENERATED and printed once. It is not in this file, not in
 * `.env.example`, and not recoverable afterwards — the column stores a bcrypt
 * hash and nothing else. Losing it means running this seeder again with
 * `SEED_USER_PASSWORD` set, which is a deliberate rotation rather than a
 * recovery flow. (There is no password-reset route: docs/PLAN.md locks Orbit
 * to a single user, and a reset flow for one account is an email-dependent
 * attack surface bought with nothing.)
 *
 * RE-RUNNING IS SAFE, and that is the property that matters: an existing user
 * keeps their password unless one is explicitly supplied. That is what makes
 * it safe to leave in a deploy script without silently locking the owner out
 * on every release.
 *
 * The values come from `config/orbit.php`, which is where the SEED_USER_*
 * variables are read. Not from env() here: `config:cache` stops env() working
 * outside a config file, and a seeder that quietly falls back to its defaults
 * on a cached deploy creates the WRONG account.
 */
final class SingleUserSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        /** @var string $email */
        $email = config('orbit.seed.email');
        /** @var string $name */
        $name = config('orbit.seed.name');
        /** @var string|null $supplied */
        $supplied = config('orbit.seed.password');

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null && $supplied === null) {
            $this->command?->info("User {$email} already exists; password left unchanged.");

            return;
        }

        // Str::password() is cryptographically random and mixes all four
        // character classes; 24 characters is well past anything a throttled
        // login could be attacked with.
        $password = $supplied ?? Str::password(24);

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password, // hashed by the model's cast
                // There is no verification flow and never will be — see
                // routes/web.php — so the one account must not be left waiting
                // for a mail it will never be sent.
                'email_verified_at' => now(),
            ]
        );

        $this->command?->newLine();
        $this->command?->info(sprintf('User %s <%s> is ready.', $name, $email));

        if ($supplied === null) {
            $this->command?->warn('Generated password (shown once, stored nowhere):');
            $this->command?->line('  '.$password);
        }

        $this->command?->newLine();
    }
}
