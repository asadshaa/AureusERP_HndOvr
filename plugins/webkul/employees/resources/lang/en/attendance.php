<?php

return [
    // Employee-safe wording. Never exposes distance, coordinates or thresholds.
    'results' => [
        'verified'               => 'Location verified.',
        'needs_review'           => 'Recorded, but your location could not be confirmed precisely, so HR may review it.',
        'outside_geofence'       => 'You appear to be outside your authorized workplace. Move closer and try again, or contact your manager.',
        'low_accuracy'           => 'Location accuracy is too low. Turn on precise location, move near a window or outdoors, and try again.',
        'invalid_coordinates'    => 'We could not read a valid location. Please try again.',
        'stale_location'         => 'Your location reading was out of date. Please try again.',
        'permission_denied'      => 'Location permission is required to check in. Allow location for this site in your browser settings, then try again.',
        'location_unavailable'   => 'Your phone could not determine your location. Check that location services are on, then try again.',
        'location_timeout'       => 'Finding your location took too long. Check that location services are on, then try again.',
        'insecure_context'       => 'Location can only be used over a secure (https) connection. Please use the official Aureus address.',
        'no_location_configured' => 'No workplace is set up for mobile check-in. Please contact HR.',
        'employee_not_eligible'  => 'Your employee profile is not set up for mobile check-in. Please contact HR.',
        'already_checked_in'     => 'You already checked in today.',
        'not_checked_in'         => 'You are not checked in.',
        'already_checked_out'    => 'You already checked out today.',
        'open_shift_exists'      => 'You are still checked in. Please check out first.',
        'on_leave_or_holiday'    => 'Today is recorded as leave or holiday. Contact HR if this is wrong.',
        'remote_exempt'          => 'Recorded (remote).',
        'review_approved'        => 'Review approved.',
        'review_rejected'        => 'Review rejected.',
        'too_early_for_checkout' => 'Minimum shift duration is 1 hour. You cannot check out yet.',
    ],

    'with_time' => [
        'already_checked_in'     => 'You already checked in today at :time.',
        'already_checked_out'    => 'You already checked out today at :time.',
        'open_shift_exists'      => 'You are still checked in since :time. Please check out first.',
        'too_early_for_checkout' => 'Minimum shift duration is 1 hour. Check-out will be available at :time.',
    ],

    'done' => [
        'check_in' => [
            'verified'      => 'Location verified. Checked in at :time.',
            'needs_review'  => 'Checked in at :time. Your location could not be confirmed precisely, so HR may review it.',
            'remote_exempt' => 'Checked in (remote) at :time.',
        ],
        'check_out' => [
            'verified'      => 'Location verified. Checked out at :time.',
            'needs_review'  => 'Checked out at :time. Your location could not be confirmed precisely, so HR may review it.',
            'remote_exempt' => 'Checked out (remote) at :time.',
        ],
    ],

    'rate_limited'     => 'Too many attempts. Please wait a minute and try again.',
    'unexpected_error' => 'Something went wrong. Please try again or contact HR.',
    'not_enabled'      => 'Mobile check-in is not enabled.',
    'privacy_note'     => 'Your location is checked only when you tap Check In or Check Out. It is not tracked at any other time.',
];
