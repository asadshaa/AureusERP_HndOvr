<?php

namespace Webkul\Employee\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\WorkSchedule;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/**
 * HR assigns effective-dated work schedules (HR parity plan 2.2).
 *
 * Who: HR of the employee's company -- hr_manage_attendance together with
 * company-wide HR visibility (hr_view_all_records). Line managers (who hold
 * hr_manage_attendance for their own people only) cannot.
 *
 * assign() keeps history: an open-ended schedule that started before the new
 * one is ended the day before it starts; any other overlap is refused
 * (WorkSchedule validates it on every save).
 */
class WorkScheduleService
{
    public function __construct(
        protected HrHierarchyService $hierarchy,
    ) {}

    public function canManage(?User $user, Employee $employee): bool
    {
        return $user !== null
            && $employee->company_id !== null
            && $user->can(HrPermissions::ManageAttendance)
            && $user->can(HrPermissions::ViewAllRecords)
            && CurrentCompany::canAccess((int) $employee->company_id, $user)
            && $this->hierarchy->canManage($user, $employee);
    }

    public function canManageAny(?User $user): bool
    {
        return $user !== null && $user->can(HrPermissions::ManageAttendance) && $user->can(HrPermissions::ViewAllRecords);
    }

    /**
     * @param  array{name?: ?string, start_time: string, end_time: string, required_minutes: int|string, working_days: array<int, int|string>, flexible_minutes?: int|string|null, effective_from: string, effective_to?: ?string, notes?: ?string}  $data
     *
     * @throws AuthorizationException|ValidationException
     */
    public function assign(User $user, Employee $employee, array $data): WorkSchedule
    {
        if (! $this->canManage($user, $employee)) {
            throw new AuthorizationException('Only HR of this employee\'s company can assign work schedules.');
        }

        $from = CarbonImmutable::parse((string) $data['effective_from'])->toDateString();

        return DB::transaction(function () use ($employee, $data, $from): WorkSchedule {
            // Keep history: the open-ended schedule in force before the new one ends the day before.
            $current = WorkSchedule::query()
                ->where('employee_id', $employee->id)
                ->whereNull('effective_to')
                ->whereDate('effective_from', '<', $from)
                ->lockForUpdate()
                ->first();

            if ($current) {
                $current->effective_to = CarbonImmutable::parse($from)->subDay()->toDateString();
                $current->save();
            }

            return WorkSchedule::query()->create([
                'company_id'       => $employee->company_id,
                'employee_id'      => $employee->id,
                'name'             => filled($data['name'] ?? null) ? (string) $data['name'] : null,
                'start_time'       => (string) $data['start_time'],
                'end_time'         => (string) $data['end_time'],
                'required_minutes' => (int) $data['required_minutes'],
                'working_days'     => array_values(array_map('intval', (array) $data['working_days'])),
                'flexible_minutes' => (int) ($data['flexible_minutes'] ?? 0),
                'effective_from'   => $from,
                'effective_to'     => filled($data['effective_to'] ?? null) ? CarbonImmutable::parse((string) $data['effective_to'])->toDateString() : null,
                'notes'            => filled($data['notes'] ?? null) ? mb_substr((string) $data['notes'], 0, 255) : null,
            ]);
        });
    }

    /**
     * The same schedule for several employees. Each one is checked and saved
     * on its own; one refusal does not stop the others.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  array<string, mixed>  $data
     * @return array{assigned: int, skipped: array<int, string>} skipped: employee name => reason
     */
    public function assignMany(User $user, Collection $employees, array $data): array
    {
        $assigned = 0;
        $skipped = [];

        foreach ($employees as $employee) {
            try {
                $this->assign($user, $employee, $data);
                $assigned++;
            } catch (AuthorizationException $e) {
                $skipped[(string) $employee->name] = 'not in your HR scope';
            } catch (ValidationException $e) {
                $skipped[(string) $employee->name] = (string) collect($e->errors())->flatten()->first();
            } catch (\RuntimeException $e) {
                $skipped[(string) $employee->name] = $e->getMessage();
            }
        }

        return ['assigned' => $assigned, 'skipped' => $skipped];
    }
}
