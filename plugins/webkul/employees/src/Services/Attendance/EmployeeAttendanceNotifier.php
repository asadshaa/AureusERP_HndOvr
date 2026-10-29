<?php

namespace Webkul\Employee\Services\Attendance;

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;
use Webkul\Employee\Jobs\NotifyLateCheckIn;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;
use Webkul\Support\Services\HierarchicalNotificationService;

/**
 * Attendance notices to the employee themselves (panel bell, database
 * notifications through HierarchicalNotificationService, so every notice
 * is idempotent by key and audit-logged):
 *
 *  - check-in reminder (hr:remind-missing-check-in): a working day, no
 *    check-in and not excused, some minutes after the employee is due --
 *    10:15, or 14:15 after an approved first half off / half On-Duty;
 *    once per employee per day;
 *  - late check-in: right after a late first check-in of the day (queued,
 *    after commit), with the month's grace balance; once per employee per day;
 *  - forgotten check-out (from hr:flag-forgotten-shifts); once per employee per day;
 *  - negative grace balance: the first time in a month the balance drops
 *    below zero (hr:grace-balance-alerts and late check-ins); once per month.
 *
 * Only employees of the company with a linked, active user who belongs to
 * that company are told. Each type can be switched off in
 * config/attendance.php -> notifications.*.
 */
class EmployeeAttendanceNotifier
{
    public function __construct(
        protected TodayAttendance $today,
        protected MonthlyAttendanceReport $report,
        protected HierarchicalNotificationService $notifications,
    ) {}

    public static function enabled(string $type): bool
    {
        return (bool) config('attendance.notifications.'.$type, true);
    }

    /**
     * "You haven't checked in today" to everyone who is due by now and has
     * not checked in. Nothing after the employee's own day (office hours or
     * shift) has ended.
     *
     * @return array{companies: int, notified: int, skipped: int}
     */
    public function remindMissingCheckIns(?int $companyId = null): array
    {
        $result = ['companies' => 0, 'notified' => 0, 'skipped' => 0];

        if (! static::enabled('check_in_reminder')) {
            return $result;
        }

        $now = CarbonImmutable::now($this->timezone());
        $date = $now->toDateString();
        $secondsNow = $this->secondsAfterMidnight($now);
        $after = max(0, (int) config('attendance.notifications.check_in_reminder_after_minutes', 15)) * 60;

        foreach ($this->companies($companyId) as $company) {
            $day = $this->today->forWholeCompany((int) $company->id, $date);

            // Each employee's own day: an evening or night shift is reminded after its own start, until its own end.
            $due = collect($day['rows'])->filter(fn (array $row): bool => $row['absent']
                && ! $row['checked_in']
                && $row['due_start_seconds'] !== null
                && $secondsNow >= $row['due_start_seconds'] + $after
                && $secondsNow < (int) ($row['due_end_seconds'] ?? $this->officeEnd()));

            if ($due->isEmpty()) {
                continue;
            }

            $result['companies']++;
            $users = $this->usersOf((int) $company->id, $due->keys()->all());

            foreach ($due as $employeeId => $row) {
                $user = $users->get($employeeId);
                if (! $user) {
                    continue;
                }

                $notification = Notification::make()
                    ->warning()
                    ->icon('heroicon-o-finger-print')
                    ->title("You haven't checked in today")
                    ->body(sprintf(
                        'You were due at %s today (%s). Please check in now, or request a correction or time off if you are away.',
                        $this->clock((int) $row['due_start_seconds']),
                        $day['date_label'],
                    ))
                    ->actions([$this->myAttendanceAction('Check in')]);

                $sent = $this->notifications->sendIdempotentNotification($user, $notification, "attendance_check_in_reminder_{$employeeId}_{$date}", [
                    'event'       => 'attendance_check_in_reminder',
                    'employee_id' => $employeeId,
                    'company_id'  => (int) $company->id,
                    'date'        => $date,
                ]);

                $result[$sent ? 'notified' : 'skipped']++;
            }
        }

        return $result;
    }

