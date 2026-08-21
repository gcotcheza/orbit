<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use App\Application\Rules\RuleViews;
use App\Http\Requests\ParseRuleRequest;
use App\Application\Ports\RuleTextParser;
use App\Http\Resources\RuleReadingResource;

/**
 * "Here's what we understood" (design/README.md §4). A READ THAT IS A POST, so a 500-character
 * sentence stays out of every access log; rate-limited alone among the reads.
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
