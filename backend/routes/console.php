<?php

use App\Models\User;
use App\Services\BookingService;
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
