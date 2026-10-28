<?php

namespace Webkul\TimeOff\Services;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;

/**
 * Business rule for short leave (Time Off by the hour, request_unit_hours
 * with request_hour_from / request_hour_to on one day): at most
 * attendance.short_leave.max_hours hours each and at most
 * attendance.short_leave.max_per_month per employee per calendar month
 * (refused and cancelled requests do not count). Anything longer must be a half day.
 *
 * Enforced on the Leave model itself (saving) so every path -- the Time Off
 * forms, the calendar widgets, the API, submit for approval -- is covered.
 */
class ShortLeavePolicy
{
    /**
     * @param  array<string, mixed>  $attributes  employee_id, request_unit_hours, request_hour_from, request_hour_to, request_date_from[, request_date_to]
     *
     * @throws ValidationException
     */
    public function validate(array $attributes, ?int $ignoreLeaveId = null): void
    {
        if (! (bool) ($attributes['request_unit_hours'] ?? false)) {
            return;
        }

        $maxHours = (float) config('attendance.short_leave.max_hours', 3);
        $maxPerMonth = (int) config('attendance.short_leave.max_per_month', 2);
        $from = $attributes['request_hour_from'] ?? null;
        $to = $attributes['request_hour_to'] ?? null;

        if ($from === null || $to === null || $from === '' || $to === '') {
            throw ValidationException::withMessages(['request_hour_from' => 'A short leave needs a from and a to time.']);
        }

        $hours = round((float) $to - (float) $from, 4);
        if ($hours <= 0) {
            throw ValidationException::withMessages(['request_hour_to' => 'The short leave must end after it starts.']);
        }

        if ($hours > $maxHours + 0.0001) {
            throw ValidationException::withMessages(['request_hour_to' => sprintf(
                'A short leave can be at most %s hours. For longer, request a half day.',
                rtrim(rtrim(number_format($maxHours, 2), '0'), '.'),
            )]);
        }

        $date = $attributes['request_date_from'] ?? null;
        $end = $attributes['request_date_to'] ?? $date;
        if ($date === null) {
            throw ValidationException::withMessages(['request_date_from' => 'A short leave needs a date.']);
        }

        $day = Carbon::parse($date);
        if ($end !== null && Carbon::parse($end)->toDateString() !== $day->toDateString()) {
            throw ValidationException::withMessages(['request_date_to' => 'A short leave is for a single day.']);
        }

        $employeeId = (int) ($attributes['employee_id'] ?? 0);
        if ($employeeId <= 0 || $maxPerMonth <= 0) {
            return;
        }

        $taken = Leave::query()
            ->where('employee_id', $employeeId)
            ->where('request_unit_hours', true)
            ->whereNotIn('state', [State::REFUSE->value, State::CANCEL->value])
            ->whereBetween('request_date_from', [$day->copy()->startOfMonth()->toDateString(), $day->copy()->endOfMonth()->toDateString().' 23:59:59'])
            ->when($ignoreLeaveId, fn ($query) => $query->whereKeyNot($ignoreLeaveId))
            ->count();

        if ($taken >= $maxPerMonth) {
            throw ValidationException::withMessages(['request_date_from' => sprintf(
                'Only %d short leaves are allowed per month; %s already has %d in %s. Request a half day instead.',
                $maxPerMonth,
                'this employee',
                $taken,
                $day->format('F Y'),
            )]);
        }
    }

    /** Hours of a short leave from its from / to times. */
    public static function hours(mixed $from, mixed $to): float
    {
        return max(0.0, round((float) $to - (float) $from, 4));
    }
}
