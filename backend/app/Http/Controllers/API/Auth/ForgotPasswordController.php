<?php

namespace App\Http\Controllers\API\Auth;

use App\Http\Controllers\API\BaseController;
use App\Models\User;
use App\Models\UserOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ForgotPasswordController extends BaseController
{
    private const GENERIC = 'If an account exists for that email, we sent a reset code.';

    /** Always answers the same way, so the endpoint can't be used to probe for accounts. */
    public function sendResetOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $email = strtolower($data['email']);

        $payload = null;
        if (User::where('email', $email)->exists()) {
            $payload = UserOtp::devPayload(UserOtp::generateOtp($email, 'password_reset'));
        }

        return $this->sendResponse($payload, self::GENERIC);
    }

    public function verifyResetOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'string', 'size:6'],
        ]);

        if (! UserOtp::verifyOtp(strtolower($data['email']), $data['otp'], 'password_reset', markUsed: false)) {
            return $this->sendError('That code is invalid or has expired.', 422, ['otp' => ['That code is invalid or has expired.']]);
        }

        return $this->sendResponse(['otp_verified' => true], 'Code verified. Choose a new password.');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'string', 'size:6'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $email = strtolower($data['email']);

        $user = User::where('email', $email)->first();
        if (! $user || ! UserOtp::verifyOtp($email, $data['otp'], 'password_reset')) {
            return $this->sendError('That code is invalid or has expired.', 422, ['otp' => ['That code is invalid or has expired.']]);
        }

        $user->update(['password' => $data['password']]);
        $user->tokens()->delete(); // sign out everywhere

        return $this->sendResponse(null, 'Password updated. You can sign in now.');
    }
}
