<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Models\Employee;
use Webkul\Support\Filament\Pages\Profile;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Filament\Clusters\MyTime\Resources\MyTimeOffResource;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveAllocation;
use Webkul\TimeOff\Models\LeaveType;

class EmployeeDashboardOverviewWidget extends Widget
{
    protected string $view = 'filament.widgets.employee-dashboard-overview';

    protected static bool $isLazy = false;

    protected static ?int $sort = -95;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        return $user->employee !== null || Employee::where('user_id', $user->id)->exists();
    }

    /**
     * Calculate canonical employee leave entitlements and remaining balances.
     * Policy allocation: Total 25 days (12 Annual, 8 Casual, 5 Sick).
     *
     * @return array<string, mixed>
     */
    public static function calculateCanonicalLeaves(Employee $employee, ?int $companyId = null, ?\DateTimeInterface $endOfYear = null): array
    {
        $companyId = $companyId ?? (int) ($employee->company_id ?? 1);
        $endOfYear = $endOfYear ?? Carbon::now()->endOfYear();

        $leaveTypes = LeaveType::query()
            ->where('is_active', true)
            ->where(function ($q) use ($companyId): void {
                $q->whereNull('company_id')
                    ->orWhere('company_id', $companyId);
            })
            ->get();

        $canonicalSpecs = [
            'annual' => [
                'name'       => 'Annual Leave',
                'allocated'  => 12.0,
                'keywords'   => ['annual', 'vacation'],
                'icon'       => 'heroicon-o-sun',
                'color'      => 'success',
            ],
            'casual' => [
                'name'       => 'Casual Leave',
                'allocated'  => 8.0,
                'keywords'   => ['casual'],
                'icon'       => 'heroicon-o-sparkles',
                'color'      => 'info',
            ],
            'sick' => [
                'name'       => 'Sick Leave',
                'allocated'  => 5.0,
                'keywords'   => ['sick', 'medical'],
                'icon'       => 'heroicon-o-heart',
                'color'      => 'danger',
            ],
        ];

        $cards = [];
        $totalAllocated = 0.0;
        $totalTaken = 0.0;
        $totalPending = 0.0;

        foreach ($canonicalSpecs as $key => $spec) {
            $matchingTypeIds = $leaveTypes->filter(function ($lt) use ($spec) {
                $nameLower = strtolower($lt->name);
                foreach ($spec['keywords'] as $kw) {
                    if (str_contains($nameLower, $kw)) {
                        return true;
                    }
                }

                return false;
            })->pluck('id')->all();

            $dbAllocated = 0.0;
            if (! empty($matchingTypeIds)) {
                $dbAllocated = (float) LeaveAllocation::where('employee_id', $employee->id)
                    ->whereIn('holiday_status_id', $matchingTypeIds)
                    ->where('state', State::VALIDATE_TWO->value)
                    ->where(function ($q) use ($endOfYear) {
                        $q->where('date_to', '<=', $endOfYear)
                            ->orWhereNull('date_to');
                    })
                    ->sum('number_of_days');
            }

            // Use DB allocation if explicitly assigned, otherwise use the company canonical allocation
            $allocated = $dbAllocated > 0 ? $dbAllocated : (float) $spec['allocated'];

            $taken = 0.0;
            if (! empty($matchingTypeIds)) {
                $taken = (float) Leave::where('employee_id', $employee->id)
                    ->whereIn('holiday_status_id', $matchingTypeIds)
                    ->where('state', State::VALIDATE_TWO->value)
                    ->sum('number_of_days');
            }

            $pending = 0.0;
            if (! empty($matchingTypeIds)) {
                $pending = (float) Leave::where('employee_id', $employee->id)
                    ->whereIn('holiday_status_id', $matchingTypeIds)
                    ->whereIn('state', [State::CONFIRM->value, State::VALIDATE_ONE->value])
                    ->sum('number_of_days');
            }

            $left = max(0.0, round($allocated - $taken, 1));
            $pct = $allocated > 0 ? min(100, max(0, (int) round(($left / $allocated) * 100))) : 0;

            $totalAllocated += $allocated;
            $totalTaken += $taken;
            $totalPending += $pending;

            $cards[$key] = [
                'key'        => $key,
                'name'       => $spec['name'],
                'allocated'  => $allocated,
                'taken'      => $taken,
                'pending'    => $pending,
                'left'       => $left,
                'percentage' => $pct,
                'icon'       => $spec['icon'],
                'color'      => $spec['color'],
            ];
        }

        $totalLeft = max(0.0, round($totalAllocated - $totalTaken, 1));

        return [
            'total_allocated' => $totalAllocated,
            'total_taken'     => $totalTaken,
            'total_pending'   => $totalPending,
            'total_left'      => $totalLeft,
            'cards'           => array_values($cards),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = Auth::user();
        if (! $user) {
            return [];
        }

        $employee = $user->employee ?? Employee::with(['department', 'job', 'parent', 'company'])->where('user_id', $user->id)->first();
        if (! $employee) {
            return [];
        }

        $companyId = (int) ($employee->company_id ?? $user->default_company_id ?? 1);
        $endOfYear = Carbon::now()->endOfYear();

        $name = $employee->name ?? $user->name;
        $employeeData = [
            'id'           => $employee->id,
            'number'       => $employee->employee_number ?? ('EMP-'.str_pad($employee->id, 4, '0', STR_PAD_LEFT)),
            'name'         => $name,
            'job_title'    => $employee->job_title ?? $employee->job?->name ?? 'Employee',
            'department'   => $employee->department?->name ?? 'General',
            'manager_name' => $employee->parent?->name ?? 'Not Assigned',
            'company_name' => $employee->company?->name ?? $user->defaultCompany?->name ?? config('app.name'),
        ];

        // 25 Days Leave Allocation (12 Annual, 8 Casual, 5 Sick)
        $leaveData = self::calculateCanonicalLeaves($employee, $companyId, $endOfYear);

        // Recent Leave Requests
        $recentLeaves = Leave::query()
            ->with(['holidayStatus'])
            ->where('employee_id', $employee->id)
            ->orderByDesc('id')
            ->take(3)
            ->get()
            ->map(function (Leave $l): array {
                $type = $l->holidayStatus?->name ?? 'Leave';
                $days = (float) ($l->number_of_days ?? 0);
                $from = $l->request_date_from ? Carbon::parse($l->request_date_from)->format('d M') : ($l->date_from ? Carbon::parse($l->date_from)->format('d M') : '');
                $to = $l->request_date_to ? Carbon::parse($l->request_date_to)->format('d M Y') : ($l->date_to ? Carbon::parse($l->date_to)->format('d M Y') : $from);
                $dateRange = $from === $to ? $from : "{$from} – {$to}";

                $stateValue = $l->state instanceof State ? $l->state->value : (string) $l->state;
                $statusLabel = match ($stateValue) {
                    'validate_two' => 'Approved',
                    'validate_one' => 'Partially Approved',
                    'confirm'      => 'Pending',
                    'refuse'       => 'Rejected',
                    default        => ucfirst($stateValue),
                };

                $statusColor = match ($stateValue) {
                    'validate_two' => 'success',
                    'validate_one' => 'info',
                    'confirm'      => 'warning',
                    'refuse'       => 'danger',
                    default        => 'gray',
                };

                return [
                    'id'           => $l->id,
                    'type'         => $type,
                    'days'         => $days,
                    'date_range'   => $dateRange,
                    'status_label' => $statusLabel,
                    'status_color' => $statusColor,
                ];
            })
            ->all();

        $requestLeaveUrl = null;
        try {
            $requestLeaveUrl = MyTimeOffResource::getUrl('create');
        } catch (\Throwable) {
            $requestLeaveUrl = url('/admin/time-off/dashboard/my-time-offs/create');
        }

        $profileUrl = null;
        try {
            $profileUrl = Profile::getUrl();
        } catch (\Throwable) {
            $profileUrl = url('/admin/settings/profile');
        }

        $myTimeOffListUrl = null;
        try {
            $myTimeOffListUrl = MyTimeOffResource::getUrl('index');
        } catch (\Throwable) {
            $myTimeOffListUrl = url('/admin/time-off/dashboard/my-time-offs');
        }

        return [
            'employee'     => $employeeData,
            'leaveSummary' => $leaveData,
            'recentLeaves' => $recentLeaves,
            'urls'         => [
                'request_leave' => $requestLeaveUrl,
                'my_leaves'     => $myTimeOffListUrl,
                'profile'       => $profileUrl,
            ],
        ];
    }
}
