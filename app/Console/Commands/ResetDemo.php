<?php

namespace App\Console\Commands;

use App\Services\DemoVenue;
use Illuminate\Console\Command;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Support\Facades\DB;

class ResetDemo extends Command
{
    protected $signature = 'demo:reset
        {--force : Reset without asking first}';

    protected $description = 'Empty the demo database and fill it with the demo venue again';

    /**
     * Throw away everything in the demo's database and set up "Strike Zone
     * Bowling" afresh, as it is at this moment.
     *
     * It refuses to run unless demo mode is on (BOWLING_DEMO) and the
     * database's name contains "demo", so a real venue's database can't be
     * emptied by mistake, even with the switch turned on.
     */
    public function handle(DemoVenue $demo): int
    {
        if (! config('bowling.demo')) {
            $this->error('Demo mode is off, so nothing was reset. Set BOWLING_DEMO=true only on the demo site.');

            return self::FAILURE;
        }

        $database = (string) DB::connection()->getDatabaseName();

        if (! str_contains($database, 'demo')) {
            $this->error("The database [{$database}] is not a demo database (its name must contain \"demo\"), so nothing was reset.");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Empty the [{$database}] database and set up the demo venue again?")) {
            return self::FAILURE;
        }

        // The app refuses to empty a production database; the demo is the
        // one place that is meant to happen.
        FreshCommand::prohibit(false);

        $this->call('migrate:fresh', ['--force' => true]);

        $demo->seed();

        $this->info('The demo is ready: '.DemoVenue::NAME.'.');

        foreach (DemoVenue::logins() as $login) {
            $this->line(" - {$login['role']}: {$login['email']} / {$login['password']}");
        }

        return self::SUCCESS;
    }
}
