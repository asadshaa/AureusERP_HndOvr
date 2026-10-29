<?php

namespace Webkul\TimeOff\Traits;

use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Webkul\Employee\Models\Employee;
use Webkul\TimeOff\Enums\RequestDateFromPeriod;
use Webkul\TimeOff\Enums\State;
use Webkul\TimeOff\Models\Leave;
use Webkul\TimeOff\Models\LeaveType;
use Webkul\TimeOff\Services\LeaveApprovalService;
use Webkul\TimeOff\Services\LeaveBalanceService;
use Webkul\TimeOff\Services\ShortLeavePolicy;

trait TimeOffHelper
{
    public function mutateTimeOffData(array $data, ?int $excludeRecordId = null, ?Action $action = null): array
    {
        $this->updateEmployeeAndCompanyData($data);

        $this->calculateBusinessDaysAndNumbers($data);

        $this->handleShortLeave($data, $excludeRecordId, $action);

        $this->handleLeaveOverlap($data, $excludeRecordId, $action);

        $this->handleLeaveAllocation($data, $action, $excludeRecordId);

        // A leave returned for changes stays "refuse" (out of the balance) while it
        // is edited; resubmitting it puts it back to "confirm" (LeaveApprovalService).
        $data['state'] = LeaveApprovalService::isReturnedLeaveId($excludeRecordId) ? State::REFUSE->value : State::CONFIRM->value;
        $data['date_from'] = $data['request_date_from'] ?? null;
        $data['date_to'] = $data['request_date_to'] ?? null;

        return $data;
    }

