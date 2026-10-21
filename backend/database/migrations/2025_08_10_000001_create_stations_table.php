<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable()->index();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            // Google Places id when a station was imported; nullable for operator-created ones.
            $table->string('place_id')->nullable()->unique();
            $table->string('timezone')->default('Asia/Kathmandu');
            $table->boolean('is_24_hours')->default(false);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->json('amenities')->nullable();
            $table->string('status', 20)->default('active')->index(); // active | maintenance | inactive
            $table->timestamps();

            $table->index(['latitude', 'longitude']);
        });

        Schema::create('chargers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->string('label', 20); // e.g. "A1"
            $table->string('connector_type', 20); // CCS2 | CHAdeMO | Type2 | GBT
            $table->decimal('power_kw', 6, 2);
            $table->decimal('price_per_kwh', 8, 2); // NPR
            // Operator-controlled state. Live occupancy is derived from sessions/bookings.
            $table->string('status', 20)->default('available'); // available | offline | maintenance
            $table->timestamps();

            $table->unique(['station_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chargers');
        Schema::dropIfExists('stations');
    }
};
