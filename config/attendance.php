<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Monthly attendance report (HR Google Sheet template)
    |--------------------------------------------------------------------------
    |
    | Rules behind Attendance -> Attendance Report and its Excel download
    | (Webkul\Employee\Services\Attendance\MonthlyAttendanceReport).
    |
    | Per working day: time inside the office window counts in full, time
    | outside it (arriving early / staying late) counts up to
    | max_outside_credit_minutes. Required = working days x
    | daily_required_minutes; weekends, public holidays and approved leave are
    | not required. Penalty % = shortfall / required.
    |
    */
    'monthly_report' => [
        // Office window, local time (24h "H:i").
        'office_start' => env('ATTENDANCE_OFFICE_START', '10:00'),
        'office_end'   => env('ATTENDANCE_OFFICE_END', '18:00'),

        'daily_required_minutes'     => (int) env('ATTENDANCE_DAILY_REQUIRED_MINUTES', 480),
        'max_outside_credit_minutes' => (int) env('ATTENDANCE_MAX_OUTSIDE_CREDIT_MINUTES', 60),

        // First In later than office_start + this counts as a late day.
        'late_grace_minutes' => (int) env('ATTENDANCE_LATE_GRACE_MINUTES', 0),

        // Time zone the punches are shown and measured in (all companies are in Pakistan).
        'timezone' => env('ATTENDANCE_REPORT_TIMEZONE', 'Asia/Karachi'),

        // ISO-8601 day numbers (1 = Monday ... 7 = Sunday). Default: Saturday and Sunday.
        'weekend_days' => [6, 7],

        // false (template): weekend / holiday rows appear only when somebody punched in.
        'show_off_days' => (bool) env('ATTENDANCE_REPORT_SHOW_OFF_DAYS', false),

        // Whether hours worked on a weekend or public holiday count towards "Total Hrs Worked".
        // Off by default, so weekend work cannot offset weekday shortfall; it is reported separately.
        'count_off_day_work' => (bool) env('ATTENDANCE_REPORT_COUNT_OFF_DAY_WORK', false),

        // An approved Time Off whose leave type name matches this is On-Duty (OD), not leave.
        // A half-day OD (Time Off "half day", morning / afternoon) is "Half OD - first / second half".
        'on_duty_leave_type_pattern' => '/\b(on[\s\-_]?duty|o\.?d\.?)\b/i',
    ],

    /*
    |--------------------------------------------------------------------------
    | Short leave (Time Off by the hour, from-to time on one day)
    |--------------------------------------------------------------------------
    |
    | Enforced server-side by Webkul\TimeOff\Services\ShortLeavePolicy when a
    | request is created, edited or submitted. Longer must be a half day.
    | Refused requests do not count towards the monthly limit.
    |
    */
    'short_leave' => [
        'max_hours'     => (float) env('ATTENDANCE_SHORT_LEAVE_MAX_HOURS', 3),
        'max_per_month' => (int) env('ATTENDANCE_SHORT_LEAVE_MAX_PER_MONTH', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Time bank / grace balance defaults
    |--------------------------------------------------------------------------
    |
    | Defaults for a company without its own policy (Attendance -> Attendance
    | Policy, table employees_attendance_policies). The daily extra-credit cap
    | defaults to monthly_report.max_outside_credit_minutes.
    |
    */
    'time_bank' => [
        'monthly_grace_minutes' => (int) env('ATTENDANCE_MONTHLY_GRACE_MINUTES', 0),
        // Unused earned extra of the previous month opens the next month (one month, no chaining).
        'carry_over' => (bool) env('ATTENDANCE_TIME_BANK_CARRY_OVER', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Employee attendance notifications
    |--------------------------------------------------------------------------
    |
    | Panel (bell) notifications to employees and line managers, sent by
    | Webkul\Employee\Services\Attendance\EmployeeAttendanceNotifier and
    | LineManagerAttendanceDigest. Each type can be switched off here; all are
    | on by default. Every notice is sent at most once (keyed per employee /
    | day, or per month for the negative grace balance).
    |
    */
    'notifications' => [
        // hr:remind-missing-check-in: "You haven't checked in today", this many minutes after
        // the employee is due (10:15; 14:15 after an approved first half off).
        'check_in_reminder'               => (bool) env('ATTENDANCE_NOTIFY_CHECK_IN_REMINDER', true),
        'check_in_reminder_after_minutes' => (int) env('ATTENDANCE_CHECK_IN_REMINDER_AFTER_MINUTES', 15),

        // Right after a late first check-in of the day (queued, after commit), with the grace balance.
        'late_check_in' => (bool) env('ATTENDANCE_NOTIFY_LATE_CHECK_IN', true),
        // Only check-ins recorded this recently are "right now" (imports / back-dated entries are not notified).
        'late_check_in_max_age_minutes' => (int) env('ATTENDANCE_LATE_CHECK_IN_MAX_AGE_MINUTES', 120),

        // hr:flag-forgotten-shifts also tells the employee to check out or request a correction.
        'forgotten_check_out' => (bool) env('ATTENDANCE_NOTIFY_FORGOTTEN_CHECK_OUT', true),

        // Time Off approved / refused (full day, half day, short leave, On-Duty).
        'leave_decision' => (bool) env('ATTENDANCE_NOTIFY_LEAVE_DECISION', true),

        // First time in a month the grace balance goes below zero (hr:grace-balance-alerts and late check-ins).
        'negative_grace_balance' => (bool) env('ATTENDANCE_NOTIFY_NEGATIVE_GRACE_BALANCE', true),

        // hr:line-manager-attendance-digest: each line manager's own team, late / absent so far.
        'line_manager_digest' => (bool) env('ATTENDANCE_NOTIFY_LINE_MANAGER_DIGEST', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shifts, rotating rosters and overnight shifts
    |--------------------------------------------------------------------------
    |
    | Day schedule order (AttendanceScheduleResolver): day override > roster
    | (employee, then department) > work schedule > company default. An
    | overnight shift (22:00-06:00) belongs to the date it starts; a GPS
    | check-in / out, manual entry or device punch after midnight belongs to
    | the previous day's shift while it is before that shift's end plus
    | overnight_tolerance_minutes.
    |
    */
    'shifts' => [
        'overnight_tolerance_minutes' => (int) env('ATTENDANCE_OVERNIGHT_TOLERANCE_MINUTES', 240),

        // Attendance -> Shift roster: weeks shown at once, and the days an employee sees ahead.
        'roster_weeks'  => 2,
        'upcoming_days' => 7,
    ],
];
