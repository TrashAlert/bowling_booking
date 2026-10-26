<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every table that says how long a group wants to play.
     *
     * @var list<string>
     */
    private const TABLES = ['bookings', 'waitlist_entries', 'waitlist_deposits', 'booking_requests'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                // How many games the group asked for, for a venue that sells
                // games instead of time. Empty for a session booked by time.
                // "minutes" still holds how long the lane is kept, which for
                // games is an estimate.
                $table->unsignedTinyInteger('games')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('games');
            });
        }
    }
};
