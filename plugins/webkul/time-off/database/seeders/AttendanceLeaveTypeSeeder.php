<?php

namespace Webkul\TimeOff\Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Support\Models\Company;
use Webkul\TimeOff\Models\LeaveType;

/**
 * The leave types the attendance rules rely on (business decisions of
 * 2026-10-28 and 2026-10-29), per company:
 *
 *  - "Half day"    : half-day unit, covers half the day in attendance; no
 *                    allocation, and it does not count as leave: nothing is
 *                    deducted from any balance (LeaveBalanceService).
 *  - "Short leave" : hour unit (from-to time), no allocation; limited by
 *                    attendance.short_leave.* (ShortLeavePolicy).
 *  - "On Duty"     : no allocation; recognised by the monthly report through
 *                    attendance.monthly_report.on_duty_leave_type_pattern.
 *
 * All are approved by the line manager through the existing leave_request
 * workflow (LeaveWorkflowSeeder / LeaveApprovalService). Idempotent: matched
 * by company + name, an existing row is never changed.
 */
class AttendanceLeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->each(fn (Company $company) => $this->provisionForCompany($company));
    }

    public function provisionForCompany(Company $company): void
    {
        $common = [
            'leave_validation_type'      => 'manager',
            'employee_requests'          => 'no',
            'allocation_validation_type' => 'manager',
            'is_active'                  => true,
            'show_on_dashboard'          => true,
            'unpaid'                     => false,
        ];

        $types = [
            'Half day' => [
                'requires_allocation' => 'no',
                'time_type'           => 'leave',
                'request_unit'        => 'half_day',
            ],
            'Short leave' => [
                'requires_allocation' => 'no',
                'time_type'           => 'leave',
                'request_unit'        => 'hour',
            ],
            'On Duty' => [
                'requires_allocation' => 'no',
                'time_type'           => 'other',
                'request_unit'        => 'day',
            ],
        ];

        foreach ($types as $name => $attributes) {
            LeaveType::query()->firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                $attributes + $common,
            );
        }
    }
}
