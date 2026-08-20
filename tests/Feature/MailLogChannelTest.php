<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The staged rollout can be read.
 *
 * Verifies the dedicated mail log channel actually catches what the old single/info setup silently dropped.
 *
 * Uses a throwaway plain-ASCII notification so assertions aren't about MIME header encoding.
 * Why: docs/BUSINESS-LOGIC.md §10.
 */
final class MailLogChannelTest extends TestCase
{
    use RefreshDatabase;

    private const SUBJECT = 'Orbit is watching AMS to Porto';

    private string $mailLog;

    private string $appLog;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // Not the real paths: a test must not append to a file the app is
        // keeping, and `storage/logs/mail.log` is the file the runbook tails.
        $this->mailLog = storage_path('logs/testing-mail.log');
        $this->appLog = storage_path('logs/testing-app.log');

        $this->removeLogs();

        $this->owner = User::factory()->create(['email' => 'owner@orbit.test']);

        config([
            // phpunit.xml pins the array mailer for every other test in the
            // suite. This one is about the log mailer, so it says so.
            'mail.default'               => 'log',
            'mail.mailers.log.channel'   => 'mail',
            'logging.channels.mail.path' => $this->mailLog,
            // PRODUCTION'S FLOOR, reproduced. `info` is what .env carries, and
            // it is the whole reason the dedicated channel exists.
            'logging.channels.single.path'  => $this->appLog,
            'logging.channels.single.level' => 'info',
        ]);

        $this->rebuildManagers();
    }

    protected function tearDown(): void
    {
        $this->removeLogs();

        parent::tearDown();
    }

    #[Test]
    public function a_mail_sent_through_the_log_mailer_lands_in_the_mail_channel(): void
    {
        $this->owner->notify($this->notification());

        $log = $this->contentsOf($this->mailLog);

        $this->assertStringContainsString('Subject: '.self::SUBJECT, $log);
        $this->assertStringContainsString('To: owner@orbit.test', $log);

        // DEBUG, which is the level the whole problem turns on: this record
        // only exists because the channel's floor is low enough for it.
        $this->assertStringContainsString('.DEBUG:', $log);
    }

    /**
     * The arrangement this replaces, asserted rather than described — otherwise
     * the channel above is a change nobody can tell was needed.
     */
    #[Test]
    public function the_application_channel_at_production_level_would_have_dropped_it(): void
    {
        // No channel of the log mailer's own: the message goes to the default
        // `stack`, i.e. `single`, at LOG_LEVEL=info.
        config(['mail.mailers.log.channel' => null]);
        $this->rebuildManagers();

        $this->owner->notify($this->notification());

        $this->assertStringNotContainsString(self::SUBJECT, $this->contentsOf($this->appLog));

        // AND IT IS THE FLOOR THAT DROPPED IT, not a mail that failed to send.
        // The same notification, the same file, one level lower.
        config(['logging.channels.single.level' => 'debug']);
        $this->rebuildManagers();

        $this->owner->notify($this->notification());

        $this->assertStringContainsString('Subject: '.self::SUBJECT, $this->contentsOf($this->appLog));
    }

    /**
     * A minimal mail notification. See the class docblock for why it is not one
     * of Orbit's own.
     */
    private function notification(): Notification
    {
        return new class(self::SUBJECT) extends Notification
        {
            public function __construct(private readonly string $subject) {}

            /**
             * @return list<string>
             */
            public function via(object $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(object $notifiable): MailMessage
            {
                return (new MailMessage)
                    ->subject($this->subject)
                    ->line('A fare fell far enough to be worth an interruption.');
            }
        };
    }

    /**
     * MailManager and LogManager each cache what they built from config, so a
     * config change either has already read does nothing without this.
     */
    private function rebuildManagers(): void
    {
        Mail::forgetMailers();

        foreach (['mail', 'single', 'stack'] as $channel) {
            Log::forgetChannel($channel);
        }
    }

    private function contentsOf(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function removeLogs(): void
    {
        foreach ([$this->mailLog, $this->appLog] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
