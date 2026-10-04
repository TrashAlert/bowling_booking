<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('opening_hours', function (Blueprint $table) {
            $table->id();

            // 0 is Sunday through 6 for Saturday. One row per day at most.
            $table->unsignedTinyInteger('weekday')->unique();

            // Clock times in the venue's time zone. Both empty means closed
            // all day. A closing time at or before the opening time is after
            // midnight, on the next day.
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opening_hours');
    }
};
