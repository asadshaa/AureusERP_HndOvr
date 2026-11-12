<?php

namespace Webkul\Employee\Support;

use Webkul\Employee\Models\Employee;

/**
 * Walks up the line-manager chain (employees_employees.parent_id) to spot a
 * loop before it is saved: A reporting to B while B already reports to A.
 */
final class ReportingChain
{
    /** More levels than any real organisation; reaching it means the data already loops. */
    private const MaxDepth = 100;

    /** Whether making $managerId the line manager of $employeeId would close a loop (including a person as their own manager). */
    public static function wouldCreateLoop(int $employeeId, ?int $managerId): bool
    {
        return $managerId !== null && self::reportsTo($managerId, $employeeId);
    }

    /** Whether $employeeId is $managerId, or reports to them directly or through others. */
    public static function reportsTo(int $employeeId, int $managerId): bool
    {
        $current = $employeeId;
        $depth = 0;

        while ($current !== null) {
            if ($current === $managerId) {
                return true;
            }
            if (++$depth > self::MaxDepth) {
                return true;
            }

            $parent = Employee::query()->withTrashed()->whereKey($current)->value('parent_id');
            $current = $parent !== null ? (int) $parent : null;
        }

        return false;
    }
}
