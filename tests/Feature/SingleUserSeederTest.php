<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SingleUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The seeder that creates Orbit's one account.
 *
 * IDEMPOTENCE IS THE POINT OF THESE TESTS. This runs on every deploy, so the
 * question that matters is not "does it create a user" — it is "what does it do
 * to the user who is already there". Getting that wrong does not fail loudly:
 * it rotates the owner's password during a release, and the only symptom is
 * being unable to sign in, at which point the new password has already scrolled
 * past in a deploy log nobody kept.
 *
 * Driven through `config('orbit.seed.*')` rather than by setting environment
 * variables, because that is what the seeder reads — config/orbit.php is the
 * one place SEED_USER_* is turned into a value, precisely so that
 * `config:cache` cannot quietly empty it.
 */
final class SingleUserSeederTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'seeded@orbit.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'orbit.seed.email' => self::EMAIL,
            'orbit.seed.name' => 'Seeded Owner',
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
     * `.env` ships `SEED_USER_PASSWORD=` with nothing after it, and env() hands
     * that back as an EMPTY STRING rather than as null. Reading it as "set the
     * password to nothing" would replace a working password with an unusable
     * hash on the next deploy, which is the exact failure this seeder exists to
     * avoid.
     *
     * config/orbit.php is where that collapse happens — it is the boundary that
     * reads the raw variable — so the file is evaluated directly here. Asserting
     * on the already-loaded value would only re-read what setUp() just put
     * there and prove nothing.
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
     * The generated password is printed once and stored nowhere else — the
     * column holds a bcrypt hash, so that line of console output is the only
     * copy of it that will ever exist. A supplied one is not echoed back,
     * because whoever supplied it already has it and a deploy log does not need
     * a second copy.
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
     * `artisan()` returns the pending command only while output expectations
     * are still allowed; once it has run it is an exit code. Narrowing it here
     * keeps that distinction visible instead of chaining off a union.
     */
    private function pendingSeed(): PendingCommand
    {
        $command = $this->artisan('db:seed', ['--class' => SingleUserSeeder::class]);

        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }
}
