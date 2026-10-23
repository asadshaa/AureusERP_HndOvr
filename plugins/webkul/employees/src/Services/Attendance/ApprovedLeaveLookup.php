<?php

namespace Webkul\Employee\Services\Attendance;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The single rule attendance uses to read leave from the Time Off plugin
 * (which stays authoritative -- nothing is copied out of it).
 *
 *  - "Approved" means state = validate_two only. confirm / validate_one are
 *    pending and never excuse absence; refuse never counts.
 *  - The leave's dates are request_date_from / request_date_to, which are
 *    always filled; date_from / date_to are only a fallback (they are NULL on
 *    many real leaves, which is why several screens used to miss approved
 *    leave entirely).
 */
class ApprovedLeaveLookup
{
    public const APPROVED_STATE = 'validate_two';

    /** @var array<int, string> */
    public const PENDING_STATES = ['confirm', 'validate_one'];

    private const START = 'COALESCE(request_date_from, date_from)';

    private const END = 'COALESCE(request_date_to, date_to)';

    public static function available(): bool
    {
        return Schema::hasTable('time_off_leaves');
    }

    /**
     * Restrict a time_off_leaves query (Eloquent or query builder) to leave
     * whose dates overlap [$from, $to] (inclusive, Y-m-d).
     */
    public static function overlapping(Builder $query, string $from, string $to): Builder
    {
        return $query
            ->whereRaw('DATE('.self::START.') <= ?', [$to])
            ->whereRaw('DATE('.self::END.') >= ?', [$from]);
    }

    /** Approved leave of one employee overlapping [$from, $to]. */
    public static function approvedForEmployee(int $employeeId, string $from, string $to): Builder
    {
        return self::overlapping(
            DB::table('time_off_leaves')->where('employee_id', $employeeId)->where('state', self::APPROVED_STATE),
            $from,
            $to,
        );
    }

    /** Leave start date (Y-m-d) of a leave row/model, using the same fallback rule. */
    public static function startDate(object $leave): ?string
    {
        $value = $leave->request_date_from ?? $leave->date_from ?? null;

        return $value ? Carbon::parse($value)->toDateString() : null;
    }

    public static function endDate(object $leave): ?string
    {
        $value = $leave->request_date_to ?? $leave->date_to ?? null;

        return $value ? Carbon::parse($value)->toDateString() : null;
    }

    public static function covers(object $leave, string $date): bool
    {
        $start = self::startDate($leave);
        $end = self::endDate($leave);

        return $start !== null && $end !== null && $start <= $date && $end >= $date;
    }

    public static function isApproved(object $leave): bool
    {
        $state = $leave->state ?? null;

        return ($state instanceof \BackedEnum ? $state->value : $state) === self::APPROVED_STATE;
    }
}
