<?php

declare(strict_types=1);

namespace App\Application\Alerts;

use App\Domain\Alerts\AlertType;

/**
 * Something Orbit has decided to say, in a form no channel is named in.
 *
 * THIS IS WHAT MAKES A SECOND CHANNEL ADDITIVE. App\Application\Ports\
 * DealNotifier takes one of these and nothing else, so the day docs/PLAN.md's
 * web push arrives it is a class implementing that port beside the mail one —
 * not a change here, not a change in the evaluation, and not a second
 * "what happened this morning" assembled for the other channel to render.
 *
 * `subject()` IS ON THE NOTICE AND NOT ON THE MAILABLE, deliberately. It is the
 * one line that has to work on a lock screen — a mail subject and a push title
 * are the same sentence, and writing it twice is how they come to disagree
 * about what today's deal was.
 *
 * THE PAYLOAD IS NOT HERE, because it is not one per notice: a rule that
 * matched four routes is four ledger rows and one mail (see
 * App\Application\Alerts\AlertEvaluation). The rows are written from the
 * App\Application\Alerts\DealSummary objects the notice carries, before the
 * notice is handed over.
 */
interface AlertNotice
{
    /**
     * Which kind of alert this is — the mail adapter's gate reads it to know
     * which of the account's switches applies.
     */
    public function type(): AlertType;

    /**
     * The subject line. Forty-odd characters that say whether to open it.
     */
    public function subject(): string;
}
