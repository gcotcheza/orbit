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
 * ===========================================================================
 * WHAT THIS IS ABOUT
 *
 * Orbit ships with `MAIL_MAILER=log` on purpose (.env.example): until
 * ghiecode.io is a verified sending domain, every alert the app decides to send
 * is written down instead of delivered, so that the firing rules can be judged
 * against real fares before anybody's phone lights up in the dark.
 *
 * That stage was invisible. Symfony's log transport writes each message at
 * DEBUG level; production's default channel is `single` with a floor of
 * `LOG_LEVEL=info`. So the mail was rendered, handed to Monolog, and dropped —
 * with no error anywhere, because nothing failed. The deploy runbook told
 * whoever was on the box to tail `laravel.log` for alert mail that was never
 * going to be in it.
 *
 * The fix is a `mail` channel of its own at `debug` (config/logging.php) with
 * `MAIL_LOG_CHANNEL` pointed at it. This file holds both halves: that a mail
 * now lands there, and that the arrangement it replaces really did swallow it.
 *
 * ===========================================================================
 * WHY THE NOTIFICATION IS A THROWAWAY AND NOT ONE OF ORBIT'S THREE
 *
 * Every real subject this app sends starts with a plane, an arrow or an em
 * dash ("✈ AMS→OPO €44 — 53% below usual"), and a non-ASCII header is written
 * into a MIME message RFC 2047-encoded — `=?utf-8?Q?=E2=9C=88...`. Asserting on
 * that would make this a test about header encoding, which is Symfony's job and
 * is not the thing in doubt. What is in doubt is whether a message that reached
 * the transport reaches a file somebody can read, so the subject here is plain
 * ASCII and appears verbatim.
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
     * Both managers cache what they built from the configuration as it was.
     * MailManager holds the transport (and therefore the channel the transport
     * writes to) and LogManager holds the channel itself, so a config change
     * either of them has already read is a change to nothing at all.
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
