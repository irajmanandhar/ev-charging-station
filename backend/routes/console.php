<?php

use App\Models\User;
use App\Services\BookingService;
use Illuminate\Support\Facades\Artisan;
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

Schedule::command('bookings:sweep')->everyMinute()->withoutOverlapping();
