<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * `POST /api/routes/lookup` — every rule it has is RoutePairRequest's. It deliberately does
 * NOT inherit "you are already watching AMS-LIS": looking is not adding (docs/API.md).
 */
final class LookupRouteRequest extends RoutePairRequest {}
