<?php

namespace Database\Seeders;

use App\Models\OpeningHour;
use Illuminate\Database\Seeder;

class OpeningHourSeeder extends Seeder
{
    /**
     * Example hours to start from: 10am to 11pm, and until 1am on Friday and
     * Saturday nights. Change them under Settings, Opening hours.
     */
    public function run(): void
    {
        foreach (range(0, 6) as $weekday) {
            OpeningHour::updateOrCreate(
                ['weekday' => $weekday],
                ['opens_at' => '10:00', 'closes_at' => in_array($weekday, [5, 6], true) ? '01:00' : '23:00'],
            );
        }
    }
}
