<?php

namespace Webkul\Employee\Services\Checklists;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;
use Webkul\Employee\Models\ChecklistTemplate;
use Webkul\Employee\Models\ChecklistTemplateTask;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\EmployeeChecklist;
use Webkul\Employee\Models\EmployeeChecklistTask;
use Webkul\Employee\Models\EmployeeDocument;
use Webkul\Employee\Models\EmployeeRequest;
use Webkul\Employee\Policies\EmployeeChecklistTaskPolicy;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\ChecklistKinds;
use Webkul\Employee\Support\EmployeeRequestKinds;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;

/**
 * Onboarding and exit checklists (HR Parity 2.4). Every write goes through
 * here so the same rules apply from the employee page, My tasks, the HR
 * list and the scheduled commands:
 *
 * - Onboarding starts by itself when an employee is created with a recent
 *   joining date, or by HR's "Start onboarding"; "Start exit" records the
 *   leaving date, departure reason, notice period and last working day on
 *   the employee and starts the exit checklist. Both are idempotent: one
 *   open checklist per employee and type (EmployeeChecklist::open_key).
 * - Tasks are copied from the company's template, assigned by role (HR
 *   queue, line manager, named IT / admin user, the employee) and dated
 *   from the joining date / last working day, never before the start day.
 * - Document-linked tasks complete when that document is uploaded; user
 *   account, line manager, working hours and experience letter tasks
 *   complete when the system sees the change.
 * - The user account is never disabled by "Start exit": the disable task
 *   offers a one-click schedule that hr:checklist-disable-users carries out
 *   on the last working day, recorded on the task, the checklist and the
 *   employee's chatter.
 * - Exit can be undone before the leaving date; the employee's previous
 *   leaving details come back exactly.
 */
class EmployeeChecklistService
{
    public function __construct(
        protected EmployeeChecklistTaskPolicy $policy,
        protected ChecklistNotifier $notifier,
        protected HrHierarchyService $hierarchy,
    ) {}

    /**
     * Employee::created: start onboarding for a new joiner. Never for an
     * inactive employee, one without a joining date or one who joined more
     * than ChecklistKinds::AutoStartMaxDaysAgo days ago (back-filled
     * records). Assignees are told only when a person created the employee;
     * imports and background conversions rely on My tasks and the daily
     * overdue reminder instead.
     */
    public function autoStartOnboarding(Employee $employee): ?EmployeeChecklist
    {
        if (! $employee->company_id || ! $employee->is_active || $employee->joining_date === null) {
            return null;
        }

        $joining = CarbonImmutable::parse($employee->joining_date->toDateString(), ChecklistKinds::timezone());

        if ($joining->lt(ChecklistKinds::today()->subDays(ChecklistKinds::AutoStartMaxDaysAgo))) {
            return null;
        }

        if (ChecklistTemplate::preferredFor((int) $employee->company_id, ChecklistKinds::Onboarding) === null) {
            return null;
        }

        $actor = Auth::user() instanceof User ? Auth::user() : null;

        return $this->open($employee, ChecklistKinds::Onboarding, null, $actor, [
            'anchor_date'  => $joining->toDateString(),
            'auto_started' => true,
        ], notify: $actor !== null);
    }

    /** HR's "Start onboarding": returns the open onboarding when there already is one. */
    public function startOnboarding(Employee $employee, User $actor, ?int $templateId = null): EmployeeChecklist
    {
        $this->assertHr($actor, $employee);

        if ($existing = $this->openChecklist($employee, ChecklistKinds::Onboarding)) {
            return $existing;
        }

        $anchor = $employee->joining_date !== null
            ? CarbonImmutable::parse($employee->joining_date->toDateString(), ChecklistKinds::timezone())
            : ChecklistKinds::today();

        return $this->open($employee, ChecklistKinds::Onboarding, $this->template($employee, ChecklistKinds::Onboarding, $templateId), $actor, [
            'anchor_date' => $anchor->toDateString(),
        ], notify: true);
    }

