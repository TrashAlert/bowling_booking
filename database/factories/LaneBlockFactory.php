<?php

namespace Database\Factories;

use App\Models\LaneBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaneBlock>
 */
class LaneBlockFactory extends Factory
{
    /**
     * Define the model's default state: a three-hour league night tomorrow
     * evening, with no lanes yet. Lanes are blocked through LaneBlocks, which
     * also keeps them taken.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->addDay()->setTime(19, 0);

        return [
            'note' => 'League night',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHours(3),
        ];
    }
}
