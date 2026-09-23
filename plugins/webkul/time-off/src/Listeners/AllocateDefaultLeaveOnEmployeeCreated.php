<?php

namespace Webkul\TimeOff\Listeners;

use Illuminate\Support\Carbon;
use Webkul\Employee\Models\Employee;
use Webkul\TimeOff\Enums\AllocationType;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\LeaveAllocation;
use Webkul\TimeOff\Models\LeaveType;

/**
 * Client requirement: an employee should never need to ask HR to be given
 * leave before they can use it -- every employee gets a standing yearly
 * allocation automatically the moment their Employee record is created,
 * already approved (no separate HR sign-off step for the allocation
 * itself). This only ever grants the three canonical leave types this app
 * ships with (LeaveWorkflowSeeder); if a company hasn't been seeded with
 * them (e.g. a fresh/foreign company, or a test not exercising Time Off),
 * this silently does nothing rather than breaking employee creation.
 */
class AllocateDefaultLeaveOnEmployeeCreated
{
    /** @var array<string, float> */
    private const DEFAULT_DAYS = [
        'Annual Leave' => 12,
        'Casual Leave' => 8,
        'Sick Leave'   => 5,
    ];

    public function handle(Employee $employee): void
    {
        if (! $employee->company_id) {
            return;
        }

        $leaveTypes = LeaveType::query()
            ->where('company_id', $employee->company_id)
            ->whereIn('name', array_keys(self::DEFAULT_DAYS))
            ->get()
            ->keyBy('name');

        if ($leaveTypes->count() !== count(self::DEFAULT_DAYS)) {
            return;
        }

        $yearStart = Carbon::now()->startOfYear();
        $yearEnd = Carbon::now()->endOfYear();

        foreach (self::DEFAULT_DAYS as $typeName => $days) {
            $leaveType = $leaveTypes->get($typeName);

            LeaveAllocation::query()->firstOrCreate(
                [
                    'employee_id'       => $employee->id,
                    'holiday_status_id' => $leaveType->id,
                    'date_from'         => $yearStart,
                ],
                [
                    'company_id'           => $employee->company_id,
                    'employee_company_id'  => $employee->company_id,
                    'name'                 => "{$typeName} {$yearStart->year}",
                    'allocation_type'      => AllocationType::REGULAR,
                    'date_to'              => $yearEnd,
                    'number_of_days'       => $days,
                    'creator_id'           => $employee->creator_id ?? $employee->user_id,
                    'state'                => State::VALIDATE_TWO->value,
                ]
            );
        }
    }
}
