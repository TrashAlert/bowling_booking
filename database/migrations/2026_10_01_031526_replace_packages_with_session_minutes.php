<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The tables that used to point at a package for their session length.
    private const TABLES = ['bookings', 'waitlist_entries'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                // How long the session lasts. Staff choose it in 30-minute steps.
                $table->unsignedSmallInteger('minutes')->nullable();
            });

            // Keep the length each existing row had through its package.
            DB::table($name)->update([
                'minutes' => DB::raw("(select packages.minutes from packages where packages.id = {$name}.package_id)"),
            ]);

            Schema::table($name, function (Blueprint $table) {
                $table->unsignedSmallInteger('minutes')->nullable(false)->change();
                $table->dropConstrainedForeignId('package_id');
            });
        }

        Schema::dropIfExists('packages');
    }

    /**
     * Brings the packages table and columns back, but not their contents:
     * which package a row had is lost once this migration has run.
     */
    public function down(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('minutes');
            $table->unsignedInteger('price_cents');
            $table->unsignedTinyInteger('max_players')->default(6);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('package_id')->nullable()->constrained()->restrictOnDelete();
                $table->dropColumn('minutes');
            });
        }
    }
};
