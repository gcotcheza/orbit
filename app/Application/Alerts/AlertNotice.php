<?php

declare(strict_types=1);

namespace App\Application\Alerts;

use App\Domain\Alerts\AlertType;

/**
 * Something Orbit has decided to say, in a form no channel is named in — which is what makes
 * a second channel additive. `subject()` is here, the payload is not (docs/BUSINESS-LOGIC.md §10).
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
