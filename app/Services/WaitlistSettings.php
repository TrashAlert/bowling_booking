<?php

namespace App\Services;

use App\Models\Setting;

/**
 * The choices an admin makes about the waitlist, under Settings. A choice
 * nobody has saved yet has its default.
 */
class WaitlistSettings
{
    private const DEPOSIT_REQUIRED = 'waitlist.deposit_required';

    /**
     * Whether a party pays a deposit to join the waitlist online when it
     * would have to wait for a lane. On until an admin turns it off.
     */
    public function depositRequired(): bool
    {
        return Setting::query()->where('key', self::DEPOSIT_REQUIRED)->value('value') ?? true;
    }

    /**
     * Turn the deposit for joining online on or off.
     */
    public function requireDeposit(bool $required): void
    {
        Setting::updateOrCreate(['key' => self::DEPOSIT_REQUIRED], ['value' => $required]);
    }
}
