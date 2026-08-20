<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;
use Database\Seeders\SingleUserSeeder;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The seeder that creates Orbit's one account. Idempotence is the point:
 * getting it wrong silently rotates the owner's password on a deploy.
 * Why: docs/BUSINESS-LOGIC.md §36.
 *
 * Driven through config('orbit.seed.*'), not env vars directly — that's
 * what the seeder reads, so `config:cache` can't quietly empty it.
 * Why: docs/BUSINESS-LOGIC.md §36.
 */
final class SingleUserSeederTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'seeded@orbit.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'orbit.seed.email'    => self::EMAIL,
            'orbit.seed.name'     => 'Seeded Owner',
            'orbit.seed.password' => null,
        ]);
    }

    #[Test]
    public function it_creates_the_account_the_configuration_describes(): void
    {
        $this->seed(SingleUserSeeder::class);

        $user = User::query()->sole();

        $this->assertSame(self::EMAIL, $user->email);
        $this->assertSame('Seeded Owner', $user->name);
        $this->assertNotNull(
            $user->email_verified_at,
            'There is no verification flow, so the one account must not be left waiting for one.'
        );
    }

    #[Test]
    public function running_it_twice_leaves_one_user_with_the_same_password(): void
    {
        $this->seed(SingleUserSeeder::class);

        $hash = User::query()->sole()->password;

        $this->seed(SingleUserSeeder::class);

        $this->assertSame(1, User::query()->count());
        $this->assertSame(
            $hash,
            User::query()->sole()->password,
            'A deploy must not rotate the owner out of their own app.'
        );
    }

    /**
     * `.env`'s empty `SEED_USER_PASSWORD=` becomes an empty string, not null —
     * config/orbit.php is where that collapse is caught, so it's loaded raw here
     * rather than asserting on setUp()'s already-loaded config.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    #[Test]
    public function an_empty_password_variable_is_read_as_absent(): void
    {
        $previous = $_ENV['SEED_USER_PASSWORD'] ?? null;

        $_ENV['SEED_USER_PASSWORD'] = '';
        $_SERVER['SEED_USER_PASSWORD'] = '';
        putenv('SEED_USER_PASSWORD=');

        try {
            /** @var array{seed: array{password: string|null}} $config */
            $config = require base_path('config/orbit.php');

            $this->assertNull($config['seed']['password']);
        } finally {
            if (is_string($previous)) {
                $_ENV['SEED_USER_PASSWORD'] = $previous;
                $_SERVER['SEED_USER_PASSWORD'] = $previous;
                putenv("SEED_USER_PASSWORD={$previous}");
            } else {
                unset($_ENV['SEED_USER_PASSWORD'], $_SERVER['SEED_USER_PASSWORD']);
                putenv('SEED_USER_PASSWORD');
            }
        }
    }

    #[Test]
    public function an_explicit_password_rotates_the_existing_one(): void
    {
        $this->seed(SingleUserSeeder::class);

        $hash = User::query()->sole()->password;

        config(['orbit.seed.password' => 'a-deliberately-chosen-password']);
        $this->seed(SingleUserSeeder::class);

        $user = User::query()->sole();

        $this->assertSame(1, User::query()->count());
        $this->assertNotSame($hash, $user->password);
        $this->assertTrue(Hash::check('a-deliberately-chosen-password', $user->password));
    }

    /**
     * A generated password is printed once — the bcrypt hash is its only other
     * copy. A supplied password is never echoed; the caller already has it.
     * Why: docs/BUSINESS-LOGIC.md §36.
     */
    #[Test]
    public function a_generated_password_is_printed_and_a_supplied_one_is_not(): void
    {
        $this->pendingSeed()
            ->expectsOutputToContain('Generated password')
            ->assertSuccessful()
            ->run();

        config(['orbit.seed.password' => 'a-deliberately-chosen-password']);

        $this->pendingSeed()
            ->doesntExpectOutputToContain('Generated password')
            ->assertSuccessful()
            ->run();
    }

    #[Test]
    public function the_default_seeder_creates_the_account(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::query()->count());
        $this->assertSame(self::EMAIL, User::query()->sole()->email);
    }

    /**
     * `artisan()` returns a pending command only before it runs (then an exit
     * code); narrowing here keeps that visible instead of chaining a union.
     */
    private function pendingSeed(): PendingCommand
    {
        $command = $this->artisan('db:seed', ['--class' => SingleUserSeeder::class]);

        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }
}
