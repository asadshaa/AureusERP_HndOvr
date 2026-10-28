<?php

namespace Webkul\Employee\Services\Attendance;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Webkul\Employee\Enums\AttendanceSource;
use Webkul\Employee\Enums\AttendanceVerificationAction as Action;
use Webkul\Employee\Enums\AttendanceVerificationMethod as Method;
use Webkul\Employee\Enums\AttendanceVerificationResult as Result;
use Webkul\Employee\Enums\AttendanceVerificationStatus as Status;
use Webkul\Employee\Enums\WorkLocation as WorkLocationType;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Models\AttendanceVerification;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\WorkLocation;
use Webkul\Employee\Services\Attendance\Contracts\AttendanceVerifier;
use Webkul\Employee\Services\Attendance\Data\AttendanceAttemptResult;
use Webkul\Employee\Services\Attendance\Data\GeofenceDecision;
use Webkul\Employee\Services\Attendance\Data\LocationEvidence;
use Webkul\Employee\Services\Attendance\Verifiers\GpsGeofenceVerifier;
use Webkul\Employee\Services\Attendance\Verifiers\RemoteExemptVerifier;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Support\Services\HierarchicalNotificationService;

/**
 * The single writer for browser-GPS attendance. Laravel is authoritative:
 * the employee, company and workplace are resolved from the authenticated
 * user server-side, the distance is computed here, and nothing the browser
 * claims about identity, distance or verification is ever trusted. Every
 * attempt (accepted or not) leaves exactly one AttendanceVerification row.
 */
class GeofencedAttendanceService
{
    /** @var array<string, AttendanceVerifier> */
    private array $resolvedVerifiers = [];

    public function __construct(
        private readonly GeoDistanceCalculator $distance,
        private readonly AttendanceScheduleResolver $schedule,
        private readonly HrHierarchyService $hierarchy,
        private readonly ?Container $container = null,
        private readonly ?GpsGeofenceVerifier $gps = null,
        private readonly ?RemoteExemptVerifier $remote = null,
        private readonly ?AttendanceIntervalService $intervals = null,
    ) {
        if ($this->gps !== null) {
            $this->resolvedVerifiers[Method::Gps->value] = $this->gps;
            $this->resolvedVerifiers['gps'] = $this->gps;
        }
        if ($this->remote !== null) {
            $this->resolvedVerifiers[Method::None->value] = $this->remote;
            $this->resolvedVerifiers['none'] = $this->remote;
            $this->resolvedVerifiers['remote'] = $this->remote;
        }
    }

    public function resolveVerifier(string|Method $method): AttendanceVerifier
    {
        $methodKey = $method instanceof Method ? $method->value : $method;

        if (isset($this->resolvedVerifiers[$methodKey])) {
            return $this->resolvedVerifiers[$methodKey];
        }

        $container = $this->container ?? app();

        if ($container->has('attendance.verifiers')) {
            /** @var iterable<AttendanceVerifier> $verifiers */
            $verifiers = $container->tagged('attendance.verifiers');
            foreach ($verifiers as $verifier) {
                if ($verifier instanceof AttendanceVerifier && ($verifier->method()->value === $methodKey || ($methodKey === 'remote' && $verifier->method() === Method::None))) {
                    return $this->resolvedVerifiers[$methodKey] = $verifier;
                }
            }
        }

        $verifierClass = match ($methodKey) {
            Method::Gps->value, 'gps'             => GpsGeofenceVerifier::class,
            Method::None->value, 'none', 'remote' => RemoteExemptVerifier::class,
            default                               => throw new InvalidArgumentException("No verifier registered for method [{$methodKey}]."),
        };

        /** @var AttendanceVerifier $verifier */
        $verifier = $container->make($verifierClass);

        return $this->resolvedVerifiers[$methodKey] = $verifier;
    }

    private const ELIGIBLE_STATUSES = ['active', 'probation', 'notice'];

    private const CLIENT_ERRORS = [
        'permission_denied'    => Result::PermissionDenied,
        'position_unavailable' => Result::LocationUnavailable,
        'unsupported'          => Result::LocationUnavailable,
        'timeout'              => Result::LocationTimeout,
        'insecure_context'     => Result::InsecureContext,
    ];

    // ------------------------------------------------------------------
    // Employee / workplace resolution
    // ------------------------------------------------------------------

    /** The user's employee record in their DEFAULT company only, whether or not eligible. */
    public function findEmployee(User $user): ?Employee
    {
        $companyId = (int) $user->default_company_id;
        if ($companyId <= 0) {
            return null;
        }

        return Employee::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->first();
    }

    public function isEligible(Employee $employee): bool
    {
        if (! (bool) $employee->is_active) {
            return false;
        }

        if (! in_array($employee->employment_status ?? 'active', self::ELIGIBLE_STATUSES, true)) {
            return false;
        }

        $today = now()->toDateString();

        if ($employee->joining_date && $employee->joining_date->toDateString() > $today) {
            return false;
        }

        $departure = $employee->departure_date ?? $employee->leaving_date;
        if ($departure && Carbon::parse($departure)->toDateString() < $today) {
            return false;
        }

        return true;
    }

    public function resolveEmployee(User $user): ?Employee
    {
        $employee = $this->findEmployee($user);

        return $employee && $this->isEligible($employee) ? $employee : null;
    }

    /**
     * @return array{mode: 'gps'|'remote'|'none', candidates: Collection<int, WorkLocation>}
     */
    public function resolveMode(Employee $employee, string $attendanceDate): array
    {
        $companyId = (int) $employee->company_id;

        $usable = static fn (?WorkLocation $location): bool => $location !== null
            && ! $location->trashed()
            && $location->is_active
            && (int) $location->company_id === $companyId;

        $primary = $employee->workLocation;
        $primary = $usable($primary) ? $primary : null;

        $assigned = $employee->workLocationAssignments()
            ->effectiveOn($attendanceDate)
            ->with('workLocation')
            ->get()
            ->map(fn ($assignment) => $assignment->workLocation)
            ->filter($usable);

        // An approved remote day (a Home assignment effective today) overrides an office primary.
        if ($assigned->contains(fn (WorkLocation $l) => $l->location_type === WorkLocationType::Home)) {
            return ['mode' => 'remote', 'candidates' => collect()];
        }

        $all = collect([$primary])->filter()->merge($assigned)->unique('id')->values();
        $fences = $all->filter(fn (WorkLocation $l) => $l->isGeofenceUsable())
            ->take((int) config('hr_attendance_geofence.max_candidate_locations'))
            ->values();

        if ($fences->isNotEmpty()) {
            return ['mode' => 'gps', 'candidates' => $fences];
        }

        // A fully-remote employee. An office whose fence is merely switched off
        // must NOT exempt anyone from location checks.
        if ($all->contains(fn (WorkLocation $l) => $l->location_type === WorkLocationType::Home)) {
            return ['mode' => 'remote', 'candidates' => collect()];
        }

        return ['mode' => 'none', 'candidates' => collect()];
    }

