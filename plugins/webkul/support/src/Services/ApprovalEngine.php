<?php

namespace Webkul\Support\Services;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use WeakMap;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Contracts\ReturnableApprovalSubject;
use Webkul\Support\Enums\ApprovalRequestStatus;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;

final class ApprovalEngine
{
    /**
     * Roles whose holders are HR people. Used for the HR fallback (no line /
     * department / team manager) and for "hr" steps. Plain manager roles are
     * deliberately absent: their permission bundle (HrPermissions::manager())
     * carries hr_manage_employee_requests / hr_approve_leave /
     * hr_manage_attendance so they can act on their OWN reports, which must
     * never make them an approver for every employee without a manager (D5).
     *
     * @var array<int, string>
     */
    public const HR_ROLE_NAMES = [
        'hr',
        'hr_manager',
        'hr manager',
        'hr_ops_manager',
        'hr ops manager',
        'hr operations manager',
        'hr_administrator',
        'hr administrator',
        'human resources',
        'human resources manager',
    ];

    /**
     * Company-wide HR visibility. On its own it is read-only (the HR auditor
     * bundle carries it), so it only makes someone an HR approver together
     * with one of HR_DECISION_PERMISSIONS on the same grant.
     *
     * @var array<int, string>
     */
    public const HR_APPROVER_PERMISSIONS = [
        'hr_view_all_records',
    ];

    /**
     * HR decision rights. Paired with HR_APPROVER_PERMISSIONS on one role (or
     * on the user's own direct permissions) they make an HR approver. Kept
     * apart from the visibility permission because a line manager's bundle
     * holds these for their own reports only, and an auditor's holds the
     * visibility only: neither may approve for the whole company.
     *
     * @var array<int, string>
     */
    public const HR_DECISION_PERMISSIONS = [
        'hr_approve_leave',
        'hr_manage_employee_requests',
        'hr_manage_attendance',
    ];

    /**
     * Per-object memos for the checks list screens repeat for every row and
     * action (canAct() for approve / reject / return, the HR approver and
     * company access checks of the same signed-in user). Keyed by the model
     * instance, so they live as long as that instance -- one page render --
     * and a re-fetched request or user is always checked afresh.
     *
     * @var WeakMap<ApprovalRequest, array<string, bool>>|null
     */
    private static ?WeakMap $canActMemo = null;

    /** @var WeakMap<User, array<int, bool>>|null */
    private static ?WeakMap $hrApproverMemo = null;

    /** @var WeakMap<User, array<int, bool>>|null */
    private static ?WeakMap $companyAccessMemo = null;

    /** @param array<string, mixed> $context */
    public function matchingWorkflow(int $companyId, string $requestType, ?string $amount = null, array $context = []): ?ApprovalWorkflow
    {
        return ApprovalWorkflow::query()
            ->forCompany($companyId)
            ->where('request_type', $requestType)
            ->where('is_active', true)
            ->with('steps')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get()
            ->first(function (ApprovalWorkflow $workflow) use ($amount, $context): bool {
                if (! $this->amountMatches($workflow, $amount)) {
                    return false;
                }

                return $this->conditionsMatch((array) $workflow->conditions, $context + ['amount' => $amount]);
            });
    }

    /** @param array<string, mixed> $context */
    public function submit(Model $subject, User $requester, string $requestType, ?string $amount = null, array $context = []): ApprovalRequest
    {
        $companyId = (int) ($context['company_id'] ?? $subject->getAttribute('company_id') ?? $requester->default_company_id);
        if ($companyId <= 0 || ! $this->userCanAccessCompany($requester, $companyId)) {
            throw new RuntimeException('The requester does not have access to the approval company.');
        }

        $workflow = $this->matchingWorkflow($companyId, $requestType, $amount, $context);
        if (! $workflow || $workflow->steps->isEmpty()) {
            throw new RuntimeException("No active approval workflow is configured for [{$requestType}] in this company.");
        }

        $firstStep = $workflow->steps
            ->first(fn (ApprovalStep $step): bool => $this->conditionsMatch((array) $step->conditions, $context + ['amount' => $amount]));
        if (! $firstStep) {
            throw new RuntimeException('The approval workflow has no applicable approval step.');
        }

        return DB::transaction(function () use ($subject, $requester, $requestType, $amount, $context, $companyId, $workflow, $firstStep): ApprovalRequest {
            $existing = ApprovalRequest::query()
                ->where('company_id', $companyId)
                ->where('request_type', $requestType)
                ->where('subject_type', $subject->getMorphClass())
                ->where('subject_id', $subject->getKey())
                ->whereIn('status', [ApprovalRequestStatus::Pending->value, ApprovalRequestStatus::Returned->value])
                ->lockForUpdate()
                ->latest('id')
                ->first();
            // A request returned for changes is the same request coming back:
            // resubmitting it keeps one audit trail instead of opening a second
            // request next to the returned one (resubmit() checks the requester).
            if ($existing?->status === ApprovalRequestStatus::Returned->value) {
                return $this->resubmit($existing, $requester, $amount, $context);
            }
            if ($existing) {
                // A pending request already exists for this exact subject. Returning it
                // unchanged used to silently discard whatever new $context/$amount this
                // caller just submitted — a second requester's intended changes vanished
                // with no error and no trace. Fail loudly instead: the caller must resolve
                // (approve/reject) the existing request before a new one can be submitted.
                throw new RuntimeException(
                    "A pending approval request already exists for this {$requestType} — it must be approved, rejected, or withdrawn before a new one can be submitted."
                );
            }

            return ApprovalRequest::query()->create([
                'company_id'              => $companyId,
                'workflow_id'             => $workflow->id,
                'requester_id'            => $requester->id,
                'subject_type'            => $subject->getMorphClass(),
                'subject_id'              => $subject->getKey(),
                'request_type'            => $requestType,
                'amount'                  => $amount,
                'context'                 => $context,
                'status'                  => 'pending',
                'current_step_sequence'   => $firstStep->sequence,
                'submission_round'        => 1,
                'submitted_at'            => now(),
                'current_step_started_at' => now(),
            ])->fresh(['workflow.steps', 'requester']);
        });
    }

