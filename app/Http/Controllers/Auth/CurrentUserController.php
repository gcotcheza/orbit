<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;

/**
 * Who is signed in: one round trip whose 200 and whose 401 are both answers, so the SPA can
 * decide between the globe and the login screen before it draws anything.
 */
final class CurrentUserController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return UserResource::make($request->user())->response();
    }
}
