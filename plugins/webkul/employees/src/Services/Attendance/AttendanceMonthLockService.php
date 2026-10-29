<?php

namespace Webkul\Employee\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Webkul\Employee\Enums\AttendanceVerificationStatus;
use Webkul\Employee\Exceptions\AttendanceMonthLockedException;
use Webkul\Employee\Models\AttendanceInterval;
use Webkul\Employee\Models\AttendanceMonthLock;
use Webkul\Employee\Models\AttendanceMonthLockEvent;
use Webkul\Employee\Models\AttendanceMonthSnapshot;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\WorkSchedule;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

/**
 * Attendance month finalise / lock by HR (HR parity plan 2.3), per company
 * and month ("Y-m" in attendance.monthly_report.timezone).
 *
 * Finalise (permission hr_finalise_attendance, a month that has ended):
 * stores one snapshot per employee -- the monthly report's employee row
 * (summary, days, schedule) as JSON, with who and when -- and locks the
 * month. The monthly report and its Excel export read the snapshot while
 * the month is locked, so the payroll export stays stable.
 *
 * A locked month blocks, at model level (whatever the path: Filament, the
 * API, imports, commands, approvals):
 *  - every create / update / delete of an attendance record or interval of
 *    a day in it (AttendanceRecord / AttendanceInterval hooks);
 *  - every Time Off state change (and every change to an approved leave)
 *    touching a day in it (Leave hooks);
 *  - work schedule changes affecting days in it (WorkSchedule hooks);
 *  - submitting or finally approving overtime for a day in it.
 * The refusal is an AttendanceMonthLockedException with a clear message.
 *
 * Re-open (same permission, a reason of at least 10 characters): unlocks the
 * month; the snapshot stays until the next finalise replaces it. Every
 * finalise and re-open is written to employees_attendance_month_lock_events.
 */
class AttendanceMonthLockService
{
    private static ?bool $tablesExist = null;

    public static function available(): bool
    {
        return self::$tablesExist ??= Schema::hasTable('employees_attendance_month_locks');
    }

    /** HR who may finalise and re-open the company's months. */
    public static function canFinalise(?User $user, int $companyId): bool
    {
        return $user !== null
            && $user->can(HrPermissions::FinaliseAttendance)
            && CurrentCompany::canAccess($companyId, $user);
    }

    public function lockFor(int $companyId, string $month): ?AttendanceMonthLock
    {
        if (! static::available()) {
            return null;
        }

        return AttendanceMonthLock::query()->where('company_id', $companyId)->where('month', $month)->first();
    }

    public function finalisedLock(int $companyId, string $month): ?AttendanceMonthLock
    {
        $lock = $this->lockFor($companyId, $month);

        return $lock?->isFinalised() ? $lock : null;
    }

    public function isLocked(int $companyId, string $date): bool
    {
        return $this->firstLockedMonth($companyId, [substr($date, 0, 7)]) !== null;
    }

    /**
     * The snapshots of a finalised month, keyed by employee id, in report
     * order. Empty when the month is not finalised.
     *
     * @param  array<int, int>|null  $employeeIds  null = everyone in the snapshot
     * @return array<int, array<string, mixed>>
     */
    public function snapshotsFor(int $companyId, string $month, ?array $employeeIds = null): array
    {
        $lock = $this->finalisedLock($companyId, $month);
        if (! $lock || $employeeIds === []) {
            return [];
        }

        return AttendanceMonthSnapshot::query()
            ->where('lock_id', $lock->id)
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', array_map('intval', $employeeIds)))
            ->orderBy('id')
            ->get(['id', 'employee_id', 'data'])
            ->mapWithKeys(fn (AttendanceMonthSnapshot $snapshot): array => [(int) $snapshot->employee_id => (array) $snapshot->data])
            ->all();
    }

    // ------------------------------------------------------------------
    // Guards
    // ------------------------------------------------------------------

