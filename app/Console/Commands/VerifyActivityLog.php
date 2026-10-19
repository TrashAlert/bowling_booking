<?php

namespace App\Console\Commands;

use App\Services\ActivityLog;
use Illuminate\Console\Command;

class VerifyActivityLog extends Command
{
    protected $signature = 'activity-log:verify';

    protected $description = 'Check no activity log line has been changed or removed out of turn';

    public function handle(ActivityLog $log): int
    {
        ['checked' => $checked, 'brokenAt' => $brokenAt] = $log->verify();

        if ($brokenAt !== null) {
            $this->error("Line {$brokenAt} does not match its fingerprint, or does not follow the line before it. The log was changed outside the app.");

            return self::FAILURE;
        }

        $this->info("All {$checked} line(s) check out.");

        return self::SUCCESS;
    }
}