    /**
     * HR's "Start exit".
     *
     * @param  array{last_working_day?: mixed, leaving_date?: mixed, notice_period_days?: mixed, departure_reason_id?: mixed, departure_description?: mixed, template_id?: mixed}  $data
     */
    public function startExit(Employee $employee, User $actor, array $data): EmployeeChecklist
    {
        $this->assertHr($actor, $employee);

        $data = Validator::make($data, [
            'last_working_day'      => ['required', 'date'],
            'leaving_date'          => ['required', 'date', 'after_or_equal:last_working_day'],
            'notice_period_days'    => ['nullable', 'integer', 'min:0', 'max:365'],
            'departure_reason_id'   => ['nullable', 'integer', Rule::exists('employees_departure_reasons', 'id')],
            'departure_description' => ['nullable', 'string', 'max:2000'],
            'template_id'           => ['nullable', 'integer'],
        ], [
            'leaving_date.after_or_equal' => 'The leaving date cannot be before the last working day.',
        ])->validate();

        $lastWorkingDay = CarbonImmutable::parse($data['last_working_day'], ChecklistKinds::timezone())->startOfDay();
        $leavingDate = CarbonImmutable::parse($data['leaving_date'], ChecklistKinds::timezone())->startOfDay();

        if ($employee->joining_date !== null && $lastWorkingDay->toDateString() < $employee->joining_date->toDateString()) {
            throw ValidationException::withMessages(['last_working_day' => 'The last working day cannot be before the joining date.']);
        }

        if ($this->openChecklist($employee, ChecklistKinds::Offboarding)) {
            throw ValidationException::withMessages(['leaving_date' => 'An exit is already in progress for this employee.']);
        }

        $template = $this->template($employee, ChecklistKinds::Offboarding, isset($data['template_id']) ? (int) $data['template_id'] : null);

        return DB::transaction(function () use ($employee, $actor, $data, $lastWorkingDay, $leavingDate, $template): EmployeeChecklist {
            $previous = [
                'leaving_date'          => $employee->leaving_date?->toDateString(),
                'departure_reason_id'   => $employee->departure_reason_id,
                'departure_description' => $employee->departure_description,
                'departure_date'        => $employee->departure_date,
                'employment_status'     => $employee->employment_status,
            ];

            $employee->forceFill([
                'leaving_date'          => $leavingDate->toDateString(),
                'departure_reason_id'   => $data['departure_reason_id'] ?? null,
                'departure_description' => $data['departure_description'] ?? null,
                'departure_date'        => $leavingDate->toDateString(),
                'employment_status'     => 'notice',
            ])->save();

            $checklist = $this->open($employee, ChecklistKinds::Offboarding, $template, $actor, [
                'anchor_date'           => $lastWorkingDay->toDateString(),
                'last_working_day'      => $lastWorkingDay->toDateString(),
                'leaving_date'          => $leavingDate->toDateString(),
                'notice_period_days'    => $data['notice_period_days'] ?? null,
                'departure_reason_id'   => $data['departure_reason_id'] ?? null,
                'departure_description' => $data['departure_description'] ?? null,
                'previous_values'       => $previous,
            ], notify: true);

            $this->audit($employee, 'exit_started', 'Exit started by '.$actor->name.': last working day '.$lastWorkingDay->format('d M Y')
                .', leaving date '.$leavingDate->format('d M Y').'. The user account stays active until it is disabled from the exit checklist.', $actor);

            return $checklist;
        });
    }

    /**
     * Cancel an open checklist. For an exit (re-hire / resignation
     * withdrawn) this is only allowed before the leaving date and restores
     * the employee's previous leaving details; a login the checklist
     * disabled is switched on again.
     */
    public function cancel(EmployeeChecklist $checklist, User $actor, ?string $reason = null): EmployeeChecklist
    {
        $employee = $checklist->employee;

        if (! $employee instanceof Employee || (int) $employee->company_id !== (int) $checklist->company_id) {
            throw new AuthorizationException('This checklist does not belong to the employee\'s company.');
        }

        $this->assertHr($actor, $employee);

        if (! $checklist->isOpen()) {
            throw ValidationException::withMessages(['checklist' => 'Only an open checklist can be cancelled.']);
        }

        if ($checklist->type === ChecklistKinds::Offboarding && ! $this->canUndoExit($checklist)) {
            throw ValidationException::withMessages(['checklist' => 'The leaving date has passed; the exit can no longer be undone. Re-hire the person as a new joiner instead.']);
        }

        return DB::transaction(function () use ($checklist, $employee, $actor, $reason): EmployeeChecklist {
            $checklist = EmployeeChecklist::query()->whereKey($checklist->getKey())->lockForUpdate()->firstOrFail();

            EmployeeChecklistTask::query()->where('checklist_id', $checklist->getKey())->where('status', ChecklistKinds::TaskPending)->update([
                'status'     => ChecklistKinds::TaskCancelled,
                'updated_at' => now(),
            ]);

            $checklist->forceFill([
                'status'        => ChecklistKinds::ChecklistCancelled,
                'open_key'      => null,
                'cancelled_at'  => now(),
                'cancelled_by'  => $actor->getKey(),
                'cancel_reason' => $reason,
            ])->save();

            if ($checklist->type !== ChecklistKinds::Offboarding) {
                $this->audit($employee, 'onboarding_cancelled', 'Onboarding checklist cancelled by '.$actor->name.($reason ? ': '.$reason : '.'), $actor);

                return $checklist;
            }

            $previous = (array) $checklist->previous_values;
            $employee->forceFill(array_intersect_key($previous, array_flip([
                'leaving_date', 'departure_reason_id', 'departure_description', 'departure_date', 'employment_status',
            ])))->save();

            $message = 'Exit undone by '.$actor->name.($reason ? ' ('.$reason.')' : '').'; the previous leaving details were restored.';

            if ($checklist->user_disabled_at !== null && $checklist->disabled_user_id) {
                $user = User::query()->find($checklist->disabled_user_id);

                if ($user instanceof User && ! $user->is_active) {
                    $user->forceFill(['is_active' => true])->saveQuietly();
                    $message .= ' The user account '.$user->email.' was switched on again.';
                }
            }

            $this->audit($employee, 'exit_cancelled', $message, $actor);

            return $checklist;
        });
    }

