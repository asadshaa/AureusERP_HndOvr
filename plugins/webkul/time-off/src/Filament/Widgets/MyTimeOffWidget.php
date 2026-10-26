<?php

namespace Webkul\TimeOff\Filament\Widgets;

use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Support\Colors\Color;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Services\CurrentCompany;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveAllocation;
use Webkul\TimeOff\Models\LeaveType;

class MyTimeOffWidget extends BaseWidget
{
    use HasWidgetShield;

    protected static function getPagePermission(): ?string
    {
        return 'widget_time_off_my_time_off_widget';
    }

    public static function canView(): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        $permission = static::getPagePermission();

        if ($permission && ! $user->can($permission)) {
            return false;
        }

        // On the root main ERP dashboard, hide this widget because the user requested
        // moving time off analysis to each person's profile page and keeping the main dashboard clean.
        if (request()?->routeIs('filament.admin.pages.dashboard')) {
            return false;
        }

        return true;
    }

    protected function getHeading(): ?string
    {
        return __('time-off::filament/widgets/my-time-off-widget.heading.title');
    }

    protected function getStats(): array
    {
        $user = Auth::user();
        $employee = $user?->employee ?? ($user ? Employee::where('user_id', $user->id)->first() : null);
        $employeeId = $employee?->id;
        $endOfYear = Carbon::now()->endOfYear();
        $companyId = (int) ($employee?->company_id ?? CurrentCompany::id());

        $leaveTypes = LeaveType::query()
            ->where('is_active', true)
            ->where('show_on_dashboard', '!=', 0)
            ->where(function ($query) use ($companyId): void {
                $query->where('company_id', $companyId)
                    ->orWhereNull('company_id');
            })
            ->get();

        // Deduplicate leave types by lowercase trimmed name (preferring company-scoped types)
        $dedupedTypes = $leaveTypes
            ->sortByDesc(fn ($lt) => $lt->company_id !== null ? 1 : 0)
            ->unique(fn ($lt) => strtolower(trim($lt->name)));

        $stats = [];

        foreach ($dedupedTypes as $leaveType) {
            $availableDays = $this->calculateAvailableDays($employeeId, $leaveType->id, $endOfYear);
            $daysVal = (float) $availableDays['days'];

            $nameLower = strtolower(trim($leaveType->name));
            $isCanonical = in_array($nameLower, ['annual leave', 'casual leave', 'sick leave', 'paid time off']);

            // Omit non-canonical types that have 0 available days to avoid clutter
            if ($daysVal <= 0 && ! $isCanonical) {
                continue;
            }

            $stats[] = Stat::make(__($leaveType->name), $availableDays['days'])
                ->description(__('time-off::filament/widgets/my-time-off-widget.stats.valid-until', ['date' => $endOfYear->format('Y-m-d')]))
                ->color(Color::generateV3Palette($leaveType->color ?? '#6B7280'));
        }

        $pendingRequests = $this->calculatePendingRequests($employeeId);

        $stats[] = Stat::make(__('time-off::filament/widgets/my-time-off-widget.stats.pending-requests'), $pendingRequests)
            ->description(__('time-off::filament/widgets/my-time-off-widget.stats.time-off-requests'))
            ->color('danger');

        return $stats;
    }

    protected function calculateAvailableDays($employeeId, $leaveTypeId, $endDate)
    {
        $totalAllocated = LeaveAllocation::where('employee_id', $employeeId)
            ->where('holiday_status_id', $leaveTypeId)
            ->where('state', State::VALIDATE_TWO->value)
            ->where(function ($query) use ($endDate) {
                $query->where('date_to', '<=', $endDate)
                    ->orWhereNull('date_to');
            })
            ->sum('number_of_days');

        $totalTaken = Leave::where('employee_id', $employeeId)
            ->where('company_id', Auth::user()?->default_company_id)
            ->where('holiday_status_id', $leaveTypeId)
            ->where(function ($query) use ($endDate) {
                $query->where('request_date_to', '<=', $endDate)
                    ->orWhereNull('request_date_to');
            })
            ->where('state', '!=', 'refuse')
            ->sum('number_of_days');

        $availableDays = $totalAllocated - $totalTaken;

        return [
            'days' => number_format($availableDays, 1),
        ];
    }

    protected function calculatePendingRequests($employeeId)
    {
        return Leave::where('employee_id', $employeeId)
            ->where('company_id', Auth::user()?->default_company_id)
            ->where('state', 'confirm')
            ->count();
    }
}
