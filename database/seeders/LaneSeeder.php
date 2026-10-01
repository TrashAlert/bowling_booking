<?php

namespace Database\Seeders;

use App\Models\Lane;
use Illuminate\Database\Seeder;

class LaneSeeder extends Seeder
{
    public function run(): void
    {
        // Change 12 to the number of lanes you have.
        foreach (range(1, 12) as $number) {
            Lane::updateOrCreate(
                ['number' => $number],
                ['has_bumpers' => $number <= 2]
            );
        }
    }
}