    /** @return Collection<int, WorkLocation> */
    public function candidateLocations(Employee $employee, string $attendanceDate): Collection
    {
        return $this->resolveMode($employee, $attendanceDate)['candidates'];
    }

    // ------------------------------------------------------------------
    // Public attempts
    // ------------------------------------------------------------------

    public function checkIn(User $user, ?LocationEvidence $evidence, Request $request, ?string $clientRequestId = null): AttendanceAttemptResult
    {
        return $this->attempt($user, Action::CheckIn, $evidence, null, $request, $clientRequestId);
    }

    public function checkOut(User $user, ?LocationEvidence $evidence, Request $request, ?string $clientRequestId = null): AttendanceAttemptResult
    {
        return $this->attempt($user, Action::CheckOut, $evidence, null, $request, $clientRequestId);
    }

    /** The browser could not produce a location (denied, unavailable, timeout, insecure, unsupported). */
    public function recordClientFailure(User $user, Action $action, string $clientErrorCode, ?string $clientRequestId, Request $request): AttendanceAttemptResult
    {
        if (! isset(self::CLIENT_ERRORS[$clientErrorCode])) {
            throw new InvalidArgumentException('Unknown client error code.');
        }

        if (! in_array($action, [Action::CheckIn, Action::CheckOut], true)) {
            throw new InvalidArgumentException('Only check-in and check-out attempts can fail on the client.');
        }

        return $this->attempt($user, $action, null, $clientErrorCode, $request, $clientRequestId);
    }

