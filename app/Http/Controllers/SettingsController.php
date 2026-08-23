<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Throwable;
use App\Models\User;
use App\Models\UserSettings;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Cache;
use App\Http\Requests\UpdateSettingsRequest;
use App\Http\Resources\UserSettingsResource;
use App\Infrastructure\Verify\GoogleFlightsCheck;

/**
 * How and when Orbit reaches the owner (design/README.md §6). Both actions answer the same
 * body, and the row is created on first read (docs/BUSINESS-LOGIC.md §36).
 */
final class SettingsController extends Controller
{
    private const CHECKS_KEY = 'settings.google-checks';

    private const CHECKS_MINUTES = 10;

    public function __construct(
        private readonly GoogleFlightsCheck $google,
        private readonly LoggerInterface $logger,
    ) {}

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
            ->additional(['meta' => [
                'sensitivities' => self::sensitivities(),
                'googleChecks'  => $this->googleChecks(),
            ]])
            ->response()
            ->setStatusCode(200);
    }

    /**
     * The SerpAPI month's remaining searches, for the "This app" card (docs/BUSINESS-LOGIC.md §31).
     * `checkedAt` is when Orbit last ASKED: null there means no key, not a failed probe.
     *
     * @return array{left: int|null, reserve: int, checkedAt: string|null}
     */
    private function googleChecks(): array
    {
        $reserve = $this->google->reserve();

        if (! $this->google->isConfigured()) {
            return ['left' => null, 'reserve' => $reserve, 'checkedAt' => null];
        }

        try {
            /* ⚠ The unknown answer is cached too: `remember` re-runs on a cached null, so a dead
               SerpAPI would stall EVERY settings load for the probe's timeout, not one in ten minutes. */
            /** @var array{left: int|null, checkedAt: string} $probe */
            $probe = Cache::remember(
                self::CHECKS_KEY,
                Date::now()->addMinutes(self::CHECKS_MINUTES),
                fn (): array => [
                    'left'      => $this->google->searchesLeft(),
                    'checkedAt' => Date::now()->setTimezone((string) config('orbit.timezone'))->toIso8601String(),
                ],
            );
        } catch (Throwable $e) {
            $this->logger->info('Could not read the SerpAPI quota for the settings screen.', ['error' => $e->getMessage()]);

            return ['left' => null, 'reserve' => $reserve, 'checkedAt' => null];
        }

        return ['left' => $probe['left'], 'reserve' => $reserve, 'checkedAt' => $probe['checkedAt']];
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
