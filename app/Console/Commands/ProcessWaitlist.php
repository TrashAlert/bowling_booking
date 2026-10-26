<?php

namespace App\Console\Commands;

use App\Services\ActivityLog;
use App\Services\SchedulerHealth;
use App\Services\WaitlistPush;
use App\Services\WaitlistService;
use Illuminate\Console\Command;

class ProcessWaitlist extends Command
{
    protected $signature = 'waitlist:process';

    protected $description = 'Skip parties who missed their call, call the next parties for free lanes, and tell those nearly at the front to get ready';

    public function handle(WaitlistService $waitlist, WaitlistPush $push, ActivityLog $log, SchedulerHealth $health): int
    {
        [$skipped, $called] = $log->asSystem('Scheduler', fn () => [$waitlist->skipExpiredCalls(), $waitlist->callNextParties()]);

        $push->notifyCalled($called);

        // After the calls, so a party called just now isn't also told to get ready.
        $readied = $log->asSystem('Scheduler', fn () => $push->notifyGetReady());

        $this->info("Skipped {$skipped} party(s), called ".count($called)." party(s), told {$readied} party(s) to get ready.");

        foreach ($called as $entry) {
            $this->line(" - {$entry->customer->name}, party of {$entry->party_size}");
        }

        $health->recordRun($this->getName());

        return self::SUCCESS;
    }
}
