<?php

namespace Webkul\Employee\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Webkul\Employee\Models\Department;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\Shift;
use Webkul\Employee\Models\ShiftOverride;
use Webkul\Employee\Models\ShiftRoster;
use Webkul\Employee\Models\ShiftRosterAssignment;
use Webkul\Employee\Services\Attendance\Data\DaySchedule;
use Webkul\Employee\Services\Attendance\Data\ScheduleSet;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\Company;

/**
 * Rotating shift rosters (HR parity plan 3.4): assigning roster patterns,
 * changing or swapping one day, and the roster calendar (weeks x employees).
 *
 * Who, enforced here whatever the screen:
 *  - shifts, patterns and department-wide rosters: HR of the company
 *    (hr_manage_attendance with company-wide visibility, hr_view_all_records);
 *  - an employee's roster and day changes: HR, or the employee's line
 *    manager (hr_manage_attendance over their own reporting tree), never
 *    for oneself;
 *  - the calendar: whoever may see attendance reports, limited to the
 *    employees they may see.
 * Company isolation: patterns, shifts, employees and departments must all
 * belong to the same company (the models check it again on every save), and
 * a finalised month cannot be changed (AttendanceMonthLockService).
 */
class ShiftRosterService
{
    public function __construct(
        protected HrHierarchyService $hierarchy,
        protected AttendanceScheduleResolver $schedules,
    ) {}

    /** HR: define shifts and patterns and roster whole departments. */
    public static function canConfigure(?User $user): bool
    {
        return $user !== null && $user->can(HrPermissions::ManageAttendance) && $user->can(HrPermissions::ViewAllRecords);
    }

    /** The roster calendar: HR and line managers (the same people who see attendance reports). */
    public static function canView(?User $user): bool
    {
        return MonthlyAttendanceReport::canView($user);
    }

    /** HR of the employee's company or their line manager; nobody changes their own roster. */
    public function canManage(?User $user, Employee $employee): bool
    {
        return $user !== null
            && $employee->company_id !== null
            && $user->can(HrPermissions::ManageAttendance)
            && ($employee->user_id === null || (int) $employee->user_id !== (int) $user->id)
            && CurrentCompany::canAccess((int) $employee->company_id, $user)
            && $this->hierarchy->canManage($user, $employee);
    }

    // ------------------------------------------------------------------
    // Assigning patterns
    // ------------------------------------------------------------------

    /**
     * The pattern for one employee from $start (day 1 of the cycle). An
     * open-ended roster of the employee that started earlier is ended the
     * day before; any other overlap is refused.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function assign(User $user, ShiftRoster $roster, Employee $employee, string $start, ?string $end = null, ?string $notes = null): ShiftRosterAssignment
    {
        if (! $this->canManage($user, $employee)) {
            throw new AuthorizationException('You may not change the roster of '.$employee->name.'.');
        }

        $this->assertSameCompany($roster, (int) $employee->company_id);

        return $this->createAssignment($roster, ['employee_id' => (int) $employee->id], $start, $end, $notes);
    }

    /**
     * The pattern for a whole department (HR only). Employees with their own
     * roster keep it.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function assignDepartment(User $user, ShiftRoster $roster, Department $department, string $start, ?string $end = null, ?string $notes = null): ShiftRosterAssignment
    {
        if (! static::canConfigure($user) || ! CurrentCompany::canAccess((int) $department->company_id, $user)) {
            throw new AuthorizationException('Only HR of the company can roster a whole department.');
        }

        $this->assertSameCompany($roster, (int) $department->company_id);

        return $this->createAssignment($roster, ['department_id' => (int) $department->id], $start, $end, $notes);
    }

    /**
     * The same pattern for several employees. Each one is checked and saved on
     * its own; one refusal does not stop the others.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array{assigned: int, skipped: array<string, string>} skipped: employee name => reason
     */
    public function assignMany(User $user, ShiftRoster $roster, Collection $employees, string $start, ?string $end = null, ?string $notes = null): array
    {
        $assigned = 0;
        $skipped = [];

        foreach ($employees as $employee) {
            try {
                $this->assign($user, $roster, $employee, $start, $end, $notes);
                $assigned++;
            } catch (AuthorizationException) {
                $skipped[(string) $employee->name] = 'not in your scope';
            } catch (ValidationException $e) {
                $skipped[(string) $employee->name] = (string) collect($e->errors())->flatten()->first();
            } catch (RuntimeException $e) {
                $skipped[(string) $employee->name] = $e->getMessage();
            }
        }

        return ['assigned' => $assigned, 'skipped' => $skipped];
    }

    // ------------------------------------------------------------------
    // One day
    // ------------------------------------------------------------------