    /**
     * Called by the AttendanceRecord hook when a day gets its first check-in:
     * queues the late check-in notice (after commit) for a check-in made just
     * now. Back-dated entries and imports are not notified. Never throws.
     */
    public function queueLateCheckIn(AttendanceRecord $record): void
    {
        try {
            if (! static::enabled('late_check_in') || $record->check_in === null || $record->employee_id === null) {
                return;
            }

            $now = CarbonImmutable::now($this->timezone());
            // Today's day, or yesterday's overnight shift checked into after midnight.
            if (! in_array($record->attendance_date?->toDateString(), [$now->toDateString(), $now->subDay()->toDateString()], true)) {
                return;
            }

            $maxAge = max(1, (int) config('attendance.notifications.late_check_in_max_age_minutes', 120));
            $age = CarbonImmutable::parse($record->check_in)->diffInMinutes($now, false);
            if ($age > $maxAge || $age < -5) {
                return;
            }

            NotifyLateCheckIn::dispatch((int) $record->id);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * "You checked in X min late. Grace balance: Y min left this month." Y =
     * the grace balance up to yesterday minus today's late minutes; a
     * negative Y also triggers the month's negative-balance alert.
     */
    public function notifyLateCheckIn(int $recordId): bool
    {
        try {
            $record = AttendanceRecord::query()->with('employee.user')->find($recordId);
            $employee = $record?->employee;

            if (! static::enabled('late_check_in') || ! $record || ! $employee || $record->check_in === null) {
                return false;
            }

            $companyId = (int) $record->company_id;
            $user = $this->activeUser($employee, $companyId);
            if (! $user || ! $employee->is_active || (int) $employee->company_id !== $companyId) {
                return false;
            }

            $date = $record->attendance_date->toDateString();
            $month = substr($date, 0, 7);
            $ledger = $this->report->forEmployeesOfCompany($companyId, $month, [(int) $employee->id])[(int) $employee->id] ?? null;
            $row = $ledger ? collect($ledger['rows'])->firstWhere('date', $date) : null;

            if (! $row || ! $row['working'] || ! $row['late']) {
                return false;
            }

            $lateMinutes = (int) $row['late_minutes'];
            $balance = $this->balanceBefore($ledger, $date) - $lateMinutes;

            $balanceSentence = $balance >= 0
                ? "Grace balance: {$balance} min left this month."
                : 'Grace balance: '.$this->signed($balance).' min this month; this will show as a shortfall.';

            $notification = Notification::make()
                ->color($balance >= 0 ? 'warning' : 'danger')
                ->icon('heroicon-o-clock')
                ->title('You checked in late')
                ->body("You checked in {$lateMinutes} min late. {$balanceSentence}")
                ->actions([$this->myAttendanceAction('View my attendance')]);

            $sent = $this->notifications->sendIdempotentNotification($user, $notification, "attendance_late_check_in_{$employee->id}_{$date}", [
                'event'                 => 'attendance_late_check_in',
                'employee_id'           => (int) $employee->id,
                'company_id'            => $companyId,
                'date'                  => $date,
                'late_minutes'          => $lateMinutes,
                'grace_balance_minutes' => $balance,
            ]);

            if ($balance < 0) {
                $this->alertNegativeBalance($employee, $user, $companyId, $month, $balance);
            }

            return $sent;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Employee copy of hr:flag-forgotten-shifts (the line manager / HR are
     * told there): "You didn't check out ..." once per employee per day.
     */
    public function notifyForgottenCheckOut(AttendanceRecord $record): bool
    {
        try {
            if (! static::enabled('forgotten_check_out')) {
                return false;
            }

            $record->loadMissing('employee.user');
            $employee = $record->employee;
            $companyId = (int) $record->company_id;
            $user = $employee ? $this->activeUser($employee, $companyId) : null;

            if (! $employee || ! $user || (int) $employee->company_id !== $companyId || ! $record->attendance_date) {
                return false;
            }

            $date = $record->attendance_date->toDateString();
            $when = $date === CarbonImmutable::now($this->timezone())->toDateString()
                ? 'today'
                : 'on '.$record->attendance_date->format('l, d M Y');
            $checkedIn = $record->check_in
                ? ' (checked in at '.CarbonImmutable::parse($record->check_in)->setTimezone($this->timezone())->format('H:i').')'
                : '';

            $notification = Notification::make()
                ->danger()
                ->icon('heroicon-o-arrow-right-on-rectangle')
                ->title("You didn't check out")
                ->body("You didn't check out {$when}{$checkedIn}. Please check out or request a correction.")
                ->actions([$this->myAttendanceAction('Open my attendance')]);

            return $this->notifications->sendIdempotentNotification($user, $notification, "attendance_forgotten_check_out_{$employee->id}_{$date}", [
                'event'                => 'attendance_forgotten_check_out',
                'attendance_record_id' => (int) $record->id,
                'employee_id'          => (int) $employee->id,
                'company_id'           => $companyId,
                'date'                 => $date,
            ]);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * The month's negative-balance alert for every employee whose settled
     * grace balance (completed days; today once the office day is over and
     * the employee has checked out) is below zero. Once per employee per month.
     *
     * @return array{companies: int, notified: int, skipped: int}
     */
    public function alertNegativeGraceBalances(?int $companyId = null): array
    {
        $result = ['companies' => 0, 'notified' => 0, 'skipped' => 0];

        if (! static::enabled('negative_grace_balance')) {
            return $result;
        }

        $now = CarbonImmutable::now($this->timezone());
        $today = $now->toDateString();
        $month = $now->format('Y-m');
        $dayOver = $this->secondsAfterMidnight($now) >= $this->officeEnd();

        foreach ($this->companies($companyId) as $company) {
            $employeeIds = Employee::query()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->whereNotNull('user_id')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $users = $this->usersOf((int) $company->id, $employeeIds);
            if ($users->isEmpty()) {
                continue;
            }

            $result['companies']++;
            $employees = Employee::query()->whereIn('id', $users->keys()->all())->get()->keyBy('id');

            foreach ($this->report->forEmployeesOfCompany((int) $company->id, $month, $users->keys()->all()) as $employeeId => $ledger) {
                $todayRow = collect($ledger['rows'])->firstWhere('date', $today);
                $balance = $todayRow && $dayOver && ! $todayRow['missing_check_out']
                    ? intdiv((int) $todayRow['grace_balance_seconds'], 60)
                    : $this->balanceBefore($ledger, $today);

                if ($balance >= 0) {
                    continue;
                }

                $sent = $this->alertNegativeBalance($employees->get($employeeId), $users->get($employeeId), (int) $company->id, $month, $balance);
                $result[$sent ? 'notified' : 'skipped']++;
            }
        }

        return $result;
    }

    /** "Your grace balance is −N min this month; this will show as a shortfall." once per employee per month. */
    public function alertNegativeBalance(Employee $employee, User $user, int $companyId, string $month, int $balanceMinutes): bool
    {
        if (! static::enabled('negative_grace_balance') || $balanceMinutes >= 0) {
            return false;
        }

        $label = CarbonImmutable::parse($month.'-01', $this->timezone())->format('F Y');

        $notification = Notification::make()
            ->danger()
            ->icon('heroicon-o-exclamation-triangle')
            ->title('Your grace balance is below zero')
            ->body('Your grace balance is '.$this->signed($balanceMinutes)." min this month ({$label}); this will show as a shortfall.")
            ->actions([$this->myAttendanceAction('View my grace balance')]);

        return $this->notifications->sendIdempotentNotification($user, $notification, "attendance_grace_negative_{$employee->id}_{$month}", [
            'event'                 => 'attendance_grace_negative',
            'employee_id'           => (int) $employee->id,
            'company_id'            => $companyId,
            'month'                 => $month,
            'grace_balance_minutes' => $balanceMinutes,
        ]);
    }

    /**
     * The running grace balance at the end of the day before $date (the
     * month's opening balance when there is no earlier day).
     *
     * @param  array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>}  $ledger
     */
    public function balanceBefore(array $ledger, string $date): int
    {
        $previous = collect($ledger['rows'])->filter(fn (array $row): bool => $row['date'] < $date)->last();

        return $previous !== null
            ? intdiv((int) $previous['grace_balance_seconds'], 60)
            : (int) $ledger['summary']['grace_allowance_minutes'] + (int) $ledger['summary']['grace_carry_in_minutes'];
    }

    /**
     * Active users linked to these employees of the company who belong to the company, keyed by employee id.
     *
     * @param  array<int, int>  $employeeIds
     * @return Collection<int, User>
     */
    public function usersOf(int $companyId, array $employeeIds): Collection
    {
        if ($employeeIds === []) {
            return collect();
        }

        return Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $employeeIds)
            ->whereNotNull('user_id')
            ->with('user')
            ->get(['id', 'company_id', 'user_id'])
            ->mapWithKeys(fn (Employee $employee): array => [(int) $employee->id => $this->activeUser($employee, $companyId)])
            ->filter();
    }

    protected function activeUser(Employee $employee, int $companyId): ?User
    {
        $user = $employee->user;

        return $user instanceof User && $user->is_active && CurrentCompany::canAccess($companyId, $user) ? $user : null;
    }

    /** @return Collection<int, Company> */
    protected function companies(?int $companyId): Collection
    {
        return Company::query()
            ->where('is_active', true)
            ->when($companyId, fn (Builder $query) => $query->whereKey($companyId))
            ->orderBy('id')
            ->get(['id', 'name']);
    }

    protected function myAttendanceAction(string $label): Action
    {
        return Action::make('my_attendance')
            ->button()
            ->label($label)
            ->url(route('filament.admin.pages.my-attendance'));
    }

    protected function timezone(): string
    {
        return $this->today->timezone();
    }

    protected function officeEnd(): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', (string) config('attendance.monthly_report.office_end', '18:00')) + [1 => 0]);

        return $hours * 3600 + $minutes * 60;
    }

    protected function secondsAfterMidnight(CarbonImmutable $moment): int
    {
        return $moment->hour * 3600 + $moment->minute * 60 + $moment->second;
    }

    protected function clock(int $seconds): string
    {
        return sprintf('%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    /** -45 -> "−45" (a real minus sign). */
    protected function signed(int $minutes): string
    {
        return ($minutes < 0 ? "\u{2212}" : '').abs($minutes);
    }
}
