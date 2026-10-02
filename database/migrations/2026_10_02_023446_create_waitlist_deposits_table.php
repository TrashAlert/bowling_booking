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
        Schema::create('waitlist_deposits', function (Blueprint $table) {
            $table->id();

            // Random secret used in the link to this deposit's payment page.
            $table->string('token', 64)->unique();

            // What the party asked for online. It only joins the line, and
            // gets a customer record, once the deposit is paid.
            $table->string('name');
            $table->string('phone');
            $table->unsignedSmallInteger('minutes');
            $table->unsignedTinyInteger('party_size');

            // Money is kept in cents.
            $table->unsignedInteger('amount_cents');
            $table->timestampTz('paid_at')->nullable();

            // The place in line this deposit paid for. Empty until it is paid.
            $table->foreignId('waitlist_entry_id')->nullable()->unique()->constrained()->restrictOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('waitlist_deposits');
    }
};
