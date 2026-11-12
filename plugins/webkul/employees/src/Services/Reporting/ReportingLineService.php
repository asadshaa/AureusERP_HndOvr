<?php

namespace Webkul\Employee\Services\Reporting;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Webkul\Employee\Models\Employee;
use Webkul\Employee\Models\PerformanceReview;
use Webkul\Employee\Models\ReportingAssignment;
use Webkul\Employee\Models\ReportingChange;
use Webkul\Employee\Services\HrHierarchyService;
use Webkul\Employee\Support\AppraisalKinds;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Employee\Support\ReportingChain;
use Webkul\Employee\Support\ReportingPurposes;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\Support\Services\HierarchicalNotificationService;

/**
 * Who reports to whom, and who approves what, per person.
 *
 * A person's LINE MANAGER is employees_employees.parent_id. For one purpose
 * (leave, attendance, claims, advances, loans, overtime, appraisals,
 * recruitment) HR can give a different manager (employees_reporting_assignments);
 * a purpose without its own manager follows the line manager. The approval
 * engine reads the same resolution for every "requester_manager" step, with the
 * manager's manager as the next choice before HR (managerUserCandidates()).
 *
 * Every change is validated (same company, active, no loop, a purpose manager
 * needs a login), recorded in employees_reporting_changes and told to the people
 * it concerns, including whoever a pending approval now waits on. A change HR
 * makes to her own line waits for a second person (decide()).
 */
class ReportingLineService
{
    /** @var array<int, array<int, array<string, int>>> company id => employee id => purpose => manager employee id, for this request */
    private static array $assignmentMemo = [];

    public function __construct(
        protected HrHierarchyService $hierarchy,
        protected ApprovalEngine $approvals,
        protected HierarchicalNotificationService $notifications,
    ) {}

