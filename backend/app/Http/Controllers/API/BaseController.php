<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class BaseController extends Controller
{
    /** Standard success envelope: { success, message, data }. */
    public function sendResponse(mixed $data, ?string $message = null, int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message ?? 'OK',
            'data' => $data,
        ], $code);
    }

    /** Standard error envelope: { success, message, errors }. */
    public function sendError(string $message = 'Something went wrong', int $code = 400, array $errors = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => (object) $errors,
        ], $code);
    }
}
