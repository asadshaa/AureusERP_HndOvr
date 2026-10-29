<?php

namespace Webkul\TimeOff\Listeners;

use Illuminate\Support\Carbon;
use Webkul\Employee\Models\Employee;
use Webkul\TimeOff\Enums\AllocationType;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\LeaveAllocation;
use Webkul\TimeOff\Models\LeaveAllocationPolicy;
use Webkul\TimeOff\Models\LeaveType;
use Webkul\TimeOff\Services\LeaveBalanceService;
use Webkul\TimeOff\Services\YearlyLeaveAllocationService;

/**
 * Client requirement: an employee should never need to ask HR to be given
 * leave before they can use it -- every employee gets a standing yearly
 * allocation automatically the moment their Employee record is created,
 * already approved (no separate HR sign-off step for the allocation
 * itself). This only ever grants the three canonical leave types this app
 * ships with (LeaveWorkflowSeeder, also provisioned for every new company);
 * if a company has none of its own (e.g. a test not exercising Time Off),
 * this silently does nothing rather than breaking employee creation.
 *
 * Days: the company's yearly allocation policy, else the defaults
 * (LeaveAllocationPolicy::DEFAULTS: Annual 12, Sick 8, Casual 5). The first
 * year is prorated from the joining date like the yearly allocation
 * (YearlyLeaveAllocationService::prorate: days x months left in the year,
 * joining month counted, / 12, nearest half day); without a joining date the
 * full days are granted, and someone joining in a later year gets nothing
 * now (the yearly allocation of that year covers them).
 */
class AllocateDefaultLeaveOnEmployeeCreated
{
    public function handle(Employee $employee): void
    {
        if (! $employee->company_id) {
            return;
        }

        $defaults = LeaveAllocationPolicy::DEFAULTS;

        $leaveTypes = LeaveType::query()
            ->where('company_id', $employee->company_id)
            ->whereIn('name', array_keys($defaults))
            ->orderBy('id')
            ->get()
            ->unique('name')
            ->keyBy('name');

        if ($leaveTypes->count() !== count($defaults)) {
            return;
        }

        // The same allocation year and key (date_from = 1 January) as the yearly allocation.
        $year = LeaveBalanceService::today()->year;
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->endOfDay();
        $joiningDate = $employee->joining_date ? Carbon::parse($employee->joining_date) : null;

        foreach ($defaults as $typeName => $default) {
            $leaveType = $leaveTypes->get($typeName);

            // The company's yearly allocation policy, when HR configured one (LeaveAllocationPolicy).
            $annualDays = LeaveAllocationPolicy::annualDaysFor((int) $employee->company_id, (int) $leaveType->id) ?? (float) $default['annual_days'];
            $days = YearlyLeaveAllocationService::prorate($annualDays, $joiningDate, $year);
            if ($days <= 0) {
                continue;
            }

            LeaveAllocation::query()->firstOrCreate(
                [
                    'employee_id'       => $employee->id,
                    'holiday_status_id' => $leaveType->id,
                    'date_from'         => $yearStart,
                ],
                [
                    'company_id'           => $employee->company_id,
                    'employee_company_id'  => $employee->company_id,
                    'name'                 => "{$typeName} {$year}",
                    'allocation_type'      => AllocationType::REGULAR,
                    'date_to'              => $yearEnd,
                    'number_of_days'       => $days,
                    'notes'                => $days < $annualDays
                        ? sprintf('Hire-time allocation %d: %s of %s day(s), prorated from the joining date %s.', $year, LeaveBalanceService::format($days), LeaveBalanceService::format($annualDays), $joiningDate?->toDateString())
                        : null,
                    'creator_id'           => $employee->creator_id ?? $employee->user_id,
                    'state'                => State::VALIDATE_TWO->value,
                ]
            );
        }
    }
}