    /** An exit can be undone while its leaving date is still ahead. */
    public function canUndoExit(EmployeeChecklist $checklist): bool
    {
        $leaving = $checklist->leaving_date ?? $checklist->last_working_day;

        return $checklist->isOpen()
            && $checklist->type === ChecklistKinds::Offboarding
            && ($leaving === null || ChecklistKinds::today()->toDateString() < $leaving->toDateString());
    }

    public function complete(EmployeeChecklistTask $task, User $actor, ?string $note = null): EmployeeChecklistTask
    {
        return $this->finish($task, $actor, ChecklistKinds::TaskDone, $note);
    }

    public function skip(EmployeeChecklistTask $task, User $actor, ?string $note): EmployeeChecklistTask
    {
        if (blank($note)) {
            throw ValidationException::withMessages(['note' => 'Say why the task is skipped.']);
        }

        return $this->finish($task, $actor, ChecklistKinds::TaskSkipped, $note);
    }

    /** HR: back to "to do". */
    public function reopen(EmployeeChecklistTask $task, User $actor): EmployeeChecklistTask
    {
        if (! $this->policy->manage($actor, $task)) {
            throw new AuthorizationException('Only HR can reopen a checklist task.');
        }

        if (! in_array($task->status, [ChecklistKinds::TaskDone, ChecklistKinds::TaskSkipped], true)
            || $task->checklist?->status === ChecklistKinds::ChecklistCancelled) {
            throw ValidationException::withMessages(['task' => 'Only a done or skipped task of a running checklist can be reopened.']);
        }

        $task->forceFill([
            'status'               => ChecklistKinds::TaskPending,
            'completion_source'    => null,
            'completed_by'         => null,
            'completed_at'         => null,
            'employee_document_id' => null,
        ])->save();

        $this->refreshStatus($task->checklist);

        return $task;
    }

    /** HR: hand a task to someone else of the company, or back to the HR queue (null). */
    public function reassign(EmployeeChecklistTask $task, User $actor, ?int $userId): EmployeeChecklistTask
    {
        if (! $this->policy->manage($actor, $task)) {
            throw new AuthorizationException('Only HR can reassign a checklist task.');
        }

        $assignee = null;

        if ($userId !== null) {
            $memberId = $this->activeMember($userId, (int) $task->company_id);
            $assignee = $memberId !== null ? User::query()->find($memberId) : null;

            if (! $assignee instanceof User) {
                throw ValidationException::withMessages(['assignee_id' => 'Choose an active user of this company.']);
            }
        }

        $task->forceFill(['assignee_id' => $assignee?->getKey()])->save();

        if ($assignee instanceof User && $task->isPending() && (int) $assignee->getKey() !== (int) $actor->getKey()) {
            $this->notifier->taskAssigned($task, $assignee);
        }

        return $task;
    }

    /**
     * The one-click "Disable user on last working day": the login is
     * switched off by hr:checklist-disable-users on that day, not now.
     */
    public function scheduleUserDisable(EmployeeChecklistTask $task, User $actor): EmployeeChecklistTask
    {
        if (! $this->policy->update($actor, $task)) {
            throw new AuthorizationException('You may not work on this task.');
        }

        $checklist = $task->checklist;
        $employee = $task->employee;

        if ($task->action_key !== ChecklistKinds::ActionDisableUser || ! $task->isPending()
            || ! $checklist?->isOpen() || $checklist->type !== ChecklistKinds::Offboarding) {
            throw ValidationException::withMessages(['task' => 'Only the open "disable user" task of a running exit can be scheduled.']);
        }

        if (! $employee?->user_id) {
            throw ValidationException::withMessages(['task' => 'This employee has no user account to disable. Mark the task done instead.']);
        }

        $day = $checklist->last_working_day ?? $checklist->anchor_date;
        $email = (string) User::query()->whereKey($employee->user_id)->value('email');

        $task->forceFill([
            'scheduled_for' => $day->toDateString(),
            'scheduled_by'  => $actor->getKey(),
            'note'          => 'User account '.$email.' will be disabled on '.$day->format('d M Y').' (scheduled by '.$actor->name.').',
        ])->save();

        $this->audit($employee, 'user_disable_scheduled', 'User account '.$email.' is scheduled to be disabled on the last working day, '.$day->format('d M Y').', by '.$actor->name.'.', $actor);

        return $task;
    }

