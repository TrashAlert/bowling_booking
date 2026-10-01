<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lane_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lane_id')->constrained()->restrictOnDelete();

            // Empty for league nights and maintenance blocks.
            // The foreign key to bookings is added in the bookings step.
            $table->unsignedBigInteger('booking_id')->nullable()->index();

            // timestampTz stores the time zone, which the overlap rule needs.
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');

            $table->string('status')->default('active');
            $table->timestampTz('held_until')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        // The rules below are PostgreSQL features. Tests use SQLite for now,
        // so they are skipped there.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        // An allocation must end after it starts.
        DB::statement('
            ALTER TABLE lane_allocations
            ADD CONSTRAINT lane_allocations_valid_period
            CHECK (ends_at > starts_at)
        ');

        // No two allocations on the same lane may overlap, unless one is released.
        // Back-to-back is fine: one ending at 8:00 and the next starting at 8:00.
        DB::statement("
            ALTER TABLE lane_allocations
            ADD CONSTRAINT lane_allocations_no_overlap
            EXCLUDE USING gist (
                lane_id WITH =,
                tstzrange(starts_at, ends_at) WITH &&
            ) WHERE (status <> 'released')
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('lane_allocations');
    }
};
