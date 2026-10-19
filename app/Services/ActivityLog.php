<?php

namespace App\Services;

use App\Models\ActivityLogEntry;
use App\Models\Booking;
use App\Models\BookingRequest;
use App\Models\Lane;
use App\Models\Setting;
use App\Models\User;
use App\Models\WaitlistDeposit;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The activity log: one line for everything staff, customers and the app
 * itself do. A line is written in the same database transaction as what it
 * records, so the two are kept or undone together.
 *
 * Each line carries a fingerprint of itself and of the line before it, made
 * with the app's secret key. A line changed or removed afterwards, even
 * directly in the database, breaks the chain, which verify() finds. The
 * database itself refuses to change a line, and only removes lines more than
 * RETENTION_MONTHS old.
 */
class ActivityLog
{
    // How long lines are kept. The database's own rule says the same and
    // must be changed with it.
    public const RETENTION_MONTHS = 18;

    // Who a line is by.
    public const ACTOR_USER = 'user';

    public const ACTOR_CUSTOMER = 'customer';

    public const ACTOR_SYSTEM = 'system';

    // Someone not known to the app, such as a failed login.
    public const ACTOR_UNKNOWN = 'unknown';

    // Taken while a line is written, so lines are chained one at a time
    // even when two things happen at once.
    private const LOCK = 7_240_315_001;

    /**
     * What each setting is called in the log, by its key in the settings
     * table. A setting that is a stored picture is marked with "picture".
     *
     * @var array<string, string>
     */
    private const SETTINGS = [
        'bowling.waitlist_deposit_cents' => 'Deposit to join online (cents)',
        'bowling.waitlist_checkin_minutes' => 'Minutes to check in after being called',
        'bowling.waitlist_max_per_device' => 'Groups one phone can have in the online line',
        'bowling.no_show_grace_minutes' => 'Minutes a reservation can be late',
        'bowling.reservation_lead_minutes' => 'Minutes a lane closes before a reservation',
        'bowling.check_in_opens_minutes' => 'Minutes before the start check-in opens',
        'bowling.reservation_max_days_ahead' => 'Days ahead a reservation can be made',
        'bowling.max_players_per_lane' => 'People per lane',
        'bowling.max_session_minutes' => 'Longest session (minutes)',
        'bowling.currency_symbol' => 'Currency symbol',
        'bowling.timezone' => 'Time zone',
        'waitlist.deposit_required' => 'Deposit to join online is on',
        'waitlist.online_open' => 'Joining the waitlist online is open',
        'waitlist.online_closed_message' => 'Message while joining online is closed',
        'business.name' => 'Business name',
        'business.logo_path' => 'Logo',
        'business.brand_color' => 'Brand colour',
        'business.contact_phone' => 'Contact phone',
        'business.contact_address' => 'Contact address',
        'business.contact_map_url' => 'Map link',
        'business.social_whatsapp' => 'WhatsApp',
        'business.social_instagram' => 'Instagram',
        'business.social_facebook' => 'Facebook',
        'business.social_tiktok' => 'TikTok',
        'front_page.tagline' => 'Front page: line under the name',
        'front_page.waitlist_title' => 'Front page: waitlist card title',
        'front_page.waitlist_text' => 'Front page: waitlist card description',
        'front_page.waitlist_button' => 'Front page: waitlist card button',
        'front_page.reserve_title' => 'Front page: reservation card title',
        'front_page.reserve_text' => 'Front page: reservation card description',
        'front_page.reserve_button' => 'Front page: reservation card button',
        'front_page.cover_path' => 'Front page cover photo',
        'front_page.show_opening_hours' => 'Opening hours listed on the front page',
        'front_page.announcement' => 'Announcement',
        'front_page.announcement_until' => 'Announcement last day',
        'dashboard.hidden_parts' => 'Dashboard parts turned off',
    ];

    // Settings that hold where a picture is stored, not something to read.
    private const PICTURES = ['business.logo_path', 'front_page.cover_path'];

