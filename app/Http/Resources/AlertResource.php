<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of the alert history, as `GET /api/alerts` publishes it. Reads from `payload`, not the relations — the
 * ledger records what was SAID (docs/BUSINESS-LOGIC.md §10).
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
             * The route CODE, not an id — what every other endpoint keys on.
             * Null on the weekly digest, which is about no route in particular.
             */
            'route' => $this->text($alert, 'routeCode'),
            'rule'  => $this->rule($alert),

            'score' => $alert->score,
            'price' => $alert->price_cents === null ? null : Euros::from($alert->price_cents),

            /*
             * Two different questions: `triggeredAt` is when Orbit decided;
             * `deliveredAt` stays null while quiet hours hold a mail, or forever if off.
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
