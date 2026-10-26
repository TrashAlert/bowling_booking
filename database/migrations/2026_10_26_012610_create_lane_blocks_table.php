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
        Schema::create('lane_blocks', function (Blueprint $table) {
            $table->id();

            // What the lanes are blocked for, e.g. "League night".
            $table->string('note', 100);

            // When the league or event itself starts and ends. Each lane is
            // also kept clear for a while before it, like a reservation's.
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');

            // Who blocked the lanes.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Speeds up "the blocks on a day".
            $table->index('starts_at');
        });

        Schema::table('lane_allocations', function (Blueprint $table) {
            // Set on the rows that keep a lane taken for a block. Removing
            // the block removes them, which frees the lanes.
            $table->foreignId('lane_block_id')->nullable()->constrained()->cascadeOnDelete();

            $table->index('lane_block_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lane_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lane_block_id');
        });

        Schema::dropIfExists('lane_blocks');
    }
};
