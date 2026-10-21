<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Signed, short-lived `state` value for the social login round trip. The login is stateless
 * (no session), so the state is self-verifying: "expiry.nonce.signature".
 */
class SocialState
{
    private const TTL_SECONDS = 600;

    public static function issue(): string
    {
        $payload = (now()->timestamp + self::TTL_SECONDS).'.'.Str::random(16);

        return $payload.'.'.self::sign($payload);
    }

    public static function valid(string $state): bool
    {
        $parts = explode('.', $state);
        if (count($parts) !== 3 || ! ctype_digit($parts[0])) {
            return false;
        }

        return hash_equals(self::sign($parts[0].'.'.$parts[1]), $parts[2]) && (int) $parts[0] >= now()->timestamp;
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', 'social-state|'.$payload, (string) config('app.key'));
    }
}
