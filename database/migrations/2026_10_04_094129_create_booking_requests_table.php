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
        Schema::create('booking_requests', function (Blueprint $table) {
            $table->id();

            // What the customer asked for. Nothing is booked until staff
            // confirm it with them on the phone.
            $table->string('name');
            $table->string('phone', 30);
            $table->unsignedSmallInteger('party_size');
            $table->unsignedSmallInteger('minutes');
            $table->timestampTz('starts_at');

            // When the customer can be called, as clock times in the venue's
            // time zone. Both empty means any time.
            $table->time('contact_from')->nullable();
            $table->time('contact_until')->nullable();

            $table->string('notes', 500)->nullable();

            // pending, confirmed or declined.
            $table->string('status')->default('pending');

            // The reservation it became, and who dealt with it and when.
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('handled_at')->nullable();
            $table->string('decline_reason')->nullable();

            $table->timestamps();

            // Speeds up "the requests still waiting, soonest first".
            $table->index(['status', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_requests');
    }
};
