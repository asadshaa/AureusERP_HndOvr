<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Approval reminders (approvals:send-reminders, hourly)
    |--------------------------------------------------------------------------
    |
    | Defaults for every approval workflow whose own reminder fields (Approval
    | Workflows screen) are empty. A request waiting at a step for
    | "after_hours" (calendar hours) gets a reminder to that step's approvers,
    | then one every "every_hours", up to "max_reminders"; one interval after
    | the last reminder it is escalated once to the company HR approvers (and
    | to a line-manager approver's own manager).
    |
    | Nothing is sent in the quiet hours (quiet_start to quiet_end) or on the
    | weekend days, in "timezone": a reminder due then waits for the next
    | working day at quiet_end. Weekend days use Carbon's numbering
    | (0 = Sunday ... 6 = Saturday).
    |
    */

    'reminders' => [
        'enabled'       => (bool) env('APPROVAL_REMINDERS_ENABLED', true),
        'after_hours'   => (int) env('APPROVAL_REMIND_AFTER_HOURS', 24),
        'every_hours'   => (int) env('APPROVAL_REMIND_EVERY_HOURS', 24),
        'max_reminders' => (int) env('APPROVAL_MAX_REMINDERS', 3),
        // A step waiting longer than this gets no reminder or escalation at all
        // (old requests are not chased); the Approval Queue still shows how long.
        'max_age_days'  => (int) env('APPROVAL_REMINDER_MAX_AGE_DAYS', 14),
        'timezone'      => env('APPROVAL_REMINDER_TIMEZONE', 'Asia/Karachi'),
        'quiet_start'   => '20:00',
        'quiet_end'     => '09:00',
        'weekend_days'  => [6, 0],
    ],

];