    public function unscheduleUserDisable(EmployeeChecklistTask $task, User $actor): EmployeeChecklistTask
    {
        if (! $this->policy->update($actor, $task) || $task->scheduled_for === null || ! $task->isPending()) {
            throw new AuthorizationException('There is no scheduled disable you may cancel.');
        }

        $task->forceFill(['scheduled_for' => null, 'scheduled_by' => null, 'note' => null])->save();

        if ($task->employee instanceof Employee) {
            $this->audit($task->employee, 'user_disable_unscheduled', 'Scheduled disabling of the user account cancelled by '.$actor->name.'.', $actor);
        }

        return $task;
    }

    /**
     * hr:checklist-disable-users: switch off the logins scheduled for today
     * or earlier. Each task is claimed under a row lock, so a re-run or an
     * overlap never acts twice; nothing happens before the scheduled day.
     *
     * @return array{disabled: int, already_inactive: int, tasks: int}
     */
    public function runScheduledUserDisables(?string $date = null, ?int $companyId = null): array
    {
        $today = $date !== null ? CarbonImmutable::parse($date, ChecklistKinds::timezone())->startOfDay() : ChecklistKinds::today();
        $result = ['disabled' => 0, 'already_inactive' => 0, 'tasks' => 0];

        $ids = EmployeeChecklistTask::query()
            ->where('action_key', ChecklistKinds::ActionDisableUser)
            ->pending()
            ->whereNotNull('scheduled_for')
            ->whereDate('scheduled_for', '<=', $today->toDateString())
            ->whereHas('checklist', fn (Builder $checklists) => $checklists->where('status', ChecklistKinds::ChecklistOpen))
            ->when($companyId, fn (Builder $query) => $query->where('company_id', $companyId))
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            try {
                $outcome = DB::transaction(fn (): ?string => $this->disableScheduledUser((int) $id, $today));
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if ($outcome === null) {
                continue;
            }

            $result['tasks']++;
            $result[$outcome === 'disabled' ? 'disabled' : 'already_inactive']++;
        }

        return $result;
    }

    /** Employee document uploaded: complete the open tasks waiting for that document type. */
    public function completeForDocument(EmployeeDocument $document): int
    {
        if (! $document->document_type_id || ! $document->employee_id) {
            return 0;
        }

        $tasks = EmployeeChecklistTask::query()
            ->where('employee_id', $document->employee_id)
            ->where('company_id', $document->company_id)
            ->where('document_type_id', $document->document_type_id)
            ->pending()
            ->whereHas('checklist', fn (Builder $checklists) => $checklists->where('status', ChecklistKinds::ChecklistOpen))
            ->get();

        foreach ($tasks as $task) {
            $task->forceFill([
                'status'               => ChecklistKinds::TaskDone,
                'completion_source'    => ChecklistKinds::SourceDocument,
                'completed_by'         => $document->uploaded_by,
                'completed_at'         => now(),
                'employee_document_id' => $document->getKey(),
                'note'                 => 'Completed when '.($document->original_name ?: 'the document').' was uploaded.',
            ])->save();
        }

        $this->refreshChecklists($tasks);

        return $tasks->count();
    }

    /** HR letter request saved: an issued experience letter completes the exit's letter task. */
    public function completeForLetterRequest(EmployeeRequest $request): int
    {
        $payload = (array) ($request->payload ?? []);

        if (($payload['letter_type'] ?? null) !== 'experience_letter' || blank($payload['letter_issued_at'] ?? null) || ! $request->employee_id) {
            return 0;
        }

        return $this->completeSystemTasks((int) $request->employee_id, ChecklistKinds::ActionExperienceLetter, 'Experience letter issued'.($request->reference ? ' ('.$request->reference.')' : '').'.');
    }

    /** A work schedule was assigned: "Add to attendance schedule" is done. */
    public function completeForWorkSchedule(int $employeeId): int
    {
        return $this->completeSystemTasks($employeeId, ChecklistKinds::ActionAttendanceSchedule, 'Work schedule assigned.');
    }

