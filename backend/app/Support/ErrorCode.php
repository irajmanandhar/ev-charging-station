<?php

namespace App\Support;

/** Stable machine-readable error code for the API error envelope's top-level `code`. */
class ErrorCode
{
    public static function forStatus(int $status): string
    {
        return match (true) {
            $status === 401 => 'unauthenticated',
            $status === 403 => 'forbidden',
            $status === 404 => 'not_found',
            $status === 409 => 'conflict',
            $status === 422 => 'validation_failed',
            $status === 429 => 'too_many_requests',
            $status >= 500 => 'server_error',
            default => 'bad_request',
        };
    }
}
