<?php

namespace Webkul\Employee\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Models\EmployeeRequestType;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Security\Models\User;

/**
 * Overtime requests (HR parity plan 2.2; no pay calculation).
 *
 * The employee or their line manager files an "Overtime" Employee Request
 * for a date. It routes through the ApprovalEngine (request_type
 * "overtime": line manager, then HR; OvertimeWorkflowSeeder). The request
 * shows the extra minutes actually recorded that day
 * (MonthlyAttendanceReport::dayRow(): minutes worked beyond the day's
 * required minutes; every minute on a weekend, public holiday or full-day
 * leave).
 *
 * Server-side rules, on submission and again on final approval:
 *  - at least 30 minutes, rounded down to a multiple of 15;
 *  - never more than the recorded extra minutes of that day (re-measured at
 *    final approval: a later correction can lower it, an approver can lower
 *    it, nobody can raise it);
 *  - one active (pending, returned or approved) request per employee and day;
 *  - not for a day of a finalised attendance month.
 *
 * Approved minutes are taken out of that day's time bank by the monthly
 * report (see MonthlyAttendanceReport), so they are never counted twice.
 */
class OvertimeService
{
    public const RequestCode = 'overtime';

    public const Category = 'overtime';

    public const ApprovalRequestType = 'overtime';

    public const MinimumMinutes = 30;

    public const StepMinutes = 15;

    /** Request statuses that hold the day (a second request for it is refused). */
    public const ActiveStatuses = ['pending_approval', 'returned', 'approved'];

    public function __construct(
        protected MonthlyAttendanceReport $report,
        protected AttendanceMonthLockService $locks,
        protected EmployeeRequestService $requests,
    ) {}

    public static function isOvertime(?EmployeeRequestType $type): bool
    {
        return $type?->category === self::Category;
    }

    /** Down to a multiple of 15. */
    public static function roundDown(int $minutes): int
    {
        return intdiv(max(0, $minutes), self::StepMinutes) * self::StepMinutes;
    }

    /**
     * What attendance recorded on the day, for the request form.
     *
     * @return array{date: string, type: string, recorded_extra_minutes: int, requestable_minutes: int, worked_minutes: int, required_minutes: int, schedule: string, approved_minutes: int}
     */
    public function dayDetails(Employee $employee, string $date): array
    {
        $row = $this->report->dayRow($employee, $this->normaliseDate($date));

        return [
            'date'                   => $row['date'],
            'type'                   => (string) $row['type'],
            'recorded_extra_minutes' => (int) $row['recorded_extra_minutes'],
            'requestable_minutes'    => self::roundDown((int) $row['recorded_extra_minutes']),
            'worked_minutes'         => intdiv((int) $row['worked_total_seconds'], 60),
            'required_minutes'       => intdiv((int) $row['required_seconds'], 60),
            'schedule'               => (string) $row['schedule'],
            'approved_minutes'       => (int) $row['overtime_minutes'],
        ];
    }

    /** "2h 15m worked beyond the required 8h 0m (10:00–18:00)" -- the form's helper line. */
    public function describeDay(Employee $employee, string $date): string
    {
        $day = $this->dayDetails($employee, $date);

        $what = match ($day['type']) {
            'weekend' => 'Weekend: every minute counts. Worked '.self::hours($day['worked_minutes']),
            'holiday' => 'Public holiday: every minute counts. Worked '.self::hours($day['worked_minutes']),
            'leave'   => 'Day on approved leave: every minute counts. Worked '.self::hours($day['worked_minutes']),
            'working' => 'Worked '.self::hours($day['worked_minutes']).' against '.self::hours($day['required_minutes']).' required ('.$day['schedule'].')',
            default   => 'Not a day the employee could work',
        };

        return $what.'. Extra time recorded: '.self::hours($day['recorded_extra_minutes'])
            .($day['requestable_minutes'] >= self::MinimumMinutes ? ' (up to '.$day['requestable_minutes'].' min can be requested).' : ' (less than the 30 minutes overtime needs).');
    }

