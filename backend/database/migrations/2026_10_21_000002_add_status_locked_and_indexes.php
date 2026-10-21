<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            // Set when an admin imposes a status, so the operator can't undo it.
            $table->boolean('status_locked')->default(false);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['status', 'starts_at']); // bookings:sweep
        });

        Schema::table('charging_sessions', function (Blueprint $table) {
            $table->index(['user_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('charging_sessions', fn (Blueprint $table) => $table->dropIndex(['user_id', 'started_at']));
        Schema::table('bookings', fn (Blueprint $table) => $table->dropIndex(['status', 'starts_at']));
        Schema::table('stations', fn (Blueprint $table) => $table->dropColumn('status_locked'));
    }
};
