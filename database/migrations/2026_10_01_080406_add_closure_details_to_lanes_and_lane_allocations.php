<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lanes', function (Blueprint $table) {
            // Why an out-of-order lane is closed, e.g. 'repair'.
            $table->string('closed_reason')->nullable();

            // When it is expected back. Only an estimate: the lane stays
            // closed until staff reopen it. Empty means nobody knows yet.
            $table->timestampTz('closed_until')->nullable();
        });

        Schema::table('lane_allocations', function (Blueprint $table) {
            // Set on a block that closes the lane for a short job such as
            // re-oiling. The block ends by itself, so the lane reopens then.
            $table->string('closure_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lane_allocations', function (Blueprint $table) {
            $table->dropColumn('closure_reason');
        });

        Schema::table('lanes', function (Blueprint $table) {
            $table->dropColumn(['closed_reason', 'closed_until']);
        });
    }
};
