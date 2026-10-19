<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ActivityLogEntry;
use App\Services\ActivityLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    // How many lines a page shows.
    private const PER_PAGE = 50;

    /**
     * The kinds of line an admin can filter by, with the names of the
     * actions each covers.
     *
     * @var array<string, array{label: string, actions: list<string>}>
     */
    private const KINDS = [
        'waitlist' => ['label' => 'Waitlist', 'actions' => ['waitlist.']],
        'deposits' => ['label' => 'Deposits', 'actions' => ['deposit.']],
        'reservations' => ['label' => 'Reservations', 'actions' => ['reservation.']],
        'requests' => ['label' => 'Reservation requests', 'actions' => ['request.']],
        'sessions' => ['label' => 'Sessions', 'actions' => ['session.']],
        'lanes' => ['label' => 'Lanes', 'actions' => ['lane.', 'lanes.']],
        'settings' => ['label' => 'Settings', 'actions' => ['settings.']],
        'users' => ['label' => 'User accounts', 'actions' => ['user.']],
        'logins' => ['label' => 'Logins', 'actions' => ['auth.']],
        'log' => ['label' => 'The log itself', 'actions' => ['log.']],
    ];

    /**
     * Show the activity log, newest first, filtered by days on the venue's
     * clock, by who, by kind, and by words in what happened.
     */
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'who' => ['nullable', 'string', 'regex:/\A(user:\d+|customer|system|unknown)\z/'],
            'kind' => ['nullable', Rule::in(array_keys(self::KINDS))],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $page = ActivityLogEntry::query()
            ->tap(fn (Builder $query) => $this->filter($query, $filters))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('settings/activity-log', [
            'entries' => collect($page->items())->map(fn (ActivityLogEntry $entry) => [
                'id' => $entry->id,
                'occurredAt' => $entry->occurred_at->toIso8601String(),
                'actorType' => $entry->actor_type,
                'actorName' => $entry->actor_name,
                'action' => $entry->action,
                'summary' => $entry->summary,
                'changes' => $entry->changes,
                'ipAddress' => $entry->ip_address,
            ])->all(),
            'page' => [
                'current' => $page->currentPage(),
                'last' => $page->lastPage(),
                'total' => $page->total(),
            ],
            'filters' => [
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
                'who' => $filters['who'] ?? null,
                'kind' => $filters['kind'] ?? null,
                'search' => $filters['search'] ?? null,
            ],
            'kinds' => collect(self::KINDS)->map(fn (array $kind) => $kind['label'])->all(),
            // Everyone who has done something as a user, by the name they
            // had then, including users removed since.
            'people' => ActivityLogEntry::query()
                ->where('actor_type', ActivityLog::ACTOR_USER)
                ->whereNotNull('actor_id')
                ->selectRaw('actor_id, max(actor_name) as actor_name')
                ->groupBy('actor_id')
                ->orderBy('actor_name')
                ->get()
                ->map(fn (ActivityLogEntry $entry) => ['value' => "user:{$entry->actor_id}", 'label' => $entry->actor_name])
                ->all(),
            'retentionMonths' => ActivityLog::RETENTION_MONTHS,
            'timezone' => config('bowling.timezone'),
        ]);
    }

    /**
     * Check every line still matches its fingerprint, and say what was found.
     */
    public function verify(ActivityLog $log): RedirectResponse
    {
        ['checked' => $checked, 'brokenAt' => $brokenAt] = $log->verify();

        Inertia::flash('toast', $brokenAt === null
            ? ['type' => 'success', 'message' => trans_choice('All :count line checks out. Nothing has been changed.|All :count lines check out. Nothing has been changed.', $checked)]
            : ['type' => 'error', 'message' => __('Line :id does not match its fingerprint. The log was changed outside the app from there on.', ['id' => $brokenAt])]);

        return back(fallback: route('activity-log.index'));
    }

    /**
     * Narrow the log to the lines the filters ask for.
     *
     * @param  Builder<ActivityLogEntry>  $query
     * @param  array<string, string|null>  $filters
     */
    private function filter(Builder $query, array $filters): void
    {
        $timezone = config('bowling.timezone');

        if (filled($filters['from'] ?? null)) {
            $query->where('occurred_at', '>=', CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()->utc());
        }

        if (filled($filters['to'] ?? null)) {
            $query->where('occurred_at', '<', CarbonImmutable::parse($filters['to'], $timezone)->startOfDay()->addDay()->utc());
        }

        $who = $filters['who'] ?? null;

        if (filled($who)) {
            str_starts_with($who, 'user:')
                ? $query->where('actor_type', ActivityLog::ACTOR_USER)->where('actor_id', (int) substr($who, 5))
                : $query->where('actor_type', $who);
        }

        if (filled($filters['kind'] ?? null)) {
            $query->where(function (Builder $query) use ($filters) {
                foreach (self::KINDS[$filters['kind']]['actions'] as $prefix) {
                    $query->orWhereLike('action', $prefix.'%');
                }
            });
        }

        if (filled($filters['search'] ?? null)) {
            // % and _ are escaped so they are looked for as typed.
            $query->whereLike('summary', '%'.addcslashes($filters['search'], '\\%_').'%');
        }
    }
}
