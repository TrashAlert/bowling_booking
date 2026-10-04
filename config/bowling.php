<?php

// Settings for how bookings behave. Each can be overridden in your .env file.

return [

    // The version shown in the staff sidebar. Not read from .env: raise it
    // here with each change that should be tracked.
    'version' => '0.7.1',

    // The venue's time zone. Opening hours are clock times in this zone.
    'timezone' => env('BOWLING_TIMEZONE', 'Asia/Kuala_Lumpur'),

    // Sessions are booked in steps of this many minutes. One step is also the
    // shortest session.
    'session_step_minutes' => (int) env('BOWLING_SESSION_STEP_MINUTES', 30),

    // The longest session that can be booked.
    'max_session_minutes' => (int) env('BOWLING_MAX_SESSION_MINUTES', 240),

    // How many people share one lane. It is also the most that one walk-in
    // entry can hold: a bigger group is entered once per lane.
    'max_players_per_lane' => (int) env('BOWLING_MAX_PLAYERS_PER_LANE', 6),

    // How long before a reservation starts its lanes stop taking anyone, so
    // they are sure to be empty on time.
    'reservation_lead_minutes' => (int) env('BOWLING_RESERVATION_LEAD_MINUTES', 60),

    // How long before a reservation starts its party can be checked in.
    // Earlier than that, check-in is refused.
    'check_in_opens_minutes' => (int) env('BOWLING_CHECK_IN_OPENS_MINUTES', 60),

    // How far ahead a reservation can be made.
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
    // and its lanes go back to the waitlist.
    'no_show_grace_minutes' => (int) env('BOWLING_NO_SHOW_GRACE_MINUTES', 15),

    // What a party pays to join the waitlist online, in cents. It counts
    // toward the party's bill once it is seated. Walk-ins added by staff at
    // the counter pay no deposit.
    'waitlist_deposit_cents' => (int) env('BOWLING_WAITLIST_DEPOSIT_CENTS', 1000),

    // The symbol shown in front of amounts of money.
    'currency_symbol' => env('BOWLING_CURRENCY_SYMBOL', 'RM'),

    // How long a called walk-in party has to check in at the desk before
    // they're skipped and the lane goes to the next party.
    'waitlist_checkin_minutes' => (int) env('BOWLING_WAITLIST_CHECKIN_MINUTES', 5),

];
