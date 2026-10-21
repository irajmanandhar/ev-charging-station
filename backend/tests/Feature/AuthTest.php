<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\EmailVerificationOtp;
use App\Notifications\PasswordResetOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_verify_and_sign_in(): void
    {
        Notification::fake();

        $this->postJson('/api/register', [
            'name' => 'Sita Sharma', 'email' => 'Sita@Example.com', 'phone' => '9800000000',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertOk()->assertJsonPath('data.email', 'sita@example.com');

        $otp = null;
        Notification::assertSentTo(\App\Models\PendingUser::first(), EmailVerificationOtp::class, function ($n) use (&$otp) {
            $otp = $n->otp;

            return true;
        });

        $this->assertNull(User::first());
        $this->postJson('/api/verify-email-otp', ['email' => 'sita@example.com', 'otp' => '000000'])->assertStatus(422);

        $token = $this->postJson('/api/verify-email-otp', ['email' => 'sita@example.com', 'otp' => $otp])
            ->assertCreated()->json('data.token');

        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('data.email', 'sita@example.com');

        $this->postJson('/api/login', ['email' => 'sita@example.com', 'password' => 'password123'])
            ->assertOk()->assertJsonStructure(['data' => ['token', 'user' => ['id', 'role']]]);
        $this->postJson('/api/login', ['email' => 'sita@example.com', 'password' => 'wrong-pass'])->assertUnauthorized();
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/register', [
            'name' => 'X', 'email' => 'taken@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_password_reset_flow_and_no_account_probing(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ram@example.com']);

        $unknown = $this->postJson('/api/password/forgot', ['email' => 'nobody@example.com'])->assertOk()->json('message');
        $known = $this->postJson('/api/password/forgot', ['email' => 'ram@example.com'])->assertOk()->json('message');
        $this->assertSame($unknown, $known);

        $otp = null;
        Notification::assertSentTo($user, PasswordResetOtpNotification::class, function ($n) use (&$otp) {
            $otp = $n->otp;

            return true;
        });

        $this->postJson('/api/password/verify-otp', ['email' => 'ram@example.com', 'otp' => $otp])->assertOk();
        $this->postJson('/api/password/reset', [
            'email' => 'ram@example.com', 'otp' => $otp,
            'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->postJson('/api/login', ['email' => 'ram@example.com', 'password' => 'new-password'])->assertOk();
        // OTP is single-use
        $this->postJson('/api/password/reset', [
            'email' => 'ram@example.com', 'otp' => $otp,
            'password' => 'another-pass', 'password_confirmation' => 'another-pass',
        ])->assertStatus(422);
    }

    public function test_dev_otp_is_never_exposed_outside_local_or_testing(): void
    {
        config(['ev.expose_otp' => true]);
        $this->app['env'] = 'production';

        $res = $this->postJson('/api/register', [
            'name' => 'P', 'email' => 'p@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertOk();

        $this->assertNull($res->json('data.dev_otp'));
    }
}
