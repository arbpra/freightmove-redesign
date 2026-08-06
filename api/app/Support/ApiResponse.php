<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

/**
 * The response envelope defined in docs/06-api-spec.md section 9.
 *
 * Every endpoint answers with { success, data, message } or
 * { success, message, errors } so the Angular client only parses one shape.
 */
class ApiResponse
{
    public static function success(mixed $data = null, string $message = '', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
        ], $status);
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    public static function error(string $message, array $errors = [], int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => (object) $errors,
        ], $status);
    }
}