    public function getFormSchema($isVisible = null): array
    {
        return [
            Section::make()
                ->schema([
                    Select::make('employee_id')
                        ->relationship(
                            'employee',
                            'name',
                            modifyQueryUsing: fn (Builder $query): Builder => $query->where('company_id', Auth::user()?->default_company_id),
                        )
                        ->searchable()
                        ->preload()
                        ->live()
                        ->visible(fn (Get $get) => $isVisible ?? false)
                        ->afterStateUpdated(function (Set $set, $state) {
                            if ($state) {
                                $employee = Employee::find($state);

                                if ($employee->department) {
                                    $set('department_id', $employee->department->id);
                                } else {
                                    $set('department_id', null);
                                }
                            }
                        })
                        ->label(__('time-off::filament/clusters/management/resources/time-off.form.fields.employee-name'))
                        ->required(),
                    Select::make('department_id')
                        ->relationship(
                            'department',
                            'name',
                            modifyQueryUsing: fn (Builder $query): Builder => $query->where('company_id', Auth::user()?->default_company_id),
                        )
                        ->label(__('time-off::filament/clusters/management/resources/time-off.form.fields.department-name'))
                        ->searchable()
                        ->visible(fn (Get $get) => $isVisible ?? false)
                        ->preload()
                        ->required(),

                    Select::make('holiday_status_id')
                        ->label(__('time-off::filament/widgets/calendar-widget.form.fields.time-off-type'))
                        ->relationship(
                            'holidayStatus',
                            'name',
                            modifyQueryUsing: fn (Builder $query): Builder => $query
                                ->where('is_active', true)
                                ->where(function (Builder $query): void {
                                    $query->whereNull('company_id')
                                        ->orWhere('company_id', Auth::user()?->default_company_id);
                                }),
                        )
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, Set $set): void {
                            // The leave type's unit drives the form: Half day -> half day, Short leave -> hours.
                            $unit = $state ? LeaveType::query()->whereKey($state)->value('request_unit') : null;
                            $unit = $unit instanceof \BackedEnum ? $unit->value : $unit;
                            if ($unit === 'hour') {
                                $set('request_unit_hours', true);
                                $set('request_unit_half', false);
                            } elseif ($unit === 'half_day') {
                                $set('request_unit_half', true);
                                $set('request_unit_hours', false);
                            } else {
                                $set('request_unit_hours', false);
                            }
                        })
                        ->columnSpanFull()
                        ->placeholder(__('time-off::filament/widgets/calendar-widget.form.fields.time-off-type-placeholder'))
                        ->helperText(__('time-off::filament/widgets/calendar-widget.form.fields.time-off-type-helper')),

                    TextEntry::make('leave_balance_info')
                        ->label('Leave balance')
                        ->visible(fn (Get $get): bool => filled($get('holiday_status_id')))
                        ->state(fn (Get $get, $record = null): string => $this->leaveBalanceSummary(
                            $get('holiday_status_id'),
                            $get('employee_id'),
                            $get('request_date_from'),
                            $record instanceof Leave ? (int) $record->id : null,
                        ))
                        ->columnSpanFull()
                        ->extraAttributes(['class' => 'font-medium p-3 rounded-lg border border-gray-200']),

                    Grid::make(2)
                        ->schema([
                            DatePicker::make('request_date_from')
                                ->native(false)
                                ->label(__('time-off::filament/widgets/calendar-widget.form.fields.request-date-from'))
                                ->required()
                                ->live()
                                ->prefixIcon('heroicon-o-calendar')
                                ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                    if (blank($state)) {
                                        $set('request_date_to', null);
                                    }

                                    if ($state && ! $get('request_unit_half')) {
                                        $this->updateDurationCalculation($set, $get);
                                    }
                                }),

                            DatePicker::make('request_date_to')
                                ->native(false)
                                ->label(__('time-off::traits/leave-accrual-plan.form.fields.to-date'))
                                ->hidden(fn (Get $get) => $get('request_unit_half') || $get('request_unit_hours'))
                                ->required(fn (Get $get) => ! $get('request_unit_half') && ! $get('request_unit_hours'))
                                ->live()
                                ->prefixIcon('heroicon-o-calendar')
                                ->disabled(fn (Get $get) => blank($get('request_date_from')))
                                ->minDate(fn (Get $get) => $get('request_date_from'))
                                ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                    if ($state && $get('request_date_from')) {
                                        $this->updateDurationCalculation($set, $get);
                                    }
                                }),
                        ]),

                    Grid::make(2)
                        ->schema([
                            Toggle::make('request_unit_half')
                                ->live()
                                ->hidden(fn (Get $get) => (bool) $get('request_unit_hours'))
                                ->label(__('time-off::filament/widgets/calendar-widget.form.fields.half-day'))
                                ->helperText(fn (Get $get): string => $this->halfDayHelperText($get('holiday_status_id')))
                                ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                    if ($state) {
                                        $set('duration_info', '0.5 day');
                                    } else {
                                        $this->updateDurationCalculation($set, $get);
                                    }
                                }),

                            Select::make('request_date_from_period')
                                ->label(__('time-off::filament/widgets/calendar-widget.form.fields.period'))
                                ->options(RequestDateFromPeriod::class)
                                ->default(RequestDateFromPeriod::MORNING)
                                ->native(false)
                                ->visible(fn (Get $get) => $get('request_unit_half'))
                                ->required(fn (Get $get) => $get('request_unit_half'))
                                ->prefixIcon('heroicon-o-sun'),
                        ]),

                    Grid::make(3)
                        ->schema([
                            Toggle::make('request_unit_hours')
                                ->live()
                                ->label('Short leave (hours)')
                                ->helperText(fn (): string => 'From-to time on one day, at most '.rtrim(rtrim(number_format((float) config('attendance.short_leave.max_hours', 3), 2), '0'), '.').' hours, '.(int) config('attendance.short_leave.max_per_month', 2).' per month. Longer: request a half day.')
                                ->afterStateUpdated(function ($state, Set $set): void {
                                    if ($state) {
                                        $set('request_unit_half', false);
                                    }
                                }),
                            Select::make('request_hour_from')
                                ->label('From')
                                ->options(fn (): array => $this->shortLeaveTimeOptions())
                                ->visible(fn (Get $get) => (bool) $get('request_unit_hours'))
                                ->required(fn (Get $get) => (bool) $get('request_unit_hours'))
                                ->live()
                                ->native(false),
                            Select::make('request_hour_to')
                                ->label('To')
                                ->options(fn (): array => $this->shortLeaveTimeOptions())
                                ->visible(fn (Get $get) => (bool) $get('request_unit_hours'))
                                ->required(fn (Get $get) => (bool) $get('request_unit_hours'))
                                ->live()
                                ->native(false),
                        ]),

                    TextEntry::make('duration_info')
                        ->label(__('time-off::filament/widgets/calendar-widget.form.fields.duration'))
                        ->live()
                        ->state(function (Get $get): string {
                            if ($get('request_unit_hours')) {
                                $hours = ShortLeavePolicy::hours($get('request_hour_from'), $get('request_hour_to'));

                                return $hours > 0 ? rtrim(rtrim(number_format($hours, 2), '0'), '.').' hours' : 'Choose the from and to time';
                            }

                            if ($get('request_unit_half')) {
                                return '0.5 day';
                            }

                            $startDate = $get('request_date_from');
                            $endDate = $get('request_date_to');

                            if (! $startDate) {
                                return __('time-off::filament/widgets/calendar-widget.form.fields.please-select-dates');
                            }

                            $start = Carbon::parse($startDate);
                            $end = $endDate ? Carbon::parse($endDate) : $start;

                            $businessDays = $this->calculateBusinessDays($start, $end);
                            $totalDays = $this->calculateTotalDays($start, $end);
                            $weekendDays = $totalDays - $businessDays;

                            $duration = $businessDays.' working day'.($businessDays !== 1 ? 's' : '');

                            if ($weekendDays > 0) {
                                $duration .= ' (+ '.$weekendDays.' weekend day'.($weekendDays !== 1 ? 's' : '').')';
                            }

                            return $duration;
                        })
                        ->extraAttributes(['class' => 'font-medium text-primary-600 bg-primary-50 p-3 rounded-lg border border-primary-200']),

                    Textarea::make('private_name')
                        ->label(__('time-off::filament/widgets/calendar-widget.form.fields.description'))
                        ->placeholder(__('time-off::filament/widgets/calendar-widget.form.fields.description-placeholder'))
                        ->rows(3)
                        ->columnSpanFull()
                        ->helperText(__('time-off::filament/widgets/calendar-widget.form.fields.description-helper')),
                ])->columnSpanFull(),
        ];
    }

    public function getDurationInfo(array $data): array
    {
        if (! empty($data['request_unit_hours'])) {
            $hours = ShortLeavePolicy::hours($data['request_hour_from'] ?? null, $data['request_hour_to'] ?? null);
            $dayHours = max(1, (int) config('attendance.monthly_report.daily_required_minutes', 480)) / 60;
            $days = round($hours / $dayHours, 4);

            return [
                'duration_display' => rtrim(rtrim(number_format($hours, 2), '0'), '.').' hours',
                'number_of_days'   => $days,
                'business_days'    => $days,
                'total_days'       => $days,
                'weekend_days'     => 0,
            ];
        }

        if (! empty($data['request_unit_half'])) {
            return [
                'duration_display' => '0.5 day',
                'number_of_days'   => 0.5,
                'business_days'    => 0.5,
                'total_days'       => 0.5,
                'weekend_days'     => 0,
            ];
        }

        $startDate = Carbon::parse($data['request_date_from']);
        $endDate = ! empty($data['request_date_to'])
            ? Carbon::parse($data['request_date_to'])
            : $startDate;

        $businessDays = $this->calculateBusinessDays($startDate, $endDate);
        $totalDays = $this->calculateTotalDays($startDate, $endDate);
        $weekendDays = $totalDays - $businessDays;

        $durationDisplay = $businessDays.' working day'.($businessDays !== 1 ? 's' : '');

        if ($weekendDays > 0) {
            $durationDisplay .= ' (+ '.$weekendDays.' weekend day'.($weekendDays !== 1 ? 's' : '').')';
        }

        return [
            'duration_display' => $durationDisplay,
            'number_of_days'   => $businessDays,
            'business_days'    => $businessDays,
            'total_days'       => $totalDays,
            'weekend_days'     => $weekendDays,
        ];
    }

    /**
     * Short leave: one day, hours from-to, limits from attendance.short_leave
     * (ShortLeavePolicy, also enforced on the Leave model). A refused request
     * is shown as a notification and the form stays open.
     */
    private function handleShortLeave(array &$data, ?int $excludeRecordId = null, ?Action $action = null): void
    {
        if (empty($data['request_unit_hours'])) {
            $data['request_unit_hours'] = false;

            return;
        }

        $data['request_unit_half'] = false;
        $data['request_date_to'] = $data['request_date_from'] ?? null;
        $data['number_of_hours'] = ShortLeavePolicy::hours($data['request_hour_from'] ?? null, $data['request_hour_to'] ?? null);

        try {
            app(ShortLeavePolicy::class)->validate($data, $excludeRecordId);
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title('Short leave not allowed')
                ->body(collect($exception->errors())->flatten()->first())
                ->send();

            if ($action) {
                $action->halt();
            } else {
                $this->halt();
            }
        }
    }

    /** @return array<string, string> 30-minute steps 08:00-20:00, keyed by decimal hours */
    private function shortLeaveTimeOptions(): array
    {
        $options = [];
        for ($minutes = 8 * 60; $minutes <= 20 * 60; $minutes += 30) {
            // Keyed like the decimal(15,4) column returns it, so an edit form shows the saved time.
            $options[number_format($minutes / 60, 4, '.', '')] = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }

        return $options;
    }

    private function handleLeaveOverlap(array &$data, ?int $excludeRecordId = null, ?Action $action = null): void
    {
        $employee = Employee::find($data['employee_id'] ?? null);

        if (! $employee) {
            Notification::make()
                ->danger()
                ->title(__('time-off::filament/widgets/overview-calendar-widget.header-actions.create.employee-not-found.notification.title'))
                ->body(__('time-off::filament/widgets/overview-calendar-widget.header-actions.create.employee-not-found.notification.body'))
                ->send();

            if ($action) {
                $action->halt();
            } else {
                $this->halt();
            }

            return;
        }

        $overlap = $this->checkForOverlappingLeave(
            $employee->id,
            $data['request_date_from'],
            $data['request_date_to'] ?? $data['request_date_from'],
            $excludeRecordId
        );

        if ($overlap) {
            Notification::make()
                ->danger()
                ->title(__('time-off::filament/clusters/my-time/resources/my-time-off/pages/create-time-off.notification.overlap.title'))
                ->body(__('time-off::filament/clusters/my-time/resources/my-time-off/pages/create-time-off.notification.overlap.body'))
                ->send();

            if ($action) {
                $action->halt();
            } else {
                $this->halt();
            }
        }
    }

    /**
     * Under the half-day toggle: the Half day type does not count as leave
     * (LeaveBalanceService deducts nothing for it); a half day of a type with
     * a balance takes 0.5 day of that type.
     */
    private function halfDayHelperText(mixed $leaveTypeId): string
    {
        $leaveType = $leaveTypeId ? LeaveType::query()->find($leaveTypeId) : null;

        if ($leaveType && LeaveBalanceService::isHalfDayType($leaveType)) {
            return "{$leaveType->name} does not count as leave: nothing is deducted from your leave balance.";
        }

        if ($leaveType && LeaveBalanceService::deductsBalance($leaveType)) {
            return "Half a day: 0.5 day of {$leaveType->name}.";
        }

        return __('time-off::filament/widgets/calendar-widget.form.fields.half-day-helper');
    }

    /**
     * The balance of the chosen type for the current allocation year (the
     * year of the start date), shown live on the request form; a Half day
     * shows that it does not count as leave.
     */
    private function leaveBalanceSummary(mixed $leaveTypeId, mixed $employeeId, mixed $startDate, ?int $ignoreLeaveId = null): string
    {
        $leaveType = $leaveTypeId ? LeaveType::query()->find($leaveTypeId) : null;
        $employee = $employeeId ? Employee::query()->find($employeeId) : Auth::user()?->employee;

        if (! $leaveType || ! $employee) {
            return 'Choose the employee and the leave type to see the balance.';
        }

        $year = filled($startDate) ? Carbon::parse($startDate)->year : null;

        return app(LeaveBalanceService::class)->summary($employee, $leaveType, $year, $ignoreLeaveId);
    }

    /**
     * Refuses a request above the remaining balance of a type that requires
     * an allocation (LeaveBalanceService; the Leave model checks it again on
     * save). A Half day is never refused for balance: it does not count as
     * leave. A refused request is shown as a notification and the form stays open.
     */
    private function handleLeaveAllocation(array &$data, ?Action $action = null, ?int $excludeRecordId = null): void
    {
        $employee = Employee::find($data['employee_id'] ?? null);
        $leaveType = LeaveType::find($data['holiday_status_id'] ?? null);

        if (! $employee || ! $leaveType) {
            return;
        }

        $refusal = app(LeaveBalanceService::class)->refusal(
            $employee,
            $leaveType,
            (float) ($data['number_of_days'] ?? 0),
            $data['request_date_from'] ?? null,
            $excludeRecordId,
        );

        if (! $refusal) {
            return;
        }

        $title = $refusal['reason'] === 'insufficient'
            ? __('time-off::filament/clusters/my-time/resources/my-time-off/pages/create-time-off.notification.leave_request_denied_insufficient_balance.title')
            : __('time-off::filament/clusters/my-time/resources/my-time-off/pages/create-time-off.notification.leave_request_denied_no_allocation.title');

        Notification::make()
            ->danger()
            ->title($title)
            ->body($refusal['message'])
            ->send();

        if ($action) {
            $action->halt();
        } else {
            $this->halt();
        }
    }

    private function calculateBusinessDays(Carbon $start, Carbon $end): int
    {
        $days = 0;

        $current = $start->copy();

        while ($current->lte($end)) {
            if (! $current->isWeekend()) {
                $days++;
            }

            $current->addDay();
        }

        return $days;
    }

    private function calculateTotalDays(Carbon $start, Carbon $end): int
    {
        return $start->diffInDays($end) + 1;
    }

    private function checkForOverlappingLeave(int $employeeId, string $startDate, ?string $endDate, ?int $excludeRecordId = null): bool
    {
        $start = Carbon::parse($startDate);

        $end = $endDate ? Carbon::parse($endDate) : $start;

        $query = Leave::where('employee_id', $employeeId)
            ->where(fn ($q) => $q->whereNull('state')->orWhere('state', '!=', State::CANCEL->value))
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('date_from', [$start, $end])
                    ->orWhereBetween('date_to', [$start, $end])
                    ->orWhere(function ($q2) use ($start, $end) {
                        $q2->where('date_from', '<=', $start)
                            ->where('date_to', '>=', $end);
                    });
            });

        if ($excludeRecordId) {
            $query->where('id', '!=', $excludeRecordId);
        }

        return $query->exists();
    }

    private function updateDurationCalculation(Set $set, Get $get): void
    {
        $startDate = $get('request_date_from');
        $endDate = $get('request_date_to');

        if (! $startDate) {
            $set('duration_info', 'Please select dates');

            return;
        }

        $start = Carbon::parse($startDate);
        $end = $endDate ? Carbon::parse($endDate) : $start;

        $businessDays = $this->calculateBusinessDays($start, $end);
        $totalDays = $this->calculateTotalDays($start, $end);
        $weekendDays = $totalDays - $businessDays;

        $duration = $businessDays.' working day'.($businessDays !== 1 ? 's' : '');

        if ($weekendDays > 0) {
            $duration .= ' (+ '.$weekendDays.' weekend day'.($weekendDays !== 1 ? 's' : '').')';
        }

        $set('duration_info', $duration);
    }

    private function calculateBusinessDaysAndNumbers(array &$data): void
    {
        $info = $this->getDurationInfo($data);

        $data['duration_display'] = $info['duration_display'];
        $data['number_of_days'] = $info['number_of_days'];
        $data['business_days'] = $info['business_days'];
        $data['total_days'] = $info['total_days'];
        $data['weekend_days'] = $info['weekend_days'];
    }

    private function updateEmployeeAndCompanyData(array &$data): void
    {

        if (! empty($data['employee_id'])) {
            $employee = Employee::find($data['employee_id']);
            if ($employee && (int) $employee->company_id !== (int) Auth::user()?->default_company_id) {
                throw new RuntimeException('The selected employee is outside the active company.');
            }
            $user = $employee?->user;
        } else {
            $user = Auth::user();
            $employee = $user->employee;
        }

        if ($employee) {
            $data['employee_id'] = $employee->id;
            $data['company_id'] = $employee->company_id;
            $data['employee_company_id'] = $employee->company_id;

            if (! empty($data['department_id'])) {
                $data['department_id'] = $data['department_id'];
            } elseif ($employee->department) {
                $data['department_id'] = $employee->department->id;
            } else {
                $data['department_id'] = null;
            }

            if ($employee->calendar) {
                $data['calendar_id'] = $employee->calendar->id;
                $data['number_of_hours'] = $employee->calendar->hours_per_day;
            }
        }

        if ($user) {
            $data['user_id'] = $user->id;
        }
    }
}
