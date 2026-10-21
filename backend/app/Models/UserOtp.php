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
        'is_used'
    ];

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
            $base['dev_otp'] = $otp->otp;
        }

        return $base ?: null;
    }

    public static function generateOtp($email, $type = 'email_verification')
    {
        self::where('email', $email)
            ->where('type', $type)
            ->where('is_used', false)
            ->delete();

        $otp = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        $otpRecord = self::create([
            'email' => $email,
            'otp' => $otp,
            'type' => $type,
            'expires_at' => now()->addMinutes(10),
            'is_used' => false
        ]);

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

    /**
     * Verify OTP
     */
    public static function verifyOtp($email, $otp, $type = 'email_verification', $markUsed = true)
    {
        $otpRecord = self::where('email', $email)
            ->where('otp', $otp)
            ->where('type', $type)
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->first();

        if ($otpRecord) {
            if ($markUsed) {
                // Mark OTP as use
                $otpRecord->update(['is_used' => true]);
            }
            return true;
        }
        return false;
    }

    /**
     * Validate OTP only (without applying it)
     */
    public static function isValidOtp($email, $otp, $type = 'email_verification')
    {
        return self::where('email', $email)
            ->where('otp', $otp)
            ->where('type', $type)
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->exists();
    }
}
