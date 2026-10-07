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
        Schema::table('waitlist_deposits', function (Blueprint $table) {
            // Who is taking this deposit, e.g. 'stand_in'. Empty until the
            // party presses Pay, and for a party that had nothing to pay.
            $table->string('payment_provider')->nullable();

            // The provider's own name for the payment, which it quotes when
            // it tells us the money has arrived.
            $table->string('payment_reference')->nullable();

            // Speeds up finding the deposit a provider's notice is about.
            $table->index(['payment_provider', 'payment_reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('waitlist_deposits', function (Blueprint $table) {
            $table->dropIndex(['payment_provider', 'payment_reference']);
            $table->dropColumn(['payment_provider', 'payment_reference']);
        });
    }
};
