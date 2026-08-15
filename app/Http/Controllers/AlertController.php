<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\AlertResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What Orbit has told this account, newest first.
 *
 * THE ONE ENDPOINT IN THIS API WITH NO SCREEN YET. design/README.md §6 is
 * settings-only and stays that way in this PR — this exists because the alert
 * pipeline is otherwise entirely invisible from outside the database, and
 * "did it fire, and did it go out" is the first question anybody asks of it.
 * The history screen that will read this is a later PR; the contract is
 * docs/API.md, written before the screen as everything else here was.
 *
 * A LIMIT AND NOT A PAGE NUMBER. The list is strictly newest-first and is read
 * as "what happened lately" rather than browsed — an offset into a table that
 * grows at the top is a page that shifts under the reader between two requests.
 * `meta.total` says how much there is, so a client can tell whether it is
 * looking at everything.
 */
final class AlertController extends Controller
{
    /**
     * Rows returned when nobody says otherwise. A fortnight of a busy account.
     */
    private const DEFAULT_LIMIT = 20;

    /**
     * The most one request will return. Not a tuning knob in config/orbit.php:
     * it is part of the published contract (docs/API.md), and a number a client
     * is told it may send has to be the number the server actually accepts.
     */
    private const MAX_LIMIT = 50;

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ], [
            'limit.integer' => 'The limit is a number of rows.',
            'limit.min' => 'Ask for at least one row.',
            'limit.max' => 'Fifty rows is the most this endpoint returns at once.',
        ]);

        /** @var User $user */
        $user = $request->user();

        $limit = (int) ($validated['limit'] ?? self::DEFAULT_LIMIT);
        $alerts = $user->alerts()->limit($limit)->get();

        return AlertResource::collection($alerts)
            ->additional(['meta' => [
                'count' => $alerts->count(),
                'limit' => $limit,
                'total' => $user->alerts()->count(),
            ]])
            ->response();
    }
}
