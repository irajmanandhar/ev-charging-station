<?php

namespace App\Http\Controllers\API\Auth;

use App\Http\Controllers\API\BaseController;
use App\Models\AuthProvider;
use App\Models\PendingUser;
use App\Models\User;
use App\Models\UserOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class RegisterController extends BaseController
{
    /**
     * Step 1 of sign-up: park the details in pending_users and email an OTP.
     * The account is only created once the OTP is verified.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150'],
            'phone' => ['nullable', 'digits:10'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $email = strtolower($data['email']);

        if (User::where('email', $email)->exists() || AuthProvider::where('email', $email)->exists()) {
            return $this->sendError('An account with this email already exists.', 422, [
                'email' => ['An account with this email already exists.'],
            ]);
        }

        if (! empty($data['phone']) && User::where('phone', $data['phone'])->exists()) {
            return $this->sendError('This phone number is already in use.', 422, [
                'phone' => ['This phone number is already in use.'],
            ]);
        }

        // A sign-up already waiting for its code keeps its original details: otherwise anyone could
        // re-register someone else's email with their own password before the real owner enters the code.
        // Re-submitting just re-sends a code for the original details.
        $pending = PendingUser::where('email', $email)->first();
        if (! $pending || $pending->updated_at->lt(now()->subMinutes((int) config('ev.otp_ttl_minutes')))) {
            PendingUser::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $data['name'],
                    'phone' => $data['phone'] ?? null,
                    'password' => Hash::make($data['password']),
                ]
            )->touch();
        }

        $otp = UserOtp::generateOtp($email, 'email_verification');

        return $this->sendResponse(
            UserOtp::devPayload($otp, ['email' => $email]),
            'We sent a 6-digit code to your email. Enter it to finish signing up.'
        );
    }
}
