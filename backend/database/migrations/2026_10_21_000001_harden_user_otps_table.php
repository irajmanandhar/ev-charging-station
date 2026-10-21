<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Codes are now stored as a 64-char keyed hash, with a failed-guess counter.
        Schema::table('user_otps', function (Blueprint $table) {
            $table->string('otp', 64)->change();
            $table->unsignedTinyInteger('attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('user_otps', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });
    }
};
