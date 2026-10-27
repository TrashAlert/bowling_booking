<?php

namespace App\Console\Commands;

use App\Services\DemoVenue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LivenDemo extends Command
{
    protected $signature = 'demo:liven';

    protected $description = 'Keep the demo venue busy: seat called groups, check in reservations, add groups to the line';

    /**
     * Play the demo venue's customers for a moment, so the lanes and the line
     * stay busy all day. Run every couple of minutes by the scheduler on the
     * demo. Like demo:reset, it refuses to run anywhere but the demo, as it
     * makes up customers.
     */
    public function handle(DemoVenue $demo): int
    {
        if (! config('bowling.demo') || ! str_contains((string) DB::connection()->getDatabaseName(), 'demo')) {
            $this->error('This only runs on the demo, with BOWLING_DEMO=true and a database whose name contains "demo".');

            return self::FAILURE;
        }

        foreach ($demo->liven() as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
