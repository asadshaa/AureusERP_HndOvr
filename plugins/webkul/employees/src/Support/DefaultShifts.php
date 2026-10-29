<?php

namespace Webkul\Employee\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The shifts every company starts with (HR parity plan 3.4) and one sample
 * rotation. HR can rename them, change times, switch them off or add more
 * in Employees -> Configurations -> Shifts / Roster patterns.
 *
 * General matches the company default (config/attendance.php office hours),
 * so moving someone from "no roster" to General changes nothing. The
 * 8-hour Morning / Evening / Night shifts have a 30-minute unpaid break
 * (deducted when no break is recorded) and 7h 30m required.
 */
final class DefaultShifts
{
    public const SamplePatternName = 'Rotating 3 shifts (5 on, 2 off)';

    /**
     * @return array<int, array{code: string, name: string, start_time: string, end_time: string, required_minutes: int, break_minutes: int, flexible_minutes: int, color: string, sort: int}>
     */
    public static function rows(): array
    {
        return [
            ['code' => 'G', 'name' => 'General', 'start_time' => '10:00:00', 'end_time' => '18:00:00', 'required_minutes' => 480, 'break_minutes' => 0, 'flexible_minutes' => 0, 'color' => '#64748b', 'sort' => 10],
            ['code' => 'M', 'name' => 'Morning', 'start_time' => '06:00:00', 'end_time' => '14:00:00', 'required_minutes' => 450, 'break_minutes' => 30, 'flexible_minutes' => 0, 'color' => '#f59e0b', 'sort' => 20],
            ['code' => 'E', 'name' => 'Evening', 'start_time' => '14:00:00', 'end_time' => '22:00:00', 'required_minutes' => 450, 'break_minutes' => 30, 'flexible_minutes' => 0, 'color' => '#8b5cf6', 'sort' => 30],
            ['code' => 'N', 'name' => 'Night', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'required_minutes' => 450, 'break_minutes' => 30, 'flexible_minutes' => 0, 'color' => '#1e3a8a', 'sort' => 40],
        ];
    }

    /**
     * The sample rotation as [shift code (null = off), days].
     *
     * @return array<int, array{0: ?string, 1: int}>
     */
    public static function samplePattern(): array
    {
        return [['M', 5], [null, 2], ['E', 5], [null, 2], ['N', 5], [null, 2]];
    }

    /** Creates the missing default shifts and the sample rotation of one company (idempotent). */
    public static function provisionForCompany(int $companyId): int
    {
        if (! Schema::hasTable('employees_shifts')) {
            return 0;
        }

        $now = now();
        $created = DB::table('employees_shifts')->insertOrIgnore(array_map(fn (array $shift): array => $shift + [
            'company_id' => $companyId,
            'is_active'  => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::rows()));

        self::provisionPatternForCompany($companyId);

        return $created;
    }

    /** The sample rotation, when the company still has all of its shifts and no pattern of that name. */
    public static function provisionPatternForCompany(int $companyId): void
    {
        if (! Schema::hasTable('employees_shift_rosters')) {
            return;
        }

        $shiftIds = DB::table('employees_shifts')->where('company_id', $companyId)->pluck('id', 'code');
        $steps = [];
        foreach (self::samplePattern() as [$code, $days]) {
            if ($code !== null && ! isset($shiftIds[$code])) {
                return;
            }

            $steps[] = ['shift_id' => $code === null ? null : (int) $shiftIds[$code], 'days' => $days];
        }

        $now = now();
        DB::table('employees_shift_rosters')->insertOrIgnore([
            'company_id'  => $companyId,
            'name'        => self::SamplePatternName,
            'pattern'     => json_encode($steps),
            'cycle_days'  => array_sum(array_column($steps, 'days')),
            'description' => 'Sample: Morning x5, Off x2, Evening x5, Off x2, Night x5, Off x2. Assign it from Attendance -> Shift roster.',
            'is_active'   => true,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
    }
}
