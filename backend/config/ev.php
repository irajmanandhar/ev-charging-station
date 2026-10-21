<?php

return [
    // Booking grid and limits
    'slot_minutes' => 15,
    'min_booking_minutes' => 30,
    'max_booking_minutes' => 240,
    'booking_horizon_days' => 7,
    'max_upcoming_bookings_per_user' => 3,

    // Check-in window around a booking's start time
    'checkin_early_minutes' => 10,
    'no_show_grace_minutes' => 15,

    // Energy estimate: delivered kWh = power_kw * hours * efficiency, capped per session
    'charge_efficiency' => 0.85,
    'max_session_kwh' => 80,

    // Cancelling less than this many minutes before the start keeps a fee (percent of the estimated cost)
    'cancellation_free_minutes' => 60,
    'cancellation_fee_percent' => 20,

    // Email a reminder this many minutes before a confirmed booking starts
    'reminder_minutes_before' => 30,

    // Used for operator/admin dashboards, which span stations
    'default_timezone' => 'Asia/Kathmandu',

    'currency' => 'NPR',

    // Only ever honoured in local/testing environments; lets you finish sign-up without a mail server.
    'expose_otp' => (bool) env('EXPOSE_OTP_IN_RESPONSE', false),

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
];
