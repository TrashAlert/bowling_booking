<?php

namespace App\Console\Commands;

use App\Services\WaitlistService;
use Illuminate\Console\Command;

class ProcessWaitlist extends Command
{
    protected $signature = 'waitlist:process';

    protected $description = 'Skip parties who missed their call, then call the next parties for free lanes';

    public function handle(WaitlistService $waitlist): int
    {
        $skipped = $waitlist->skipExpiredCalls();
        $called = $waitlist->callNextParties();

        $this->info("Skipped {$skipped} party(s), called ".count($called).' party(s).');

        foreach ($called as $entry) {
            $this->line(" - {$entry->customer->name}, party of {$entry->party_size}");
        }

        return self::SUCCESS;
    }
}
