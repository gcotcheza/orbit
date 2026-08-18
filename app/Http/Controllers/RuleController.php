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
 * EVERY ANSWER IS THE ROW IN THE LIST'S OWN SHAPE, the same contract
 * WatchlistItemController keeps, so a screen replaces the rule it was holding
 * rather than re-fetching. That matters most for the pause toggle: the
 * response is what the server actually believes, including a match count that
 * may have moved since the row was drawn.
 *
 * CREATING A RULE QUEUES A SWEEP AND DOES NOT WAIT FOR IT. A brand-new rule
 * names routes Orbit has never priced, so its honest match count on the moment
 * of creation is often zero and fills in a minute later — the same day-1
 * honesty a new watchlist route has (docs/API.md). Running the sweep inline
 * would put thirty provider calls behind one tap.
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
         * A RULE THAT ASKS FOR NOTHING IS REFUSED, and not because the text
         * was empty. Empty criteria mean "every fare from every airport to
         * everywhere, at any price" — a rule that would match hundreds of
         * routes and alert on all of them, which is not a deal tracker, it is
         * a firehose. It is also the exact state somebody reaches by removing
         * every chip, so the message names the way out.
         */
        if ($criteria->isEmpty()) {
            throw ValidationException::withMessages([
                'text' => 'Orbit could not read a trip out of that. Try naming a price, a season, a day or what the trip is for.',
            ]);
        }

        $rule = DealRule::query()->create([
            'user_id' => $user->id,
            /*
             * The TRIMMED text, and the criteria AFTER the removals — the two
             * are deliberately not the same reading. See the migration for why
             * both are stored and why loading a rule never re-parses its text.
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
         * A rule that has just been switched back on may have been asleep for
         * weeks, so its routes are re-swept rather than left to the next
         * morning. Pausing queues nothing — there is nothing to find out about
         * a rule nobody is listening to.
         */
        if ($rule->active) {
            SweepRuleFares::dispatch($rule->id);
        }

        return RuleResource::make($views->of($rule, $user))->response();
    }

    /**
     * Drop a rule. 204 — there is nothing left to describe.
     *
     * THE ROUTES IT SURFACED SURVIVE, and so do their fares. Every one of them
     * cost a provider call, several may be on the watchlist by now, and a rule
     * is a question rather than a possession — deleting the question does not
     * unask what it already found out.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        self::rule($user, $id)->delete();

        return new JsonResponse(status: 204);
    }

    /**
     * This account's rule, or a 404 that says so.
     *
     * SCOPED TO THE USER rather than merely filtered by id. There is one
     * account today; the row this returns is the one about to be written to,
     * and "whose is it" is not a question a write should answer by assuming.
     */
    private static function rule(User $user, int $id): DealRule
    {
        // abort() rather than firstOrFail(), like the rest of this app: the
        // framework's own 404 body names an internal class.
        return $user->dealRules()->whereKey($id)->first() ?? abort(404, 'No such rule.');
    }
}
