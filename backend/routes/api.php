<?php

use App\Http\Controllers\API\Admin;
use App\Http\Controllers\API\Auth\EmailVerificationController;
use App\Http\Controllers\API\Auth\ForgotPasswordController;
use App\Http\Controllers\API\Auth\LoginController;
use App\Http\Controllers\API\Auth\RegisterController;
use App\Http\Controllers\API\Auth\SocialAuthController;
use App\Http\Controllers\API\BookingController;
use App\Http\Controllers\API\ChargerController;
use App\Http\Controllers\API\Operator;
use App\Http\Controllers\API\SessionController;
use App\Http\Controllers\API\StationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth
|--------------------------------------------------------------------------
*/
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register', [RegisterController::class, 'register']);
    Route::post('/verify-email-otp', [EmailVerificationController::class, 'verifyEmail']);
    Route::post('/resend-otp', [EmailVerificationController::class, 'resendOtp']);
    Route::post('/login', [LoginController::class, 'login']);

    Route::prefix('password')->group(function () {
        Route::post('forgot', [ForgotPasswordController::class, 'sendResetOtp']);
        Route::post('verify-otp', [ForgotPasswordController::class, 'verifyResetOtp']);
        Route::post('reset', [ForgotPasswordController::class, 'resetPassword']);
    });
});

Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirectToProvider']);
Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'handleProviderCallback']);

/*
|--------------------------------------------------------------------------
| Public: station finder
|--------------------------------------------------------------------------
*/
Route::get('/stations', [StationController::class, 'index']);
Route::get('/stations/{station}', [StationController::class, 'show']);
Route::get('/chargers/{charger}/availability', [ChargerController::class, 'availability']);
Route::get('/chargers/{charger}/quote', [ChargerController::class, 'quote']);

/*
|--------------------------------------------------------------------------
| Signed-in users (drivers, and operators/admins acting as drivers)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [LoginController::class, 'me']);
    Route::post('/logout', [LoginController::class, 'logout']);

    Route::get('/bookings', [BookingController::class, 'index']);
    Route::post('/bookings', [BookingController::class, 'store'])->middleware('throttle:20,1');
    Route::get('/bookings/{booking}', [BookingController::class, 'show']);
    Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel']);
    Route::post('/bookings/{booking}/check-in', [BookingController::class, 'checkIn']);

    Route::post('/chargers/{charger}/start', [ChargerController::class, 'startNow'])->middleware('throttle:20,1');

    Route::get('/sessions', [SessionController::class, 'index']);
    Route::post('/sessions/{session}/stop', [SessionController::class, 'stop']);

    /*
    | Operator portal (admins can use it too and see every station)
    */
    Route::middleware('role:operator,admin')->prefix('operator')->group(function () {
        Route::get('/metrics', [Operator\MetricsController::class, 'index']);
        Route::get('/bookings', [Operator\BookingController::class, 'index']);

        Route::get('/stations', [Operator\StationController::class, 'index']);
        Route::post('/stations', [Operator\StationController::class, 'store']);
        Route::get('/stations/{station}', [Operator\StationController::class, 'show']);
        Route::patch('/stations/{station}', [Operator\StationController::class, 'update']);
        Route::delete('/stations/{station}', [Operator\StationController::class, 'destroy']);

        Route::post('/stations/{station}/chargers', [Operator\ChargerController::class, 'store']);
        Route::patch('/chargers/{charger}', [Operator\ChargerController::class, 'update']);
        Route::delete('/chargers/{charger}', [Operator\ChargerController::class, 'destroy']);
    });

    /*
    | Platform admin
    */
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/overview', [Admin\OverviewController::class, 'index']);
        Route::get('/users', [Admin\UserController::class, 'index']);
        Route::patch('/users/{user}/role', [Admin\UserController::class, 'updateRole']);
        Route::get('/stations', [Admin\StationController::class, 'index']);
        Route::patch('/stations/{station}/status', [Admin\StationController::class, 'updateStatus']);
    });
});
