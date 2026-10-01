<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lane_allocations', function (Blueprint $table) {
            // Set on the row that keeps a lane empty in the hour before a
            // reservation. That row has no booking_id of its own, so a
            // booking's allocations are still only its playing time.
            $table->foreignId('closed_for_booking_id')->nullable()->constrained('bookings')->cascadeOnDelete();

            $table->index('closed_for_booking_id');
        });
    }

    public function down(): void
    {
        Schema::table('lane_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_for_booking_id');
        });
    }
};
