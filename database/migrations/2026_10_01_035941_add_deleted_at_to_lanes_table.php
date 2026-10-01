<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lanes', function (Blueprint $table) {
            // A removed lane is kept, hidden, so past bookings on it still
            // have a lane to point at.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('lanes', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
