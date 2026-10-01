<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lane_allocations', function (Blueprint $table) {
            // Every booking_id must now point at a real booking.
            // Deleting a booking also deletes its lane allocations.
            $table->foreign('booking_id')->references('id')->on('bookings')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lane_allocations', function (Blueprint $table) {
            $table->dropForeign(['booking_id']);
        });
    }
};