    /**
     * Files and submits an overtime request for the employee (by the employee,
     * their line manager or HR).
     */
    public function request(Employee $employee, User $requester, string $date, int $minutes, ?string $reason = null): EmployeeRequest
    {
        $type = EmployeeRequestType::query()
            ->where('company_id', $employee->company_id)
            ->where('code', self::RequestCode)
            ->where('is_active', true)
            ->first() ?? throw new RuntimeException('No active "Overtime" request type is configured for this company.');

        $date = $this->normaliseDate($date);

        return DB::transaction(function () use ($employee, $requester, $type, $date, $minutes, $reason): EmployeeRequest {
            $request = EmployeeRequest::query()->create([
                'company_id'      => $employee->company_id,
                'employee_id'     => $employee->id,
                'request_type_id' => $type->id,
                'requested_by'    => $requester->id,
                'title'           => 'Overtime - '.CarbonImmutable::parse($date)->format('d M Y'),
                'description'     => $reason,
                'status'          => 'draft',
                'payload'         => [
                    'kind'              => self::Category,
                    'overtime_date'     => $date,
                    'requested_minutes' => $minutes,
                    'overtime_reason'   => $reason,
                ],
            ]);

            $this->requests->submit($request, $requester);

            return $request->fresh(['approvalRequest', 'requestType']);
        });
    }

    /**
     * Validates an overtime request on (re)submission and records what
     * attendance showed at that moment. Called by EmployeeRequestService::submit().
     */
    public function prepareSubmission(EmployeeRequest $request): void
    {
        $request->loadMissing('employee');
        $payload = (array) ($request->payload ?? []);
        $date = $this->normaliseDate((string) ($payload['overtime_date'] ?? $payload['attendance_date'] ?? ''));

        if ($date > CarbonImmutable::now($this->report->timezone())->toDateString()) {
            throw new RuntimeException('Overtime can only be requested for a day that has already been worked.');
        }

        $this->locks->assertDatesOpen((int) $request->company_id, [$date], 'overtime');

        $duplicate = EmployeeRequest::query()
            ->where('company_id', $request->company_id)
            ->where('employee_id', $request->employee_id)
            ->when($request->exists, fn (Builder $query) => $query->whereKeyNot($request->getKey()))
            ->whereIn('status', self::ActiveStatuses)
            ->whereHas('requestType', fn (Builder $types) => $types->where('category', self::Category))
            ->where('payload->attendance_date', $date)
            ->exists();
        if ($duplicate) {
            throw new RuntimeException('There is already an overtime request for '.CarbonImmutable::parse($date)->format('d M Y').'. Withdraw or reject it first.');
        }

        $requested = $payload['requested_minutes'] ?? null;
        if (filter_var($requested, FILTER_VALIDATE_INT) === false || (int) $requested <= 0) {
            throw new RuntimeException('Give the overtime in whole minutes (at least 30).');
        }

        $day = $this->dayDetails($request->employee, $date);
        $minutes = $this->validatedMinutes((int) $requested, $day['recorded_extra_minutes'], $date);

        $payload['kind'] = self::Category;
        $payload['overtime_date'] = $date;
        $payload['attendance_date'] = $date;
        $payload['requested_minutes'] = $minutes;
        $payload['recorded_extra_minutes'] = $day['recorded_extra_minutes'];
        $payload['day_type'] = $day['type'];
        $payload['worked_minutes'] = $day['worked_minutes'];
        $payload['required_minutes'] = $day['required_minutes'];
        $payload['schedule'] = $day['schedule'];
        unset($payload['approved_minutes'], $payload['approved_at'], $payload['approved_recorded_extra_minutes'], $payload['formatted_date'], $payload['day_of_week']);

        $request->payload = $payload;
        if (blank($request->title) || str_starts_with((string) $request->title, 'Overtime - ')) {
            $request->title = 'Overtime - '.CarbonImmutable::parse($date)->format('d M Y');
        }
        $request->save();
    }

    /**
     * Approves the current step, optionally lowering the minutes (never
     * above what was requested or recorded). The minutes are kept on the
     * approval decision; the final approval applies the lowest of them.
     */
    public function approve(EmployeeRequest $request, User $actor, ?int $approvedMinutes = null, ?string $reason = null): EmployeeRequest
    {
        $request->loadMissing(['requestType', 'employee']);
        if (! self::isOvertime($request->requestType)) {
            throw new RuntimeException('This is not an overtime request.');
        }

        $values = [];
        if ($approvedMinutes !== null) {
            $requested = (int) data_get($request->payload, 'requested_minutes', 0);
            $minutes = self::roundDown($approvedMinutes);
            if ($minutes < self::MinimumMinutes) {
                throw new RuntimeException('Approve at least 30 minutes of overtime (in steps of 15), or reject the request.');
            }
            if ($minutes > $requested) {
                throw new RuntimeException("You cannot approve more than the {$requested} minutes requested.");
            }
            $values['approved_minutes'] = $minutes;
        }

        return $this->requests->approve($request, $actor, $reason, $values);
    }

