<?php

namespace Database\Seeders;

use App\Models\Package;
use Illuminate\Database\Seeder;

class PackageSeeder extends Seeder
{
    public function run(): void
    {
        // Placeholder prices in the smallest currency unit (6000 = 60.00).
        Package::updateOrCreate(['name' => '1 hour'], ['minutes' => 60, 'price_cents' => 6000]);
        Package::updateOrCreate(['name' => '2 hours'], ['minutes' => 120, 'price_cents' => 11000]);
    }
}
