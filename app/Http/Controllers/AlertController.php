<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Resources\AlertResource;

/**
 * What Orbit has told this account, newest first — the one endpoint with no screen yet.
 * A limit, not a page number: an offset into a table that grows at the top shifts.
 */
final class AlertController extends Controller
{
    /**
     * Rows returned when nobody says otherwise. A fortnight of a busy account.
     */
    private const DEFAULT_LIMIT = 20;

    /**
     * The most one request will return. Not a tuning knob: it is part of the published
     * contract (docs/API.md), and a client must be told the number the server accepts.
     */
    private const MAX_LIMIT = 50;

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ], [
            'limit.integer' => 'The limit is a number of rows.',
            'limit.min'     => 'Ask for at least one row.',
            'limit.max'     => 'Fifty rows is the most this endpoint returns at once.',
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