    /**
     * Sends a request that was returned for changes back into approval. It
     * restarts at the FIRST applicable step of the workflow (re-matched, as the
     * changed amount or context may now match another one) in a new
     * submission round: approvals given before the return no longer count, so
     * every step sees the changed request. Only the original requester may
     * resubmit. Recorded as a "resubmitted" decision -- with what changed when
     * the caller passes it -- so the whole return / edit / resubmit cycle stays
     * in the audit trail. A null $amount or $context keeps the submitted one.
     *
     * @param  array<string, mixed>|null  $context
     * @param  array<string, mixed>  $previousValues
     * @param  array<string, mixed>  $newValues
     */
    public function resubmit(
        ApprovalRequest $request,
        User $requester,
        ?string $amount = null,
        ?array $context = null,
        ?string $note = null,
        array $previousValues = [],
        array $newValues = [],
    ): ApprovalRequest {
        return DB::transaction(function () use ($request, $requester, $amount, $context, $note, $previousValues, $newValues): ApprovalRequest {
            $locked = ApprovalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ApprovalRequestStatus::Returned->value) {
                throw new RuntimeException('Only a request returned for changes can be resubmitted.');
            }
            if (! $this->canResubmit($locked, $requester)) {
                throw new RuntimeException('Only the person who submitted this request can resubmit it.');
            }

            $context ??= (array) $locked->context;
            $amount ??= $locked->amount !== null ? (string) $locked->amount : null;

            $workflow = $this->matchingWorkflow((int) $locked->company_id, (string) $locked->request_type, $amount, $context);
            if (! $workflow || $workflow->steps->isEmpty()) {
                throw new RuntimeException("No active approval workflow is configured for [{$locked->request_type}] in this company.");
            }

            $firstStep = $workflow->steps
                ->first(fn (ApprovalStep $step): bool => $this->conditionsMatch((array) $step->conditions, $context + ['amount' => $amount]));
            if (! $firstStep) {
                throw new RuntimeException('The approval workflow has no applicable approval step.');
            }

            $round = (int) ($locked->submission_round ?: 1) + 1;

            $locked->update([
                'workflow_id'             => $workflow->id,
                'amount'                  => $amount,
                'context'                 => $context,
                'status'                  => ApprovalRequestStatus::Pending->value,
                'current_step_sequence'   => $firstStep->sequence,
                'submission_round'        => $round,
                'current_step_started_at' => now(),
                'completed_at'            => null,
            ]);

            $locked->decisions()->create([
                'step_id'          => $firstStep->id,
                'submission_round' => $round,
                'actor_id'         => $requester->id,
                'decision'         => 'resubmitted',
                'reason'           => $this->cleanText($note),
                'previous_values'  => $previousValues,
                'new_values'       => $newValues,
                'decided_at'       => now(),
            ]);

            return $locked->fresh(['workflow.steps', 'requester']);
        });
    }

    /**
     * Whether the request's subject is wired for "Return to requester"
     * (editable while returned, with a resubmit path). returnToRequester()
     * itself works for any subject; the screens only offer it for these.
     */
    public function supportsReturn(ApprovalRequest $request): bool
    {
        return $request->subject instanceof ReturnableApprovalSubject;
    }

    /** A current-step approver of a request whose subject supports being returned. */
    public function canReturn(ApprovalRequest $request, User $actor): bool
    {
        return $this->canAct($request, $actor) && $this->supportsReturn($request);
    }

    /** The original requester, while the request is returned for changes. */
    public function canResubmit(ApprovalRequest $request, User $actor): bool
    {
        return $request->status === ApprovalRequestStatus::Returned->value
            && $request->requester_id !== null
            && (int) $request->requester_id === (int) $actor->id
            && $this->userCanAccessCompany($actor, (int) $request->company_id);
    }

    /**
     * The requester, or the employee the request is about, while it is still
     * pending or returned for changes.
     */
    public function canWithdraw(ApprovalRequest $request, User $actor): bool
    {
        if (! in_array($request->status, [ApprovalRequestStatus::Pending->value, ApprovalRequestStatus::Returned->value], true)
            || ! $this->userCanAccessCompany($actor, (int) $request->company_id)) {
            return false;
        }

        if ($request->requester_id !== null && (int) $request->requester_id === (int) $actor->id) {
            return true;
        }

        $subject = $request->subject;
        $employeeUserId = $subject && method_exists($subject, 'employee') ? $subject->employee?->user_id : null;

        return $employeeUserId !== null && (int) $employeeUserId === (int) $actor->id;
    }

    /**
     * Takes back a pending or returned request: closed as "withdrawn" (no one
     * can act on it any more), recorded as a "withdrawn" decision, the
     * subject synchronised, and the approvers of a pending step told.
     */
    public function withdraw(ApprovalRequest $request, User $actor, ?string $reason = null): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $actor, $reason): ApprovalRequest {
            $locked = ApprovalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $locked->loadMissing(['workflow.steps', 'subject']);
            if (! $this->canWithdraw($locked, $actor)) {
                throw new RuntimeException('Only the requester can withdraw a request, and only while it is pending or returned for changes.');
            }

            $step = $locked->currentStep() ?? $locked->workflow?->steps?->first();
            $approvers = $locked->status === ApprovalRequestStatus::Pending->value
                ? $this->resolveAuthorizedApprovers($locked)->reject(fn (User $user): bool => (int) $user->id === (int) $actor->id)->values()
                : collect();

            if ($step) {
                $locked->decisions()->create([
                    'step_id'          => $step->id,
                    'submission_round' => (int) ($locked->submission_round ?: 1),
                    'actor_id'         => $actor->id,
                    'decision'         => 'withdrawn',
                    'reason'           => $this->cleanText($reason),
                    'decided_at'       => now(),
                ]);
            }

            $locked->update(['status' => ApprovalRequestStatus::Withdrawn->value, 'completed_at' => now()]);

            DB::afterCommit(function () use ($locked, $actor, $reason, $approvers): void {
                app(HierarchicalNotificationService::class)->notifyRequestWithdrawn($locked, $actor, $reason, $approvers);
            });

            $fresh = $locked->fresh(['workflow.steps', 'decisions.actor']);
            app(ApprovalSubjectSynchronizer::class)->synchronize($fresh);

            return $fresh->fresh(['workflow.steps', 'decisions.actor']);
        });
    }

    /**
     * The manager a hierarchy step routes to (line, department or team
     * manager), or null when the step is not a hierarchy step or falls back
     * to HR because there is no such manager.
     */
    public function hierarchyManagerFor(ApprovalRequest $request, ApprovalStep $step): ?User
    {
        $subjectEmployee = $this->resolveHierarchySubjectEmployee($request);
        $techHeadUserId = $step->hierarchy_route === 'department_manager' && $request->request_type === 'claim_tech'
            ? $this->resolveTechHeadUserId($request)
            : null;

        $manager = match ($step->hierarchy_route) {
            'requester_manager'  => $subjectEmployee?->parent?->user,
            'department_manager' => $techHeadUserId ? User::query()->find($techHeadUserId) : $subjectEmployee?->department?->manager?->user,
            'team_manager'       => $subjectEmployee?->team?->manager?->user,
            default              => null,
        };

        if (! $manager instanceof User || ! $manager->is_active
            || (int) $manager->id === (int) $subjectEmployee?->user_id
            || ! $this->userCanAccessCompany($manager, (int) $request->company_id)) {
            return null;
        }

        return $manager;
    }

    /** User id of the employee the request is about (the subject's employee, else the requester's). */
    public function subjectEmployeeUserId(ApprovalRequest $request): ?int
    {
        $userId = $this->resolveHierarchySubjectEmployee($request)?->user_id;

        return $userId !== null ? (int) $userId : null;
    }

    public function resolveAuthorizedApprovers(ApprovalRequest $request, ?ApprovalStep $step = null): Collection
    {
        $request->loadMissing([
            'workflow.steps',
            'requester.employee.parent.user',
            'requester.employee.department.manager.user',
            'requester.employee.team.manager.user',
            'subject',
        ]);

        $step = $step ?? $request->currentStep();
        if (! $step) {
            return collect();
        }

        $companyId = (int) $request->company_id;

        if ($step->approver_user_id) {
            $user = User::query()->whereKey($step->approver_user_id)->where('is_active', true)->first();
            if ($user && $this->userCanAccessCompany($user, $companyId)) {
                return collect([$user]);
            }

            return collect();
        }

        if ($step->approver_role_id) {
            if ($request->status !== 'pending') {
                return collect();
            }

            return CurrentCompany::scopeUsersOf(User::query(), $companyId)
                ->where('is_active', true)
                ->whereHas('roles', fn ($q) => $q->where('roles.id', $step->approver_role_id))
                ->orderBy('id')
                ->get();
        }

        $subjectEmployee = $this->resolveHierarchySubjectEmployee($request);

        if ($step->hierarchy_route === 'hr') {
            return $this->resolveHrApproversForCompany($companyId, $subjectEmployee?->user_id);
        }

        if ($step->hierarchy_route === 'requester_manager') {
            $manager = $subjectEmployee?->parent?->user;
            if ($manager && $manager->is_active && $this->userCanAccessCompany($manager, $companyId) && (int) $manager->id !== (int) $subjectEmployee?->user_id) {
                return collect([$manager]);
            }

            return $this->resolveHrApproversForCompany($companyId, $subjectEmployee?->user_id);
        }

        if ($step->hierarchy_route === 'department_manager') {
            if ($request->request_type === 'claim_tech') {
                $techHeadUserId = $this->resolveTechHeadUserId($request);
                if ($techHeadUserId) {
                    $techHeadUser = User::query()->whereKey($techHeadUserId)->where('is_active', true)->first();
                    if ($techHeadUser && $this->userCanAccessCompany($techHeadUser, $companyId)) {
                        return collect([$techHeadUser]);
                    }
                }
            }

            $deptManager = $subjectEmployee?->department?->manager?->user;
            if ($deptManager && $deptManager->is_active && $this->userCanAccessCompany($deptManager, $companyId) && (int) $deptManager->id !== (int) $subjectEmployee?->user_id) {
                return collect([$deptManager]);
            }

            return $this->resolveHrApproversForCompany($companyId, $subjectEmployee?->user_id);
        }

        if ($step->hierarchy_route === 'team_manager') {
            $teamManager = $subjectEmployee?->team?->manager?->user;
            if ($teamManager && $teamManager->is_active && $this->userCanAccessCompany($teamManager, $companyId) && (int) $teamManager->id !== (int) $subjectEmployee?->user_id) {
                return collect([$teamManager]);
            }

            return $this->resolveHrApproversForCompany($companyId, $subjectEmployee?->user_id);
        }

        return collect();
    }

    /**
     * The HR people of one company: active users who belong to it (default
     * company or an explicit membership -- a group owner's implicit access to
     * every company does not count) and hold an HR role, or a role (or direct
     * permissions) granting both company-wide HR visibility and an HR decision
     * right. One query; line managers (decision rights without company-wide
     * visibility) and read-only HR auditors (visibility without decision
     * rights) are never included (D5).
     */
    public function resolveHrApproversForCompany(int $companyId, ?int $excludeUserId = null): Collection
    {
        return $this->hrApproversQuery($companyId, $excludeUserId)->orderBy('id')->get();
    }

    /** Whether $user is one of $companyId's HR approvers (see resolveHrApproversForCompany()). */
    public function isCompanyHrApprover(User $user, int $companyId): bool
    {
        self::$hrApproverMemo ??= new WeakMap;
        $memo = self::$hrApproverMemo[$user] ?? [];

        if (! array_key_exists($companyId, $memo)) {
            $memo[$companyId] = $this->hrApproversQuery($companyId)->whereKey($user->id)->exists();
            self::$hrApproverMemo[$user] = $memo;
        }

        return $memo[$companyId];
    }

    /** @return Builder<User> */
    public function hrApproversQuery(int $companyId, ?int $excludeUserId = null): Builder
    {
        return CurrentCompany::scopeUsersOf(User::query(), $companyId)
            ->where('is_active', true)
            ->when($excludeUserId, fn (Builder $query) => $query->whereKeyNot($excludeUserId))
            ->where(fn (Builder $query) => $query
                ->whereHas('roles', fn (Builder $roles) => $roles->whereIn('name', self::HR_ROLE_NAMES))
                ->orWhereHas('roles', fn (Builder $roles) => $this->grantsHrDecisions($roles))
                ->orWhere(fn (Builder $directPermissions) => $this->grantsHrDecisions($directPermissions)));
    }

    /**
     * Constrains a permission holder (a role, or a user's direct permissions)
     * to one that carries company-wide HR visibility AND an HR decision right.
     * Requiring both on the same holder keeps a line manager who is also an
     * HR auditor out: neither of their roles makes them a company approver.
     *
     * @param  Builder<Model>  $holder
     * @return Builder<Model>
     */
    private function grantsHrDecisions(Builder $holder): Builder
    {
        return $holder
            ->whereHas('permissions', fn (Builder $permissions) => $permissions->whereIn('name', self::HR_APPROVER_PERMISSIONS))
            ->whereHas('permissions', fn (Builder $permissions) => $permissions->whereIn('name', self::HR_DECISION_PERMISSIONS));
    }

    /**
     * Pending requests of a company that $actor can act on right now. Same
     * answer as filtering with canAct() one by one, but everything canAct()
     * reads (workflow steps, requester and subject hierarchy chains, the
     * actor's roles) is loaded in a few batched queries instead of several
     * queries per request. Used by the dashboard action center and the queue
     * badge; decide() still re-checks canAct() on the locked row.
     *
     * @return Collection<int, ApprovalRequest>
     */
    public function actionableRequestsFor(User $actor, int $companyId): Collection
    {
        if (! $this->userCanAccessCompany($actor, $companyId)) {
            return collect();
        }

        $actor->loadMissing('roles');

        $requests = ApprovalRequest::query()
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->with([
                'workflow.steps',
                'requester.employee.parent.user',
                'requester.employee.department.manager.user',
                'requester.employee.team.manager.user',
                'subject',
            ])
            ->get();

        // Subjects with an employee (leave, employee requests...): load that
        // employee's chain once per subject type.
        $requests->pluck('subject')->filter()->groupBy(fn ($subject): string => $subject::class)
            ->each(function ($subjects): void {
                if (method_exists($subjects->first(), 'employee')) {
                    (new \Illuminate\Database\Eloquent\Collection($subjects->all()))
                        ->loadMissing(['employee.parent.user', 'employee.department.manager.user', 'employee.team.manager.user']);
                }
            });

        return $requests->filter(fn (ApprovalRequest $request): bool => $this->canAct($request, $actor))->values();
    }

    /**
     * Memoised per request instance, actor and request state (status, step,
     * round): a list row asks it for each of its approve / reject / return
     * buttons, and Filament evaluates visibility more than once per render.
     */
    public function canAct(ApprovalRequest $request, User $actor): bool
    {
        self::$canActMemo ??= new WeakMap;
        $key = implode(':', [(int) $actor->id, (string) $request->status, (int) $request->current_step_sequence, (int) ($request->submission_round ?: 1)]);
        $memo = self::$canActMemo[$request] ?? [];

        if (! array_key_exists($key, $memo)) {
            $memo[$key] = $this->resolveCanAct($request, $actor);
            self::$canActMemo[$request] = $memo;
        }

        return $memo[$key];
    }

    private function resolveCanAct(ApprovalRequest $request, User $actor): bool
    {
        if ($request->status !== 'pending' || ! $this->userCanAccessCompany($actor, (int) $request->company_id)) {
            return false;
        }

        $request->loadMissing([
            'workflow.steps',
            'requester.employee.parent.user',
            'requester.employee.department.manager.user',
            'requester.employee.team.manager.user',
            'subject',
        ]);
        $step = $request->currentStep();
        if (! $step) {
            return false;
        }

        // Deliberately no Admin/Super Admin bypass here: every step must be
        // decided by its actual matched approver (the named user, the
        // matching role, or the resolved hierarchy manager) -- no account,
        // including Admin, may approve on someone else's behalf. An Admin
        // still sees and can manage every request elsewhere in the app; they
        // just can't stand in for a step that isn't theirs.
        if ((int) $step->approver_user_id === (int) $actor->id) {
            return true;
        }
        if ($step->approver_role_id && ($actor->relationLoaded('roles')
            ? $actor->roles->contains('id', $step->approver_role_id)
            : $actor->roles()->whereKey($step->approver_role_id)->exists())) {
            return true;
        }
        if ($request->request_type === 'employee_sensitive_change' && $this->isHrApprover($actor, (int) $request->company_id)) {
            return true;
        }

        // Hierarchy-based steps ("requester_manager" etc.) must route off the
        // employee the request is ABOUT, not whoever happened to click
        // Submit -- HR routinely submits a request on an employee's behalf
        // (e.g. an HR reviewer filing a claim for someone), and in that case
        // the requester's own manager is the wrong person entirely.
        $subjectEmployee = $this->resolveHierarchySubjectEmployee($request);

        return match ($step->hierarchy_route) {
            'hr'                 => $this->canActAsHr($subjectEmployee, $actor, $request),
            'requester_manager'  => $this->canActAsRequesterManager($subjectEmployee, $actor, $request),
            'department_manager' => $this->canActAsDepartmentManager($subjectEmployee, $actor, $request),
            'team_manager'       => $this->canActAsTeamManager($subjectEmployee, $actor, $request),
            default              => false,
        };
    }

    /** An "hr" step: any HR approver of the request's company except the employee the request is about. */
    private function canActAsHr(mixed $subjectEmployee, User $actor, ApprovalRequest $request): bool
    {
        if ($subjectEmployee?->user_id && (int) $subjectEmployee->user_id === (int) $actor->id) {
            return false;
        }

        return $this->isCompanyHrApprover($actor, (int) $request->company_id);
    }

    private function canActAsRequesterManager(mixed $subjectEmployee, User $actor, ApprovalRequest $request): bool
    {
        $managerUserId = $subjectEmployee?->parent?->user_id;

        if ($managerUserId) {
            return (int) $managerUserId === (int) $actor->id;
        }

        // If no line manager is assigned (or line manager has no user account),
        // fallback to HR / Admin for review and approval.
        if ($subjectEmployee?->user_id && (int) $subjectEmployee->user_id === (int) $actor->id) {
            return false;
        }

        return $this->isCompanyHrApprover($actor, (int) $request->company_id);
    }

    private function resolveTechHeadUserId(ApprovalRequest $request): ?int
    {
        return DB::table('employees_departments')
            ->join('employees_employees', 'employees_departments.manager_id', '=', 'employees_employees.id')
            ->where('employees_departments.company_id', $request->company_id)
            ->where(function ($query) {
                $query->where('employees_departments.name', 'like', '%Engineering%')
                    ->orWhere('employees_departments.name', 'like', '%Tech%');
            })
            ->whereNotNull('employees_employees.user_id')
            ->value('employees_employees.user_id');
    }

    private function canActAsDepartmentManager(mixed $subjectEmployee, User $actor, ApprovalRequest $request): bool
    {
        if ($request->request_type === 'claim_tech') {
            $techHeadUserId = $this->resolveTechHeadUserId($request);

            if ($techHeadUserId) {
                return (int) $techHeadUserId === (int) $actor->id;
            }
        }

        $deptManagerUserId = $subjectEmployee?->department?->manager?->user_id;

        if ($deptManagerUserId) {
            return (int) $deptManagerUserId === (int) $actor->id;
        }

        if ($subjectEmployee?->user_id && (int) $subjectEmployee->user_id === (int) $actor->id) {
            return false;
        }

        return $this->isCompanyHrApprover($actor, (int) $request->company_id);
    }

    private function canActAsTeamManager(mixed $subjectEmployee, User $actor, ApprovalRequest $request): bool
    {
        $teamManagerUserId = $subjectEmployee?->team?->manager?->user_id;

        if ($teamManagerUserId) {
            return (int) $teamManagerUserId === (int) $actor->id;
        }

        if ($subjectEmployee?->user_id && (int) $subjectEmployee->user_id === (int) $actor->id) {
            return false;
        }

        return $this->isCompanyHrApprover($actor, (int) $request->company_id);
    }

    /**
     * Who may decide an employee_sensitive_change at any step: HR roles, the
     * sensitive-data custodian, and the company's HR approvers. Company-wide
     * HR visibility alone (the read-only HR auditor) is not enough.
     */
    private function isHrApprover(User $actor, int $companyId): bool
    {
        return $actor->hasRole([
            'hr',
            'Hr',
            'hr_manager',
            'Hr_manager',
            'hr manager',
            'hr_ops_manager',
            'hr ops manager',
            'hr operations manager',
            'hr_administrator',
            'hr administrator',
            'human resources',
            'human resources manager',
            'sensitive_data_custodian',
            'Sensitive_data_custodian',
        ])
        || $actor->can('hr_manage_sensitive_employee_data')
        || $this->isCompanyHrApprover($actor, $companyId);
    }

    /**
     * A short, human-readable "who is this waiting on right now" sentence for the
     * request's current step, for notifications shown right after submission (e.g.
     * "Forwarded to Accounting Manager.") instead of a vague "pending" status.
     */
    public function describeCurrentApprover(ApprovalRequest $request): string
    {
        $request->loadMissing([
            'workflow.steps.approverUser',
            'workflow.steps.approverRole',
            'requester.employee.parent.user',
            'requester.employee.department.manager.user',
            'requester.employee.team.manager.user',
            'subject',
        ]);

        $step = $request->currentStep();
        if (! $step) {
            return 'Forwarded for approval.';
        }

        if ($step->approver_user_id) {
            return $step->approverUser?->name
                ? "Forwarded to {$step->approverUser->name}."
                : 'Forwarded for approval.';
        }

        if ($step->approver_role_id) {
            return $step->approverRole?->name
                ? "Forwarded to {$step->approverRole->name}."
                : 'Forwarded for approval.';
        }

        $subjectEmployee = $this->resolveHierarchySubjectEmployee($request);
        $techHeadUser = null;
        if ($step->hierarchy_route === 'department_manager' && $request->request_type === 'claim_tech') {
            $techHeadUserId = $this->resolveTechHeadUserId($request);
            if ($techHeadUserId) {
                $techHeadUser = User::find($techHeadUserId);
            }
        }

        $manager = match ($step->hierarchy_route) {
            'hr'                 => null,
            'requester_manager'  => $subjectEmployee?->parent?->user,
            'department_manager' => $techHeadUser ?? $subjectEmployee?->department?->manager?->user,
            'team_manager'       => $subjectEmployee?->team?->manager?->user,
            default              => null,
        };
        if ($manager?->name) {
            return "Forwarded to {$manager->name}.";
        }

        return match ($step->hierarchy_route) {
            'hr'                 => 'Forwarded to HR.',
            'requester_manager'  => 'Forwarded to HR (no line manager assigned).',
            'department_manager' => 'Forwarded to HR (no department manager assigned).',
            'team_manager'       => 'Forwarded to HR (no team manager assigned).',
            default              => 'Forwarded for approval.',
        };
    }

    /**
     * The subject of an approval request is often the employee-relevant
     * record itself (e.g. HR's EmployeeRequest has its own `employee`
     * relation); fall back to the requester's own employee record for
     * subject types with no such concept.
     */
    /**
     * Deliberately untyped (not `?Employee`) -- this base "support" plugin
     * must not take a hard dependency on the "employees" plugin's model;
     * duck-typing via method_exists() above is what keeps this generic
     * across any subject type that happens to expose an `employee()`
     * relation, HR's EmployeeRequest today, potentially others later.
     */
    private function resolveHierarchySubjectEmployee(ApprovalRequest $request): mixed
    {
        $subject = $request->subject;

        if ($subject && method_exists($subject, 'employee')) {
            if (! $subject->relationLoaded('employee')) {
                // Kept on the subject so the next check of this request does not query again.
                $subject->setRelation('employee', $subject->employee()->with(['parent.user', 'department.manager.user', 'team.manager.user'])->first());
            }
            $employee = $subject->employee?->loadMissing(['parent.user', 'department.manager.user', 'team.manager.user']);
            if ($employee) {
                return $employee;
            }
        }

        return $request->requester?->employee;
    }

    /** @param array<string, mixed> $previousValues @param array<string, mixed> $newValues */
    public function approve(
        ApprovalRequest $request,
        User $actor,
        ?string $reason = null,
        array $previousValues = [],
        array $newValues = [],
    ): ApprovalRequest {
        return $this->decide($request, $actor, 'approved', $reason, $previousValues, $newValues);
    }

    /** @param array<string, mixed> $previousValues @param array<string, mixed> $newValues */
    public function reject(
        ApprovalRequest $request,
        User $actor,
        string $reason,
        array $previousValues = [],
        array $newValues = [],
    ): ApprovalRequest {
        if (trim($reason) === '') {
            throw new RuntimeException('A rejection reason is required.');
        }

        return $this->decide($request, $actor, 'rejected', $reason, $previousValues, $newValues);
    }

    /**
     * "Return to requester": instead of approving or rejecting, a current-step
     * approver sends the request back for changes with a required comment.
     * The request becomes "returned" (nobody can act on it, it is not
     * pending), the requester is told, and the subject is synchronised so it
     * becomes editable again. resubmit() restarts it at the first step.
     *
     * @param  array<string, mixed>  $previousValues
     * @param  array<string, mixed>  $newValues
     */
    public function returnToRequester(
        ApprovalRequest $request,
        User $actor,
        string $comment,
        array $previousValues = [],
        array $newValues = [],
    ): ApprovalRequest {
        if (trim($comment) === '') {
            throw new RuntimeException('Say what needs to change: a comment is required to return a request.');
        }

        return $this->decide($request, $actor, 'returned', trim($comment), $previousValues, $newValues);
    }

    /** @param array<string, mixed> $previousValues @param array<string, mixed> $newValues */
    private function decide(
        ApprovalRequest $request,
        User $actor,
        string $decision,
        ?string $reason,
        array $previousValues,
        array $newValues,
    ): ApprovalRequest {
        return DB::transaction(function () use ($request, $actor, $decision, $reason, $previousValues, $newValues): ApprovalRequest {
            $request = ApprovalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $request->loadMissing('workflow.steps');
            if (! $this->canAct($request, $actor)) {
                throw new RuntimeException('This user is not an approver for the current approval step.');
            }

            $step = $request->currentStep() ?? throw new RuntimeException('The approval request has no current step.');
            // Only this submission round counts: after a return and resubmit the
            // same approver decides the restarted step again.
            $round = (int) ($request->submission_round ?: 1);
            if ($request->decisions()
                ->where('step_id', $step->id)
                ->where('submission_round', $round)
                ->where('actor_id', $actor->id)
                ->whereIn('decision', ['approved', 'rejected', 'returned'])
                ->exists()) {
                throw new RuntimeException('This user has already decided the current approval step.');
            }

            $request->decisions()->create([
                'step_id'          => $step->id,
                'submission_round' => $round,
                'actor_id'         => $actor->id,
                'decision'         => $decision,
                'reason'           => $reason,
                'previous_values'  => $previousValues,
                'new_values'       => $newValues,
                'decided_at'       => now(),
            ]);

            if ($decision === 'rejected') {
                $request->update(['status' => 'rejected', 'completed_at' => now()]);

                DB::afterCommit(function () use ($request, $actor, $reason): void {
                    app(HierarchicalNotificationService::class)->notifyRequestRejected($request, $actor, $reason ?? '');
                });
            } elseif ($decision === 'returned') {
                // current_step_sequence is kept: it records the step that returned it.
                $request->update(['status' => ApprovalRequestStatus::Returned->value]);

                DB::afterCommit(function () use ($request, $actor, $reason, $step): void {
                    app(HierarchicalNotificationService::class)->notifyRequestReturned($request, $actor, (string) $reason, $step);
                });
            } else {
                $approvalCount = $request->decisions()
                    ->where('step_id', $step->id)
                    ->where('submission_round', $round)
                    ->where('decision', 'approved')
                    ->distinct('actor_id')
                    ->count('actor_id');

                if ($approvalCount >= $step->required_approvals) {
                    $nextStep = $request->workflow->steps
                        ->where('sequence', '>', $step->sequence)
                        ->first(fn (ApprovalStep $candidate): bool => $this->conditionsMatch(
                            (array) $candidate->conditions,
                            (array) $request->context + ['amount' => $request->amount],
                        ));
                    $request->update($nextStep ? [
                        'current_step_sequence'   => $nextStep->sequence,
                        'current_step_started_at' => now(),
                    ] : [
                        'status'                => 'approved',
                        'current_step_sequence' => null,
                        'completed_at'          => now(),
                    ]);

                    if ($nextStep) {
                        DB::afterCommit(function () use ($request, $step, $nextStep, $actor): void {
                            app(HierarchicalNotificationService::class)->notifyStepAdvanced($request, $step, $nextStep, $actor);
                        });
                    } else {
                        DB::afterCommit(function () use ($request, $actor): void {
                            app(HierarchicalNotificationService::class)->notifyRequestApproved($request, $actor);
                        });
                    }
                }
            }

            $request = $request->fresh(['workflow.steps', 'decisions.actor']);

            // Applying the subject-side effect of a decision (e.g. writing an
            // approved sensitive-change to the Employee, or flipping a Leave to
            // validate_two) used to run AFTER this transaction committed. If it
            // failed — e.g. the subject's company changed since submission — the
            // request was left permanently showing its new status with the
            // actual change never applied, and nothing anywhere could retry it.
            // Running it inside the same transaction means a failure here rolls
            // the whole decision back instead of leaving that inconsistency.
            app(ApprovalSubjectSynchronizer::class)->synchronize($request);

            return $request->fresh(['workflow.steps', 'decisions.actor']);
        });
    }

    private function amountMatches(ApprovalWorkflow $workflow, ?string $amount): bool
    {
        if ($workflow->minimum_amount === null && $workflow->maximum_amount === null) {
            return true;
        }
        if ($amount === null) {
            return false;
        }

        $value = BigDecimal::of($amount);

        return ! ($workflow->minimum_amount !== null && $value->isLessThan($workflow->minimum_amount))
            && ! ($workflow->maximum_amount !== null && $value->isGreaterThan($workflow->maximum_amount));
    }

    /** @param array<int, array<string, mixed>> $conditions @param array<string, mixed> $context */
    private function conditionsMatch(array $conditions, array $context): bool
    {
        foreach ($conditions as $condition) {
            $actual = data_get($context, (string) ($condition['field'] ?? ''));
            $expected = $condition['value'] ?? null;
            $matches = match ($condition['operator'] ?? 'equals') {
                'equals'     => (string) $actual === (string) $expected,
                'not_equals' => (string) $actual !== (string) $expected,
                'in'         => in_array((string) $actual, array_map('strval', (array) $expected), true),
                'gte'        => BigDecimal::of((string) $actual)->isGreaterThanOrEqualTo((string) $expected),
                'lte'        => BigDecimal::of((string) $actual)->isLessThanOrEqualTo((string) $expected),
                default      => false,
            };
            if (! $matches) {
                return false;
            }
        }

        return true;
    }

    public function userCanAccessCompany(User $user, int $companyId): bool
    {
        if ((int) $user->default_company_id === $companyId) {
            return true;
        }

        self::$companyAccessMemo ??= new WeakMap;
        $memo = self::$companyAccessMemo[$user] ?? [];

        if (! array_key_exists($companyId, $memo)) {
            $memo[$companyId] = $user->allowedCompanies()->whereKey($companyId)->exists();
            self::$companyAccessMemo[$user] = $memo;
        }

        return $memo[$companyId];
    }

    /** Forgets the per-object memos (after changing roles or company access of an already loaded user). */
    public static function flushMemo(): void
    {
        self::$canActMemo = null;
        self::$hrApproverMemo = null;
        self::$companyAccessMemo = null;
    }

    private function cleanText(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}
