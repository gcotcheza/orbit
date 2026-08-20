<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\DealRule;
use App\Jobs\SweepRuleFares;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Application\Rules\RuleView;
use App\Application\Rules\RuleViews;
use App\Http\Resources\RuleResource;
use App\Http\Requests\ParseRuleRequest;
use App\Http\Requests\UpdateRuleRequest;
use App\Application\Ports\RuleTextParser;
use Illuminate\Validation\ValidationException;

/**
 * The owner's standing rules: listing them, writing one, pausing one, dropping
 * one (design/README.md §4 and the rules section of §5).
 *
 * Every answer is the row's own shape, matching WatchlistItemController's contract.
 *
 * Creating a rule queues a sweep and does not wait for it — day-1 honesty.
 * Why: docs/BUSINESS-LOGIC.md §11.
 */
final class RuleController extends Controller
{
    /**
     * Every rule this account has, newest first, each with what it matches
     * right now.
     */
    public function index(Request $request, RuleViews $views): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $rules = $views->for($user->dealRules()->get(), $user);

        return RuleResource::collection($rules)
            ->additional(['meta' => [
                'count'  => count($rules),
                'active' => count(array_filter($rules, static fn (RuleView $view): bool => $view->rule->active)),
            ]])
            ->response();
    }

    /**
     * Save the rule currently on the create screen. 201, with the row.
     */
    public function store(ParseRuleRequest $request, RuleTextParser $parser, RuleViews $views): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $text = trim($request->text());
        $criteria = $parser->parse($text)->without($request->removed())->criteria();

        /*
         * A rule with empty criteria matches every fare everywhere — refused as a firehose, not merely for empty text
         * (docs/BUSINESS-LOGIC.md §11).
         */
        if ($criteria->isEmpty()) {
            throw ValidationException::withMessages([
                'text' => 'Orbit could not read a trip out of that. Try naming a price, a season, a day or what the trip is for.',
            ]);
        }

        $rule = DealRule::query()->create([
            'user_id' => $user->id,
            /*
             * Trimmed text and post-removal criteria — deliberately not the same reading.
             * Why: docs/BUSINESS-LOGIC.md §11.
             */
            'raw_text' => $text,
            'criteria' => $criteria->toArray(),
            'active'   => true,
        ]);

        SweepRuleFares::dispatch($rule->id);

        return RuleResource::make($views->of($rule, $user))->response()->setStatusCode(201);
    }

    /**
     * Pause a rule or start it again. Its text, its criteria and its place in
     * the list all stay.
     */
    public function update(UpdateRuleRequest $request, int $id, RuleViews $views): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $rule = self::rule($user, $id);
        $rule->update(['active' => $request->boolean('active')]);

        /*
         * Resuming re-sweeps immediately rather than waiting for the next
         * morning; pausing queues nothing.
         */
        if ($rule->active) {
            SweepRuleFares::dispatch($rule->id);
        }

        return RuleResource::make($views->of($rule, $user))->response();
    }

    /**
     * Drop a rule. 204 — there is nothing left to describe.
     *
     * The routes it surfaced survive, and so do their fares — deleting the question doesn't unask what it found.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        self::rule($user, $id)->delete();

        return new JsonResponse(status: 204);
    }

    /**
     * This account's rule, or a 404 that says so — scoped to the user, not just the id.
     * Why: docs/BUSINESS-LOGIC.md §11.
     */
    private static function rule(User $user, int $id): DealRule
    {
        // abort() rather than firstOrFail(), like the rest of this app: the
        // framework's own 404 body names an internal class.
        return $user->dealRules()->whereKey($id)->first() ?? abort(404, 'No such rule.');
    }
}
