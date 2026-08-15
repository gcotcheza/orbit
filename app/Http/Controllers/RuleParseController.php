<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Ports\RuleTextParser;
use App\Application\Rules\RuleViews;
use App\Http\Requests\ParseRuleRequest;
use App\Http\Resources\RuleReadingResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * "Here's what we understood" (design/README.md §4).
 *
 * A READ THAT IS A POST, which is the one thing worth explaining here. It
 * changes nothing — nothing is written and nothing is queued — but the
 * sentence is a 500-character free-text field and putting it in a query string
 * would mean the owner's rule ending up in every access log and browser
 * history between here and the phone. The body is also what the create call
 * takes, so the screen sends the same object to both.
 *
 * IT IS RATE-LIMITED, alone among this app's reads. Today it runs a handful of
 * regexes and could take any number of calls; the moment an Anthropic key
 * exists it becomes a metered third-party request behind a 500 ms debounce,
 * and a limiter added on that day would be a limiter tuned in a hurry. See
 * App\Providers\AppServiceProvider for the number.
 */
final class RuleParseController extends Controller
{
    public function __invoke(ParseRuleRequest $request, RuleTextParser $parser, RuleViews $views): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $parsed = $parser->parse($request->text())->without($request->removed());

        return RuleReadingResource::make($views->read($parsed, $user))->response();
    }
}
