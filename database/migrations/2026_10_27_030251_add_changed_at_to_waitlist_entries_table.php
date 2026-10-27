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
        Schema::table('waitlist_entries', function (Blueprint $table) {
            // When the group changed its name, size or length from its own
            // page. A group can do that once; after that it is locked.
            $table->timestampTz('changed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->dropColumn('changed_at');
        });
    }
};
