<?php

namespace Webkul\Employee\Services\Attendance;

use Illuminate\Support\Facades\Cache;
use Webkul\Employee\Models\ShiftOverride;
use Webkul\Employee\Models\ShiftRosterAssignment;

/**
 * Whether a company uses shift rosters at all (any roster assignment or day
 * override), kept in the cache (memoized for the request) so the schedules
 * of the companies and employees that are not rostered cost no roster
 * queries on the dashboard, Today's attendance and the reports.
 *
 * Forgotten whenever an assignment or override of the company is saved or
 * deleted (ShiftRosterAssignment / ShiftOverride model events), so a stale
 * answer can only ever be "yes" (a few extra queries), never "no".
 */
final class RosterPresence
{
    public static function key(int $companyId): string
    {
        return 'attendance:shift-rosters:company:'.$companyId;
    }

    /**
     * @param  array<int, int>  $companyIds
     * @return array<int, int> the given companies that have roster data
     */
    public static function companiesWithRosters(array $companyIds): array
    {
        $result = [];

        foreach (array_values(array_unique(array_filter(array_map('intval', $companyIds)))) as $companyId) {
            if (self::has($companyId)) {
                $result[] = $companyId;
            }
        }

        return $result;
    }

    public static function has(int $companyId): bool
    {
        if ($companyId <= 0 || ! AttendanceScheduleResolver::shiftsAvailable()) {
            return false;
        }

        return (bool) Cache::memo()->rememberForever(self::key($companyId), fn (): bool => ShiftRosterAssignment::query()->where('company_id', $companyId)->exists()
            || ShiftOverride::query()->where('company_id', $companyId)->exists());
    }

    public static function forget(?int $companyId): void
    {
        if ($companyId !== null && $companyId > 0) {
            Cache::memo()->forget(self::key($companyId));
        }
    }
}
