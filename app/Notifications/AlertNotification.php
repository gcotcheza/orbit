<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Alerts\CarriesAlerts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * What the three Orbit mails have in common: they are queued, they go by mail,
 * and each one settles some rows of the alert ledger.
 *
 * QUEUED IS NOT AN OPTIMISATION HERE, it is the feature. `ShouldQueue` is what
 * makes `->delay()` mean anything at all, and `->delay()` is how quiet hours
 * work: a deal found at 06:55 inside a 22:00–08:00 window is a job that sits on
 * redis until 08:00 rather than a mail somebody's phone lights up for in the
 * dark. Without this interface the delay is silently ignored and the whole
 * quiet-hours setting becomes decoration.
 *
 * THE MAIL COPY IS NOT HERE. Each subclass renders its own markdown from its
 * own App\Application\Alerts\AlertNotice, because the three say genuinely
 * different things; what is shared is the plumbing, and the plumbing is all
 * that is shared.
 */
abstract class AlertNotification extends Notification implements CarriesAlerts, ShouldQueue
{
    use Queueable;

    /**
     * PROTECTED AND NOT PRIVATE, AND THAT IS NOT A STYLE CHOICE. Laravel's
     * Notification base class uses SerializesModels, whose `__serialize()`
     * walks `(new ReflectionClass($this))->getProperties()` — and reflection
     * does not report a PRIVATE property declared on a PARENT class. A private
     * one here is therefore silently dropped on the way to the queue and
     * uninitialized when the worker picks the job up, which surfaces as a fatal
     * inside the delivery listener rather than as a missing mail.
     *
     * @param  list<int>  $alertIds
     */
    public function __construct(protected readonly array $alertIds) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * @return list<int>
     */
    public function alertIds(): array
    {
        return $this->alertIds;
    }
}