    /**
     * Employee saved: a linked user account, line manager or working hours
     * complete their tasks, and tasks waiting for "the employee" or "the
     * line manager" go to the person just linked.
     */
    public function syncWithEmployee(Employee $employee): void
    {
        // A create never reports changes (the auto start already saw the new record); an update does.
        if (! $employee->wasChanged(['user_id', 'parent_id', 'calendar_id'])) {
            return;
        }

        if (! EmployeeChecklist::query()->where('employee_id', $employee->getKey())->where('status', ChecklistKinds::ChecklistOpen)->exists()) {
            return;
        }

        if ($employee->wasChanged('user_id') && $employee->user_id) {
            $this->completeSystemTasks((int) $employee->getKey(), ChecklistKinds::ActionCreateUser, 'User account linked.');
            $this->handOver($employee, ChecklistKinds::RoleEmployee, (int) $employee->user_id, onlyUnassigned: true);
        }

        if ($employee->wasChanged('parent_id') && $employee->parent_id) {
            $this->completeSystemTasks((int) $employee->getKey(), ChecklistKinds::ActionAssignManager, 'Line manager set.');
            $managerUserId = Employee::query()->whereKey($employee->parent_id)->value('user_id');

            if ($managerUserId) {
                $this->handOver($employee, ChecklistKinds::RoleLineManager, (int) $managerUserId, onlyUnassigned: false);
            }
        }

        if ($employee->wasChanged('calendar_id') && $employee->calendar_id) {
            $this->completeSystemTasks((int) $employee->getKey(), ChecklistKinds::ActionAttendanceSchedule, 'Working hours set.');
        }
    }

    /**
     * Tasks the user should work on in a company: assigned to them, and the
     * HR queue for the company's HR (all of it for HR approvers, the
     * employees in their HR scope for other hr_manage_onboarding holders).
     *
     * @return Builder<EmployeeChecklistTask>
     */
    public function myTasksQuery(User $user, int $companyId): Builder
    {
        $query = EmployeeChecklistTask::query()->where('company_id', $companyId)
            ->where('status', '!=', ChecklistKinds::TaskCancelled);

        if (! CurrentCompany::canAccess($companyId, $user)) {
            return $query->whereRaw('1 = 0');
        }

        $companyHr = $this->policy->isCompanyHr($user, $companyId);
        $scopedIds = ! $companyHr && $user->can(HrPermissions::ManageChecklists)
            ? $this->hierarchy->visibleEmployeeIds($user, $companyId)->all()
            : null;

        return $query->where(function (Builder $inner) use ($user, $companyHr, $scopedIds): void {
            $inner->where('assignee_id', $user->getKey());

            if ($companyHr) {
                $inner->orWhereNull('assignee_id');
            } elseif ($scopedIds !== null) {
                $inner->orWhere(fn (Builder $queue) => $queue->whereNull('assignee_id')->whereIn('employee_id', $scopedIds ?: [0]));
            }
        });
    }

    /** @return array{open: int, overdue: int} open (to do) and overdue tasks of the user in the company, in one query */
    public function openTaskCounts(User $user, int $companyId): array
    {
        $row = $this->myTasksQuery($user, $companyId)
            ->where('status', ChecklistKinds::TaskPending)
            ->toBase()
            ->selectRaw('count(*) as open_count, coalesce(sum(case when due_date < ? then 1 else 0 end), 0) as overdue_count', [ChecklistKinds::today()->toDateString()])
            ->first();

        return ['open' => (int) ($row->open_count ?? 0), 'overdue' => (int) ($row->overdue_count ?? 0)];
    }