    /**
     * @param  array<int, string>  $dates  Y-m-d
     *
     * @throws AttendanceMonthLockedException
     */
    public function assertDatesOpen(int $companyId, array $dates, string $what = 'attendance'): void
    {
        $months = array_values(array_unique(array_filter(array_map(fn (?string $date): ?string => $date ? substr($date, 0, 7) : null, $dates))));
        $lock = $this->firstLockedMonth($companyId, $months);

        if ($lock !== null) {
            throw $this->lockedException($lock, $what);
        }
    }

    /**
     * Refuses when any month of [$from, $to] is finalised ($to null = open-ended).
     *
     * @throws AttendanceMonthLockedException
     */
    public function assertRangeOpen(int $companyId, string $from, ?string $to, string $what = 'attendance'): void
    {
        if (! static::available() || $companyId <= 0 || ($to !== null && $to < $from)) {
            return;
        }

        $lock = AttendanceMonthLock::query()
            ->where('company_id', $companyId)
            ->where('status', AttendanceMonthLock::Finalised)
            ->where('month', '>=', substr($from, 0, 7))
            ->when($to !== null, fn ($query) => $query->where('month', '<=', substr((string) $to, 0, 7)))
            ->orderBy('month')
            ->first();

        if ($lock !== null) {
            throw $this->lockedException($lock, $what);
        }
    }

    /** AttendanceRecord saving / deleting: the day (old and new) must be in an open month. */
    public function guardAttendanceRecord(AttendanceRecord $record, bool $deleting = false): void
    {
        $companyId = (int) ($record->company_id ?: Employee::query()->withTrashed()->whereKey($record->employee_id)->value('company_id'));
        $checks = [$companyId.'|'.$record->attendance_date?->toDateString()];

        if ($record->exists) {
            $originalDate = $record->getRawOriginal('attendance_date');
            $checks[] = (int) ($record->getRawOriginal('company_id') ?: $companyId).'|'.($originalDate ? substr((string) $originalDate, 0, 10) : '');
        }

        foreach (array_unique($checks) as $check) {
            [$company, $date] = explode('|', $check);
            if ((int) $company > 0 && $date !== '') {
                $this->assertDatesOpen((int) $company, [$date], 'attendance');
            }
        }
    }

    /** AttendanceInterval saving / deleting: guarded by its day. */
    public function guardAttendanceInterval(AttendanceInterval $interval): void
    {
        $record = $interval->relationLoaded('attendanceRecord')
            ? $interval->attendanceRecord
            : AttendanceRecord::query()->find($interval->attendance_record_id, ['id', 'company_id', 'attendance_date']);

        if ($record?->attendance_date) {
            $this->assertDatesOpen((int) ($record->company_id ?: $interval->company_id), [$record->attendance_date->toDateString()], 'attendance');
        }
    }

    /**
     * Time Off (time_off_leaves) saving / deleting: a new request, a state
     * change, a change to an approved leave, or deleting an approved leave
     * must not touch a day of a finalised month. Duck-typed (the Time Off
     * plugin depends on this one, not the other way round).
     */
    public function guardLeave(Model $leave, bool $deleting = false): void
    {
        $approved = ApprovedLeaveLookup::APPROVED_STATE;
        $state = $this->stateValue($leave->getAttribute('state'));
        $originalState = $this->stateValue($leave->getRawOriginal('state'));
        $watched = ['request_date_from', 'request_date_to', 'date_from', 'date_to', 'request_unit_half', 'request_unit_hours',
            'request_hour_from', 'request_hour_to', 'request_date_from_period', 'holiday_status_id', 'employee_id'];

        $original = (object) [
            'request_date_from' => $leave->getRawOriginal('request_date_from'),
            'request_date_to'   => $leave->getRawOriginal('request_date_to'),
            'date_from'         => $leave->getRawOriginal('date_from'),
            'date_to'           => $leave->getRawOriginal('date_to'),
        ];

        $ranges = match (true) {
            $deleting                                                                        => $originalState === $approved ? [$original] : [],
            ! $leave->exists                                                                 => [$leave],
            $leave->isDirty('state')                                                         => [$original, $leave],
            $leave->isDirty($watched) && in_array($approved, [$state, $originalState], true) => [$original, $leave],
            default                                                                          => [],
        };

        if ($ranges === []) {
            return;
        }

        $employeeIds = array_filter([(int) $leave->getAttribute('employee_id'), (int) $leave->getRawOriginal('employee_id')]);
        $companyIds = Employee::query()->withTrashed()->whereIn('id', $employeeIds)->pluck('company_id')->map(fn ($id): int => (int) $id)->unique()->all()
            ?: array_filter([(int) $leave->getAttribute('company_id')]);

        foreach ($ranges as $range) {
            $from = ApprovedLeaveLookup::startDate($range);
            $to = ApprovedLeaveLookup::endDate($range) ?? $from;
            if ($from === null) {
                continue;
            }

            foreach ($companyIds as $companyId) {
                $this->assertRangeOpen((int) $companyId, $from, $to, 'leave');
            }
        }
    }

