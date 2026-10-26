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

    private const ONLINE_OPEN = 'waitlist.online_open';

    private const CLOSED_MESSAGE = 'waitlist.online_closed_message';

    private const FINISH_BY_CLOSING = 'waitlist.finish_by_closing';

    private const MAX_WAIT = 'waitlist.max_wait_minutes';

    // The shortest and longest wait an admin can set as the limit.
    public const MAX_WAIT_LIMITS = ['min' => 10, 'max' => 600];

    // The most an admin can write to customers while joining online is closed.
    public const CLOSED_MESSAGE_LENGTH = 200;

    /**
     * Whether customers can join the waitlist online. On until an admin
     * closes it. Staff can add walk-ins at the counter either way, and
     * groups already in the line stay in it.
     */
    public function onlineJoiningOpen(): bool
    {
        return Setting::query()->where('key', self::ONLINE_OPEN)->value('value') ?? true;
    }

    /**
     * What an admin wrote for customers while joining online is closed, or
     * null when they wrote nothing.
     */
    public function closedMessage(): ?string
    {
        return Setting::query()->where('key', self::CLOSED_MESSAGE)->value('value');
    }

    /**
     * What a customer is told while joining online is closed: the admin's
     * message, or a plain one.
     */
    public function closedNotice(): string
    {
        return $this->closedMessage() ?? __('We are not taking groups online right now. Please ask our staff at the counter.');
    }

    /**
     * Open or close joining online, with what to tell customers while it is
     * closed. A blank message goes back to the plain one.
     */
    public function setOnlineJoining(bool $open, ?string $message): void
    {
        Setting::updateOrCreate(['key' => self::ONLINE_OPEN], ['value' => $open]);

        blank($message)
            ? Setting::forget(self::CLOSED_MESSAGE)
            : Setting::updateOrCreate(['key' => self::CLOSED_MESSAGE], ['value' => trim($message)]);
    }

    /**
     * Whether joining online is open, and what customers are told while it
     * is not, for the customer pages.
     *
     * @return array{open: bool, message: string}
     */
    public function onlineStatus(): array
    {
        return ['open' => $this->onlineJoiningOpen(), 'message' => $this->closedNotice()];
    }

    /**
     * Whether a group joining online must be able to finish before the venue
     * closes: its session, starting after the estimated wait, has to end by
     * closing time. On until an admin turns it off. Groups already in the
     * line are not affected, and are still called after closing.
     */
    public function finishByClosing(): bool
    {
        return Setting::query()->where('key', self::FINISH_BY_CLOSING)->value('value') ?? true;
    }

    /**
     * The longest wait, in minutes, at which groups can still join online,
     * or null for no limit. Above it, customers are asked to come to the
     * counter. No limit until an admin sets one.
     */
    public function maxWaitMinutes(): ?int
    {
        return Setting::query()->where('key', self::MAX_WAIT)->value('value');
    }

    /**
     * Set the limits on joining online: whether groups must finish by
     * closing time, and the longest wait, or null for no limit.
     */
    public function setJoinLimits(bool $finishByClosing, ?int $maxWaitMinutes): void
    {
        Setting::updateOrCreate(['key' => self::FINISH_BY_CLOSING], ['value' => $finishByClosing]);

        $maxWaitMinutes === null
            ? Setting::forget(self::MAX_WAIT)
            : Setting::updateOrCreate(['key' => self::MAX_WAIT], ['value' => $maxWaitMinutes]);
    }

    /**
     * The limits on joining online, for the join page.
     *
     * @return array{finishByClosing: bool, maxWaitMinutes: int|null}
     */
    public function joinLimits(): array
    {
        return ['finishByClosing' => $this->finishByClosing(), 'maxWaitMinutes' => $this->maxWaitMinutes()];
    }

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
