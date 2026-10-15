<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lane_repairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lane_id')->constrained()->restrictOnDelete();

            // When the lane was marked out of order, and when it was opened
            // again. Empty while the repair is still going on.
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();

            $table->timestamps();

            // Speeds up "repairs that overlap a span of days".
            $table->index(['started_at', 'ended_at']);
        });

        // Repairs from before this table are not known. A lane that is out of
        // order now is counted from when it was last changed, which is when
        // it was closed.
        DB::table('lanes')
            ->where('status', 'out_of_order')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'updated_at'])
            ->each(fn (object $lane) => DB::table('lane_repairs')->insert([
                'lane_id' => $lane->id,
                'started_at' => $lane->updated_at ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]));

        // The rules below are PostgreSQL features.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // A repair must end after it starts.
        DB::statement('
            ALTER TABLE lane_repairs
            ADD CONSTRAINT lane_repairs_valid_period
            CHECK (ended_at IS NULL OR ended_at >= started_at)
        ');

        // A lane has one repair going on at most.
        DB::statement('
            CREATE UNIQUE INDEX lane_repairs_one_open_per_lane
            ON lane_repairs (lane_id)
            WHERE ended_at IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lane_repairs');
    }
};
