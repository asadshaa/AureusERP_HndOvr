<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Filament\Pages\Profile;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Filament\Clusters\MyTime\Resources\MyTimeOffResource;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveAllocationPolicy;
use Webkul\TimeOff\Models\LeaveType;
use Webkul\TimeOff\Services\LeaveApprovalService;
use Webkul\TimeOff\Services\LeaveBalanceService;

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
     * The employee's Annual, Sick and Casual Leave for one allocation year
     * (the year of $endOfYear), from LeaveBalanceService, the same figures as
     * the request form: approved allocations, approved (taken) and pending
     * leave of that year; refused and cancelled leave never count, and left =
     * allocated - taken - pending. Each card uses the company's own type of
     * that name (else a shared one). Policy: 25 days, Annual 12, Sick 8,
     * Casual 5 (LeaveAllocationPolicy); until an allocation exists, the
     * company's policy days (else the default) are shown as the entitlement.
     *
     * @return array<string, mixed>
     */
    public static function calculateCanonicalLeaves(Employee $employee, ?int $companyId = null, ?\DateTimeInterface $endOfYear = null): array
    {
        $companyId = $companyId ?? (int) ($employee->company_id ?? CurrentCompany::id());
        $year = $endOfYear ? Carbon::instance($endOfYear)->year : LeaveBalanceService::today()->year;
        $balances = app(LeaveBalanceService::class);

        $leaveTypes = LeaveType::query()
            ->where('is_active', true)
            ->where(function ($q) use ($companyId): void {
                $q->whereNull('company_id')
                    ->orWhere('company_id', $companyId);
            })
            ->orderByRaw('company_id IS NULL')
            ->orderBy('id')
            ->get();

        $canonicalSpecs = [
            'annual' => [
                'name'       => 'Annual Leave',
                'keywords'   => ['annual', 'vacation'],
                'icon'       => 'heroicon-o-sun',
                'color'      => 'success',
            ],
            'casual' => [
                'name'       => 'Casual Leave',
                'keywords'   => ['casual'],
                'icon'       => 'heroicon-o-sparkles',
                'color'      => 'info',
            ],
            'sick' => [
                'name'       => 'Sick Leave',
                'keywords'   => ['sick', 'medical'],
                'icon'       => 'heroicon-o-heart',
                'color'      => 'danger',
            ],
        ];

        $cards = [];
        $totalAllocated = 0.0;
        $totalTaken = 0.0;
        $totalPending = 0.0;

        $typesBySpec = collect($canonicalSpecs)->map(fn (array $spec): ?LeaveType => $leaveTypes->first(fn (LeaveType $type): bool => strcasecmp(trim((string) $type->name), $spec['name']) === 0)
            ?? $leaveTypes->first(fn (LeaveType $type): bool => LeaveBalanceService::deductsBalance($type)
                && Str::contains(strtolower((string) $type->name), $spec['keywords'])));

        // One query each for allocations, leaves and policies, whatever the number of cards.
        $usageByType = $balances->usageForTypes($employee, $typesBySpec->filter(), $year);
        $policyDaysByType = LeaveAllocationPolicy::query()
            ->where('company_id', $companyId)
            ->whereIn('leave_type_id', $typesBySpec->filter()->pluck('id'))
            ->where('is_active', true)
            ->pluck('annual_days', 'leave_type_id');

        foreach ($canonicalSpecs as $key => $spec) {
            $leaveType = $typesBySpec[$key];

            $usage = $leaveType
                ? $usageByType[(int) $leaveType->id]
                : ['allocated' => 0.0, 'taken' => 0.0, 'pending' => 0.0, 'remaining' => 0.0];

            $policyDays = $leaveType && $leaveType->company_id !== null && isset($policyDaysByType[$leaveType->id])
                ? (float) $policyDaysByType[$leaveType->id]
                : null;

            // The approved allocation when there is one, otherwise the policy entitlement.
            $allocated = $usage['allocated'] > 0
                ? $usage['allocated']
                : ($policyDays ?? (float) LeaveAllocationPolicy::DEFAULTS[$spec['name']]['annual_days']);
            $taken = $usage['taken'];
            $pending = $usage['pending'];

            $left = max(0.0, round($allocated - $taken - $pending, 1));
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

        $totalLeft = max(0.0, round($totalAllocated - $totalTaken - $totalPending, 1));

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

        $companyId = (int) ($employee->company_id ?? CurrentCompany::id($user));
        $endOfYear = LeaveBalanceService::today()->endOfYear();

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

        // 25 days a year: Annual 12, Sick 8, Casual 5 (LeaveAllocationPolicy).
        $leaveData = self::calculateCanonicalLeaves($employee, $companyId, $endOfYear);

        // Recent Leave Requests
        $recentLeaves = Leave::query()
            ->with(['holidayStatus', 'approvalRequest'])
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
                // Returned for changes by an approver: kept as "refuse" (out of the balance) but not refused.
                if (LeaveApprovalService::isReturned($l)) {
                    $stateValue = 'returned';
                }
                $statusLabel = match ($stateValue) {
                    'validate_two' => 'Approved',
                    'validate_one' => 'Partially Approved',
                    'confirm'      => 'Pending',
                    'refuse'       => 'Rejected',
                    'returned'     => 'Returned for changes',
                    'cancel'       => 'Cancelled',
                    default        => ucfirst($stateValue),
                };

                $statusColor = match ($stateValue) {
                    'validate_two' => 'success',
                    'validate_one' => 'info',
                    'confirm'      => 'warning',
                    'refuse'       => 'danger',
                    'returned'     => 'info',
                    'cancel'       => 'gray',
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