    /**
     * Changes one employee's shift on one date ($shiftId null = day off),
     * with a reason. Replaces an earlier change of that day.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function setDay(User $user, Employee $employee, string $date, ?int $shiftId, string $reason): ShiftOverride
    {
        if (! $this->canManage($user, $employee)) {
            throw new AuthorizationException('You may not change the roster of '.$employee->name.'.');
        }

        $date = $this->normaliseDate($date);

        return DB::transaction(function () use ($employee, $date, $shiftId, $reason): ShiftOverride {
            $override = ShiftOverride::query()
                ->where('employee_id', $employee->id)
                ->whereDate('date', $date)
                ->lockForUpdate()
                ->first() ?? new ShiftOverride(['employee_id' => $employee->id, 'company_id' => $employee->company_id, 'date' => $date]);

            $override->fill(['shift_id' => $shiftId, 'swap_employee_id' => null, 'reason' => $reason])->save();

            return $override;
        });
    }

    /**
     * Back to the roster / work schedule for that day.
     *
     * @throws AuthorizationException
     */
    public function clearDay(User $user, Employee $employee, string $date): bool
    {
        if (! $this->canManage($user, $employee)) {
            throw new AuthorizationException('You may not change the roster of '.$employee->name.'.');
        }

        $override = ShiftOverride::query()->where('employee_id', $employee->id)->whereDate('date', $this->normaliseDate($date))->first();

        return (bool) $override?->delete();
    }