    /** The employee's loan and salary advance requests that are still pending or approved. */
    public function openAdvanceRequests(Employee $employee): EloquentCollection
    {
        return EmployeeRequest::query()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->getKey())
            ->whereIn('status', ['pending_approval', 'approved'])
            ->whereHas('requestType', fn (Builder $types) => $types->whereIn('category', [EmployeeRequestKinds::LoanCategory, EmployeeRequestKinds::SalaryAdvanceCategory]))
            ->with('requestType:id,name,category')
            ->orderBy('created_at')
            ->get();
    }

    public function openChecklist(Employee $employee, string $type): ?EmployeeChecklist
    {
        return EmployeeChecklist::query()
            ->where('open_key', EmployeeChecklist::openKey((int) $employee->getKey(), $type))
            ->first();
    }

    /**
     * Create the checklist and its tasks, complete what is already done, and
     * tell the assignees.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function open(Employee $employee, string $type, ?ChecklistTemplate $template, ?User $actor, array $attributes, bool $notify): EmployeeChecklist
    {
        $template ??= ChecklistTemplate::preferredFor((int) $employee->company_id, $type);

        if (! $template instanceof ChecklistTemplate) {
            throw ValidationException::withMessages(['template_id' => 'This company has no active '.mb_strtolower(ChecklistKinds::typeLabel($type)).' checklist template.']);
        }

        try {
            [$checklist, $tasks] = DB::transaction(function () use ($employee, $type, $template, $actor, $attributes): array {
                $checklist = EmployeeChecklist::query()->create($attributes + [
                    'company_id'  => $employee->company_id,
                    'employee_id' => $employee->getKey(),
                    'template_id' => $template->getKey(),
                    'type'        => $type,
                    'status'      => ChecklistKinds::ChecklistOpen,
                    'open_key'    => EmployeeChecklist::openKey((int) $employee->getKey(), $type),
                    'started_by'  => $actor?->getKey(),
                ]);

                $anchor = CarbonImmutable::parse($checklist->anchor_date->toDateString(), ChecklistKinds::timezone());
                $tasks = new Collection;

                foreach ($template->tasks()->get() as $templateTask) {
                    $tasks->push(EmployeeChecklistTask::query()->create([
                        'company_id'       => $employee->company_id,
                        'checklist_id'     => $checklist->getKey(),
                        'employee_id'      => $employee->getKey(),
                        'template_task_id' => $templateTask->getKey(),
                        'code'             => $templateTask->code,
                        'title'            => $templateTask->title,
                        'description'      => $templateTask->description,
                        'responsible_role' => $templateTask->responsible_role,
                        'assignee_id'      => $this->assigneeFor($templateTask, $employee),
                        'due_date'         => $this->dueDate($templateTask, $employee, $anchor)->toDateString(),
                        'document_type_id' => $templateTask->document_type_id,
                        'action_key'       => $templateTask->action_key,
                        'status'           => ChecklistKinds::TaskPending,
                        'sort'             => $templateTask->sort,
                    ]));
                }

                $this->completeWhatIsAlreadyDone($employee, $tasks);

                return [$checklist, $tasks];
            });
        } catch (UniqueConstraintViolationException) {
            return $this->openChecklist($employee, $type)
                ?? throw ValidationException::withMessages(['checklist' => 'This checklist was just started by someone else.']);
        }

        $checklist->setRelation('employee', $employee);
        $this->refreshStatus($checklist);

        if ($notify) {
            $tasks->each(fn (EmployeeChecklistTask $task) => $task->setRelation('checklist', $checklist)->setRelation('employee', $employee));
            $this->notifier->tasksCreated($checklist, $tasks, $actor);
        }

        if ($type === ChecklistKinds::Onboarding) {
            $this->audit($employee, 'onboarding_started', 'Onboarding checklist started'.($actor ? ' by '.$actor->name : ' automatically').' ('.$tasks->count().' tasks).', $actor);
        }

        return $checklist;
    }

    protected function template(Employee $employee, string $type, ?int $templateId): ?ChecklistTemplate
    {
        if ($templateId === null) {
            return null;
        }

        $template = ChecklistTemplate::query()
            ->whereKey($templateId)
            ->where('company_id', $employee->company_id)
            ->where('type', $type)
            ->where('is_active', true)
            ->first();

        if (! $template instanceof ChecklistTemplate) {
            throw ValidationException::withMessages(['template_id' => 'Choose an active template of this company.']);
        }

        return $template;
    }

    /** The responsible person, or null for the company's HR queue. */
    protected function assigneeFor(ChecklistTemplateTask $templateTask, Employee $employee): ?int
    {
        $userId = match ($templateTask->responsible_role) {
            ChecklistKinds::RoleLineManager => $employee->parent_id ? Employee::query()->whereKey($employee->parent_id)->value('user_id') : null,
            ChecklistKinds::RoleEmployee    => $employee->user_id,
            default                         => $templateTask->responsible_user_id,
        };

        return $this->activeMember($userId ? (int) $userId : null, (int) $employee->company_id);
    }

    /**
     * The user, when active and a member of the company (default company or
     * an explicit membership). Queried directly rather than through
     * CurrentCompany, whose per-request membership cache is meant for the
     * signed-in user, not for the people a task is handed to.
     */
    protected function activeMember(?int $userId, int $companyId): ?int
    {
        if ($userId === null) {
            return null;
        }

        $isMember = CurrentCompany::scopeUsersOf(User::query(), $companyId)
            ->whereKey($userId)
            ->where('is_active', true)
            ->exists();

        return $isMember ? $userId : null;
    }

    /** Offset from the anchor (probation review: a week before the probation end), never before the start day. */
    protected function dueDate(ChecklistTemplateTask $templateTask, Employee $employee, CarbonImmutable $anchor): CarbonImmutable
    {
        $due = $templateTask->action_key === ChecklistKinds::ActionProbationReview && $employee->probation_end_date !== null
            ? CarbonImmutable::parse($employee->probation_end_date->toDateString(), ChecklistKinds::timezone())->subDays(7)
            : $anchor->addDays((int) $templateTask->due_offset_days);

        $today = ChecklistKinds::today();

        return $due->lt($today) ? $today : $due;
    }

    /** @param  Collection<int, EmployeeChecklistTask>  $tasks */
    protected function completeWhatIsAlreadyDone(Employee $employee, Collection $tasks): void
    {
        $documentTypeIds = $tasks->pluck('document_type_id')->filter()->unique()->values();
        $documents = $documentTypeIds->isEmpty()
            ? collect()
            : EmployeeDocument::query()
                ->where('employee_id', $employee->getKey())
                ->whereIn('document_type_id', $documentTypeIds)
                ->whereNull('replaced_by_id')
                ->orderByDesc('id')
                ->get(['id', 'document_type_id', 'uploaded_by'])
                ->unique('document_type_id')
                ->keyBy('document_type_id');

        foreach ($tasks as $task) {
            $document = $task->document_type_id ? $documents->get($task->document_type_id) : null;

            [$source, $note] = match (true) {
                $document !== null                                                                       => [ChecklistKinds::SourceDocument, 'The document was already on file.'],
                $task->action_key === ChecklistKinds::ActionCreateUser && $employee->user_id             => [ChecklistKinds::SourceSystem, 'User account already linked.'],
                $task->action_key === ChecklistKinds::ActionAssignManager && $employee->parent_id        => [ChecklistKinds::SourceSystem, 'Line manager already set.'],
                $task->action_key === ChecklistKinds::ActionAttendanceSchedule && $employee->calendar_id => [ChecklistKinds::SourceSystem, 'Working hours already set.'],
                default                                                                                  => [null, null],
            };

            if ($source === null) {
                continue;
            }

            $task->forceFill([
                'status'               => ChecklistKinds::TaskDone,
                'completion_source'    => $source,
                'completed_at'         => now(),
                'employee_document_id' => $document?->getKey(),
                'note'                 => $note,
            ])->save();
        }
    }

    protected function finish(EmployeeChecklistTask $task, User $actor, string $status, ?string $note): EmployeeChecklistTask
    {
        if (! $this->policy->update($actor, $task)) {
            throw new AuthorizationException('You may not work on this task.');
        }

        $task = DB::transaction(function () use ($task, $actor, $status, $note): EmployeeChecklistTask {
            $locked = EmployeeChecklistTask::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPending() || ! $locked->checklist?->isOpen()) {
                throw ValidationException::withMessages(['task' => 'This task is no longer open.']);
            }

            $locked->forceFill([
                'status'            => $status,
                'completion_source' => ChecklistKinds::SourceManual,
                'completed_by'      => $actor->getKey(),
                'completed_at'      => now(),
                'note'              => filled($note) ? trim((string) $note) : $locked->note,
            ])->save();

            return $locked;
        });

        $this->refreshStatus($task->checklist);

        return $task;
    }

    /** Complete the open tasks of one kind (action) of an employee. */
    protected function completeSystemTasks(int $employeeId, string $action, string $note): int
    {
        $tasks = EmployeeChecklistTask::query()
            ->where('employee_id', $employeeId)
            ->where('action_key', $action)
            ->pending()
            ->whereHas('checklist', fn (Builder $checklists) => $checklists->where('status', ChecklistKinds::ChecklistOpen))
            ->get();

        foreach ($tasks as $task) {
            $task->forceFill([
                'status'            => ChecklistKinds::TaskDone,
                'completion_source' => ChecklistKinds::SourceSystem,
                'completed_by'      => Auth::id(),
                'completed_at'      => now(),
                'note'              => $note,
            ])->save();
        }

        $this->refreshChecklists($tasks);

        return $tasks->count();
    }

    /** Give the open tasks of a role to the person just linked (only those still in the HR queue when $onlyUnassigned). */
    protected function handOver(Employee $employee, string $role, int $userId, bool $onlyUnassigned): void
    {
        $assigneeId = $this->activeMember($userId, (int) $employee->company_id);

        if ($assigneeId === null) {
            return;
        }

        $tasks = EmployeeChecklistTask::query()
            ->where('employee_id', $employee->getKey())
            ->where('responsible_role', $role)
            ->pending()
            ->when($onlyUnassigned, fn (Builder $query) => $query->whereNull('assignee_id'))
            ->where(fn (Builder $query) => $query->whereNull('assignee_id')->orWhere('assignee_id', '!=', $assigneeId))
            ->whereHas('checklist', fn (Builder $checklists) => $checklists->where('status', ChecklistKinds::ChecklistOpen))
            ->with('checklist')
            ->get();

        if ($tasks->isEmpty()) {
            return;
        }

        EmployeeChecklistTask::query()->whereKey($tasks->modelKeys())->update(['assignee_id' => $assigneeId, 'updated_at' => now()]);

        if (! Auth::user() instanceof User) {
            return;
        }

        $assignee = User::query()->find($assigneeId);

        foreach ($tasks as $task) {
            $task->assignee_id = $assigneeId;
            $task->setRelation('employee', $employee);

            if ($assignee instanceof User && (int) $assigneeId !== (int) Auth::id()) {
                $this->notifier->taskAssigned($task, $assignee);
            }
        }
    }

    /** @return 'disabled'|'already_inactive'|null */
    protected function disableScheduledUser(int $taskId, CarbonImmutable $today): ?string
    {
        $task = EmployeeChecklistTask::query()->whereKey($taskId)->lockForUpdate()->first();

        if (! $task instanceof EmployeeChecklistTask || ! $task->isPending() || $task->scheduled_for === null
            || $task->scheduled_for->toDateString() > $today->toDateString() || ! $task->checklist?->isOpen()) {
            return null;
        }

        $checklist = $task->checklist;
        $employee = $task->employee;
        $user = $employee?->user_id ? User::query()->find($employee->user_id) : null;
        $scheduler = $task->scheduled_by ? User::query()->find($task->scheduled_by) : null;
        $wasActive = $user instanceof User && $user->is_active;

        if ($wasActive) {
            $user->forceFill(['is_active' => false])->saveQuietly();

            $checklist->forceFill([
                'user_disabled_at' => now(),
                'disabled_user_id' => $user->getKey(),
            ])->save();
        }

        $note = $user instanceof User
            ? ($wasActive
                ? 'User account '.$user->email.' disabled automatically on '.$today->format('d M Y').($scheduler ? ' (scheduled by '.$scheduler->name.').' : '.')
                : 'User account '.$user->email.' was already disabled.')
            : 'No user account was linked any more.';

        $task->forceFill([
            'status'            => ChecklistKinds::TaskDone,
            'completion_source' => ChecklistKinds::SourceSystem,
            'completed_at'      => now(),
            'note'              => $note,
        ])->save();

        if ($employee instanceof Employee) {
            $this->audit($employee, 'user_disabled', $note, $scheduler);
        }

        $this->refreshStatus($checklist);

        return $wasActive ? 'disabled' : 'already_inactive';
    }

    /** Close the checklist once nothing is left to do (and open it again when a task is reopened). */
    protected function refreshStatus(?EmployeeChecklist $checklist): void
    {
        if (! $checklist instanceof EmployeeChecklist || $checklist->status === ChecklistKinds::ChecklistCancelled) {
            return;
        }

        $pending = $checklist->tasks()->where('status', ChecklistKinds::TaskPending)->exists();

        if (! $pending && $checklist->isOpen()) {
            $checklist->forceFill(['status' => ChecklistKinds::ChecklistCompleted, 'completed_at' => now(), 'open_key' => null])->save();

            return;
        }

        if ($pending && $checklist->status === ChecklistKinds::ChecklistCompleted) {
            try {
                $checklist->forceFill([
                    'status'       => ChecklistKinds::ChecklistOpen,
                    'completed_at' => null,
                    'open_key'     => EmployeeChecklist::openKey((int) $checklist->employee_id, $checklist->type),
                ])->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['task' => 'A newer '.mb_strtolower($checklist->typeLabel()).' checklist is already running for this employee.']);
            }
        }
    }

    /** @param  Collection<int, EmployeeChecklistTask>  $tasks */
    protected function refreshChecklists(Collection $tasks): void
    {
        EmployeeChecklist::query()
            ->whereKey($tasks->pluck('checklist_id')->unique()->values()->all())
            ->get()
            ->each(fn (EmployeeChecklist $checklist) => $this->refreshStatus($checklist));
    }

    protected function assertHr(User $actor, Employee $employee): void
    {
        if (! $this->policy->manageEmployee($actor, $employee)) {
            throw new AuthorizationException('Only HR of this employee\'s company can do this.');
        }
    }

    /** A line on the employee's chatter, so starts, undos and disabled logins stay on record. */
    protected function audit(Employee $employee, string $event, string $body, ?User $causer = null): void
    {
        try {
            $employee->addMessage([
                'type'        => 'notification',
                'body'        => e($body),
                'is_internal' => true,
                'log_name'    => 'employee_checklist',
                'event'       => $event,
                'company_id'  => $employee->company_id,
            ] + ($causer instanceof User ? ['causer_type' => $causer->getMorphClass(), 'causer_id' => $causer->getKey()] : []));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
