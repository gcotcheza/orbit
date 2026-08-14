<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSettingsRequest;
use App\Http\Resources\UserSettingsResource;
use App\Models\User;
use App\Models\UserSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * How and when Orbit reaches the owner (design/README.md §6).
 *
 * BOTH ACTIONS ANSWER THE SAME BODY, which is the contract the screen is built
 * on: it PUTs the object it is holding and renders whatever comes back, so a
 * value the server clamped or normalised lands on screen without a follow-up
 * GET. That is also what makes the optimistic switches safe — the response is
 * the truth, and the screen adopts it.
 *
 * THE ROW IS CREATED ON FIRST READ (App\Models\UserSettings::for) rather than
 * by a seeder. One account today, and an account that has never opened this
 * screen still has to have settings the alert engine can read — so "the
 * settings exist" is a property of asking for them, not of a deploy having
 * run.
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
     * ALWAYS 200, PINNED. A JsonResource answers 201 when the model behind it
     * `wasRecentlyCreated`, which is right for a resource somebody asked to
     * create and wrong for both of these: the row appearing on first read is
     * an implementation detail of storage, not a thing the client made, and a
     * PUT that answered 201 the first time and 200 afterwards would be a
     * status code that describes the database rather than the request.
     */
    private function present(UserSettings $settings): JsonResponse
    {
        return UserSettingsResource::make($settings)
            ->additional(['meta' => ['sensitivities' => self::sensitivities()]])
            ->response()
            ->setStatusCode(200);
    }

    /**
     * The three levels of the segmented control, described.
     *
     * SENT WITH EVERY RESPONSE RATHER THAN BAKED INTO THE SCREEN. Each blurb
     * quotes the score its level fires at, and that number is config's
     * (`score.tiers`, via UserSettings::minimumScoreFor) — a "80+" typed into
     * a Vue template is a sentence that goes quietly wrong the day the tier is
     * retuned, on the one screen whose whole job is to explain what will
     * happen.
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
                'level' => $level,
                'name' => $meta['name'],
                'minimumScore' => $minimum,
                'blurb' => sprintf($meta['blurb'], $minimum),
            ];
        }

        return $described;
    }
}
