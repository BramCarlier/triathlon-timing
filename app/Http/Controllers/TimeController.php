<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Server clock sample for browsers. Clients measure the round trip and take the
 * midpoint, so taps are stamped on the server's timeline rather than the device clock.
 */
class TimeController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['now_ms' => (int) floor(microtime(true) * 1000)])
            ->header('Cache-Control', 'no-store');
    }
}