    // Who lines are by while asSystem() or as() runs, as [type, id, name].
    /** @var array{0: string, 1: int|null, 2: string}|null */
    private ?array $actingAs = null;

    /**
     * Write a line. $changes are what changed, each as [before, after].
     * $customerName names the customer when a customer did it.
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     */
    public function record(string $action, string $summary, ?Model $subject = null, array $changes = [], ?string $customerName = null): void
    {
        DB::transaction(function () use ($action, $summary, $subject, $changes, $customerName) {
            DB::select('select pg_advisory_xact_lock(?)', [self::LOCK]);

            [$actorType, $actorId, $actorName] = $this->actor($customerName);

            $line = [
                'occurred_at' => CarbonImmutable::now()->utc()->startOfSecond()->format('Y-m-d H:i:sP'),
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'actor_name' => mb_substr($actorName, 0, 255),
                'action' => $action,
                'subject_type' => $subject === null ? null : $this->subjectType($subject),
                'subject_id' => $subject?->getKey(),
                'summary' => $summary,
                'changes' => $changes === [] ? null : json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip_address' => request()->route() === null ? null : request()->ip(),
                'previous_hash' => DB::table('activity_logs')->orderByDesc('id')->value('hash'),
            ];

            DB::table('activity_logs')->insert([...$line, 'hash' => $this->fingerprint($line)]);
        });
    }

    /**
     * Run $callback with every line it writes put down to the app itself,
     * under $name, e.g. "Scheduler".
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function asSystem(string $name, Closure $callback): mixed
    {
        return $this->as(self::ACTOR_SYSTEM, null, $name, $callback);
    }

    /**
     * Run $callback with every line it writes put down to the given actor,
     * e.g. someone who failed to log in.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function as(string $type, ?int $id, string $name, Closure $callback): mixed
    {
        $before = $this->actingAs;
        $this->actingAs = [$type, $id, $name];

        try {
            return $callback();
        } finally {
            $this->actingAs = $before;
        }
    }

    /**
     * Note a setting an admin changed, set or cleared. Nothing is written
     * when the value is the same.
     */
    public function settingChanged(string $key, mixed $before, mixed $after): void
    {
        if ($before === $after) {
            return;
        }

        $label = self::SETTINGS[$key] ?? $key;

        if (in_array($key, self::PICTURES, true)) {
            $this->record('settings.changed', match (true) {
                $after === null => "Removed the {$this->lower($label)}.",
                $before === null => "Added a {$this->lower($label)}.",
                default => "Replaced the {$this->lower($label)}.",
            }, changes: [$label => [$before === null ? null : 'picture', $after === null ? null : 'new picture']]);

            return;
        }

        $this->record('settings.changed', match (true) {
            $after === null => "Cleared “{$label}”.",
            $before === null => "Set “{$label}”.",
            default => "Changed “{$label}”.",
        }, changes: [$label => [$before, $after]]);
    }

    /**
     * Check every line still matches its fingerprint and follows on from the
     * line before it. Returns how many lines were checked, and the first one
     * that does not hold up, if any.
     *
     * @return array{checked: int, brokenAt: int|null}
     */
    public function verify(): array
    {
        $checked = 0;
        $previous = null;
        $brokenAt = null;

        DB::table('activity_logs')->orderBy('id')->chunkById(1000, function ($lines) use (&$checked, &$previous, &$brokenAt) {
            foreach ($lines as $line) {
                $line = (array) $line;
                $follows = $previous === null || $line['previous_hash'] === $previous;
                $line['occurred_at'] = CarbonImmutable::parse($line['occurred_at'])->utc()->format('Y-m-d H:i:sP');

                if (! $follows || ! hash_equals($this->fingerprint($line), (string) $line['hash'])) {
                    $brokenAt = (int) $line['id'];

                    return false;
                }

                $previous = $line['hash'];
                $checked++;
            }

            return true;
        });

        return ['checked' => $checked, 'brokenAt' => $brokenAt];
    }