    public static function flushMemo(): void
    {
        self::$assignmentMemo = [];
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * The purpose managers of one employee. The whole company is read in one
     * query the first time, so a list of approvals does not run one query per person.
     *
     * @return array<string, int> purpose => manager employee id
     */
    public function assignmentsFor(Employee $employee): array
    {
        $companyId = (int) $employee->company_id;
        self::$assignmentMemo[$companyId] ??= $this->assignmentsForCompany($companyId);

        return self::$assignmentMemo[$companyId][(int) $employee->id] ?? [];
    }

    /**
     * Every purpose manager of a company in one query, for lists.
     *
     * @return array<int, array<string, int>> employee id => purpose => manager employee id
     */
    public function assignmentsForCompany(int $companyId): array
    {
        $rows = [];

        foreach (ReportingAssignment::query()->where('company_id', $companyId)->get(['employee_id', 'purpose', 'manager_employee_id']) as $row) {
            $rows[(int) $row->employee_id][$row->purpose] = (int) $row->manager_employee_id;
        }

        return $rows;
    }

    /** The manager set for this purpose only, or null when the line manager approves it. */
    public function purposeManager(Employee $employee, string $purpose): ?Employee
    {
        $managerId = $this->assignmentsFor($employee)[$purpose] ?? null;

        return $managerId ? Employee::query()->with('user')->find($managerId) : null;
    }

    public function hasOwnManager(Employee $employee, string $purpose): bool
    {
        return isset($this->assignmentsFor($employee)[$purpose]);
    }

    /** Who is the manager for a purpose: the one set for it, else the line manager (null purpose: the line manager). */
    public function managerFor(Employee $employee, ?string $purpose = null): ?Employee
    {
        if ($purpose !== null && ReportingPurposes::isValid($purpose)) {
            $own = $this->purposeManager($employee, $purpose);

            if ($own) {
                return $own;
            }
        }

        return $employee->parent;
    }

    /**
     * The login users a "requester_manager" approval step may route to, in
     * order: the purpose manager, the line manager, then the line manager's
     * manager. Closures, so the approval engine only loads the next one when
     * the one before it was not eligible.
     *
     * @return array<int, Closure(): ?User>
     */
    public function managerUserCandidates(Employee $employee, ?string $purpose): array
    {
        $candidates = [];

        if ($purpose !== null && $this->hasOwnManager($employee, $purpose)) {
            $candidates[] = fn (): ?User => $this->purposeManager($employee, $purpose)?->user;
        }

        $candidates[] = fn (): ?User => $employee->parent?->user;
        $candidates[] = fn (): ?User => $employee->parent?->parent?->user;

        return $candidates;
    }

    // ------------------------------------------------------------------
    // Authorisation
    // ------------------------------------------------------------------

    public function canManage(?User $user, ?int $companyId = null): bool
    {
        if (! $user) {
            return false;
        }

        $companyId ??= CurrentCompany::id($user);

        return $companyId !== null
            && $user->can(HrPermissions::ManageReportingLines)
            && CurrentCompany::canAccess((int) $companyId, $user);
    }

    // ------------------------------------------------------------------
    // Changing
    // ------------------------------------------------------------------

    /**
     * Sets the line manager (purpose null) or the manager for one purpose
     * (null manager: the line manager approves it again). Applied at once and
     * recorded, except HR changing her own line: that is recorded as pending
     * until another person decides it.
     *
     * @throws RuntimeException|InvalidArgumentException
     */
    public function change(User $actor, Employee $employee, ?string $purpose, ?int $managerEmployeeId, ?string $reason = null): ReportingChange
    {
        if (! $this->canManage($actor, (int) $employee->company_id)) {
            throw new RuntimeException('You may not change reporting lines in this company.');
        }
        if ($purpose !== null && ! ReportingPurposes::isValid($purpose)) {
            throw new InvalidArgumentException('Choose what the manager approves.');
        }

        $manager = $managerEmployeeId !== null ? Employee::query()->with('user')->find($managerEmployeeId) : null;
        if ($managerEmployeeId !== null && ! $manager) {
            throw new InvalidArgumentException('That manager could not be found.');
        }

        $this->assertValidManager($employee, $manager, $purpose);

        $current = $this->currentManagerId($employee, $purpose);
        if ($current === ($manager?->id)) {
            throw new RuntimeException($this->describe($employee, $purpose).' is already set to '.($manager?->name ?? ($purpose === null ? 'nobody' : 'the line manager')).'. Nothing to change.');
        }

        $isOwnLine = $employee->user_id !== null && (int) $employee->user_id === (int) $actor->id;

        $change = ReportingChange::query()->create([
            'company_id'              => $employee->company_id,
            'employee_id'             => $employee->id,
            'purpose'                 => $purpose,
            'old_manager_employee_id' => $current,
            'new_manager_employee_id' => $manager?->id,
            'status'                  => $isOwnLine ? ReportingChange::Pending : ReportingChange::Applied,
            'reason'                  => filled($reason) ? trim($reason) : null,
            'requested_by'            => $actor->id,
        ]);

        if ($isOwnLine) {
            $this->notifyPending($change, $employee);

            return $change;
        }

        return $this->apply($change, $employee, $manager, $actor);
    }

    /**
     * Another HR person or an admin decides a change HR made to her own line.
     *
     * @throws RuntimeException|InvalidArgumentException
     */
    public function decide(User $actor, ReportingChange $change, bool $approve, ?string $note = null): ReportingChange
    {
        // Always the full employee: a change taken from historyQuery() carries only a name.
        $change->load('employee');
        $employee = $change->employee;

        if ($change->status !== ReportingChange::Pending || ! $employee) {
            throw new RuntimeException('This change has already been decided.');
        }
        if (! $this->canManage($actor, (int) $change->company_id)) {
            throw new RuntimeException('You may not decide reporting line changes in this company.');
        }
        if ((int) $actor->id === (int) $change->requested_by || ($employee->user_id !== null && (int) $employee->user_id === (int) $actor->id)) {
            throw new RuntimeException('A change to your own reporting line must be decided by someone else.');
        }

        if (! $approve) {
            $change->update(['status' => ReportingChange::Rejected, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => filled($note) ? trim($note) : null]);
            $this->notifyDecision($change, false);

            return $change->fresh();
        }

        $manager = $change->new_manager_employee_id ? Employee::query()->with('user')->find($change->new_manager_employee_id) : null;
        $this->assertValidManager($employee, $manager, $change->purpose);

        return $this->apply($change, $employee, $manager, $actor, filled($note) ? trim($note) : null);
    }

    /**
     * Gives several people the same new manager (or purpose manager). Each
     * person is checked on their own; the people who could not be changed come
     * back with the reason instead of being skipped silently.
     *
     * @param  array<int, int>  $employeeIds
     * @return array{changed: int, failed: array<int, array{employee: string, reason: string}>}
     */
    public function reassign(User $actor, array $employeeIds, ?string $purpose, ?int $managerEmployeeId, ?string $reason = null): array
    {
        $changed = 0;
        $failed = [];

        foreach (Employee::query()->whereIn('id', $employeeIds)->orderBy('name')->get() as $employee) {
            try {
                $this->change($actor, $employee, $purpose, $managerEmployeeId, $reason);
                $changed++;
            } catch (RuntimeException|InvalidArgumentException $exception) {
                $failed[] = ['employee' => (string) $employee->name, 'reason' => $exception->getMessage()];
            }
        }

        return ['changed' => $changed, 'failed' => $failed];
    }

    // ------------------------------------------------------------------
    // History
    // ------------------------------------------------------------------

    /** @return Builder<ReportingChange> */
    public function historyQuery(int $companyId): Builder
    {
        return ReportingChange::query()
            ->where('company_id', $companyId)
            ->with(['employee:id,name', 'oldManager:id,name', 'newManager:id,name', 'requester:id,name', 'decider:id,name'])
            ->latest('id');
    }

    // ------------------------------------------------------------------
    // The approval flow of one person, with real names
    // ------------------------------------------------------------------

    /**
     * Every approval workflow of the person's company, step by step, with who
     * each step reaches for THIS person.
     *
     * @return array<int, array{purpose: ?string, purpose_label: string, flows: array<int, array{request_type: string, label: string, name: string, steps: array<int, array{sequence: int, name: string, who: string}>}>}>
     */
    public function flowFor(Employee $employee): array
    {
        $employee->loadMissing(['parent.user', 'department.manager.user', 'team.manager.user', 'user']);

        $groups = [];

        $workflows = ApprovalWorkflow::query()
            ->where('company_id', $employee->company_id)
            ->where('is_active', true)
            ->with(['steps' => fn ($steps) => $steps->orderBy('sequence')])
            ->orderBy('request_type')
            ->get();

        foreach ($workflows as $workflow) {
            $purpose = ReportingPurposes::forRequestType($workflow->request_type);
            $key = $purpose ?? '_other';

            $groups[$key]['purpose'] = $purpose;
            $groups[$key]['purpose_label'] = $purpose === null ? 'Everything else' : ReportingPurposes::label($purpose);
            $groups[$key]['flows'][] = [
                'request_type' => $workflow->request_type,
                'label'        => ReportingPurposes::requestTypeLabel($workflow->request_type),
                'name'         => (string) $workflow->name,
                'steps'        => $workflow->steps->map(fn (ApprovalStep $step): array => [
                    'sequence' => (int) $step->sequence,
                    'name'     => (string) $step->name,
                    'who'      => $this->describeStep($employee, $step, $purpose),
                ])->all(),
            ];
        }

        $order = array_flip([...ReportingPurposes::keys(), '_other']);
        uksort($groups, fn ($a, $b): int => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

        return array_values($groups);
    }

    /** Who a step reaches for this person, in plain words. */
    public function describeStep(Employee $employee, ApprovalStep $step, ?string $purpose): string
    {
        if ($step->approver_user_id) {
            $user = User::query()->find($step->approver_user_id);

            return $user ? $user->name.($user->is_active ? '' : ' (inactive: HR steps in)') : 'A person who no longer exists';
        }

        if ($step->approver_role_id) {
            $role = DB::table('roles')->where('id', $step->approver_role_id)->value('name');

            return 'Anyone with the role "'.($role ?? 'unknown').'"';
        }

        return match ($step->hierarchy_route) {
            'requester_manager'  => $this->describeManagerStep($employee, $purpose),
            'department_manager' => $employee->department?->manager?->name ? $employee->department->manager->name.' (department head)' : 'The department head (none set: HR steps in)',
            'team_manager'       => $employee->team?->manager?->name ? $employee->team->manager->name.' (team lead)' : 'The team lead (none set: HR steps in)',
            'record_manager'     => 'The manager named on the record (for example the hiring manager)',
            'hr'                 => $this->describeHr($employee),
            default              => 'Not set',
        };
    }

    private function describeManagerStep(Employee $employee, ?string $purpose): string
    {
        $own = $purpose !== null ? $this->purposeManager($employee, $purpose) : null;

        if ($own?->user?->is_active) {
            return $own->name.' ('.ReportingPurposes::label($purpose).' manager)';
        }

        $line = $employee->parent;
        if ($line?->user?->is_active) {
            return $line->name.' (line manager)';
        }

        $above = $line?->parent;
        if ($above?->user?->is_active) {
            return $above->name." (line manager's manager)";
        }

        return 'No manager with a login: HR approves';
    }

    private function describeHr(Employee $employee): string
    {
        $names = $this->approvals->resolveHrApproversForCompany((int) $employee->company_id, $employee->user_id)->pluck('name');

        if ($names->isEmpty()) {
            return 'HR approvers (none set up yet)';
        }

        return 'HR: '.$names->take(3)->implode(', ').($names->count() > 3 ? ' and '.($names->count() - 3).' more' : '');
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function currentManagerId(Employee $employee, ?string $purpose): ?int
    {
        if ($purpose === null) {
            return $employee->parent_id !== null ? (int) $employee->parent_id : null;
        }

        return $this->assignmentsFor($employee)[$purpose] ?? null;
    }

    private function describe(Employee $employee, ?string $purpose): string
    {
        return $purpose === null ? $employee->name."'s line manager" : $employee->name."'s ".mb_strtolower(ReportingPurposes::label($purpose)).' manager';
    }

    /** @throws InvalidArgumentException */
    private function assertValidManager(Employee $employee, ?Employee $manager, ?string $purpose): void
    {
        if ($manager === null) {
            return;
        }

        if ((int) $manager->id === (int) $employee->id) {
            throw new InvalidArgumentException($employee->name.' cannot be their own manager.');
        }
        if ((int) $manager->company_id !== (int) $employee->company_id) {
            throw new InvalidArgumentException($manager->name.' works in another company. Choose someone from '.$employee->name."'s company.");
        }
        if (! $manager->is_active) {
            throw new InvalidArgumentException($manager->name.' is not an active employee.');
        }
        if (ReportingChain::reportsTo((int) $manager->id, (int) $employee->id)) {
            throw new InvalidArgumentException($manager->name.' already reports to '.$employee->name.' (directly or through others), so cannot also be their manager. Choose someone else.');
        }
        if ($purpose !== null && ! $manager->user?->is_active) {
            throw new InvalidArgumentException($manager->name.' has no active login, so cannot approve '.mb_strtolower(ReportingPurposes::label($purpose)).'. Choose someone with a login.');
        }
    }

    private function apply(ReportingChange $change, Employee $employee, ?Employee $manager, User $actor, ?string $decisionNote = null): ReportingChange
    {
        DB::transaction(function () use ($change, $employee, $manager, $actor, $decisionNote): void {
            $purpose = $change->purpose;

            if ($purpose === null) {
                $employee->parent_id = $manager?->id;
                $employee->save();
            } elseif ($manager === null) {
                ReportingAssignment::query()->where('employee_id', $employee->id)->where('purpose', $purpose)->delete();
            } else {
                ReportingAssignment::query()->updateOrCreate(
                    ['employee_id' => $employee->id, 'purpose' => $purpose],
                    ['company_id' => $employee->company_id, 'manager_employee_id' => $manager->id, 'created_by' => $actor->id],
                );
            }

            self::flushMemo();
            $employee->unsetRelation('parent');

            if ($purpose === ReportingPurposes::Leave) {
                // Keeps the leave-manager column the Time Off screens read in step with the approver.
                $employee->save();
            }

            if ($purpose === ReportingPurposes::Appraisal || $purpose === null) {
                $this->moveOpenAppraisals($employee);
            }

            $change->forceFill([
                'status'        => ReportingChange::Applied,
                'applied_at'    => now(),
                'decided_by'    => $change->status === ReportingChange::Pending ? $actor->id : $change->decided_by,
                'decided_at'    => $change->status === ReportingChange::Pending ? now() : $change->decided_at,
                'decision_note' => $decisionNote ?? $change->decision_note,
            ])->save();

            $this->hierarchy->flushCache();
        });

        DB::afterCommit(fn () => $this->notifyApplied($change->fresh(), $employee->fresh()));

        return $change->fresh();
    }

    /** Appraisals not yet reviewed follow the employee's current appraisal manager. */
    private function moveOpenAppraisals(Employee $employee): void
    {
        $reviewer = $this->managerFor($employee->fresh(), ReportingPurposes::Appraisal);

        PerformanceReview::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', [AppraisalKinds::StatusSelfReview, AppraisalKinds::StatusManagerReview])
            ->update(['reviewer_id' => $reviewer?->id]);
    }

    // ------------------------------------------------------------------
    // Telling people
    // ------------------------------------------------------------------

    private function notifyApplied(ReportingChange $change, Employee $employee): void
    {
        $change->loadMissing(['oldManager.user', 'newManager.user']);
        $what = $change->purpose === null ? 'line manager' : mb_strtolower(ReportingPurposes::label($change->purpose)).' approver';
        $name = $employee->name;

        $this->tell($employee->user, $change, 'employee', 'Your '.$what.' changed', $change->newManager
            ? 'Your '.$what.' is now '.$change->newManager->name.'.'
            : 'Your '.$what.' is now your line manager.');

        if ($change->newManager?->user) {
            $this->tell($change->newManager->user, $change, 'new', $name.' now reports to you', 'You are now '.$name."'s ".$what.'. Requests of theirs for this go to you.');
        }

        if ($change->oldManager?->user && (int) $change->oldManager->user->id !== (int) $change->newManager?->user?->id) {
            $this->tell($change->oldManager->user, $change, 'old', $name.' no longer reports to you', 'You are no longer '.$name."'s ".$what.'. Anything of theirs still waiting for you now goes to the new approver.');
        }

        $this->notifyRerouted($change, $employee);
    }

    /** Pending approvals of this person whose manager step now reaches someone else: tell that person. */
    private function notifyRerouted(ReportingChange $change, Employee $employee): void
    {
        if ($employee->user_id === null) {
            return;
        }

        $pending = ApprovalRequest::query()
            ->where('company_id', $employee->company_id)
            ->where('status', 'pending')
            ->with(['workflow.steps', 'subject', 'requester.employee.parent.user'])
            ->get();

        foreach ($pending as $request) {
            if ((int) ($this->approvals->subjectEmployeeUserId($request) ?? 0) !== (int) $employee->user_id) {
                continue;
            }

            $step = $request->currentStep();
            if (! $step || $step->hierarchy_route !== 'requester_manager') {
                continue;
            }

            $requestPurpose = ReportingPurposes::forRequestType($request->request_type);
            if ($change->purpose !== null && $requestPurpose !== $change->purpose) {
                continue;
            }

            $approver = $this->approvals->hierarchyManagerFor($request, $step);
            if ($approver) {
                $this->tell($approver, $change, 'reroute-'.$request->id, $employee->name."'s ".ReportingPurposes::requestTypeLabel($request->request_type).' now waits for you', 'It was waiting on someone else; after a change of reporting line it is yours to decide.');
            }
        }
    }

    private function notifyPending(ReportingChange $change, Employee $employee): void
    {
        $approvers = User::query()
            ->where('is_active', true)
            ->where('id', '!=', $change->requested_by)
            ->get()
            ->filter(fn (User $user): bool => $this->canManage($user, (int) $change->company_id));

        foreach ($approvers as $user) {
            $this->tell($user, $change, 'pending-'.$user->id, $employee->name.' asked to change their own reporting line', 'Open Reporting lines, then History, to approve or reject it.');
        }
    }

    private function notifyDecision(ReportingChange $change, bool $approved): void
    {
        $change->loadMissing(['requester', 'employee']);

        $this->tell($change->requester, $change, 'decision', $approved ? 'Your reporting line change was approved' : 'Your reporting line change was rejected', $change->decision_note ?: ($approved ? 'It is in effect now.' : 'It was not applied.'));
    }

    private function tell(?User $user, ReportingChange $change, string $role, string $title, string $body): void
    {
        if (! $user) {
            return;
        }

        $this->notifications->sendIdempotentNotification(
            $user,
            Notification::make()->title($title)->body($body)->icon('heroicon-o-arrows-right-left')->color('info'),
            'reporting_change_'.$change->id.'_'.$role.'_'.$user->id,
            ['event' => 'reporting_line_change', 'reporting_change_id' => $change->id],
        );
    }

    /** All purposes with the manager each currently has for a person: purpose => [manager, own]. */
    public function summaryFor(Employee $employee): Collection
    {
        return collect(ReportingPurposes::keys())->mapWithKeys(fn (string $purpose): array => [
            $purpose => ['manager' => $this->managerFor($employee, $purpose), 'own' => $this->hasOwnManager($employee, $purpose)],
        ]);
    }
}
