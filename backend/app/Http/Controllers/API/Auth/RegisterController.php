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

        PendingUser::updateOrCreate(
            ['email' => $email],
            [
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password']),
            ]
        );

        $otp = UserOtp::generateOtp($email, 'email_verification');

        return $this->sendResponse(
            UserOtp::devPayload($otp, ['email' => $email]),
            'We sent a 6-digit code to your email. Enter it to finish signing up.'
        );
    }
}
