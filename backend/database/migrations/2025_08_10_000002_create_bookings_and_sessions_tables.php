<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 12)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charger_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            // confirmed -> active -> completed, or cancelled / no_show
            $table->string('status', 20)->default('confirmed');
            $table->string('vehicle')->nullable();
            $table->decimal('estimated_kwh', 8, 2);
            $table->decimal('estimated_cost', 10, 2);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // Overlap checks scan a charger's bookings by time window.
            $table->index(['charger_id', 'status', 'starts_at', 'ends_at']);
            $table->index(['user_id', 'starts_at']);
        });

        Schema::create('charging_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charger_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->decimal('energy_kwh', 8, 2)->default(0);
            $table->decimal('cost', 10, 2)->default(0);
            $table->string('status', 20)->default('active'); // active | completed
            $table->timestamps();

            $table->index(['charger_id', 'status']);
            $table->index(['started_at']);
        });

        Schema::create('admin_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_logs');
        Schema::dropIfExists('charging_sessions');
        Schema::dropIfExists('bookings');
    }
};
