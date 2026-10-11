<?php

namespace App\Http\Controllers\Api\V1\System;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/health — liveness/connectivity probe (architecture §24).
 * The `database` flag only reflects connectivity at probe time; no tables
 * are touched.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = false;
        try {
            DB::connection()->getPdo();
            $database = true;
        } catch (\Throwable) {
            $database = false;
        }

        return ApiResponse::success([
            'status' => 'ok',
            'database' => $database,
            'timestamp' => now()->toIso8601String(),
        ], $database ? 'Service healthy.' : 'Service up; database unreachable.', $database ? 200 : 200);
    }
}
