<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

/**
 * The one account this app will ever have (docs/BUSINESS-LOGIC.md §18-19).
 *
 *   docker compose exec app php artisan db:seed --force
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

        // 24 chars, cryptographically random, all four character classes.
        $password = $supplied ?? Str::password(24);

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name'     => $name,
                'password' => $password, // hashed by the model's cast
                // No verification flow exists (docs/BUSINESS-LOGIC.md §36).
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
