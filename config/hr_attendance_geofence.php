<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Browser GPS geofenced attendance
    |--------------------------------------------------------------------------
    |
    | Kill switch. Off by default: when false the "My Attendance" page is
    | hidden and the service refuses new attempts. Existing attendance data,
    | including GPS-created records, is unaffected.
    |
    */
    'enabled' => env('HR_ATTENDANCE_GEOFENCE_ENABLED', false),
    'hr_only' => (bool) env('HR_ATTENDANCE_HR_ONLY', false),

    'radius_min_meters'     => 50,
    'radius_max_meters'     => 5000,
    'default_radius_meters' => 150,

    // Accuracy policy (metres, 68% confidence radius as reported by the browser).
    'accuracy_verified_max'     => 50,   // inside + <= this  => verified
    'accuracy_reviewable_max'   => 150,  // inside + <= this  => accepted, needs review; above => rejected
    'accuracy_suspicious_below' => 2,    // < this => flagged (typical of mock-location tools)

    'max_location_age_seconds' => 120,
    'max_clock_skew_seconds'   => 60,

    // An open record older than this is treated as a forgotten check-out.
    'max_shift_hours' => 16,

    // A check-out that makes the shift longer than this (or, when the employee
    // has a schedule, later than the scheduled end plus the grace) is recorded
    // but flagged for review: it is usually a forgotten check-out tapped late.
    'long_shift_hours'       => 12,
    'long_shift_grace_hours' => 3,

    // What hr:auto-complete-shifts may do with an open shift. "none" (default):
    // never close it -- the forgotten-shift flag + review queue handles it.
    // "scheduled_end": close shifts that have a scheduled end at that end,
    // always marked needs-review (never "verified"); no-schedule shifts stay open.
    'auto_close_policy' => env('HR_ATTENDANCE_AUTO_CLOSE_POLICY', 'none'),

    // review | reject
    'checkout_outside_geofence' => 'review',
    'checkout_without_location' => 'review',

    'max_candidate_locations' => 20,
    'rate_limit_per_minute'   => 10,

    // Minimum duration before an employee is permitted to check out after checking in (in minutes).
    'min_checkout_interval_minutes' => (int) env('HR_ATTENDANCE_MIN_CHECKOUT_MINUTES', 60),

    // Thresholds for auto-marking attendance status based on worked hours
    'full_day_min_hours' => (float) env('HR_ATTENDANCE_FULL_DAY_MIN_HOURS', 7.5),
    'half_day_min_hours' => (float) env('HR_ATTENDANCE_HALF_DAY_MIN_HOURS', 4.0),

    // Precise evidence (coordinates, IP, user agent) is nulled after this many days.
    'evidence_retention_days' => 365,
];