    /**
     * WorkSchedule saving / deleting: refused when it would change the
     * schedule of a day in a finalised month (a new or deleted period, a
     * changed definition, or the days added / removed by new dates).
     */
    public function guardWorkSchedule(WorkSchedule $schedule, bool $deleting = false): void
    {
        $companyId = (int) ($schedule->company_id ?: Employee::query()->withTrashed()->whereKey($schedule->employee_id)->value('company_id'));
        $newFrom = $schedule->effective_from?->toDateString();
        $newTo = $schedule->effective_to?->toDateString();
        $oldFrom = $schedule->getRawOriginal('effective_from') ? substr((string) $schedule->getRawOriginal('effective_from'), 0, 10) : null;
        $oldTo = $schedule->getRawOriginal('effective_to') ? substr((string) $schedule->getRawOriginal('effective_to'), 0, 10) : null;

        if ($deleting) {
            $ranges = [[$oldFrom, $oldTo]];
        } elseif (! $schedule->exists) {
            $ranges = [[$newFrom, $newTo]];
        } elseif ($schedule->isDirty(['start_time', 'end_time', 'required_minutes', 'working_days', 'flexible_minutes', 'employee_id', 'company_id'])) {
            $ranges = [[$oldFrom, $oldTo], [$newFrom, $newTo]];
        } else {
            $ranges = $this->changedDays($oldFrom, $oldTo, $newFrom, $newTo);
        }

        foreach ($ranges as [$from, $to]) {
            if ($from !== null) {
                $this->assertRangeOpen($companyId, $from, $to, 'work schedules');
            }
        }
    }

    // ------------------------------------------------------------------
    // Finalise / re-open
    // ------------------------------------------------------------------

    /**
     * What HR sees before finalising: who is in the month and what is still
     * open (missing check-outs, days flagged for review, pending leave,
     * pending overtime).
     *
     * @return array{month: string, month_label: string, employees: int, missing_check_outs: int, flagged_days: int, days_needing_review: int, pending_leave: int, pending_overtime: int, warnings: array<int, string>}
     */
    public function review(int $companyId, string $month): array
    {
        [$from, $to, $label] = $this->monthBounds($month);

        $records = AttendanceRecord::query()
            ->where('company_id', $companyId)
            ->whereDate('attendance_date', '>=', $from)
            ->whereDate('attendance_date', '<=', $to);

        $missingCheckOuts = (clone $records)->whereNotNull('check_in')->whereNull('check_out')->count();
        $flagged = (clone $records)->where('verification_status', AttendanceVerificationStatus::NeedsReview->value)->count();
        $needingReview = (clone $records)->where(fn ($query) => $query
            ->where(fn ($open) => $open->whereNotNull('check_in')->whereNull('check_out'))
            ->orWhere('verification_status', AttendanceVerificationStatus::NeedsReview->value))
            ->count();

        $pendingLeave = ApprovedLeaveLookup::available()
            ? ApprovedLeaveLookup::overlapping(
                DB::table('time_off_leaves')
                    ->whereIn('employee_id', Employee::query()->withTrashed()->where('company_id', $companyId)->select('id'))
                    ->whereIn('state', ApprovedLeaveLookup::PENDING_STATES),
                $from,
                $to,
            )->count()
            : 0;

        $pendingOvertime = OvertimeService::pendingCount($companyId, $from, $to);

        $employees = app(MonthlyAttendanceReport::class)->companyMonthEmployeeCount($companyId, $month);

        $warnings = array_values(array_filter([
            $missingCheckOuts > 0 ? $missingCheckOuts.' day(s) with a missing check-out' : null,
            $flagged > 0 ? $flagged.' day(s) flagged for review' : null,
            $pendingLeave > 0 ? $pendingLeave.' leave request(s) still pending' : null,
            $pendingOvertime > 0 ? $pendingOvertime.' overtime request(s) still pending' : null,
        ]));

        return [
            'month'               => $month,
            'month_label'         => $label,
            'employees'           => $employees,
            'missing_check_outs'  => $missingCheckOuts,
            'flagged_days'        => $flagged,
            'days_needing_review' => $needingReview,
            'pending_leave'       => $pendingLeave,
            'pending_overtime'    => $pendingOvertime,
            'warnings'            => $warnings,
        ];
    }

