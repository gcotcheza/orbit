<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Models\User;
use DateTimeInterface;
use App\Application\Alerts\AlertNotice;

/**
 * Where alerts go.
 *
 * ONE METHOD, AND NO CHANNEL IN IT. docs/PLAN.md has email first and web push
 * after the PWA shell, so the shape of this port is what decides whether the
 * second channel is a class or a refactor. It is a class: an adapter takes an
 * App\Application\Alerts\AlertNotice and decides for itself whether this
 * account wants to hear from it — `email_alerts` is a fact about mail,
 * `push_alerts` is a fact about push, and neither belongs to the evaluation
 * that produced the notice. Adding push is a class, a bind, and nothing else.
 *
 * `$alertIds` IS THE LEDGER ROWS THIS NOTICE IS THE DELIVERY OF, and it is here
 * rather than inside the notice because it belongs to the sending rather than
 * to the news: one notice can be several rows (a rule that matched four routes
 * is one mail), and the adapter's job is to stamp `delivered_at` on all of them
 * when — and only when — a transport has actually taken it.
 *
 * `$notBefore` IS QUIET HOURS, converted to an instant by
 * App\Application\Alerts\DeliveryWindow. NULL means send now. The port takes
 * the moment rather than the window because "after 08:00 Amsterdam" is a
 * question about a wall clock that has to be settled exactly once, and an
 * adapter that settled it again would be a second place for the answer to be
 * wrong twice a year.
 */
interface DealNotifier
{
    /**
     * @param  list<int>  $alertIds  the ledger rows to stamp on delivery
     * @param  DateTimeInterface|null  $notBefore  hold until this instant; null sends now
     */
    public function deliver(
        User $user,
        AlertNotice $notice,
        array $alertIds,
        ?DateTimeInterface $notBefore = null,
    ): void;
}