    private function attempt(User $user, Action $action, ?LocationEvidence $evidence, ?string $clientError, Request $request, ?string $clientRequestId): AttendanceAttemptResult
    {
        if (! config('hr_attendance_geofence.enabled')) {
            throw new RuntimeException(__('employees::attendance.not_enabled'));
        }

        $employee = $this->findEmployee($user);
        if (! $employee) {
            Log::warning('Geofenced attendance attempt by a user with no employee in their default company.', ['user_id' => $user->id]);

            return new AttendanceAttemptResult(Result::EmployeeNotEligible, false, Result::EmployeeNotEligible->getLabel());
        }

        $requestId = $this->normalizeRequestId($clientRequestId ?? $evidence?->clientRequestId);

        try {
            return DB::transaction(fn () => $this->attemptLocked($user, $employee, $action, $evidence, $clientError, $request, $requestId));
        } catch (QueryException $e) {
            // Belt and braces: the employee row lock should make this unreachable,
            // but a unique-key collision must never surface as a server error.
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }

            $existing = AttendanceVerification::query()
                ->where('employee_id', $employee->id)
                ->where('client_request_id', $requestId)
                ->first();
            if ($existing) {
                return $this->resultFromVerification($existing, $this->schedule->timezoneFor($employee));
            }

            $result = $action === Action::CheckIn ? Result::AlreadyCheckedIn : Result::AlreadyCheckedOut;

            return new AttendanceAttemptResult($result, false, $result->getLabel());
        }
    }

    private function attemptLocked(User $user, Employee $employee, Action $action, ?LocationEvidence $evidence, ?string $clientError, Request $request, string $requestId): AttendanceAttemptResult
    {
        $nowUtc = CarbonImmutable::now('UTC');

        // Serialises every attempt for this employee (double-tap, two tabs, retries).
        Employee::query()->whereKey($employee->id)->lockForUpdate()->first();

        $timezone = $this->schedule->timezoneFor($employee);

        $existing = AttendanceVerification::query()
            ->where('employee_id', $employee->id)
            ->where('client_request_id', $requestId)
            ->first();
        if ($existing) {
            return $this->resultFromVerification($existing, $timezone);
        }

        if (! $this->isEligible($employee)) {
            return $this->reject($employee, $user, $request, $action, Result::EmployeeNotEligible, $requestId, $timezone);
        }

        return $action === Action::CheckIn
            ? $this->doCheckIn($user, $employee, $evidence, $clientError, $request, $requestId, $nowUtc, $timezone)
            : $this->doCheckOut($user, $employee, $evidence, $clientError, $request, $requestId, $nowUtc, $timezone);
    }

    // ------------------------------------------------------------------
    // Check-in / check-out
    // ------------------------------------------------------------------

    private function doCheckIn(User $user, Employee $employee, ?LocationEvidence $evidence, ?string $clientError, Request $request, string $requestId, CarbonImmutable $nowUtc, string $timezone): AttendanceAttemptResult
    {
        $attendanceDate = $this->schedule->attendanceDateFor($employee, $nowUtc);
        ['mode' => $mode, 'candidates' => $candidates] = $this->resolveMode($employee, $attendanceDate);

        if ($mode === 'none') {
            return $this->reject($employee, $user, $request, Action::CheckIn, Result::NoLocationConfigured, $requestId, $timezone, null, null, $mode);
        }

        $open = $this->openRecord($employee, $nowUtc);
        if ($open) {
            return $this->reject($employee, $user, $request, Action::CheckIn, Result::OpenShiftExists, $requestId, $timezone, $this->localTime($open->check_in, $timezone), $open, $mode);
        }

        $today = AttendanceRecord::query()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->where('attendance_date', $attendanceDate)
            ->lockForUpdate()
            ->first();

        // A day whose last interval is closed takes a new check-in (several pairs per day).
        // A day still open but older than max_shift_hours is a forgotten check-out: refused.
        if ($today?->check_in && $today->check_out === null) {
            return $this->reject($employee, $user, $request, Action::CheckIn, Result::AlreadyCheckedIn, $requestId, $timezone, $this->localTime($today->check_in, $timezone), $today, $mode);
        }

        if (($today && in_array($today->status, ['leave', 'holiday'], true))
            || $this->hasApprovedFullDayLeave($employee, $attendanceDate)) {
            return $this->reject($employee, $user, $request, Action::CheckIn, Result::OnLeaveOrHoliday, $requestId, $timezone, null, $today, $mode);
        }

        $decision = $this->decide($mode, $employee, $evidence, $clientError, $candidates, Action::CheckIn, $timezone, $attendanceDate);
        $verification = $this->logVerification($employee, $user, $request, Action::CheckIn, $mode, $decision, $evidence, $clientError, $requestId);

        if (! $decision->accepted) {
            return $this->resultFor(Action::CheckIn, $decision->result, false, null, null);
        }

        [$scheduledStart, $scheduledEnd] = $this->schedule->scheduledWindowFor($employee, $attendanceDate);
        $appTimezone = config('app.timezone');
        $newStatus = $this->statusFor($mode, $decision)->value;

        if ($today?->check_in) {
            $record = AttendanceIntervalService::quietly(function () use ($today, $nowUtc, $appTimezone, $mode, $verification, $newStatus): AttendanceRecord {
                $this->intervals()->open($today, $nowUtc->setTimezone($appTimezone), $mode === 'remote' ? AttendanceSource::SelfService->value : AttendanceSource::Gps->value, $verification->id, $newStatus);

                return $this->intervals()->syncSummary($today, [
                    'check_out_verification_id' => null,
                    'verification_status'       => $today->verification_status === Status::NeedsReview->value ? Status::NeedsReview->value : $newStatus,
                ]);
            });

            $verification->update(['attendance_record_id' => $record->id]);

            if ($decision->needsReview) {
                $this->notifyLineManager($record, 'checkin_flagged', 'Check-in flagged', $this->flaggedSentence('check-in', $decision));
            }

            return $this->resultFor(Action::CheckIn, $decision->result, true, $this->localTime($nowUtc, $timezone), $record);
        }

        $record = AttendanceIntervalService::quietly(function () use ($today, $employee, $attendanceDate, $nowUtc, $mode, $verification, $decision, $user, $scheduledStart, $scheduledEnd, $appTimezone) {
            $record = $today ?? new AttendanceRecord;
            $record->fill([
                'company_id'               => $employee->company_id,
                'employee_id'              => $employee->id,
                'attendance_date'          => $attendanceDate,
                'check_in'                 => $nowUtc->setTimezone($appTimezone),
                'status'                   => $mode === 'remote' ? 'remote' : 'present',
                'source'                   => $mode === 'remote' ? AttendanceSource::SelfService->value : AttendanceSource::Gps->value,
                'source_reference'         => 'verification:'.$verification->id,
                'check_in_verification_id' => $verification->id,
                'verification_status'      => $this->statusFor($mode, $decision)->value,
            ]);
            $record->creator_id ??= $user->id;
            if ($record->scheduled_start === null && $scheduledStart) {
                $record->scheduled_start = $scheduledStart->setTimezone($appTimezone);
            }
            if ($record->scheduled_end === null && $scheduledEnd) {
                $record->scheduled_end = $scheduledEnd->setTimezone($appTimezone);
            }
            $record->save();

            $this->intervals()->open($record, $record->check_in, $record->source, $verification->id, $record->verification_status);

            return $record;
        });

        $verification->update(['attendance_record_id' => $record->id]);

        if ($decision->needsReview) {
            $this->notifyLineManager($record, 'checkin_flagged', 'Check-in flagged', $this->flaggedSentence('check-in', $decision));
        }

        return $this->resultFor(Action::CheckIn, $decision->result, true, $this->localTime($record->check_in, $timezone), $record);
    }

    private function doCheckOut(User $user, Employee $employee, ?LocationEvidence $evidence, ?string $clientError, Request $request, string $requestId, CarbonImmutable $nowUtc, string $timezone): AttendanceAttemptResult
    {
        $open = $this->openRecord($employee, $nowUtc, lock: true);

        if (! $open) {
            $localToday = $this->schedule->attendanceDateFor($employee, $nowUtc);
            ['mode' => $resolvedMode] = $this->resolveMode($employee, $localToday);
            $closed = AttendanceRecord::query()
                ->where('company_id', $employee->company_id)
                ->where('employee_id', $employee->id)
                ->where('attendance_date', $localToday)
                ->whereNotNull('check_out')
                ->first();

            if ($closed) {
                return $this->reject($employee, $user, $request, Action::CheckOut, Result::AlreadyCheckedOut, $requestId, $timezone, $this->localTime($closed->check_out, $timezone), $closed, $resolvedMode);
            }

            return $this->reject($employee, $user, $request, Action::CheckOut, Result::NotCheckedIn, $requestId, $timezone, null, null, $resolvedMode);
        }

        $checkOutAt = $nowUtc->setTimezone(config('app.timezone'));
        // An overnight shift keeps the assignments of the day it started on.
        ['mode' => $mode, 'candidates' => $candidates] = $this->resolveMode($employee, $open->attendance_date->toDateString());

        // The interval being closed (the day's check_in is only its first one).
        $openInterval = $this->intervals()->openInterval($open);
        $intervalStart = $openInterval?->check_in ?? $open->check_in;

        if ($checkOutAt->lessThanOrEqualTo($intervalStart)) {
            return $this->reject($employee, $user, $request, Action::CheckOut, Result::NotCheckedIn, $requestId, $timezone, null, $open, $mode);
        }

        $minMinutes = (int) config('hr_attendance_geofence.min_checkout_interval_minutes', 60);
        if ($minMinutes > 0 && $intervalStart) {
            $elapsedMinutes = $intervalStart->diffInMinutes($checkOutAt);
            if ($elapsedMinutes < $minMinutes) {
                $availableAt = $intervalStart->copy()->addMinutes($minMinutes);
                $availableAtLocal = $this->localTime($availableAt, $timezone);

                return $this->reject($employee, $user, $request, Action::CheckOut, Result::TooEarlyForCheckOut, $requestId, $timezone, $availableAtLocal, $open, $mode);
            }
        }

        if ($mode === 'none') {
            // Losing a check-out to a configuration gap corrupts worked hours more than flagging it does.
            $decision = config('hr_attendance_geofence.checkout_without_location') === 'review'
                ? new GeofenceDecision(Result::NeedsReview, true, true, null, null, ['no_location_configured'])
                : new GeofenceDecision(Result::NoLocationConfigured, false);
        } else {
            $decision = $this->decide($mode, $employee, $evidence, $clientError, $candidates, Action::CheckOut, $timezone, $open->attendance_date->toDateString());
        }

        $decision = $this->flagLongShift($decision, $open, $checkOutAt);

        $verification = $this->logVerification($employee, $user, $request, Action::CheckOut, $mode === 'none' ? 'gps' : $mode, $decision, $evidence, $clientError, $requestId, $open->id);

        if (! $decision->accepted) {
            return $this->resultFor(Action::CheckOut, $decision->result, false, null, null);
        }

        $newStatus = $this->statusFor($mode, $decision);
        $current = $open->verification_status;
        $finalStatus = ($current === Status::NeedsReview->value || $newStatus === Status::NeedsReview)
            ? Status::NeedsReview->value
            : ($current ?? $newStatus->value);

        $fullDayHours = (float) config('hr_attendance_geofence.full_day_min_hours', 7.5);
        $halfDayHours = (float) config('hr_attendance_geofence.half_day_min_hours', 4.0);
        // Worked = the closed intervals plus the one being closed (never first-in to last-out).
        $workedHours = max(0, ($this->intervals()->workedMinutes($open) + ($intervalStart ? $intervalStart->diffInMinutes($checkOutAt) : 0)) / 60);

        $attendanceStatus = $open->status;
        if (! in_array($attendanceStatus, ['leave', 'holiday', 'absent'], true)) {
            if ($workedHours >= $fullDayHours) {
                $attendanceStatus = $mode === 'remote' ? 'remote' : 'present';
            } elseif ($workedHours >= $halfDayHours) {
                $attendanceStatus = 'half_day';
            } else {
                $attendanceStatus = $mode === 'remote' ? 'remote' : 'present';
            }
        }

        AttendanceIntervalService::quietly(function () use ($open, $openInterval, $checkOutAt, $verification, $finalStatus, $attendanceStatus): void {
            if ($openInterval === null) {
                // A day written without intervals: its single pair becomes the interval.
                $this->intervals()->open($open, $open->check_in, (string) $open->source, $open->check_in_verification_id, $open->verification_status);
            }
            $this->intervals()->close($open, $checkOutAt, $verification->id, $finalStatus);
            $this->intervals()->syncSummary($open, [
                'status'                    => $attendanceStatus,
                'check_out_verification_id' => $verification->id,
                'verification_status'       => $finalStatus,
            ]);
        });

        if ($decision->needsReview) {
            $this->notifyLineManager($open, 'checkout_flagged', 'Check-out flagged', $this->flaggedSentence('check-out', $decision));
        }

        return $this->resultFor(Action::CheckOut, $decision->result, true, $this->localTime($open->check_out, $timezone), $open);
    }

    // ------------------------------------------------------------------
    // Review and HR correction
    // ------------------------------------------------------------------

    public function reviewVerification(AttendanceRecord $record, User $reviewer, bool $approve, string $note): void
    {
        if (trim($note) === '') {
            throw new InvalidArgumentException('A review note is required.');
        }

        DB::transaction(function () use ($record, $reviewer, $approve, $note): void {
            $record = AttendanceRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

            $pending = $record->verifications()->where('review_status', 'pending')->get();
            if ($pending->isEmpty()) {
                throw new RuntimeException('There is no pending verification to review on this record.');
            }

            foreach ($pending as $verification) {
                if (! $reviewer->can('review', $verification)) {
                    throw new AuthorizationException('You are not allowed to review this attendance verification.');
                }
            }

            foreach ($pending as $verification) {
                $verification->update([
                    'review_status' => $approve ? 'approved' : 'rejected',
                    'reviewed_by'   => $reviewer->id,
                    'reviewed_at'   => now(),
                    'review_note'   => $note,
                ]);
            }

            AttendanceVerification::query()->create([
                'company_id'           => $record->company_id,
                'employee_id'          => $record->employee_id,
                'user_id'              => $reviewer->id,
                'attendance_record_id' => $record->id,
                'action'               => Action::Review,
                'method'               => Method::Manual,
                'result'               => $approve ? Result::ReviewApproved : Result::ReviewRejected,
                'accepted'             => false,
                'metadata'             => ['note' => $note, 'reviewed_verification_ids' => $pending->pluck('id')->all()],
                'server_recorded_at'   => now(),
            ]);

            // Rejecting a review flags the day; it never alters the recorded times.
            $record->newQuery()->whereKey($record->id)->update([
                'verification_status' => ($approve ? Status::Reviewed : Status::ReviewRejected)->value,
            ]);
        });
    }

    /**
     * A direct, reasoned HR correction to a record's times. Employees can never
     * correct their own attendance through this path.
     *
     * @param  array{check_in?: ?string, check_out?: ?string}  $after
     */
    public function recordHrCorrection(AttendanceRecord $record, User $actor, array $after, string $reason): AttendanceVerification
    {
        $record->loadMissing('employee');
        $employee = $record->employee;

        if (! $employee
            || ! $actor->can(HrPermissions::ManageAttendance)
            || (int) $employee->user_id === (int) $actor->id
            || ! $this->hierarchy->canManage($actor, $employee)) {
            throw new AuthorizationException('You are not allowed to correct this attendance record.');
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw new InvalidArgumentException('A correction reason of at least 10 characters is required.');
        }

        $after = array_intersect_key($after, array_flip(['check_in', 'check_out']));
        if ($after === []) {
            throw new InvalidArgumentException('A correction must change the check-in or check-out time.');
        }

        return DB::transaction(function () use ($record, $actor, $after, $reason, $employee): AttendanceVerification {
            $record = AttendanceRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

            $before = [
                'check_in'  => $record->check_in?->toDateTimeString(),
                'check_out' => $record->check_out?->toDateTimeString(),
            ];

            $intervalsBefore = AttendanceIntervalService::describe($this->intervals()->intervalsFor($record), $this->schedule->timezoneFor($employee));
            $previousReason = AttendanceRecord::$pendingIntervalReason;
            AttendanceRecord::$pendingIntervalReason = 'HR correction: '.$reason;

            try {
                $this->withAuditSuppressed(function () use ($record, $after, $actor): void {
                    $record->fill($after);
                    if ($record->check_in && $record->check_out && $record->check_out->lessThanOrEqualTo($record->check_in)) {
                        throw new InvalidArgumentException('Check-out must be after check-in.');
                    }
                    $record->approved_by = $actor->id;
                    $record->verification_status = Status::Overridden->value;
                    $record->save();
                });
            } finally {
                AttendanceRecord::$pendingIntervalReason = $previousReason;
            }

            $verification = AttendanceVerification::query()->create([
                'company_id'           => $record->company_id,
                'employee_id'          => $record->employee_id,
                'user_id'              => $actor->id,
                'attendance_record_id' => $record->id,
                'action'               => Action::HrCorrection,
                'method'               => Method::Manual,
                'result'               => Result::Overridden,
                'accepted'             => true,
                'metadata'             => [
                    'before' => $before,
                    'after'  => [
                        'check_in'  => $record->check_in?->toDateTimeString(),
                        'check_out' => $record->check_out?->toDateTimeString(),
                    ],
                    'reason'           => $reason,
                    'path'             => 'hr_correction',
                    'intervals_before' => $intervalsBefore,
                    'intervals_after'  => AttendanceIntervalService::describe($this->intervals()->intervalsFor($record), $this->schedule->timezoneFor($employee)),
                ],
                'server_recorded_at'   => now(),
            ]);

            try {
                if ($employee->user && $employee->user->is_active && (int) $employee->user->id !== (int) $actor->id) {
                    $dateStr = $record->attendance_date?->format('d M Y') ?? 'attendance';
                    $inTime = ! empty($after['check_in']) ? Carbon::parse($after['check_in'])->format('H:i') : null;
                    $outTime = ! empty($after['check_out']) ? Carbon::parse($after['check_out'])->format('H:i') : null;
                    $times = [];
                    if ($inTime) {
                        $times[] = "Check-in: {$inTime}";
                    }
                    if ($outTime) {
                        $times[] = "Check-out: {$outTime}";
                    }
                    $timeStr = ! empty($times) ? ' ('.implode(', ', $times).')' : '';

                    $title = 'HR updated your attendance time';
                    $body = "HR ({$actor->name}) updated your attendance time for {$dateStr}{$timeStr}. Reason: {$reason}";

                    $notification = FilamentNotification::make()
                        ->info()
                        ->icon('heroicon-o-clock')
                        ->title($title)
                        ->body($body);

                    $employee->user->notifyNow($notification->toDatabase());
                }
            } catch (Throwable $e) {
                report($e);
            }

            return $verification;
        });
    }

    /**
     * Deletes an attendance record on behalf of HR. Evidence-backed (GPS /
     * self-service) days need a reason, and the deletion is audited by the
     * AttendanceRecord deleting hook; the verification evidence is kept.
     * Nobody can delete their own attendance.
     */
    public function deleteRecord(AttendanceRecord $record, User $actor, ?string $reason): void
    {
        $record->loadMissing('employee');
        $employee = $record->employee;

        if (! $employee
            || ! $actor->can(HrPermissions::ManageAttendance)
            || (int) $employee->user_id === (int) $actor->id
            || ! $this->hierarchy->canManage($actor, $employee)) {
            throw new AuthorizationException('You are not allowed to delete this attendance record.');
        }

        $evidenceBacked = in_array($record->source, AttendanceSource::evidenceBacked(), true);
        if ($evidenceBacked && mb_strlen(trim((string) $reason)) < 10) {
            throw new InvalidArgumentException('A reason of at least 10 characters is required to delete GPS-verified attendance.');
        }

        DB::transaction(function () use ($record, $reason): void {
            $previous = AttendanceRecord::$pendingDeletionReason;
            AttendanceRecord::$pendingDeletionReason = $reason !== null ? trim($reason) : null;

            try {
                AttendanceRecord::query()->whereKey($record->id)->lockForUpdate()->firstOrFail()->delete();
            } finally {
                AttendanceRecord::$pendingDeletionReason = $previous;
            }
        });
    }

    /**
     * Flags evidence-backed shifts that were never checked out once they are
     * older than max_shift_hours, so they land in the HR review queue (and the
     * line manager is told) instead of sitting forever with no check-out and
     * zero hours. Idempotent: a record is flagged at most once. Returns the
     * number of records flagged.
     */
    public function flagForgottenShifts(?CarbonImmutable $nowUtc = null): int
    {
        $nowUtc ??= CarbonImmutable::now('UTC');
        $cutoff = $nowUtc->subHours((int) config('hr_attendance_geofence.max_shift_hours'))->setTimezone(config('app.timezone'));
        $flagged = 0;

        AttendanceRecord::query()
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->where(fn ($query) => $query
                ->whereHas('intervals', fn ($interval) => $interval->whereNull('voided_at')->whereNull('check_out')->where('check_in', '<', $cutoff))
                ->orWhere(fn ($legacy) => $legacy->whereDoesntHave('intervals', fn ($interval) => $interval->whereNull('voided_at'))->where('check_in', '<', $cutoff)))
            ->whereIn('source', AttendanceSource::evidenceBacked())
            ->whereDoesntHave('verifications', fn ($query) => $query->where('action', Action::SystemFlag->value))
            ->with('employee')
            ->chunkById(200, function ($records) use (&$flagged): void {
                foreach ($records as $record) {
                    DB::transaction(function () use ($record): void {
                        AttendanceVerification::query()->create([
                            'company_id'           => $record->company_id,
                            'employee_id'          => $record->employee_id,
                            'attendance_record_id' => $record->id,
                            'action'               => Action::SystemFlag,
                            'method'               => Method::Manual,
                            'result'               => Result::NeedsReview,
                            'accepted'             => false,
                            'flags'                => ['missing_check_out'],
                            'review_status'        => 'pending',
                            'server_recorded_at'   => now(),
                        ]);

                        $record->newQuery()->whereKey($record->id)->update([
                            'verification_status' => Status::NeedsReview->value,
                        ]);
                    });

                    $this->notifyLineManager($record, 'missing_checkout', 'Missing check-out', 'never checked out. Set the correct check-out time (Edit) or ask them to request a correction.');
                    $flagged++;
                }
            });

        return $flagged;
    }

    /**
     * Optional auto-close of open GPS / self-service shifts, governed by
     * config('hr_attendance_geofence.auto_close_policy'):
     *
     *  - "none" (default): nothing is closed. Missing check-outs are handled by
     *    hr:flag-forgotten-shifts (review queue + line-manager notice) and the
     *    employee's own correction request. No work time is ever made up.
     *  - "scheduled_end": a shift that HAS a scheduled end is closed at that end
     *    once it has passed. Shifts without a schedule are left open (no more
     *    invented "check-in + 8 hours").
     *
     * An auto-closed day is never silently "verified": it is marked needs-review
     * with a pending review item and the auto_closed flag, and its attendance
     * status is left as it was, so HR confirms or corrects it.
     */
    public function autoCompleteCompletedShifts(?CarbonImmutable $nowUtc = null): int
    {
        if (config('hr_attendance_geofence.auto_close_policy', 'none') !== 'scheduled_end') {
            return 0;
        }

        $nowUtc ??= CarbonImmutable::now('UTC');
        $completed = 0;

        AttendanceRecord::query()
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->whereNotNull('scheduled_end')
            ->where('scheduled_end', '<=', $nowUtc->setTimezone(config('app.timezone')))
            ->whereIn('source', AttendanceSource::evidenceBacked())
            ->with(['employee'])
            ->chunkById(200, function ($records) use (&$completed): void {
                foreach ($records as $record) {
                    if (! $record->employee || ! $record->scheduled_end->greaterThan($record->check_in)) {
                        continue;
                    }

                    DB::transaction(function () use ($record): void {
                        $verification = AttendanceVerification::query()->create([
                            'company_id'           => $record->company_id,
                            'employee_id'          => $record->employee_id,
                            'attendance_record_id' => $record->id,
                            'action'               => Action::CheckOut,
                            'method'               => Method::Manual,
                            'result'               => Result::NeedsReview,
                            'accepted'             => true,
                            // auto_completed_full_shift kept for existing reports/filters.
                            'flags'                => ['auto_closed', 'auto_completed_full_shift'],
                            'metadata'             => [
                                'reason' => 'Closed automatically at the scheduled end because no check-out was recorded (auto_close_policy=scheduled_end).',
                                'policy' => 'scheduled_end',
                            ],
                            'review_status'        => 'pending',
                            'server_recorded_at'   => now(),
                        ]);

                        AttendanceIntervalService::quietly(function () use ($record, $verification): void {
                            $openInterval = $this->intervals()->openInterval($record);
                            if ($openInterval && $record->scheduled_end->greaterThan($openInterval->check_in)) {
                                $this->intervals()->close($record, $record->scheduled_end, $verification->id, Status::NeedsReview->value);
                                $this->intervals()->syncSummary($record, [
                                    'check_out_verification_id' => $verification->id,
                                    'verification_status'       => Status::NeedsReview->value,
                                ]);

                                return;
                            }

                            $record->check_out = $record->scheduled_end;
                            $record->check_out_verification_id = $verification->id;
                            $record->verification_status = Status::NeedsReview->value;
                            $record->save();
                        });
                    });

                    $this->notifyLineManager($record, 'auto_closed', 'Check-out added automatically', 'had no check-out, so it was closed at the scheduled end. Please confirm or correct it.');
                    $completed++;
                }
            });

        return $completed;
    }

    /**
     * Tells the employee's line manager (a database notification in the panel
     * bell) that an attendance day needs a look. Sent synchronously so it does
     * not depend on a queue worker. A notification failure never affects the
     * attendance write itself.
     */
    private function flaggedSentence(string $what, GeofenceDecision $decision): string
    {
        $reasons = collect($decision->flags)->map(fn (string $flag): string => Str::lower(Str::headline($flag)))->implode(', ');

        return "{$what} was recorded but needs review".($reasons !== '' ? " ({$reasons})" : '').'. Open HR → Attendance and use Approve or Reject.';
    }

    /**
     * $event identifies what happened (checkin_flagged, checkout_flagged,
     * missing_checkout, auto_closed). Together with the record it forms an
     * idempotency key, so re-running a job or re-processing the same event never
     * notifies the same person twice.
     */
    private function notifyLineManager(AttendanceRecord $record, string $event, string $title, string $what): void
    {
        try {
            $parentId = $record->employee?->parent_id;
            $manager = $parentId ? Employee::query()->with('user')->find($parentId)?->user : null;
            if (! $manager || (int) $manager->id === (int) $record->employee?->user_id) {
                // If there is no line manager, forward notification to company HR reviewers.
                $hrUsers = User::query()
                    ->where('default_company_id', $record->company_id)
                    ->where('is_active', true)
                    ->get()
                    ->filter(fn (User $u): bool => $u->hasRole(['Admin', 'Super Admin', 'hr_manager', 'hr manager', 'hr_ops_manager', 'hr', 'human resources'])
                        || $u->can('hr_manage_attendance')
                        || $u->can('hr_review_attendance_verifications'));

                if ($hrUsers->isEmpty()) {
                    return;
                }

                $notification = FilamentNotification::make()
                    ->warning()
                    ->title("Attendance review: {$title}")
                    ->body(sprintf(
                        '%s (%s) %s [Forwarded to HR: no line manager assigned]',
                        $record->employee->name,
                        $record->attendance_date?->format('d M Y'),
                        $what,
                    ));

                foreach ($hrUsers as $hrUser) {
                    if ((int) $hrUser->id !== (int) $record->employee?->user_id) {
                        $this->sendAttendanceNotice($hrUser, $notification, $record, $event);
                    }
                }

                return;
            }

            $notification = FilamentNotification::make()
                ->warning()
                ->title("Attendance review: {$title}")
                ->body(sprintf(
                    '%s (%s) %s',
                    $record->employee->name,
                    $record->attendance_date?->format('d M Y'),
                    $what,
                ));

            $this->sendAttendanceNotice($manager, $notification, $record, $event);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function sendAttendanceNotice(User $recipient, FilamentNotification $notification, AttendanceRecord $record, string $event): void
    {
        app(HierarchicalNotificationService::class)->sendIdempotentNotification(
            $recipient,
            $notification,
            sprintf('attendance_%s_%d_%d', $event, $record->id, $recipient->id),
            ['attendance_record_id' => $record->id, 'event' => $event],
        );
    }

    // ------------------------------------------------------------------
    // Page state
    // ------------------------------------------------------------------

    /**
     * @return array{state: string, mode: string, check_in: ?string, check_out: ?string, worked: ?string, location: ?string, timezone: string}|null
     */
    public function todayState(User $user): ?array
    {
        $employee = $this->resolveEmployee($user);
        if (! $employee) {
            return null;
        }

        $nowUtc = CarbonImmutable::now('UTC');
        $timezone = $this->schedule->timezoneFor($employee);
        $attendanceDate = $this->schedule->attendanceDateFor($employee, $nowUtc);

        $open = $this->openRecord($employee, $nowUtc);
        $record = $open ?? AttendanceRecord::query()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->where('attendance_date', $attendanceDate)
            ->first();

        ['mode' => $mode, 'candidates' => $candidates] = $this->resolveMode($employee, $open ? $open->attendance_date->toDateString() : $attendanceDate);

        $scheduleName = $employee->calendar?->name;
        [$schedStartUtc, $schedEndUtc] = $this->schedule->scheduledWindowFor($employee, $attendanceDate);
        $schedStartLocal = $schedStartUtc ? $this->localTime($schedStartUtc, $timezone) : null;
        $schedEndLocal = $schedEndUtc ? $this->localTime($schedEndUtc, $timezone) : null;
        $schedDurationHours = ($schedStartUtc && $schedEndUtc) ? round($schedStartUtc->diffInMinutes($schedEndUtc) / 60, 1) : 8.0;

        $elapsedSeconds = 0;
        $elapsedFormatted = null;
        $progressPercent = 0;
        $isShiftComplete = false;

        $dayIntervals = $record ? $this->intervals()->intervalsFor($record) : collect();
        $closedSeconds = (int) $dayIntervals->sum(fn ($interval): int => $interval->durationMinutes() * 60);
        $openInterval = $dayIntervals->first(fn ($interval): bool => $interval->isOpen());
        $currentStart = $openInterval?->check_in ?? $open?->check_in;

        $state = 'not_checked_in';
        $worked = null;
        if ($open) {
            $state = 'checked_in';

            if ($currentStart) {
                // Worked today = the closed pairs plus the running one.
                $elapsedSeconds = $closedSeconds + max(0, $nowUtc->getTimestamp() - $currentStart->getTimestamp());
                $elapsedHours = $elapsedSeconds / 3600;
                $elapsedMinutes = intdiv($elapsedSeconds, 60);
                $elapsedFormatted = intdiv($elapsedMinutes, 60).'h '.($elapsedMinutes % 60).'m';

                $targetSeconds = ($schedDurationHours > 0 ? $schedDurationHours : 8.0) * 3600;
                $progressPercent = min(100, (int) round(($elapsedSeconds / $targetSeconds) * 100));

                $isShiftComplete = $elapsedHours >= 8.0 || ($schedEndUtc && $nowUtc >= $schedEndUtc);
            }
        } elseif ($record?->check_in && $record->check_out) {
            $state = 'checked_out';
            $minutes = $dayIntervals->isNotEmpty() ? intdiv($closedSeconds, 60) : (int) $record->check_in->diffInMinutes($record->check_out);
            $worked = intdiv($minutes, 60).'h '.($minutes % 60).'m';
        }

        $isPastStart = false;
        if (! $open && ! ($record?->check_in) && $schedStartUtc) {
            $isPastStart = $nowUtc > $schedStartUtc;
        }

        $minIntervalMinutes = (int) config('hr_attendance_geofence.min_checkout_interval_minutes', 60);
        $lockoutRemainingMinutes = 0;
        if ($open && $currentStart) {
            $elapsedMin = intdiv(max(0, $nowUtc->getTimestamp() - $currentStart->getTimestamp()), 60);
            $lockoutRemainingMinutes = max(0, $minIntervalMinutes - $elapsedMin);
        }

        return [
            'state'                      => $state,
            'mode'                       => $mode,
            'check_in'                   => $record?->check_in ? $this->localTime($record->check_in, $timezone) : null,
            'check_out'                  => $record?->check_out ? $this->localTime($record->check_out, $timezone) : null,
            'worked'                     => $worked,
            'location'                   => $candidates->first()?->name,
            'timezone'                   => $timezone,
            'schedule_name'              => $scheduleName,
            'scheduled_start'            => $schedStartLocal,
            'scheduled_end'              => $schedEndLocal,
            'scheduled_hours'            => $schedDurationHours,
            'elapsed_seconds'            => $elapsedSeconds,
            'elapsed_formatted'          => $elapsedFormatted,
            'progress_percent'           => $progressPercent,
            'is_shift_complete'          => $isShiftComplete,
            'lockout_remaining_minutes'  => $lockoutRemainingMinutes,
            'late_minutes'               => $record?->late_minutes ?? 0,
            'is_past_start'              => $isPastStart,
            'intervals'                  => AttendanceIntervalService::describe($dayIntervals, $timezone),
            'interval_count'             => $dayIntervals->count(),
            'current_check_in'           => $currentStart ? $this->localTime($currentStart, $timezone) : null,
            'can_check_in_again'         => $state === 'checked_out',
        ];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function decide(string $mode, Employee $employee, ?LocationEvidence $evidence, ?string $clientError, Collection $candidates, Action $action, string $timezone, string $attendanceDate): GeofenceDecision
    {
        if ($mode === 'remote') {
            return $this->resolveVerifier('remote')->verify($employee, null, $candidates, $action);
        }

        if ($clientError !== null) {
            if ($action === Action::CheckOut && config('hr_attendance_geofence.checkout_without_location') === 'review') {
                return new GeofenceDecision(Result::NeedsReview, true, true, null, null, ['location_unavailable', $clientError]);
            }

            return new GeofenceDecision(self::CLIENT_ERRORS[$clientError], false);
        }

        $repeated = $evidence !== null && $this->repeatsPreviousCoordinates($employee, $evidence, $timezone, $attendanceDate);

        return $this->resolveVerifier('gps')->verify($employee, $evidence, $candidates, $action, $repeated);
    }

    /** Real GPS essentially never repeats to ~1 cm on a different day; a repeat suggests a fixed/mock location. */
    private function repeatsPreviousCoordinates(Employee $employee, LocationEvidence $evidence, string $timezone, string $attendanceDate): bool
    {
        $previous = AttendanceVerification::query()
            ->where('employee_id', $employee->id)
            ->where('method', Method::Gps->value)
            ->where('accepted', true)
            ->whereNotNull('latitude')
            ->orderByDesc('server_recorded_at')
            ->first();

        if (! $previous || $previous->server_recorded_at->copy()->setTimezone($timezone)->toDateString() === $attendanceDate) {
            return false;
        }

        return round((float) $previous->latitude, 7) === round($evidence->latitude, 7)
            && round((float) $previous->longitude, 7) === round($evidence->longitude, 7);
    }

    /**
     * A check-out that stretches the shift implausibly (past the scheduled end
     * plus a grace period, or past long_shift_hours when there is no schedule)
     * is almost always a forgotten check-out tapped hours later. It is still
     * recorded, but never as a clean "verified" day.
     */
    private function flagLongShift(GeofenceDecision $decision, AttendanceRecord $open, CarbonInterface $checkOutAt): GeofenceDecision
    {
        if (! $decision->accepted) {
            return $decision;
        }

        $limit = $open->scheduled_end
            ? $open->scheduled_end->copy()->addHours((int) config('hr_attendance_geofence.long_shift_grace_hours'))
            : $open->check_in->copy()->addHours((int) config('hr_attendance_geofence.long_shift_hours'));

        if ($checkOutAt->lessThanOrEqualTo($limit)) {
            return $decision;
        }

        return new GeofenceDecision(
            Result::NeedsReview,
            true,
            true,
            $decision->matchedLocation,
            $decision->distanceMeters,
            array_values(array_unique([...$decision->flags, 'long_shift'])),
        );
    }

    /**
     * Full-day leave approved in the Time Off module blocks check-in for that
     * day. Half-day and hourly leave do not (the employee works the rest of
     * the day). "validate_two" is the fully-approved state, the same rule
     * HrAnalyticsService uses. Read through the table so the Employees plugin
     * keeps working when Time Off is not installed.
     */
    private function hasApprovedFullDayLeave(Employee $employee, string $attendanceDate): bool
    {
        if (! Schema::hasTable('time_off_leaves')) {
            return false;
        }

        return DB::table('time_off_leaves')
            ->where('employee_id', $employee->id)
            ->where('state', 'validate_two')
            ->where(fn ($query) => $query->whereNull('request_unit_half')->orWhere('request_unit_half', false))
            ->where(fn ($query) => $query->whereNull('request_unit_hours')->orWhere('request_unit_hours', false))
            ->whereDate('request_date_from', '<=', $attendanceDate)
            ->whereDate('request_date_to', '>=', $attendanceDate)
            ->exists();
    }

    private function openRecord(Employee $employee, CarbonImmutable $nowUtc, bool $lock = false): ?AttendanceRecord
    {
        $cutoff = $nowUtc->subHours((int) config('hr_attendance_geofence.max_shift_hours'))->setTimezone(config('app.timezone'));

        $query = AttendanceRecord::query()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->where(fn ($query) => $query
                // The open interval started recently (a later pair of a long day still counts) ...
                ->whereHas('intervals', fn ($interval) => $interval->whereNull('voided_at')->whereNull('check_out')->where('check_in', '>=', $cutoff))
                // ... or a day without intervals checked in recently.
                ->orWhere(fn ($legacy) => $legacy->whereDoesntHave('intervals', fn ($interval) => $interval->whereNull('voided_at'))->where('check_in', '>=', $cutoff)))
            ->orderByDesc('check_in');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function intervals(): AttendanceIntervalService
    {
        return $this->intervals ?? app(AttendanceIntervalService::class);
    }

    private function statusFor(string $mode, GeofenceDecision $decision): Status
    {
        if ($decision->needsReview) {
            return Status::NeedsReview;
        }

        return $mode === 'remote' ? Status::Remote : Status::Verified;
    }

    /** Rejections that are not location decisions carry no coordinates. */
    private function reject(
        Employee $employee,
        User $user,
        Request $request,
        Action $action,
        Result $result,
        string $requestId,
        string $timezone,
        ?string $time = null,
        ?AttendanceRecord $record = null,
        string $mode = 'gps',
    ): AttendanceAttemptResult {
        $this->logVerification($employee, $user, $request, $action, $mode, new GeofenceDecision($result, false), null, null, $requestId, $record?->id);

        return $this->resultFor($action, $result, false, $time, null);
    }

    private function logVerification(Employee $employee, User $user, Request $request, Action $action, string $mode, GeofenceDecision $decision, ?LocationEvidence $evidence, ?string $clientError, string $requestId, ?int $recordId = null): AttendanceVerification
    {
        // Coordinates are stored only when a real location decision was made from real evidence.
        $storeLocation = $mode === 'gps'
            && $evidence !== null
            && $clientError === null
            && in_array($decision->result, [Result::Verified, Result::NeedsReview, Result::OutsideGeofence, Result::LowAccuracy, Result::StaleLocation], true)
            && $this->distance->isValidCoordinate($evidence->latitude, $evidence->longitude);

        $matched = $decision->matchedLocation;
        $metadata = $clientError !== null ? ['client_error_code' => $clientError] : null;

        return AttendanceVerification::query()->create([
            'company_id'           => $employee->company_id,
            'employee_id'          => $employee->id,
            'user_id'              => $user->id,
            'attendance_record_id' => $recordId,
            'work_location_id'     => $matched?->id,
            'action'               => $action,
            'method'               => in_array($mode, ['remote', 'none'], true) ? Method::None : Method::Gps,
            'result'               => $decision->result,
            'accepted'             => $decision->accepted,
            'latitude'             => $storeLocation ? round($evidence->latitude, 7) : null,
            'longitude'            => $storeLocation ? round($evidence->longitude, 7) : null,
            'accuracy_meters'      => $storeLocation ? round(min($evidence->accuracy, 999999.0), 2) : null,
            'distance_meters'      => $decision->distanceMeters,
            'geofence_snapshot'    => $matched ? [
                'id'            => $matched->id,
                'name'          => $matched->name,
                'latitude'      => (float) $matched->latitude,
                'longitude'     => (float) $matched->longitude,
                'radius_meters' => (int) $matched->geofence_radius_meters,
            ] : null,
            'flags'                => $decision->flags ?: null,
            'metadata'             => $metadata,
            'client_request_id'    => $requestId,
            'client_captured_at'   => $storeLocation && $evidence->capturedAt ? $evidence->capturedAt->setTimezone(config('app.timezone')) : null,
            'server_recorded_at'   => CarbonImmutable::now(config('app.timezone')),
            'failure_reason'       => $decision->accepted ? null : $decision->result->value,
            'ip_address'           => $request->ip(),
            'user_agent'           => Str::limit((string) $request->userAgent(), 255, ''),
            'review_status'        => $decision->needsReview ? 'pending' : null,
        ]);
    }

    private function resultFor(Action $action, Result $result, bool $accepted, ?string $time, ?AttendanceRecord $record): AttendanceAttemptResult
    {
        return new AttendanceAttemptResult($result, $accepted, $this->message($action, $result, $time, $accepted), $time, $record);
    }

    private function resultFromVerification(AttendanceVerification $verification, string $timezone): AttendanceAttemptResult
    {
        $time = $verification->accepted || in_array($verification->result, [Result::AlreadyCheckedIn, Result::AlreadyCheckedOut, Result::OpenShiftExists, Result::TooEarlyForCheckOut], true)
            ? $this->localTime($verification->server_recorded_at, $timezone)
            : null;

        return $this->resultFor($verification->action, $verification->result, $verification->accepted, $time, $verification->attendanceRecord);
    }

    private function message(Action $action, Result $result, ?string $time, bool $accepted): string
    {
        if ($accepted && $result->isSuccess() && in_array($action, [Action::CheckIn, Action::CheckOut], true)) {
            return __('employees::attendance.done.'.$action->value.'.'.$result->value, ['time' => $time]);
        }

        if ($time && in_array($result, [Result::AlreadyCheckedIn, Result::AlreadyCheckedOut, Result::OpenShiftExists, Result::TooEarlyForCheckOut], true)) {
            return __('employees::attendance.with_time.'.$result->value, ['time' => $time]);
        }

        return $result->getLabel();
    }

    private function localTime(?CarbonInterface $moment, string $timezone): ?string
    {
        return $moment?->copy()->setTimezone($timezone)->format('h:i A');
    }

    private function normalizeRequestId(?string $requestId): string
    {
        return $requestId !== null && Str::isUuid($requestId) ? strtolower($requestId) : (string) Str::uuid();
    }

    /** @template T @param callable(): T $callback @return T */
    private function withAuditSuppressed(callable $callback): mixed
    {
        $previous = AttendanceRecord::$suppressCorrectionAudit;
        AttendanceRecord::$suppressCorrectionAudit = true;

        try {
            return $callback();
        } finally {
            AttendanceRecord::$suppressCorrectionAudit = $previous;
        }
    }
}
