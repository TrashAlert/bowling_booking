<?php

namespace App\Console\Commands;

use App\Services\ActivityLog;
use Illuminate\Console\Command;

class PruneActivityLog extends Command
{
    protected $signature = 'activity-log:prune';

    protected $description = 'Remove activity log lines older than 18 months';

    public function handle(ActivityLog $log): int
    {
        $removed = $log->asSystem('Scheduler', fn () => $log->prune());

        $this->info("Removed {$removed} line(s) older than ".ActivityLog::RETENTION_MONTHS.' months.');

        return self::SUCCESS;
    }
}