    /**
     * Two employees of one company swap their shifts of one date (both
     * shift days or days off, as the roster has them). Recorded as one
     * override per employee, naming the other.
     *
     * @return array{0: ShiftOverride, 1: ShiftOverride}
     *
     * @throws AuthorizationException|ValidationException
     */
    public function swap(User $user, Employee $first, Employee $second, string $date, string $reason): array
    {
        if ((int) $first->id === (int) $second->id) {
            throw ValidationException::withMessages(['swap_employee_id' => 'Choose a different colleague to swap with.']);
        }
        if ((int) $first->company_id !== (int) $second->company_id) {
            throw ValidationException::withMessages(['swap_employee_id' => 'Shifts can only be swapped within one company.']);
        }
        if (! $this->canManage($user, $first) || ! $this->canManage($user, $second)) {
            throw new AuthorizationException('You may only swap shifts of people whose roster you manage.');
        }

        $date = $this->normaliseDate($date);
        $sets = $this->schedules->setsFor([$first, $second], $date, $date);
        $firstDay = $this->schedules->pick($sets[(int) $first->id] ?? new ScheduleSet, $date);
        $secondDay = $this->schedules->pick($sets[(int) $second->id] ?? new ScheduleSet, $date);

        foreach ([[$first, $firstDay], [$second, $secondDay]] as [$employee, $day]) {
            if (! $day->isOff() && $day->shiftId === null) {
                throw ValidationException::withMessages(['swap_employee_id' => $employee->name.' has no shift that day (only roster shifts and days off can be swapped).']);
            }
        }

        $reason = trim($reason);

        return DB::transaction(fn (): array => [
            $this->writeSwap($first, $second, $date, $secondDay->shiftId, $reason),
            $this->writeSwap($second, $first, $date, $firstDay->shiftId, $reason),
        ]);
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * The roster calendar: $days days from $from for the employees of the
     * company the user may see (optionally one department).
     *
     * @return array{company: array{id: int, name: string}, from: string, to: string, dates: array<int, array{date: string, day: string, label: string, weekend: bool, today: bool}>, rows: array<int, array{employee_id: int, name: string, code: string, department: string, can_edit: bool, cells: array<string, array<string, mixed>>}>, shifts: array<int, array{id: int, code: string, name: string, hours: string, color: string, break_minutes: int}>}
     *
     * @throws AuthorizationException
     */
    public function calendar(User $user, int $companyId, string $from, int $days, ?int $departmentId = null, ?string $search = null): array
    {
        if (! static::canView($user) || ! CurrentCompany::canAccess($companyId, $user)) {
            throw new AuthorizationException('You may not view the shift roster of this company.');
        }

        $days = max(1, min(62, $days));
        $start = CarbonImmutable::parse($this->normaliseDate($from));
        $end = $start->addDays($days - 1);
        $today = CarbonImmutable::now($this->timezone())->toDateString();
        $weekend = array_map('intval', (array) config('attendance.monthly_report.weekend_days', [6, 7]));

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $this->hierarchy->visibleEmployeeIds($user, $companyId))
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('departure_date')->orWhereDate('departure_date', '>=', $start->toDateString()))
            ->when($departmentId, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->when(filled($search), fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('name', 'like', '%'.trim((string) $search).'%')
                ->orWhere('employee_number', 'like', '%'.trim((string) $search).'%')))
            ->with('department:id,name')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'company_id', 'user_id', 'name', 'employee_number', 'department_id']);

        $sets = $this->schedules->setsFor($employees, $start->toDateString(), $end->toDateString());

        $dates = [];
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $dates[] = [
                'date'    => $date->toDateString(),
                'day'     => $date->format('D'),
                'label'   => $date->format('j M'),
                'weekend' => in_array($date->dayOfWeekIso, $weekend, true),
                'today'   => $date->toDateString() === $today,
            ];
        }

        $rows = [];
        foreach ($employees as $employee) {
            $cells = [];
            foreach ($dates as $date) {
                $cells[$date['date']] = $this->cell($this->schedules->pick($sets[(int) $employee->id] ?? new ScheduleSet, $date['date']), $date['date']);
            }

            $rows[] = [
                'employee_id' => (int) $employee->id,
                'name'        => (string) $employee->name,
                'code'        => (string) ($employee->employee_number ?? ''),
                'department'  => (string) ($employee->department?->name ?? ''),
                'can_edit'    => $this->canManage($user, $employee),
                'cells'       => $cells,
            ];
        }

        return [
            'company' => ['id' => $companyId, 'name' => (string) Company::query()->whereKey($companyId)->value('name')],
            'from'    => $start->toDateString(),
            'to'      => $end->toDateString(),
            'dates'   => $dates,
            'rows'    => $rows,
            'shifts'  => $this->legend($companyId),
        ];
    }

    /**
     * One employee's own shifts from $from for $days days (My Attendance, the
     * home dashboard). Empty when nothing is rostered in that window: a plain
     * work schedule is shown on its own elsewhere.
     *
     * @return array<int, array{date: string, day: string, label: string, code: string, hours: string, color: ?string, off: bool, changed: bool, note: ?string}>
     */
    public function upcomingFor(Employee $employee, ?string $from = null, ?int $days = null): array
    {
        if (! AttendanceScheduleResolver::shiftsAvailable()) {
            return [];
        }

        $days = max(1, min(31, $days ?? (int) config('attendance.shifts.upcoming_days', 7)));
        $start = CarbonImmutable::parse($from ?? CarbonImmutable::now($this->schedules->timezoneFor($employee))->toDateString());
        $end = $start->addDays($days - 1);

        // One query for the (usual) employee without a roster.
        if (! $this->isRostered($employee, $start->toDateString(), $end->toDateString())) {
            return [];
        }

        $set = $this->schedules->setsFor([$employee], $start->toDateString(), $end->toDateString())[(int) $employee->id] ?? new ScheduleSet;

        $result = [];
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $schedule = $this->schedules->pick($set, $date->toDateString());
            $cell = $this->cell($schedule, $date->toDateString());

            $result[] = [
                'date'    => $date->toDateString(),
                'day'     => $date->format('D j M'),
                'label'   => $cell['name'],
                'code'    => $cell['code'],
                'hours'   => $cell['hours'],
                'color'   => $cell['color'],
                'off'     => $cell['off'],
                'changed' => $cell['override'],
                'note'    => $cell['override'] ? $cell['note'] : null,
            ];
        }

        return $result;
    }

    /** Whether a roster (own or department) or a day change covers any day of [$from, $to]. */
    public function isRostered(Employee $employee, string $from, string $to): bool
    {
        if (! RosterPresence::has((int) $employee->company_id)) {
            return false;
        }

        $assignments = ShiftRosterAssignment::query()
            ->covering($from, $to)
            ->where(fn (Builder $query) => $query->where('employee_id', $employee->id)
                ->when($employee->department_id, fn (Builder $inner) => $inner->orWhere(fn (Builder $department) => $department
                    ->where('department_id', $employee->department_id)
                    ->where('company_id', $employee->company_id))))
            ->toBase()
            ->selectRaw('1 as found');

        $overrides = ShiftOverride::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->toBase()
            ->selectRaw('1 as found');

        return $assignments->union($overrides)->limit(1)->get()->isNotEmpty();
    }

    /** @return array<int, array{id: int, code: string, name: string, hours: string, color: string, break_minutes: int, active: bool}> */
    public function legend(int $companyId): array
    {
        return Shift::query()
            ->where('company_id', $companyId)
            ->orderBy('sort')
            ->orderBy('name')
            ->get()
            ->map(fn (Shift $shift): array => [
                'id'            => (int) $shift->id,
                'code'          => (string) $shift->code,
                'name'          => (string) $shift->name,
                'hours'         => $shift->hours(),
                'color'         => (string) $shift->color,
                'break_minutes' => (int) $shift->break_minutes,
                'active'        => (bool) $shift->is_active,
            ])
            ->all();
    }

    /**
     * A calendar cell: what the day is and where it comes from.
     *
     * @return array{code: string, name: string, hours: string, color: ?string, off: bool, working: bool, override: bool, source: string, shift_id: ?int, note: ?string, title: string}
     */
    public function cell(DaySchedule $schedule, string $date): array
    {
        $working = $schedule->isWorkingDay($date);
        $off = ! $working;
        $hours = $working ? $schedule->startClock().'–'.$schedule->endClock().($schedule->isOvernight() ? ' (+1)' : '') : '';
        $name = match (true) {
            $off && $schedule->isRostered()  => 'Off',
            $off                             => 'Weekend',
            $schedule->shiftId !== null      => (string) $schedule->name,
            $schedule->scheduleId !== null   => 'Schedule',
            default                          => 'Office hours',
        };
        $source = match ($schedule->source) {
            DaySchedule::SourceOverride => 'Changed for the day',
            DaySchedule::SourceRoster   => 'Roster'.($schedule->note ? ': '.$schedule->note : ''),
            DaySchedule::SourceSchedule => 'Work schedule',
            default                     => 'Company office hours',
        };

        return [
            'code'     => $off ? ($schedule->isRostered() ? 'Off' : '–') : ($schedule->shiftCode ?? ($schedule->scheduleId !== null ? 'S' : 'G')),
            'name'     => $name,
            'hours'    => $hours,
            'color'    => $off ? null : $schedule->color,
            'off'      => $off,
            'working'  => $working,
            'override' => $schedule->source === DaySchedule::SourceOverride,
            'source'   => $schedule->source,
            'shift_id' => $schedule->shiftId,
            'note'     => $schedule->note,
            'title'    => trim($name.' '.$hours).' · '.$source.($schedule->source === DaySchedule::SourceOverride && $schedule->note ? ': '.$schedule->note : ''),
        ];
    }

    /** @return array<int, string> active patterns of the company, id => name */
    public function patternOptions(int $companyId): array
    {
        return ShiftRoster::query()->where('company_id', $companyId)->active()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<int, string> active shifts of the company, id => "Night (N) 22:00–06:00 (+1)" */
    public function shiftOptions(int $companyId): array
    {
        return Shift::query()->where('company_id', $companyId)->active()->orderBy('sort')->orderBy('name')->get()
            ->mapWithKeys(fn (Shift $shift): array => [(int) $shift->id => $shift->label()])
            ->all();
    }

    // ------------------------------------------------------------------

    /**
     * @param  array{employee_id?: int, department_id?: int}  $target
     */
    private function createAssignment(ShiftRoster $roster, array $target, string $start, ?string $end, ?string $notes): ShiftRosterAssignment
    {
        $start = $this->normaliseDate($start);
        $end = filled($end) ? $this->normaliseDate((string) $end) : null;

        return DB::transaction(function () use ($roster, $target, $start, $end, $notes): ShiftRosterAssignment {
            // Keep history: the open-ended roster in force before the new one ends the day before.
            $current = ShiftRosterAssignment::query()
                ->where($target)
                ->whereNull('end_date')
                ->whereDate('start_date', '<', $start)
                ->lockForUpdate()
                ->first();

            if ($current) {
                $current->end_date = CarbonImmutable::parse($start)->subDay()->toDateString();
                $current->save();
            }

            return ShiftRosterAssignment::query()->create($target + [
                'company_id' => $roster->company_id,
                'roster_id'  => $roster->id,
                'start_date' => $start,
                'end_date'   => $end,
                'notes'      => filled($notes) ? mb_substr((string) $notes, 0, 255) : null,
            ]);
        });
    }

    private function writeSwap(Employee $employee, Employee $with, string $date, ?int $shiftId, string $reason): ShiftOverride
    {
        $override = ShiftOverride::query()
            ->where('employee_id', $employee->id)
            ->whereDate('date', $date)
            ->lockForUpdate()
            ->first() ?? new ShiftOverride(['employee_id' => $employee->id, 'company_id' => $employee->company_id, 'date' => $date]);

        $override->fill(['shift_id' => $shiftId, 'swap_employee_id' => $with->id, 'reason' => $reason])->save();

        return $override;
    }

    /** @throws ValidationException */
    private function assertSameCompany(ShiftRoster $roster, int $companyId): void
    {
        if ((int) $roster->company_id !== $companyId) {
            throw ValidationException::withMessages(['roster_id' => 'The roster pattern belongs to another company.']);
        }
        if (! $roster->is_active) {
            throw ValidationException::withMessages(['roster_id' => 'The roster pattern is switched off.']);
        }
    }

    private function normaliseDate(string $date): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}/', trim($date))) {
            throw new InvalidArgumentException('The date must look like 2026-10-28.');
        }

        return CarbonImmutable::parse(substr(trim($date), 0, 10))->toDateString();
    }

    private function timezone(): string
    {
        return (string) config('attendance.monthly_report.timezone', 'Asia/Karachi');
    }
}
