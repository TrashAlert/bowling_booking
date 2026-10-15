<?php

namespace Database\Factories;

use App\Models\Lane;
use App\Models\LaneRepair;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaneRepair>
 */
class LaneRepairFactory extends Factory
{
    /**
     * Define the model's default state: a repair that began an hour ago and
     * is still going on.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lane_id' => Lane::factory()->outOfOrder(),
            'started_at' => now()->subHour(),
            'ended_at' => null,
        ];
    }
}
