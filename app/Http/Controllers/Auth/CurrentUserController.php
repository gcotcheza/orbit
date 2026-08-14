<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who is signed in.
 *
 * The SPA boots into an empty `#app` and has to decide, before it draws
 * anything, whether to show the globe or the login screen. This is that
 * decision: one round trip whose 200 and whose 401 are both answers.
 *
 * It reads the session the request already carries and asks nothing of the
 * database beyond the user the guard resolved, so it is cheap enough to be on
 * the critical path of every launch.
 */
final class CurrentUserController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return UserResource::make($request->user())->response();
    }
}
