<?php

namespace Database\Factories;

use App\Enums\LaneStatus;
use App\Models\Lane;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lane>
 */
class LaneFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => fake()->unique()->numberBetween(1, 999),
            'has_bumpers' => false,
            'status' => LaneStatus::Open,
        ];
    }

    /**
     * Indicate that the lane has bumpers.
     */
    public function withBumpers(): static
    {
        return $this->state(fn (array $attributes) => [
            'has_bumpers' => true,
        ]);
    }

    /**
     * Indicate that the lane is closed for repair.
     */
    public function outOfOrder(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LaneStatus::OutOfOrder,
        ]);
    }
}