    /**
     * Finalises (locks) the month and stores the snapshot. Open issues do not
     * block it; they are recorded on the lock and its audit row.
     *
     * @throws AuthorizationException
     */
    public function finalise(User $user, int $companyId, string $month): AttendanceMonthLock
    {
        if (! static::canFinalise($user, $companyId)) {
            throw new AuthorizationException('You may not finalise attendance months of this company.');
        }

        [, $to, $label] = $this->monthBounds($month);
        $today = CarbonImmutable::now($this->timezone())->toDateString();
        if ($to >= $today) {
            throw new RuntimeException("{$label} can be finalised once it has ended (from ".CarbonImmutable::parse($to)->addDay()->format('d M Y').').');
        }

        if ($this->finalisedLock($companyId, $month)) {
            throw new RuntimeException("{$label} is already finalised.");
        }

        $review = $this->review($companyId, $month);
        $reports = app(MonthlyAttendanceReport::class)->liveCompanyMonth($companyId, $month);

        return DB::transaction(function () use ($user, $companyId, $month, $label, $review, $reports): AttendanceMonthLock {
            $lock = AttendanceMonthLock::query()->where('company_id', $companyId)->where('month', $month)->lockForUpdate()->first()
                ?? new AttendanceMonthLock(['company_id' => $companyId, 'month' => $month]);

            if ($lock->exists && $lock->isFinalised()) {
                throw new RuntimeException("{$label} is already finalised.");
            }

            $now = now();
            $lock->fill([
                'status'          => AttendanceMonthLock::Finalised,
                'employees_count' => count($reports),
                'open_issues'     => $review,
                'finalised_at'    => $now,
                'finalised_by'    => $user->id,
            ])->save();

            // The previous snapshot (from before a re-open) is replaced.
            AttendanceMonthSnapshot::query()->where('company_id', $companyId)->where('month', $month)->delete();

            foreach ($reports as $report) {
                AttendanceMonthSnapshot::query()->create([
                    'lock_id'      => $lock->id,
                    'company_id'   => $companyId,
                    'month'        => $month,
                    'employee_id'  => $report['employee']['id'],
                    'data'         => $report,
                    'finalised_at' => $now,
                    'finalised_by' => $user->id,
                ]);
            }

            AttendanceMonthLockEvent::query()->create([
                'lock_id'    => $lock->id,
                'company_id' => $companyId,
                'month'      => $month,
                'action'     => AttendanceMonthLock::Finalised,
                'user_id'    => $user->id,
                'details'    => $review + ['snapshots' => count($reports)],
            ]);

            return $lock->fresh(['finaliser']);
        });
    }

    /**
     * Re-opens a finalised month with a reason (audited). The snapshot stays
     * until the month is finalised again.
     *
     * @throws AuthorizationException
     */
    public function reopen(User $user, int $companyId, string $month, string $reason): AttendanceMonthLock
    {
        if (! static::canFinalise($user, $companyId)) {
            throw new AuthorizationException('You may not re-open attendance months of this company.');
        }

        [, , $label] = $this->monthBounds($month);
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw new InvalidArgumentException('Give a reason of at least 10 characters for re-opening the month.');
        }

