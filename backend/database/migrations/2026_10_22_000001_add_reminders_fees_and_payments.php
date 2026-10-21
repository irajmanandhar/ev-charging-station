<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable();
            // Fee kept when a booking is cancelled inside the free-cancellation window.
            $table->decimal('cancellation_fee', 10, 2)->default(0);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20); // khalti | esewa
            $table->string('reference')->nullable()->index(); // provider transaction id (pidx / transaction_uuid)
            $table->decimal('amount', 10, 2);
            $table->decimal('refunded_amount', 10, 2)->default(0);
            $table->string('currency', 3)->default('NPR');
            // initiated -> completed | failed ; completed -> refunded | refund_pending
            $table->string('status', 20)->default('initiated');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn(['reminder_sent_at', 'cancellation_fee']));
    }
};
