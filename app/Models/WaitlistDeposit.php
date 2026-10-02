<?php

namespace App\Models;

use App\Enums\DepositOutcome;
use App\Enums\WaitlistStatus;
use Database\Factories\WaitlistDepositFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A party's request to join the waitlist online, and the deposit it pays for
 * it. It holds what the party asked for until the deposit is paid; only then
 * is the party put in line. The amount is zero when a lane was free for the
 * party as it joined: nothing is paid and it is in line at once. Walk-ins
 * added by staff at the counter have no record here.
 */
class WaitlistDeposit extends Model
{
    /** @use HasFactory<WaitlistDepositFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'minutes',
        'party_size',
        'amount_cents',
        'paid_at',
        'waitlist_entry_id',
    ];

    // Never send the secret token to the browser by accident.
    protected $hidden = ['token'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minutes' => 'integer',
            'party_size' => 'integer',
            'amount_cents' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * Give every new deposit a random, hard-to-guess token automatically.
     */
    protected static function booted(): void
    {
        static::creating(function (WaitlistDeposit $deposit) {
            $deposit->token ??= Str::random(40);
        });
    }

    /**
     * The place in line this deposit paid for. Empty until it is paid.
     *
     * @return BelongsTo<WaitlistEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(WaitlistEntry::class, 'waitlist_entry_id');
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    /**
     * What became of the deposit, or null while it is unpaid.
     *
     * This is read from what happened to the party in line and is not stored,
     * so it is right however the party left the line. A party that leaves
     * after being called had a lane held for it, which counts as a missed
     * call.
     */
    public function outcome(): ?DepositOutcome
    {
        $entry = $this->entry;

        if (! $this->isPaid() || $entry === null) {
            return null;
        }

        return match ($entry->status) {
            WaitlistStatus::Waiting, WaitlistStatus::Called => DepositOutcome::Held,
            WaitlistStatus::Seated => DepositOutcome::Applied,
            WaitlistStatus::Skipped => DepositOutcome::Forfeited,
            WaitlistStatus::Left => $entry->called_at === null
                ? DepositOutcome::RefundDue
                : DepositOutcome::Forfeited,
        };
    }
}
