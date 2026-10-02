<?php

namespace Database\Factories;

use App\Models\WaitlistDeposit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaitlistDeposit>
 */
class WaitlistDepositFactory extends Factory
{
    /**
     * Define the model's default state: a deposit that hasn't been paid yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->e164PhoneNumber(),
            'minutes' => 60,
            'party_size' => 4,
            'amount_cents' => 1000,
        ];
    }
}
