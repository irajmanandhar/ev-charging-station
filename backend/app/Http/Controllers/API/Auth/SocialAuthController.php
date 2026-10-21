<?php

namespace App\Http\Controllers\API\Auth;

use App\Http\Controllers\API\BaseController;
use App\Http\Resources\UserResource;
use App\Support\SocialState;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
use App\Models\AuthProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Exception;

class SocialAuthController extends BaseController
{
    public function redirectToProvider(Request $request, $provider)
    {
        try {
            if (!in_array($provider, ['google', 'facebook'])) {
                return $this->sendError('Invalid provider', 400);
            }
            $driver = Socialite::driver($provider)->stateless()->with(['state' => SocialState::issue()]);
            if ($request->wantsJson()) {
                $url = $driver->redirect()->getTargetUrl();
                return $this->sendResponse(['url' => $url], 'Redirect URL generated');
            } else {
                return $driver->redirect();
            }
        } catch (Exception $e) {
            report($e);

            return $this->sendError('Failed to initiate provider authentication', 500);
        }
    }

    public function handleProviderCallback(Request $request, $provider)
    {
        try {
            if (!in_array($provider, ['google', 'facebook'])) {
                return $this->sendError('Invalid provider', 400);
            }

            // The state we signed when the login started: stops forged callback links.
            if (! SocialState::valid((string) $request->query('state'))) {
                return $this->sendError('This sign-in link is invalid or has expired. Please start again.', 422);
            }

            $socialUser = Socialite::driver($provider)->stateless()->user();
            $email = strtolower((string) $socialUser->getEmail());
            if ($email === '') {
                return $this->sendError('Your account has no email address. Please sign up with email instead.', 422);
            }

            // Check if the provider user already exists in auth_providers
            $authProvider = AuthProvider::where('provider', $provider)
                ->where('provider_id', $socialUser->getId())
                ->first();

            if ($authProvider) {
                // User exists, return their token and data
                $user = $authProvider->user;
                $token = $user->createToken('API Token')->plainTextToken;
                return $this->respondWithToken($request, $token, $user, 'User logged in successfully');
            }

            // Check if a user with the same email exists
            $user = User::where('email', $email)->first();

            if ($user) {
                // Only link by email when the provider vouches for it; otherwise anyone could
                // claim an existing account by registering its address at the provider.
                $raw = (array) ($socialUser->user ?? []);
                if ($provider !== 'google' || ! filter_var($raw['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    return $this->sendError('An account with this email already exists. Sign in with your password instead.', 409);
                }

                // Link new provider to existing user
                $authProvider = AuthProvider::create([
                    'user_id' => $user->id,
                    'provider' => $provider,
                    'provider_id' => $socialUser->getId(),
                    'email' => $email,
                ]);
                $token = $user->createToken('API Token')->plainTextToken;
                return $this->respondWithToken($request, $token, $user, 'User logged in successfully');
            }

            // Create a new user and link provider
            DB::beginTransaction();
            try {
                $user = User::create([
                    'name' => $socialUser->getName() ?? 'User_' . Str::random(8),
                    'email' => $email,
                    'password' => Str::random(32),
                    'email_verified_at' => now(),
                ]);

                AuthProvider::create([
                    'user_id' => $user->id,
                    'provider' => $provider,
                    'provider_id' => $socialUser->getId(),
                    'email' => $email,
                ]);

                $token = $user->createToken('API Token')->plainTextToken;
                DB::commit();
                return $this->respondWithToken($request, $token, $user, 'User registered successfully');
            } catch (Exception $e) {
                DB::rollBack();
                report($e);

                return $this->sendError('Failed to register user', 500);
            }
        } catch (Exception $e) {
            report($e);

            return $this->sendError('Authentication failed', 500);
        }
    }

    private function respondWithToken(Request $request, $token, $user, $message)
    {
        if ($request->wantsJson()) {
            return $this->sendResponse(
                ['token' => $token, 'user' => new UserResource($user)],
                $message
            );
        } else {
            $frontendUrl = rtrim(config('ev.frontend_url'), '/').'/auth/social#token='.urlencode($token); // fragment: never sent to servers or logged
            return redirect()->to($frontendUrl);
        }
    }
}
