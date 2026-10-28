<?php

namespace Webkul\Employee\Filament\Pages;

use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Webkul\Employee\Enums\AttendanceVerificationAction as VerificationAction;
use Webkul\Employee\Models\AttendanceRecord;
use Webkul\Employee\Services\Attendance\ApprovedLeaveLookup;
use Webkul\Employee\Services\Attendance\AttendanceScheduleResolver;
use Webkul\Employee\Services\Attendance\Data\LocationEvidence;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;
use Webkul\Employee\Services\Attendance\MonthlyAttendanceReport;
use Webkul\Employee\Services\EmployeeRequestService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\TimeOff\Models\Leave;

/**
 * Employee self-service Check In / Check Out. The browser only supplies raw
 * location evidence through the two Livewire actions below (authenticated
 * panel session + CSRF + Livewire checksum); every decision, and the
 * employee/company/workplace themselves, are resolved server-side by
 * GeofencedAttendanceService. No public API route exists for this feature.
 *
 * Deliberately NOT gated with HasPageShield: there is no universal
 * "employee" role to grant a page permission to. Access is a property of
 * the employee record (an eligible employee in the user's default
 * company) and the feature switch.
 */
class MyAttendance extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'my-attendance';

    protected string $view = 'employees::filament.pages.my-attendance';

    /** @var array{ok: bool, message: string, result: string}|null */
    public ?array $outcome = null;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if ($user === null || ! (bool) config('hr_attendance_geofence.enabled')) {
            return false;
        }

        if ((bool) config('hr_attendance_geofence.hr_only', false)) {
            if (! ($user->can(HrPermissions::ManageAttendance) || $user->can(HrPermissions::ViewAttendance))) {
                return false;
            }
        }

        return app(GeofencedAttendanceService::class)->resolveEmployee($user) !== null;
    }

    public static function getNavigationGroup(): string|\UnitEnum
    {
        return NavigationGroup::Attendance;
    }

    public static function getNavigationLabel(): string
    {
        return 'My Attendance';
    }

    public function getTitle(): string
    {
        return 'My Attendance';
    }

    public int $calendarYear = 0;

    public int $calendarMonth = 0;

    public function mount(): void
    {
        $this->calendarYear = (int) now()->year;
        $this->calendarMonth = (int) now()->month;
    }

    public function previousMonth(): void
    {
        if ($this->calendarMonth <= 1) {
            $this->calendarMonth = 12;
            $this->calendarYear--;
        } else {
            $this->calendarMonth--;
        }
    }

    public function nextMonth(): void
    {
        if ($this->calendarMonth >= 12) {
            $this->calendarMonth = 1;
            $this->calendarYear++;
        } else {
            $this->calendarMonth++;
        }
    }

    public function currentMonth(): void
    {
        $this->calendarYear = (int) now()->year;
        $this->calendarMonth = (int) now()->month;
    }

    /**
     * The signed-in employee's own grace balance for the month on screen
     * (MonthlyAttendanceReport, so it equals what HR exports).
     *
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>, title: string}|null
     */
    public function getGraceBalance(): ?array
    {
        $user = Auth::user();
        $employee = $user ? app(GeofencedAttendanceService::class)->resolveEmployee($user) : null;
        $report = app(MonthlyAttendanceReport::class);

        if (! $employee || ! $report->canSeeLedger($user, $employee)) {
            return null;
        }

        $month = sprintf('%04d-%02d', $this->calendarYear ?: now()->year, $this->calendarMonth ?: now()->month);

        try {
            $data = $report->forEmployee($employee, (int) $employee->company_id, $month);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return [
            'rows'    => $data['rows'],
            'summary' => $data['summary'],
            'title'   => 'My grace balance — '.CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->format('F Y'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function getTodayState(): ?array
    {
        return app(GeofencedAttendanceService::class)->todayState(Auth::user());
    }

    /**
     * @return array{
     *     monthName: string,
     *     year: int,
     *     month: int,
     *     stats: array{daysPresent: int, totalHours: float, lateDays: int, leaveDays: int},
     *     days: array<int, array<string, mixed>>
     * }
     */
    public function getMonthlyCalendarData(): array
    {
        if ($this->calendarYear === 0) {
            $this->mount();
        }

        $employee = app(GeofencedAttendanceService::class)->resolveEmployee(Auth::user());
        if (! $employee) {
            return [
                'monthName' => '',
                'year'      => $this->calendarYear,
                'month'     => $this->calendarMonth,
                'stats'     => ['daysPresent' => 0, 'totalHours' => 0.0, 'lateDays' => 0, 'leaveDays' => 0],
                'days'      => [],
            ];
        }

        $timezone = app(AttendanceScheduleResolver::class)->timezoneFor($employee);
        $monthStart = Carbon::createFromDate($this->calendarYear, $this->calendarMonth, 1, $timezone)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $todayStr = Carbon::today($timezone)->toDateString();

        $records = AttendanceRecord::query()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', '>=', $monthStart->toDateString())
            ->whereDate('attendance_date', '<=', $monthEnd->toDateString())
            ->get()
            ->keyBy(fn ($r) => $r->attendance_date->toDateString());

        // Approved AND pending leave, so a pending request can be shown as pending
        // (it never counts as leave). Dates use ApprovedLeaveLookup's rule.
        $leaves = ApprovedLeaveLookup::overlapping(
            Leave::query()
                ->where('company_id', $employee->company_id)
                ->where('employee_id', $employee->id)
                ->whereIn('state', [ApprovedLeaveLookup::APPROVED_STATE, ...ApprovedLeaveLookup::PENDING_STATES]),
            $monthStart->toDateString(),
            $monthEnd->toDateString(),
        )->get();

        $daysPresent = 0;
        $totalHours = 0.0;
        $lateDays = 0;
        $leaveDays = 0;

        $days = [];

        // Prepend empty slots for start of week (Monday = 1, Sunday = 7)
        $startDayOfWeek = $monthStart->dayOfWeekIso;
        for ($pad = 1; $pad < $startDayOfWeek; $pad++) {
            $days[] = [
                'type' => 'empty',
                'day'  => null,
                'date' => null,
            ];
        }

        for ($d = 1; $d <= $monthEnd->day; $d++) {
            $currentDate = Carbon::createFromDate($this->calendarYear, $this->calendarMonth, $d, $timezone);
            $dateStr = $currentDate->toDateString();
            $isToday = $dateStr === $todayStr;
            $isFuture = $dateStr > $todayStr;
            $isWeekend = $currentDate->isWeekend();

            /** @var AttendanceRecord|null $record */
            $record = $records->get($dateStr);

            $dayLeaves = $leaves->filter(fn ($l) => ApprovedLeaveLookup::covers($l, $dateStr));
            $matchingLeave = $dayLeaves->first(fn ($l) => ApprovedLeaveLookup::isApproved($l));
            $pendingLeave = $matchingLeave ? null : $dayLeaves->first();

            $status = 'none';
            $workedHours = null;
            $lateMinutes = null;
            $checkIn = null;
            $checkOut = null;
            $badgeColor = 'gray';
            $label = '';

            if ($record) {
                if ($record->status === 'present') {
                    $daysPresent++;
                    $totalHours += (float) ($record->worked_hours ?? 0);
                    $workedHours = number_format((float) $record->worked_hours, 1).'h';
                    $status = 'present';
                    $badgeColor = 'success';
                    $label = $workedHours;

                    if ($record->late_minutes > 0) {
                        $lateDays++;
                        $lateMinutes = $record->late_minutes.'m';
                        $status = 'late';
                        $badgeColor = 'warning';
                        $label = 'Late '.$lateMinutes;
                    }
                } elseif ($record->status === 'leave') {
                    $leaveDays++;
                    $status = 'leave';
                    $badgeColor = 'info';
                    $label = 'Leave';
                }

                if ($record->verification_status === 'needs_review') {
                    $status = 'needs_review';
                    $badgeColor = 'amber';
                    $label = 'Review';
                }

                $checkIn = $record->check_in ? $record->check_in->setTimezone($timezone)->format('h:i A') : null;
                $checkOut = $record->check_out ? $record->check_out->setTimezone($timezone)->format('h:i A') : null;
            } elseif ($matchingLeave) {
                $leaveDays++;
                $status = 'leave';
                $badgeColor = 'info';
                $label = 'Leave';
            } elseif ($pendingLeave && ! $isWeekend) {
                // Pending leave does not excuse the day; it is shown so the employee
                // knows the request is still waiting.
                $status = 'leave_pending';
                $badgeColor = 'warning';
                $label = 'Leave pending';
            } elseif (! $isWeekend && ! $isFuture) {
                $status = 'absent';
                $badgeColor = 'danger';
                $label = 'Missing';
            } elseif ($isWeekend) {
                $status = 'weekend';
                $badgeColor = 'gray';
                $label = 'Off';
            }

            $days[] = [
                'type'        => 'day',
                'day'         => $d,
                'date'        => $dateStr,
                'isToday'     => $isToday,
                'isFuture'    => $isFuture,
                'isWeekend'   => $isWeekend,
                'status'      => $status,
                'badgeColor'  => $badgeColor,
                'label'       => $label,
                'workedHours' => $workedHours,
                'lateMinutes' => $lateMinutes,
                'checkIn'     => $checkIn,
                'checkOut'    => $checkOut,
                'recordId'    => $record?->id,
            ];
        }

        return [
            'monthName' => $monthStart->format('F Y'),
            'year'      => $this->calendarYear,
            'month'     => $this->calendarMonth,
            'stats'     => [
                'daysPresent' => $daysPresent,
                'totalHours'  => round($totalHours, 1),
                'lateDays'    => $lateDays,
                'leaveDays'   => $leaveDays,
            ],
            'days'      => $days,
        ];
    }

    /** @return Collection<int, array<string, mixed>> the last 14 days, times shown in the employee's timezone */
    public function getRecentRecords(): Collection
    {
        $service = app(GeofencedAttendanceService::class);
        $employee = $service->resolveEmployee(Auth::user());
        if (! $employee) {
            return collect();
        }

        $timezone = app(AttendanceScheduleResolver::class)->timezoneFor($employee);
        $format = fn ($moment): ?string => $moment?->copy()->setTimezone($timezone)->format('h:i A');

        return AttendanceRecord::query()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->orderByDesc('attendance_date')
            ->limit(14)
            ->get()
            ->map(fn (AttendanceRecord $record): array => [
                'id'        => $record->id,
                'date'      => $record->attendance_date->format('D, d M Y'),
                'check_in'  => $format($record->check_in) ?? '—',
                'check_out' => $format($record->check_out) ?? '—',
                'worked'    => $record->check_out ? number_format((float) $record->worked_hours, 2).' h' : '—',
                'status'    => ucfirst((string) $record->status),
                'review'    => $record->verification_status === 'needs_review',
            ]);
    }

    // ------------------------------------------------------------------
    // Livewire actions (untrusted input)
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $payload */
    public function checkIn(array $payload = []): void
    {
        $this->submit(VerificationAction::CheckIn, $payload);
    }

    /** @param array<string, mixed> $payload */
    public function checkOut(array $payload = []): void
    {
        $this->submit(VerificationAction::CheckOut, $payload);
    }

    /** The browser could not produce a location. */
    public function reportLocationFailure(string $action, string $code, ?string $clientRequestId = null): void
    {
        $verificationAction = VerificationAction::tryFrom($action);
        if (! in_array($verificationAction, [VerificationAction::CheckIn, VerificationAction::CheckOut], true)) {
            $this->outcome = $this->failure('invalid_coordinates', __('employees::attendance.results.invalid_coordinates'));

            return;
        }

        $this->guarded(function () use ($verificationAction, $code, $clientRequestId): void {
            try {
                $result = app(GeofencedAttendanceService::class)->recordClientFailure(
                    Auth::user(),
                    $verificationAction,
                    $code,
                    $clientRequestId,
                    request(),
                );
            } catch (InvalidArgumentException) {
                $this->outcome = $this->failure('invalid_coordinates', __('employees::attendance.results.invalid_coordinates'));

                return;
            }

            $this->outcome = ['ok' => $result->accepted, 'message' => $result->message, 'result' => $result->result->value];
        });
    }

    /** @param array<string, mixed> $payload */
    private function submit(VerificationAction $action, array $payload): void
    {
        $this->guarded(function () use ($action, $payload): void {
            $service = app(GeofencedAttendanceService::class);
            $evidence = $this->evidenceFrom($payload);

            $result = $action === VerificationAction::CheckIn
                ? $service->checkIn(Auth::user(), $evidence, request(), $evidence->clientRequestId)
                : $service->checkOut(Auth::user(), $evidence, request(), $evidence->clientRequestId);

            $this->outcome = ['ok' => $result->accepted, 'message' => $result->message, 'result' => $result->result->value];
        });
    }

    /** Rate limit + last-resort error handling shared by every browser-facing action. */
    private function guarded(callable $callback): void
    {
        $user = Auth::user();
        if (! $user) {
            abort(403);
        }

        $allowed = RateLimiter::attempt(
            'hr-geo-attendance:'.$user->id,
            (int) config('hr_attendance_geofence.rate_limit_per_minute'),
            fn (): bool => true,
            60,
        );
        if (! $allowed) {
            $this->outcome = $this->failure('rate_limited', __('employees::attendance.rate_limited'));

            return;
        }

        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
            $this->outcome = $this->failure('unexpected_error', __('employees::attendance.unexpected_error'));
        }
    }

    /**
     * Anything the browser sends is untrusted. Only raw location evidence is
     * read: employee, company, workplace, distance and verdict keys are
     * ignored outright. Malformed input becomes deliberately-invalid evidence
     * so the attempt is still audited as InvalidCoordinates.
     *
     * @param  array<string, mixed>  $payload
     */
    private function evidenceFrom(array $payload): LocationEvidence
    {
        $requestId = is_string($payload['client_request_id'] ?? null) && Str::isUuid($payload['client_request_id'])
            ? strtolower($payload['client_request_id'])
            : (string) Str::uuid();

        $validator = Validator::make($payload, [
            'latitude'          => ['required', 'numeric', 'between:-90,90'],
            'longitude'         => ['required', 'numeric', 'between:-180,180'],
            'accuracy'          => ['required', 'numeric', 'gt:0', 'max:10000'],
            'captured_at'       => ['nullable', 'integer', 'min:0'],
            'client_now'        => ['nullable', 'integer', 'min:0'],
            'client_request_id' => ['nullable', 'uuid'],
        ]);

        if ($validator->fails()) {
            return new LocationEvidence(0.0, 0.0, 0.0, null, $requestId);
        }

        $data = $validator->validated();

        return new LocationEvidence(
            (float) $data['latitude'],
            (float) $data['longitude'],
            (float) $data['accuracy'],
            isset($data['captured_at']) ? CarbonImmutable::createFromTimestampMs((int) $data['captured_at'], 'UTC') : null,
            $requestId,
            isset($data['client_now']) ? CarbonImmutable::createFromTimestampMs((int) $data['client_now'], 'UTC') : null,
        );
    }

    /** @return array{ok: bool, message: string, result: string} */
    private function failure(string $result, string $message): array
    {
        return ['ok' => false, 'message' => $message, 'result' => $result];
    }

    // ------------------------------------------------------------------
    // Corrections (reuse the existing approval workflow)
    // ------------------------------------------------------------------

    public function requestCorrectionAction(): Action
    {
        return Action::make('requestCorrection')
            ->label('Request correction')
            ->icon('heroicon-o-clock')
            ->color('warning')
            ->size('sm')
            ->link()
            ->modalHeading('Request an attendance correction')
            ->schema([
                DateTimePicker::make('requested_check_in')->seconds(false),
                DateTimePicker::make('requested_check_out')->seconds(false),
                Textarea::make('reason')
                    ->label('Reason for the change (optional)')
                    ->placeholder('e.g. Traffic delay, badge issue, forgot to clock in'),
            ])
            ->fillForm(function (array $arguments): array {
                $record = $this->ownRecord($arguments['record'] ?? null);

                return ['requested_check_in' => $record?->check_in, 'requested_check_out' => $record?->check_out];
            })
            ->action(function (array $data, array $arguments): void {
                $record = $this->ownRecord($arguments['record'] ?? null);
                if (! $record) {
                    Notification::make()->danger()->title('That attendance record was not found.')->send();

                    return;
                }

                try {
                    app(EmployeeRequestService::class)->requestAttendanceTimeChange(
                        $record,
                        Auth::user(),
                        ['check_in' => $data['requested_check_in'] ?? null, 'check_out' => $data['requested_check_out'] ?? null],
                        $data['reason'] ?? null,
                    );
                    Notification::make()->success()->title('Correction request submitted to your line manager')->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Could not submit the request')->body($e->getMessage())->send();
                }
            });
    }

    public function requestMissingDayAction(): Action
    {
        return Action::make('requestMissingDay')
            ->label('Request attendance for a missed day')
            ->icon('heroicon-o-calendar-days')
            ->color('gray')
            ->modalHeading('Request attendance for a missed day')
            ->schema([
                DatePicker::make('attendance_date')->required()->native(false)
                    ->minDate(now()->subDays(30)->toDateString())->maxDate(now()->toDateString()),
                DateTimePicker::make('check_in')->seconds(false)->required(),
                DateTimePicker::make('check_out')->seconds(false),
                Textarea::make('reason')
                    ->label('Reason (optional)')
                    ->placeholder('e.g. Forgot to clock in, badge failure'),
            ])
            ->action(function (array $data): void {
                $employee = app(GeofencedAttendanceService::class)->resolveEmployee(Auth::user());
                if (! $employee) {
                    Notification::make()->danger()->title('Your employee profile is not set up for this.')->send();

                    return;
                }

                try {
                    app(EmployeeRequestService::class)->requestMissingAttendance(
                        $employee,
                        Auth::user(),
                        (string) $data['attendance_date'],
                        ['check_in' => $data['check_in'] ?? null, 'check_out' => $data['check_out'] ?? null],
                        $data['reason'] ?? null,
                    );
                    Notification::make()->success()->title('Request submitted to your line manager')->send();
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title('Could not submit the request')->body($e->getMessage())->send();
                }
            });
    }

    /** Only ever the signed-in employee's OWN record in their default company. */
    private function ownRecord(mixed $id): ?AttendanceRecord
    {
        $employee = app(GeofencedAttendanceService::class)->resolveEmployee(Auth::user());
        if (! $employee || ! is_numeric($id)) {
            return null;
        }

        return AttendanceRecord::query()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereKey((int) $id)
            ->first();
    }
}