    /**
     * Remove the lines older than RETENTION_MONTHS, and note that it was
     * done. Returns how many were removed.
     */
    public function prune(): int
    {
        // A day's margin, so the database's own rule, which counts from its
        // own clock, never refuses a line right on the edge.
        $cutoff = CarbonImmutable::now()->subMonths(self::RETENTION_MONTHS)->subDay();

        return DB::transaction(function () use ($cutoff) {
            $removed = ActivityLogEntry::query()->where('occurred_at', '<', $cutoff)->delete();

            if ($removed > 0) {
                $this->record('log.pruned', trans_choice(
                    'Removed :count line older than :months months.|Removed :count lines older than :months months.',
                    $removed,
                    ['months' => self::RETENTION_MONTHS],
                ));
            }

            return $removed;
        });
    }

    /**
     * A moment on the venue's clock, e.g. "Sat 18 Oct, 8:00 PM".
     */
    public function when(CarbonInterface $moment): string
    {
        return CarbonImmutable::instance($moment)->setTimezone(config('bowling.timezone'))->format('D j M, g:i A');
    }

    /**
     * A clock time on the venue's clock, e.g. "8:00 PM".
     */
    public function time(CarbonInterface $moment): string
    {
        return CarbonImmutable::instance($moment)->setTimezone(config('bowling.timezone'))->format('g:i A');
    }

    /**
     * Lane numbers in words, e.g. "lane 3" or "lanes 3 and 4".
     *
     * @param  iterable<int>  $numbers
     */
    public function lanes(iterable $numbers): string
    {
        $numbers = collect($numbers)->sort()->values()->all();

        if ($numbers === []) {
            return 'no lane';
        }

        return (count($numbers) === 1 ? 'lane ' : 'lanes ').Arr::join($numbers, ', ', ' and ');
    }

    /**
     * A length of time in words, e.g. "1 hour 30 minutes".
     */
    public function length(int $minutes): string
    {
        return CarbonInterval::minutes($minutes)->cascade()->forHumans();
    }

    /**
     * An amount of money kept in cents, e.g. "RM 10.00".
     */
    public function money(int $cents): string
    {
        return config('bowling.currency_symbol').' '.number_format($cents / 100, 2);
    }

    /**
     * A party in words, e.g. "Farah's group (4 people)".
     */
    public function party(string $name, int $people): string
    {
        return "{$name}'s group ({$people} ".($people === 1 ? 'person' : 'people').')';
    }

    /**
     * Who is doing what is being logged, as [type, user id, name].
     *
     * @return array{0: string, 1: int|null, 2: string}
     */
    private function actor(?string $customerName): array
    {
        if ($this->actingAs !== null) {
            return $this->actingAs;
        }

        $user = Auth::user();

        if ($user instanceof User) {
            return [self::ACTOR_USER, $user->id, $user->name];
        }

        // Outside any web request: a command typed on the server.
        if (request()->route() === null) {
            return [self::ACTOR_SYSTEM, null, 'Command line'];
        }

        return [self::ACTOR_CUSTOMER, null, $customerName ?? 'Customer'];
    }

    /**
     * What kind of thing a line is about, as the log names it.
     */
    private function subjectType(Model $subject): string
    {
        return match ($subject::class) {
            Lane::class => 'lane',
            Booking::class => 'booking',
            WaitlistEntry::class => 'waitlist_entry',
            WaitlistDeposit::class => 'deposit',
            BookingRequest::class => 'booking_request',
            User::class => 'user',
            Setting::class => 'setting',
            default => class_basename($subject),
        };
    }

    /**
     * The fingerprint of a line, chained to the one before it and signed
     * with the app's secret key.
     *
     * @param  array<string, mixed>  $line
     */
    private function fingerprint(array $line): string
    {
        $fields = ['previous_hash', 'occurred_at', 'actor_type', 'actor_id', 'actor_name', 'action', 'subject_type', 'subject_id', 'summary', 'changes', 'ip_address'];

        $payload = implode("\x1F", array_map(fn (string $field) => (string) ($line[$field] ?? ''), $fields));

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    private function lower(string $label): string
    {
        return mb_strtolower(mb_substr($label, 0, 1)).mb_substr($label, 1);
    }
}
