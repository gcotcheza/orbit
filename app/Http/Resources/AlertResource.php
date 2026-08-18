<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of the alert history, as `GET /api/alerts` publishes it.
 *
 * ALMOST EVERYTHING HERE COMES OUT OF `payload` RATHER THAN OFF THE RELATIONS,
 * and that is the point of the endpoint. The ledger is a record of what was
 * SAID: a row from March quotes March's price and March's percentage under
 * usual, and reading those back through today's route would quietly rewrite the
 * history to agree with today's statistics. It also means a rule that has since
 * been deleted still explains the mails it caused — `deal_rules` rows go away
 * (docs/API.md), and the alerts they produced do not.
 *
 * `summary` IS THE STORED HEADLINE, i.e. the subject line that actually landed
 * on somebody's phone, not a sentence re-rendered from the parts. See
 * App\Application\Alerts\DealSummary.
 */
final class AlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Alert $alert */
        $alert = $this->resource;

        return [
            'id'   => $alert->id,
            'type' => $alert->type->value,

            /*
             * The route CODE and not an id, because that is what every other
             * endpoint in this API keys on and what a client would navigate to.
             * Null on the weekly digest, which is about no route in particular.
             */
            'route' => $this->text($alert, 'routeCode'),
            'rule'  => $this->rule($alert),

            'score' => $alert->score,
            'price' => $alert->price_cents === null ? null : Euros::from($alert->price_cents),

            /*
             * TWO TIMESTAMPS, AND THEY ARE DIFFERENT QUESTIONS. `triggeredAt`
             * is when Orbit decided; `deliveredAt` is when a channel took it,
             * and stays null while quiet hours hold a mail — or forever, if the
             * account has that channel switched off.
             */
            'triggeredAt' => $alert->triggered_at->toIso8601String(),
            'deliveredAt' => $alert->delivered_at?->toIso8601String(),

            'summary' => $this->text($alert, 'headline'),
        ];
    }

    /**
     * @return array{id: int|null, text: string|null, chips: list<string>}|null
     */
    private function rule(Alert $alert): ?array
    {
        $rule = $alert->payload['rule'] ?? null;

        if (! is_array($rule)) {
            return null;
        }

        $id = $rule['id'] ?? null;
        $text = $rule['text'] ?? null;
        $chips = $rule['chips'] ?? null;

        return [
            'id'    => is_int($id) ? $id : null,
            'text'  => is_string($text) ? $text : null,
            'chips' => is_array($chips) ? array_values(array_filter($chips, 'is_string')) : [],
        ];
    }

    private function text(Alert $alert, string $key): ?string
    {
        $value = $alert->payload[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
