<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserSettings;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\UpdateSettingsRequest;
use App\Http\Resources\UserSettingsResource;

/**
 * How and when Orbit reaches the owner (design/README.md §6). Both actions answer the same
 * body, and the row is created on first read (docs/BUSINESS-LOGIC.md §36).
 */
final class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->present(UserSettings::for($user));
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $settings = UserSettings::for($user);
        $settings->update($request->toColumns());

        return $this->present($settings);
    }

    /**
     * ALWAYS 200, PINNED — a JsonResource answers 201 when wasRecentlyCreated, which is
     * wrong here: the row appearing on first read is not a thing the client made.
     */
    private function present(UserSettings $settings): JsonResponse
    {
        return UserSettingsResource::make($settings)
            ->additional(['meta' => ['sensitivities' => self::sensitivities()]])
            ->response()
            ->setStatusCode(200);
    }

    /**
     * The three levels of the segmented control, sent with every response rather than baked
     * into the screen — a hardcoded "80+" would drift from config's `score.tiers`.
     *
     * @return list<array{level: int, name: string, minimumScore: int, blurb: string}>
     */
    private static function sensitivities(): array
    {
        /** @var array<int, array{name: string, tier: string, blurb: string}> $levels */
        $levels = config('orbit.alerts.sensitivities');

        $described = [];

        foreach ($levels as $level => $meta) {
            $minimum = UserSettings::minimumScoreFor($level);

            $described[] = [
                'level'        => $level,
                'name'         => $meta['name'],
                'minimumScore' => $minimum,
                'blurb'        => sprintf($meta['blurb'], $minimum),
            ];
        }

        return $described;
    }
}
