<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * Central API response envelope (PHASE4 architecture §6.1).
 *
 * Every endpoint returns:
 *   { "success": bool, "message": string, "data": mixed, "meta": ?array, "errors": ?array }
 *
 * Money values are always strings with 2 decimals (never float) — see the
 * per-model `decimal:2` casts.
 */
final class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'OK', int $status = 200, ?array $meta = null): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => $meta,
            'errors' => null,
        ], $status);
    }

    public static function created(mixed $data = null, string $message = 'Created', ?array $meta = null): JsonResponse
    {
        return self::success($data, $message, 201, $meta);
    }

    /**
     * Error envelope. For field validation errors pass the field->messages map
     * as $errors; for business-rule failures pass a stable machine-readable
     * $code (architecture §7), e.g. EP_NOT_EDITABLE.
     */
    public static function error(string $message, string $code = 'ERROR', int $status = 422, mixed $errors = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'meta' => null,
            'errors' => $errors ?? ['code' => [$code]],
        ], $status);
    }
}
