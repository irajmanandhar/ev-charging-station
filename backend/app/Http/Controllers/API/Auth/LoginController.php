<?php

namespace App\Http\Controllers\API\Auth;

use App\Http\Controllers\API\BaseController;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class LoginController extends BaseController
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', strtolower($data['email']))->first();

        // Always run a hash check so response time doesn't reveal which emails exist.
        if (! $user) {
            Hash::make($data['password']);
        }

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return $this->sendError('Incorrect email or password.', 401);
        }

        if (! $user->hasVerifiedEmail()) {
            return $this->sendError('Please verify your email before signing in.', 403, ['code' => 'email_unverified']);
        }

        return $this->sendResponse([
            'user' => new UserResource($user),
            'token' => $user->createToken('web')->plainTextToken,
        ], 'Signed in.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->sendResponse(new UserResource($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return $this->sendResponse(null, 'Signed out.');
    }
}
