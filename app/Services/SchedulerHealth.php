<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Whether the scheduler is doing its jobs. Each job the scheduler runs every
 * minute notes when it last finished, and a job that has gone quiet is
 * reported, so staff hear about it before groups stop being called.
 *
 * Each job is watched on its own, because one job can be stuck while the
 * rest still run: a job that crashes keeps its "already running" lock for a
 * day. The times are kept in the app's cache, which the scheduler and the
 * web pages share; emptying the cache makes every job look as if it has
 * never run, until its next run a minute later.
 */
class SchedulerHealth
{
    /**
     * The jobs the scheduler runs every minute, by their command name.
     *
     * @var list<string>
     */
    public const JOBS = [
        'bookings:release-expired-holds',
        'bookings:mark-no-shows',
        'bookings:complete-finished',
        'waitlist:process',
    ];

    // How long a job can go without finishing before it counts as stopped.
    // It runs every minute, so this leaves room for a slow run or two.
    public const STALE_MINUTES = 5;

    /**
     * Note that a job has just finished.
     */
    public function recordRun(string $job): void
    {
        Cache::forever($this->key($job), now()->utc()->toIso8601String());
    }

    /**
     * The jobs that have not finished in the last STALE_MINUTES minutes, in
     * the order of JOBS, with when each last finished as an ISO 8601 string
     * in UTC, or null when it has never run (as far as the cache knows).
     *
     * @return list<array{job: string, lastRunAt: string|null}>
     */
    public function stalled(): array
    {
        $cutoff = now()->subMinutes(self::STALE_MINUTES);
        $stalled = [];

        foreach (self::JOBS as $job) {
            $lastRunAt = Cache::get($this->key($job));

            if ($lastRunAt === null || CarbonImmutable::parse($lastRunAt)->lessThan($cutoff)) {
                $stalled[] = ['job' => $job, 'lastRunAt' => $lastRunAt];
            }
        }

        return $stalled;
    }

    private function key(string $job): string
    {
        return "scheduler.last_run.{$job}";
    }
}
