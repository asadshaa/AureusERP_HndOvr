<?php

namespace Webkul\TimeOff\Services;

use Illuminate\Support\Carbon;
use Webkul\Employee\Services\Attendance\ApprovedLeaveLookup;
use Webkul\TimeOff\Enums\RequestDateFromPeriod;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;

/**
 * Who is on leave on each day of a month, for one company: approved leave
 * (optionally also leave waiting for approval), never refused or cancelled,
 * limited to the employees the viewer may see and optionally to one
 * department (the employee's current department).
 */
class LeaveCalendarService
{
    /**
     * @param  iterable<int, int>  $visibleEmployeeIds
     * @return array{month: string, label: string, people: int, days: array<int, array{date: string, label: string, weekend: bool, entries: array<int, array{leave_id: int, employee: string, department: ?string, type: string, portion: string, state: string, approved: bool}>}>}
     */
    public function month(int $companyId, string $month, iterable $visibleEmployeeIds, ?int $departmentId = null, bool $includePending = false): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth();
        $employeeIds = collect($visibleEmployeeIds)->map(fn ($id): int => (int) $id)->all();

        $states = $includePending
            ? [State::VALIDATE_TWO->value, ...LeaveBalanceService::PENDING_STATES]
            : [State::VALIDATE_TWO->value];

        $leaves = $employeeIds === [] ? collect() : ApprovedLeaveLookup::overlapping(
            Leave::query()
                ->where('company_id', $companyId)
                ->whereIn('employee_id', $employeeIds)
                ->whereIn('state', $states)
                ->when($departmentId, fn ($query) => $query->whereHas('employee', fn ($employee) => $employee->where('department_id', $departmentId))),
            $start->toDateString(),
            $end->toDateString(),
        )
            ->with(['employee.department', 'holidayStatus'])
            ->get()
            ->sortBy(fn (Leave $leave): string => (string) $leave->employee?->name)
            ->values();

        $days = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $date = $day->toDateString();
            $entries = [];

            foreach ($leaves as $leave) {
                if (! ApprovedLeaveLookup::covers($leave, $date)) {
                    continue;
                }

                $entries[] = [
                    'leave_id'   => (int) $leave->id,
                    'employee'   => (string) ($leave->employee?->name ?? '—'),
                    'department' => $leave->employee?->department?->name,
                    'type'       => (string) ($leave->holidayStatus?->name ?? 'Leave'),
                    'portion'    => $this->portion($leave),
                    'state'      => (string) ($leave->state?->getLabel() ?? ''),
                    'approved'   => $leave->state === State::VALIDATE_TWO,
                ];
            }

            $days[] = [
                'date'    => $date,
                'label'   => $day->format('D d M'),
                'weekend' => $day->isWeekend(),
                'entries' => $entries,
            ];
        }

        return [
            'month'  => $start->format('Y-m'),
            'label'  => $start->format('F Y'),
            'people' => $leaves->pluck('employee_id')->unique()->count(),
            'days'   => $days,
        ];
    }

    private function portion(Leave $leave): string
    {
        if ($leave->request_unit_half) {
            return $leave->request_date_from_period === RequestDateFromPeriod::AFTERNOON ? 'second half' : 'first half';
        }

        if ($leave->request_unit_hours && $leave->request_hour_from !== null && $leave->request_hour_to !== null) {
            return $this->clock((float) $leave->request_hour_from).'–'.$this->clock((float) $leave->request_hour_to);
        }

        return 'full day';
    }

    private function clock(float $hours): string
    {
        $minutes = (int) round($hours * 60);

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
