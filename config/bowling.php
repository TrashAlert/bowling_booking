<?php

use App\Services\Payments\StandInPaymentProvider;

// Settings for how bookings behave. Each can be overridden in your .env file.

return [

    // The version shown in the staff sidebar. Not read from .env: raise it
    // here with each change that should be tracked.
    'version' => '1.6.0',

    // The venue's time zone. Opening hours are clock times in this zone. An
    // admin can change it under Settings, Business.
    'timezone' => env('BOWLING_TIMEZONE', 'Asia/Kuala_Lumpur'),

    // Sessions are booked in steps of this many minutes. One step is also the
    // shortest session.
    'session_step_minutes' => (int) env('BOWLING_SESSION_STEP_MINUTES', 30),

    // The longest session that can be booked. An admin can change it under
    // Settings, Booking rules.
    'max_session_minutes' => (int) env('BOWLING_MAX_SESSION_MINUTES', 240),

    // How many people share one lane. It is also the most that one walk-in
    // entry can hold: a bigger group is entered once per lane. An admin can
    // change it under Settings, Booking rules.
    'max_players_per_lane' => (int) env('BOWLING_MAX_PLAYERS_PER_LANE', 6),

    // How long before a reservation starts its lanes stop taking anyone, so
    // they are sure to be empty on time. An admin can change it under
    // Settings, Booking rules; reservations already made keep the closing
    // time they were given.
    'reservation_lead_minutes' => (int) env('BOWLING_RESERVATION_LEAD_MINUTES', 60),

    // How long before a reservation starts its party can be checked in.
    // Earlier than that, check-in is refused. An admin can change it under
    // Settings, Booking rules.
    'check_in_opens_minutes' => (int) env('BOWLING_CHECK_IN_OPENS_MINUTES', 60),

    // How far ahead a reservation can be made, by staff or asked for by a
    // customer. An admin can change it under Settings, Booking rules.
    'reservation_max_days_ahead' => (int) env('BOWLING_RESERVATION_MAX_DAYS_AHEAD', 90),

    // The lengths staff can pick when closing a lane for a short job such as
    // re-oiling. The lane reopens by itself afterwards.
    'closure_minutes' => [15, 30, 45, 60],

    // The estimates staff can give for a repair, in days. Only a guide: a
    // lane under repair stays closed until staff reopen it.
    'repair_days' => [1, 2, 3],

    // How long an unpaid online booking keeps its lanes reserved.
    'hold_minutes' => (int) env('BOWLING_HOLD_MINUTES', 10),

    // How long after the start time a reservation can arrive before it's a no-show
    // and its lanes go back to the waitlist. An admin can change it under
    // Settings, Booking rules.
    'no_show_grace_minutes' => (int) env('BOWLING_NO_SHOW_GRACE_MINUTES', 15),

    // What a party pays to join the waitlist online, in cents. It counts
    // toward the party's bill once it is seated. Walk-ins added by staff at
    // the counter pay no deposit. An admin can change it under Settings,
    // Deposit; this is only what it is until they do.
    'waitlist_deposit_cents' => (int) env('BOWLING_WAITLIST_DEPOSIT_CENTS', 1000),

    // Who takes that deposit. "provider" names one of "providers"; the
    // stand-in takes no money and is only for trying the waitlist out. To
    // connect a real one (FPX, DuitNow, cards), write a class that implements
    // App\Services\Payments\DepositPaymentProvider, list it here under a
    // short name, and set BOWLING_DEPOSIT_PAYMENT_PROVIDER to that name.
    'deposit_payments' => [
        'provider' => env('BOWLING_DEPOSIT_PAYMENT_PROVIDER', StandInPaymentProvider::NAME),

        'providers' => [
            StandInPaymentProvider::NAME => StandInPaymentProvider::class,
        ],
    ],

    // The address customers reach the public pages at, e.g.
    // "https://bowl.example.com". The QR code a walk-in scans to follow the
    // line links there. Empty means the address the counter's screen was
    // opened on, which only works for phones on the venue's own network.
    'public_url' => env('BOWLING_PUBLIC_URL'),

    // The symbol shown in front of amounts of money. An admin can change it
    // under Settings, Business.
    'currency_symbol' => env('BOWLING_CURRENCY_SYMBOL', 'RM'),

    // How many groups one phone or browser can have in the online waitlist
    // at the same time, so one person can't fill the line with made-up
    // groups. A real group bigger than that asks at the counter. An admin
    // can change it under Settings, Booking rules.
    'waitlist_max_per_device' => (int) env('BOWLING_WAITLIST_MAX_PER_DEVICE', 2),

    // How long a called walk-in party has to check in at the desk before
    // they're skipped and the lane goes to the next party. An admin can
    // change it under Settings, Booking rules.
    'waitlist_checkin_minutes' => (int) env('BOWLING_WAITLIST_CHECKIN_MINUTES', 5),

];
