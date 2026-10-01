<?php

// Settings for how bookings behave. Each can be overridden in your .env file.

return [

    // How long an unpaid online booking keeps its lanes reserved.
    'hold_minutes' => (int) env('BOWLING_HOLD_MINUTES', 10),

    // How long after the start time a reservation can arrive before it's a no-show
    // and its lanes go back to the waitlist.
    'no_show_grace_minutes' => (int) env('BOWLING_NO_SHOW_GRACE_MINUTES', 15),

    // How long a called walk-in party has to check in at the desk before
    // they're skipped and the lane goes to the next party.
    'waitlist_checkin_minutes' => (int) env('BOWLING_WAITLIST_CHECKIN_MINUTES', 5),

];