        return DB::transaction(function () use ($user, $companyId, $month, $label, $reason): AttendanceMonthLock {
            $lock = AttendanceMonthLock::query()->where('company_id', $companyId)->where('month', $month)->lockForUpdate()->first();
            if (! $lock || ! $lock->isFinalised()) {
                throw new RuntimeException("{$label} is not finalised.");
            }

            $lock->fill([
                'status'        => AttendanceMonthLock::Reopened,
                'reopened_at'   => now(),
                'reopened_by'   => $user->id,
                'reopen_reason' => mb_substr($reason, 0, 1000),
            ])->save();

            AttendanceMonthLockEvent::query()->create([
                'lock_id'    => $lock->id,
                'company_id' => $companyId,
                'month'      => $month,
                'action'     => AttendanceMonthLock::Reopened,
                'user_id'    => $user->id,
                'reason'     => mb_substr($reason, 0, 1000),
                'details'    => ['finalised_at' => $lock->finalised_at?->toDateTimeString(), 'finalised_by' => $lock->finalised_by],
            ]);

            return $lock->fresh(['reopener']);
        });
    }

    // ------------------------------------------------------------------

    /** @param  array<int, string>  $months  Y-m */
    private function firstLockedMonth(int $companyId, array $months): ?AttendanceMonthLock
    {
        if ($months === [] || $companyId <= 0 || ! static::available()) {
            return null;
        }

        return AttendanceMonthLock::query()
            ->where('company_id', $companyId)
            ->whereIn('month', $months)
            ->where('status', AttendanceMonthLock::Finalised)
            ->orderBy('month')
            ->first();
    }

    private function lockedException(AttendanceMonthLock $lock, string $what): AttendanceMonthLockedException
    {
        $label = CarbonImmutable::createFromFormat('!Y-m', $lock->month)->format('F Y');
        $company = (string) Company::query()->whereKey($lock->company_id)->value('name');

        $change = match ($what) {
            'leave'          => 'leave requests for those days can no longer be created, approved, refused or cancelled',
            'work schedules' => 'work schedules covering it can no longer be changed',
            'overtime'       => 'overtime for those days can no longer be requested or approved',
            default          => 'its attendance can no longer be changed',
        };

        return new AttendanceMonthLockedException(
            "Attendance for {$label}".($company !== '' ? " ({$company})" : '')." is finalised, so {$change}. Ask HR to re-open the month first.",
            (int) $lock->company_id,
            (string) $lock->month,
        );
    }

    /** @return array{0: string, 1: string, 2: string} first day, last day, "August 2026" */
    private function monthBounds(string $month): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new InvalidArgumentException('The month must look like 2026-08.');
        }

        $start = CarbonImmutable::createFromFormat('!Y-m', $month, $this->timezone());

        return [$start->toDateString(), $start->endOfMonth()->toDateString(), $start->format('F Y')];
    }

    /**
     * The days whose coverage differs between [$oldFrom, $oldTo] and
     * [$newFrom, $newTo] (null end = open-ended).
     *
     * @return array<int, array{0: ?string, 1: ?string}>
     */
    private function changedDays(?string $oldFrom, ?string $oldTo, ?string $newFrom, ?string $newTo): array
    {
        $ranges = [];
        $dayBefore = fn (string $date): string => CarbonImmutable::parse($date)->subDay()->toDateString();
        $dayAfter = fn (string $date): string => CarbonImmutable::parse($date)->addDay()->toDateString();

        if ($oldFrom !== $newFrom && $oldFrom !== null && $newFrom !== null) {
            $ranges[] = [min($oldFrom, $newFrom), $dayBefore(max($oldFrom, $newFrom))];
        }

        if ($oldTo !== $newTo) {
            $ranges[] = match (true) {
                $oldTo === null => [$dayAfter((string) $newTo), null],
                $newTo === null => [$dayAfter($oldTo), null],
                default         => [$dayAfter(min($oldTo, $newTo)), max($oldTo, $newTo)],
            };
        }

        return $ranges;
    }

    private function stateValue(mixed $state): ?string
    {
        return $state instanceof \BackedEnum ? (string) $state->value : ($state !== null ? (string) $state : null);
    }

    private function timezone(): string
    {
        return (string) config('attendance.monthly_report.timezone', 'Asia/Karachi');
    }
}
