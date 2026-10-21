<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Services\BookingService;
use App\Support\ProductionChecks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('bookings:sweep', function (BookingService $bookings) {
    $result = $bookings->sweep();
    $this->info("No-shows: {$result['no_shows']}, sessions auto-stopped: {$result['auto_stopped']}");
})->purpose('Mark missed bookings as no-shows and stop sessions whose slot has ended');

Artisan::command('demo:seed-if-empty', function () {
    if (User::query()->exists()) {
        $this->info('Database already has users; skipping demo seed.');

        return;
    }
    $this->info('Seeding demo data...');
    $this->call('db:seed', ['--force' => true]);
})->purpose('Seed demo data on a fresh database (used by Docker)');

Artisan::command('env:check', function () {
    $problems = ProductionChecks::fromConfig();
    foreach ($problems as $problem) {
        $this->error($problem);
    }
    if (! $problems) {
        $this->info('Environment looks production-safe.');
    }

    return $problems ? 1 : 0;
})->purpose('Fail if the environment has development settings (used by the production entrypoint)');

Artisan::command('user:set-role {email} {role : driver, operator or admin}', function (string $email, string $role) {
    $enum = UserRole::tryFrom($role);
    if (! $enum) {
        $this->error('Role must be driver, operator or admin.');

        return 1;
    }
    $user = User::where('email', strtolower($email))->first();
    if (! $user) {
        $this->error("No user with email {$email}.");

        return 1;
    }
    $user->role = $enum;
    $user->save();
    $this->info("{$user->email} is now {$enum->value}.");
})->purpose('Set a user role from the command line (create the first admin)');

Artisan::command('auth:prune', function () {
    $otps = DB::table('user_otps')->where('expires_at', '<', now()->subDay())->delete();
    $pending = DB::table('pending_users')->where('created_at', '<', now()->subDay())->delete();
    $this->info("Pruned {$otps} OTPs and {$pending} stale sign-ups.");
})->purpose('Delete expired OTP codes and abandoned sign-ups');

Artisan::command('bookings:remind', function (BookingService $bookings) {
    $this->info('Reminders sent: '.$bookings->sendReminders());
})->purpose('Email drivers whose booking starts soon');

Schedule::command('bookings:sweep')->everyMinute()->withoutOverlapping();
Schedule::command('bookings:remind')->everyMinute()->withoutOverlapping();
Schedule::command('auth:prune')->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
