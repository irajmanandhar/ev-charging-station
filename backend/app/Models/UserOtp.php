<?php

namespace App\Models;

use App\Models\User;
use App\Models\PendingUser;
use App\Notifications\EmailVerificationOtp;
use App\Notifications\PasswordResetOtpNotification;
use Illuminate\Database\Eloquent\Model;

class UserOtp extends Model
{
    protected $fillable = [
        'email',
        'otp',
        'type',
        'expires_at',
        'is_used',
        'attempts',
    ];

    /** The plain code, only available on the instance returned by generateOtp(). */
    public ?string $plain = null;

    protected $casts = [
        'expires_at' => 'datetime',
        'is_used' => 'boolean'
    ];

    /**
     * Generate a new OTP for the given email and send notification
     */
    /**
     * Extra response data for local development only: echoes the OTP so the
     * sign-up flow can be completed without a mail server.
     */
    public static function devPayload(self $otp, array $base = []): ?array
    {
        if (config('ev.expose_otp') && app()->environment('local', 'testing')) {
            $base['dev_otp'] = $otp->plain;
        }

        return $base ?: null;
    }

    public static function generateOtp($email, $type = 'email_verification')
    {
        // Wrong guesses carry over to the replacement code, so asking for a new code
        // can't be used to get a fresh set of guesses.
        $carried = (int) self::where('email', $email)
            ->where('type', $type)
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->max('attempts');

        self::where('email', $email)
            ->where('type', $type)
            ->where('is_used', false)
            ->delete();

        $otp = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        $otpRecord = self::create([
            'email' => $email,
            'otp' => self::digest($email, $type, $otp),
            'type' => $type,
            'expires_at' => now()->addMinutes((int) config('ev.otp_ttl_minutes')),
            'is_used' => false,
            'attempts' => $carried,
        ]);

        $otpRecord->plain = $otp;

        self::sendOtpNotification($email, $otp, $type);

        return $otpRecord;
    }

    /**
     * Send OTP notification based on type
     */
    private static function sendOtpNotification($email, $otp, $type)
    {
        $user = User::where('email', $email)->first();

        if (!$user && $type === 'email_verification') {
            $user = PendingUser::where('email', $email)->first();
        }

        if (!$user) {
            return;
        }

        switch ($type) {
            case 'email_verification':
                $user->notify(new EmailVerificationOtp($otp));
                break;
            case 'password_reset':
                $user->notify(new PasswordResetOtpNotification($otp));
                break;
        }
    }

    /** Codes are stored keyed-hashed, so a database leak doesn't expose live codes. */
    private static function digest(string $email, string $type, string $otp): string
    {
        return hash_hmac('sha256', "{$email}|{$type}|{$otp}", (string) config('app.key'));
    }

    /**
     * Verify OTP. Wrong guesses are counted per code; after MAX_ATTEMPTS the code is dead.
     */
    public static function verifyOtp($email, $otp, $type = 'email_verification', $markUsed = true)
    {
        $record = self::where('email', $email)
            ->where('type', $type)
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        $max = (int) config('ev.otp_max_attempts');
        if (! $record || $record->attempts >= $max) {
            return false;
        }

        if (! hash_equals($record->otp, self::digest($email, $type, (string) $otp))) {
            // Atomic: parallel guesses can't squeeze past the limit.
            self::whereKey($record->id)->where('attempts', '<', $max)->increment('attempts');

            return false;
        }

        if ($markUsed) {
            $record->update(['is_used' => true]);
        }

        return true;
    }
}
