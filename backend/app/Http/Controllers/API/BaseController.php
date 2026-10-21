<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Support\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

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

    /** Success envelope for a page of results: { success, message, data: [...], meta: { current_page, last_page, per_page, total } }. */
    public function sendPage(LengthAwarePaginator $page, string $resource, ?string $message = null): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message ?? 'OK',
            'data' => $resource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** Standard error envelope: { success, message, errors }. */
    public function sendError(string $message = 'Something went wrong', int $code = 400, array $errors = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'code' => ErrorCode::forStatus($code),
            'errors' => (object) $errors,
        ], $code);
    }
}
