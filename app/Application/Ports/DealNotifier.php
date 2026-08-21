<?php

declare(strict_types=1);

namespace App\Application\Ports;

use App\Models\User;
use DateTimeInterface;
use App\Application\Alerts\AlertNotice;

/**
 * Where alerts go: one method, no channel in it, so a second channel is a class and a bind.
 * `$alertIds` belong to the sending; `$notBefore` is quiet hours (docs/BUSINESS-LOGIC.md §10).
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
