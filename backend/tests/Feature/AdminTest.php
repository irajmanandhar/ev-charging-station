<?php

namespace Tests\Feature;

use App\Models\AdminLog;
use App\Models\Station;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_reach_admin_routes(): void
    {
        $this->actingAs(User::factory()->operator()->create())->getJson('/api/admin/overview')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->getJson('/api/admin/overview')->assertOk()
            ->assertJsonStructure(['data' => ['counts', 'metrics' => ['totals', 'daily'], 'recent_activity']]);
    }

    public function test_admin_promotes_a_driver_and_it_is_logged(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = $this->driver();

        $this->actingAs($admin)->patchJson("/api/admin/users/{$driver->id}/role", ['role' => 'operator'])
            ->assertOk()->assertJsonPath('data.role', 'operator');

        $this->assertDatabaseHas('admin_logs', ['action' => 'user.role_changed', 'subject_id' => $driver->id]);
        $this->actingAs($admin)->patchJson("/api/admin/users/{$admin->id}/role", ['role' => 'driver'])->assertStatus(422);
    }

    public function test_role_cannot_be_mass_assigned_on_register(): void
    {
        config(['ev.expose_otp' => true]);
        $otp = $this->postJson('/api/register', [
            'name' => 'Sneaky', 'email' => 'sneaky@example.com', 'role' => 'admin',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->json('data.dev_otp');

        $this->postJson('/api/verify-email-otp', ['email' => 'sneaky@example.com', 'otp' => $otp])
            ->assertCreated()->assertJsonPath('data.user.role', 'driver');
    }

    public function test_admin_can_suspend_a_station(): void
    {
        $admin = User::factory()->admin()->create();
        $station = Station::factory()->create();

        $this->actingAs($admin)->patchJson("/api/admin/stations/{$station->id}/status", ['status' => 'inactive'])->assertOk();
        $this->getJson("/api/stations/{$station->id}")->assertNotFound();
        $this->assertSame(1, AdminLog::count());
    }
}