    /**
     * Final approval of an overtime request (inside the approval decision's
     * transaction, so a refusal here rolls the decision back). Idempotent.
     */
    public function applyApproval(EmployeeRequest $request): void
    {
        $request->loadMissing(['employee', 'approvalRequest.decisions']);
        $payload = (array) ($request->payload ?? []);
        if (isset($payload['approved_minutes'])) {
            return;
        }

        $date = $this->normaliseDate((string) ($payload['attendance_date'] ?? $payload['overtime_date'] ?? ''));
        $this->locks->assertDatesOpen((int) $request->company_id, [$date], 'overtime');

        $recorded = $this->dayDetails($request->employee, $date)['recorded_extra_minutes'];
        $candidates = collect([(int) ($payload['requested_minutes'] ?? 0), $recorded])
            ->merge($request->approvalRequest?->decisions
                ->where('decision', 'approved')
                ->map(fn ($decision) => data_get($decision->new_values, 'approved_minutes'))
                ->filter(fn ($value): bool => is_numeric($value))
                ->map(fn ($value): int => (int) $value) ?? []);

        $approved = self::roundDown((int) $candidates->min());
        if ($approved < self::MinimumMinutes) {
            throw new RuntimeException(sprintf(
                'Only %d extra minutes are recorded on %s now, so this overtime cannot be approved (minimum 30). Reject the request instead.',
                $recorded,
                CarbonImmutable::parse($date)->format('d M Y'),
            ));
        }

        $payload['approved_minutes'] = $approved;
        $payload['approved_recorded_extra_minutes'] = $recorded;
        $payload['approved_at'] = now()->toDateTimeString();

        $request->forceFill(['payload' => $payload])->save();
    }

    /**
     * Approved and pending overtime minutes per employee and day, in one
     * query (the monthly report's input). Pending = submitted and waiting
     * for a decision.
     *
     * @param  array<int, int>  $employeeIds
     * @return array{approved: array<int, array<string, int>>, pending: array<int, array<string, int>>}
     */
    public static function minutesByDay(int $companyId, array $employeeIds, string $from, string $to): array
    {
        $result = ['approved' => [], 'pending' => []];

        if ($employeeIds === []) {
            return $result;
        }

        EmployeeRequest::query()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', array_map('intval', $employeeIds))
            ->whereIn('status', ['approved', 'pending_approval'])
            ->whereHas('requestType', fn (Builder $types) => $types->where('category', self::Category))
            ->where('payload->attendance_date', '>=', $from)
            ->where('payload->attendance_date', '<=', $to)
            ->get(['id', 'employee_id', 'status', 'payload'])
            ->each(function (EmployeeRequest $request) use (&$result): void {
                $date = (string) data_get($request->payload, 'attendance_date');
                $employeeId = (int) $request->employee_id;

                if ($request->status === 'approved') {
                    $result['approved'][$employeeId][$date] = ($result['approved'][$employeeId][$date] ?? 0) + (int) data_get($request->payload, 'approved_minutes', 0);
                } else {
                    $result['pending'][$employeeId][$date] = ($result['pending'][$employeeId][$date] ?? 0) + (int) data_get($request->payload, 'requested_minutes', 0);
                }
            });

        return $result;
    }

    /** Overtime requests of the company waiting for a decision for days in [$from, $to]. */
    public static function pendingCount(int $companyId, string $from, string $to): int
    {
        return EmployeeRequest::query()
            ->where('company_id', $companyId)
            ->where('status', 'pending_approval')
            ->whereHas('requestType', fn (Builder $types) => $types->where('category', self::Category))
            ->where('payload->attendance_date', '>=', $from)
            ->where('payload->attendance_date', '<=', $to)
            ->count();
    }

    public static function hours(int $minutes): string
    {
        return intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }

    private function validatedMinutes(int $requested, int $recorded, string $date): int
    {
        $label = CarbonImmutable::parse($date)->format('d M Y');

        if (self::roundDown($recorded) < self::MinimumMinutes) {
            throw new RuntimeException("Only {$recorded} extra minutes were recorded on {$label}; overtime needs at least 30.");
        }

        $minutes = self::roundDown($requested);
        if ($minutes < self::MinimumMinutes) {
            throw new RuntimeException('Overtime must be at least 30 minutes, in steps of 15.');
        }
        if ($minutes > $recorded) {
            throw new RuntimeException('At most '.self::roundDown($recorded)." minutes can be requested: that is the extra time recorded on {$label}.");
        }

        return $minutes;
    }

    private function normaliseDate(string $date): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
            throw new RuntimeException('Choose the day the overtime was worked.');
        }

        return substr($date, 0, 10);
    }
}
