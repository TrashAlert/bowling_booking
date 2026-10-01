<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            // What they want to play, e.g. "1 hour". Gives the session length.
            $table->foreignId('package_id')->constrained()->restrictOnDelete();

            // Filled in when they're seated and a booking is created.
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedTinyInteger('party_size');
            $table->string('status')->default('waiting');

            // Random secret used in the customer's private status page link.
            $table->string('token', 64)->unique();

            $table->timestampTz('called_at')->nullable();
            $table->timestampTz('seated_at')->nullable();
            $table->timestamps();

            // Speeds up "who is in line, in order".
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
