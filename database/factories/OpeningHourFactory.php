<?php

namespace Database\Factories;

use App\Models\OpeningHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpeningHour>
 */
class OpeningHourFactory extends Factory
{
    /**
     * Define the model's default state: open from 10am to 11pm.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'weekday' => fake()->unique()->numberBetween(0, 6),
            'opens_at' => '10:00',
            'closes_at' => '23:00',
        ];
    }

    /**
     * Indicate that the venue is closed all day.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'opens_at' => null,
            'closes_at' => null,
        ]);
    }
}
