<?php

namespace App\Http\Controllers\API\Auth;

use App\Http\Controllers\API\BaseController;
use App\Http\Resources\UserResource;
use App\Models\PendingUser;
use App\Models\User;
use App\Models\UserOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmailVerificationController extends BaseController
{
    /** Step 2 of sign-up: verify the OTP, create the account and sign the user in. */
    public function verifyEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'otp' => ['required', 'string', 'size:6'],
        ]);
        $email = strtolower($data['email']);

        if (User::where('email', $email)->exists()) {
            return $this->sendError('This email is already verified. Please sign in.', 409);
        }

        $pending = PendingUser::where('email', $email)->first();
        if (! $pending) {
            return $this->sendError('We could not find a pending sign-up for this email.', 404);
        }

        if (! UserOtp::verifyOtp($email, $data['otp'], 'email_verification')) {
            return $this->sendError('That code is invalid or has expired.', 422, ['otp' => ['That code is invalid or has expired.']]);
        }

        $user = DB::transaction(function () use ($pending, $email) {
            if (User::where('email', $email)->lockForUpdate()->exists()) {
                return null;
            }
            if ($pending->phone && User::where('phone', $pending->phone)->exists()) {
                return false;
            }

            // pending_users.password is already a hash; the 'hashed' cast keeps it as-is.
            $user = User::create([
                'name' => $pending->name,
                'email' => $pending->email,
                'phone' => $pending->phone,
                'password' => $pending->password,
                'email_verified_at' => now(),
            ]);
            $pending->delete();

            return $user;
        });

        if ($user === false) {
            return $this->sendError('This phone number is already in use by another account.', 422, ['phone' => ['This phone number is already in use.']]);
        }
        if (! $user) {
            return $this->sendError('This email is already verified. Please sign in.', 409);
        }

        return $this->sendResponse([
            'user' => new UserResource($user->refresh()),
            'token' => $user->createToken('web')->plainTextToken,
        ], 'Email verified. Welcome aboard!', 201);
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $email = strtolower($data['email']);

        // Same answer whether or not a sign-up is pending, so this can't be used to probe emails.
        $payload = null;
        if (PendingUser::where('email', $email)->exists()) {
            $payload = UserOtp::devPayload(UserOtp::generateOtp($email, 'email_verification'));
        }

        return $this->sendResponse($payload, 'If a sign-up is pending for that email, a new code is on its way.');
    }
}
