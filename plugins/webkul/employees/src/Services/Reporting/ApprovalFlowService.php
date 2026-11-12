<?php

namespace Webkul\Employee\Services\Reporting;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Employee\Support\ReportingPurposes;
use Webkul\Security\Models\Role;
use Webkul\Security\Models\User;
use Webkul\Security\Services\CurrentCompany;
use Webkul\Support\Models\ApprovalDecision;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;
use Webkul\Support\Services\ApprovalEngine;

/**
 * HR edits the approval steps of the approval flows of HER company.
 *
 * Every rule is enforced here (the Approval flows screen only calls this):
 * the company always comes from the flow itself or from the actor's company,
 * never from the input; each step has exactly one kind of approver; steps that
 * already decided something, or that requests are waiting on, are protected;
 * and a flow is switched off instead of deleted.
 *
 * Audit: the approval tables carry no activity log and no "updated by"
 * column, so every change is written as a structured `approval_flow_changed`
 * log line (who, which flow, before and after) and the flow's updated_at is
 * touched, which the screen shows as "Last changed".
 *
 * Step input (one array per step, in the order they should run):
 * id (existing step, optional), name, hierarchy_route | approver_user_id |
 * approver_role_id (exactly one), required_approvals (roles only), sla_hours.
 */
class ApprovalFlowService
{
    /** Routes that follow the organisation instead of naming a person or a role. */
    public const Routes = [
        'requester_manager'  => 'The person\'s manager (for this kind of request)',
        'department_manager' => 'The department head',
        'team_manager'       => 'The team lead',
        'record_manager'     => 'The person named on the record (for example the hiring manager)',
        'hr'                 => 'HR approvers',
    ];

    /** Short names of the routes for the one-line summary of a flow. */
    private const RouteShortNames = [
        'requester_manager'  => 'Line manager',
        'department_manager' => 'Department head',
        'team_manager'       => 'Team lead',
        'record_manager'     => 'Record manager',
        'hr'                 => 'HR',
    ];

    /** Kinds of request that always exist for HR even before any flow was created. */
    private const KnownRequestTypes = [
        'leave_request', 'attendance_time_change', 'business_advance', 'advance_settlement',
        'loan_request', 'salary_advance', 'hr_letter', 'overtime', 'recruitment_job_opening',
        'job_offer', 'employee_sensitive_change',
    ];

    public function canManage(?User $user, int $companyId): bool
    {
        return $user instanceof User
            && $user->can(HrPermissions::ManageApprovalFlows)
            && CurrentCompany::canAccess($companyId, $user);
    }

    /** Whether HR may handle flows of this kind of request (accounting flows stay with administrators). */
    public static function isHrRequestType(string $requestType): bool
    {
        return ReportingPurposes::forRequestType($requestType) !== null
            || in_array($requestType, self::KnownRequestTypes, true);
    }

    /** @return Builder<ApprovalWorkflow> */
    public function flowsFor(int $companyId): Builder
    {
        $hrTypes = $this->knownRequestTypes();

        return ApprovalWorkflow::query()
            ->where('company_id', $companyId)
            ->whereIn('request_type', $hrTypes)
            ->with(['steps.approverUser:id,name', 'steps.approverRole:id,name']);
    }

    /**
     * Request types a new flow can be created for: the kinds HR handles that
     * this company has no flow for yet.
     *
     * @return array<string, string> request type => plain label
     */
    public function availableRequestTypes(int $companyId): array
    {
        $taken = ApprovalWorkflow::query()->where('company_id', $companyId)->pluck('request_type')->all();

        return collect($this->knownRequestTypes())
            ->reject(fn (string $type): bool => in_array($type, $taken, true))
            ->mapWithKeys(fn (string $type): array => [$type => ReportingPurposes::requestTypeLabel($type)])
            ->sort()
            ->all();
    }

    /**
     * Waiting requests per flow, in one query.
     *
     * @param  array<int, int>  $workflowIds
     * @return array<int, int> workflow id => pending requests
     */
    public function pendingCounts(array $workflowIds): array
    {
        if ($workflowIds === []) {
            return [];
        }

        return ApprovalRequest::query()
            ->whereIn('workflow_id', $workflowIds)
            ->where('status', 'pending')
            ->selectRaw('workflow_id, count(*) as aggregate')
            ->groupBy('workflow_id')
            ->pluck('aggregate', 'workflow_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    public function pendingCount(ApprovalWorkflow $workflow): int
    {
        return $this->pendingCounts([(int) $workflow->id])[(int) $workflow->id] ?? 0;
    }

    /** The plain sentence shown when steps cannot be added, removed or reordered. */
    public function lockMessage(int $pending): string
    {
        return sprintf(
            'There %s %d %s waiting on this flow. You can change who approves a step, but not add, remove or reorder steps until %s finished.',
            $pending === 1 ? 'is' : 'are',
            $pending,
            $pending === 1 ? 'request' : 'requests',
            $pending === 1 ? 'it is' : 'they are',
        );
    }

    /**
     * Create (null workflow) or update a flow of the actor's company.
     *
     * @param  array{name?: string, request_type?: string, is_active?: bool, steps?: array<int, array<string, mixed>>}  $data
     *
     * @throws RuntimeException|InvalidArgumentException
     */
    public function save(User $actor, ?ApprovalWorkflow $workflow, array $data): ApprovalWorkflow
    {
        $companyId = $workflow !== null ? (int) $workflow->company_id : (int) CurrentCompany::id($actor);

        if ($companyId <= 0 || ! $this->canManage($actor, $companyId)) {
            throw new RuntimeException('You cannot change the approval flows of this company.');
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Give the flow a name.');
        }

        $steps = $this->cleanSteps((array) ($data['steps'] ?? []), $companyId);

        $saved = DB::transaction(function () use ($actor, $workflow, $data, $name, $steps, $companyId): ApprovalWorkflow {
            if ($workflow === null) {
                return $this->create($actor, $companyId, $name, (string) ($data['request_type'] ?? ''), (bool) ($data['is_active'] ?? true), $steps);
            }

            return $this->update($actor, $workflow, $name, $data, $steps);
        });

        ApprovalEngine::flushMemo();

        return $saved->load('steps');
    }

    /** @throws RuntimeException */
    public function setActive(User $actor, ApprovalWorkflow $workflow, bool $active): ApprovalWorkflow
    {
        if (! $this->canManage($actor, (int) $workflow->company_id) || ! self::isHrRequestType((string) $workflow->request_type)) {
            throw new RuntimeException('You cannot change the approval flows of this company.');
        }

        if ((bool) $workflow->is_active === $active) {
            return $workflow;
        }

        $workflow->forceFill(['is_active' => $active])->save();
        $workflow->touch();
        ApprovalEngine::flushMemo();

        $this->audit($actor, $workflow, $active ? 'switched on' : 'switched off', ['is_active' => ! $active], ['is_active' => $active]);

        return $workflow->fresh('steps');
    }

    /** One line such as "Line manager → HR → Role: Finance". */
    public function summarise(ApprovalWorkflow $workflow): string
    {
        $steps = $workflow->relationLoaded('steps') ? $workflow->steps : $workflow->steps()->get();

        return $steps->sortBy('sequence')->map(fn (ApprovalStep $step): string => $this->shortLabel($step))->implode(' → ');
    }

    public function shortLabel(ApprovalStep $step): string
    {
        if ($step->approver_user_id) {
            return $step->approverUser?->name ?? 'A person who no longer exists';
        }

        if ($step->approver_role_id) {
            return 'Role: '.($step->approverRole?->name ?? 'unknown');
        }

        return self::RouteShortNames[$step->hierarchy_route] ?? 'Not set';
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, array<string, mixed>>
     *
     * @throws InvalidArgumentException
     */
    private function cleanSteps(array $steps, int $companyId): array
    {
        $steps = array_values($steps);

        if ($steps === []) {
            throw new InvalidArgumentException('A flow needs at least one step.');
        }

        $clean = [];
        $seenIds = [];

        foreach ($steps as $index => $step) {
            $number = $index + 1;
            $stepName = trim((string) ($step['name'] ?? ''));
            if ($stepName === '') {
                throw new InvalidArgumentException("Step {$number} needs a name.");
            }

            $route = filled($step['hierarchy_route'] ?? null) ? (string) $step['hierarchy_route'] : null;
            $userId = filled($step['approver_user_id'] ?? null) ? (int) $step['approver_user_id'] : null;
            $roleId = filled($step['approver_role_id'] ?? null) ? (int) $step['approver_role_id'] : null;

            if (count(array_filter([$route, $userId, $roleId], fn ($value): bool => $value !== null)) !== 1) {
                throw new InvalidArgumentException("Step {$number} must say who approves it: choose a route, one person or one role, not several.");
            }

            if ($route !== null && ! array_key_exists($route, self::Routes)) {
                throw new InvalidArgumentException("Step {$number} has a way of choosing the approver that is not known.");
            }

            if ($userId !== null) {
                $this->assertUsable($userId, $companyId, $number);
            }

            if ($roleId !== null) {
                $this->assertRoleUsable($roleId, $number);
            }

            $required = $roleId !== null ? (int) ($step['required_approvals'] ?? 1) : 1;
            if ($required < 1) {
                throw new InvalidArgumentException("Step {$number}: at least one approval is needed.");
            }

            $sla = filled($step['sla_hours'] ?? null) ? (int) $step['sla_hours'] : null;
            if ($sla !== null && $sla < 1) {
                throw new InvalidArgumentException("Step {$number}: the reminder time must be at least 1 hour, or left empty.");
            }

            $id = filled($step['id'] ?? null) ? (int) $step['id'] : null;
            if ($id !== null) {
                if (in_array($id, $seenIds, true)) {
                    throw new InvalidArgumentException("Step {$number} is listed twice.");
                }
                $seenIds[] = $id;
            }

            $clean[] = [
                'id'                 => $id,
                'sequence'           => $number,
                'name'               => $stepName,
                'hierarchy_route'    => $route,
                'approver_user_id'   => $userId,
                'approver_role_id'   => $roleId,
                'required_approvals' => $required,
                'sla_hours'          => $sla,
            ];
        }

        return $clean;
    }

    private function assertUsable(int $userId, int $companyId, int $number): void
    {
        $exists = CurrentCompany::scopeUsersOf(User::query(), $companyId)
            ->whereKey($userId)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            $name = User::query()->whereKey($userId)->value('name') ?? 'That person';

            throw new InvalidArgumentException("Step {$number}: {$name} cannot approve in this company. Choose an active person who works here.");
        }
    }

    private function assertRoleUsable(int $roleId, int $number): void
    {
        $role = Role::query()->whereKey($roleId)->where('guard_name', 'web')->first();

        if (! $role) {
            throw new InvalidArgumentException("Step {$number}: that role does not exist.");
        }

        if ($role->isSystemRole()) {
            throw new InvalidArgumentException("Step {$number}: the role \"{$role->name}\" is a built-in administrator role and cannot be an approver. Choose another role.");
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     *
     * @throws InvalidArgumentException
     */
    private function create(User $actor, int $companyId, string $name, string $requestType, bool $active, array $steps): ApprovalWorkflow
    {
        if (! self::isHrRequestType($requestType) || ! array_key_exists($requestType, $this->availableRequestTypes($companyId))) {
            $exists = ApprovalWorkflow::query()->where('company_id', $companyId)->where('request_type', $requestType)->exists();

            throw new InvalidArgumentException($exists
                ? 'This company already has a flow for '.ReportingPurposes::requestTypeLabel($requestType).'. Edit that one instead.'
                : 'Choose what kind of request this flow is for.');
        }

        $workflow = ApprovalWorkflow::query()->create([
            'company_id'   => $companyId,
            'creator_id'   => $actor->id,
            'name'         => $name,
            'request_type' => $requestType,
            'priority'     => 100,
            'is_active'    => $active,
        ]);

        foreach ($steps as $step) {
            $workflow->steps()->create($this->stepAttributes($step));
        }

        $this->audit($actor, $workflow, 'created', [], ['name' => $name, 'is_active' => $active, 'steps' => $this->describeInput($steps)]);

        return $workflow;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $steps
     *
     * @throws RuntimeException|InvalidArgumentException
     */
    private function update(User $actor, ApprovalWorkflow $workflow, string $name, array $data, array $steps): ApprovalWorkflow
    {
        /** @var ApprovalWorkflow $locked */
        $locked = ApprovalWorkflow::query()->whereKey($workflow->getKey())->lockForUpdate()->firstOrFail();

        if ((int) $locked->company_id !== (int) $workflow->company_id || ! self::isHrRequestType((string) $locked->request_type)) {
            throw new RuntimeException('You cannot change the approval flows of this company.');
        }

        if (isset($data['request_type']) && (string) $data['request_type'] !== (string) $locked->request_type) {
            throw new InvalidArgumentException('The kind of request of an existing flow cannot be changed. Create a new flow instead.');
        }

        $existing = $locked->steps()->get()->keyBy('id');
        $before = ['name' => $locked->name, 'is_active' => (bool) $locked->is_active, 'steps' => $this->describeSteps($existing->values())];

        $givenIds = collect($steps)->pluck('id')->filter()->all();
        foreach ($givenIds as $id) {
            if (! $existing->has($id)) {
                throw new InvalidArgumentException('One of the steps does not belong to this flow. Reload the page and try again.');
            }
        }

        $removed = $existing->keys()->reject(fn ($id): bool => in_array((int) $id, $givenIds, true))->values();
        $structureChanged = $removed->isNotEmpty()
            || collect($steps)->contains(fn (array $step): bool => $step['id'] === null)
            || $givenIds !== $existing->keys()->map(fn ($id): int => (int) $id)->all();

        if ($removed->isNotEmpty()) {
            $decided = ApprovalDecision::query()->whereIn('step_id', $removed)->exists();
            if ($decided) {
                throw new RuntimeException('A step that has already approved or rejected a request cannot be removed. Change who approves it, or switch the flow off.');
            }
        }

        if ($structureChanged) {
            $pending = $this->pendingCount($locked);
            if ($pending > 0) {
                throw new RuntimeException($this->lockMessage($pending));
            }
        }

        $locked->forceFill(['name' => $name, 'is_active' => (bool) ($data['is_active'] ?? $locked->is_active)])->save();

        if ($removed->isNotEmpty()) {
            ApprovalStep::query()->whereIn('id', $removed)->delete();
        }

        // Park the kept steps on unused sequence numbers first: (flow, sequence) is unique.
        $offset = 1000 + count($steps);
        foreach ($existing->keys()->diff($removed) as $id) {
            ApprovalStep::query()->whereKey($id)->update(['sequence' => $offset++]);
            $existing->get($id)->refresh();
        }

        foreach ($steps as $step) {
            $attributes = $this->stepAttributes($step);
            if ($step['id'] === null) {
                $locked->steps()->create($attributes);

                continue;
            }

            /** @var ApprovalStep $model */
            $model = $existing->get($step['id']);
            $model->forceFill($attributes);
            $model->save();
        }

        $locked->touch();
        $locked->unsetRelation('steps');

        $after = ['name' => $locked->name, 'is_active' => (bool) $locked->is_active, 'steps' => $this->describeSteps($locked->steps()->with(['approverUser:id,name', 'approverRole:id,name'])->get())];
        $this->audit($actor, $locked, 'updated', $before, $after);

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function stepAttributes(array $step): array
    {
        return [
            'sequence'           => $step['sequence'],
            'name'               => $step['name'],
            'hierarchy_route'    => $step['hierarchy_route'],
            'approver_user_id'   => $step['approver_user_id'],
            'approver_role_id'   => $step['approver_role_id'],
            'required_approvals' => $step['required_approvals'],
            'sla_hours'          => $step['sla_hours'],
        ];
    }

    /**
     * @param  Collection<int, ApprovalStep>  $steps
     * @return array<int, string>
     */
    private function describeSteps(Collection $steps): array
    {
        return $steps->sortBy('sequence')->values()->map(
            fn (ApprovalStep $step, int $index): string => ($index + 1).'. '.$step->name.' — '.$this->shortLabel($step).($step->approver_role_id ? ' (needs '.$step->required_approvals.')' : '').($step->sla_hours ? ', remind after '.$step->sla_hours.'h' : '')
        )->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<int, string>
     */
    private function describeInput(array $steps): array
    {
        return collect($steps)->map(function (array $step): string {
            $who = $step['hierarchy_route'] !== null
                ? self::RouteShortNames[$step['hierarchy_route']]
                : ($step['approver_user_id'] !== null ? 'user #'.$step['approver_user_id'] : 'role #'.$step['approver_role_id']);

            return $step['sequence'].'. '.$step['name'].' — '.$who;
        })->all();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(User $actor, ApprovalWorkflow $workflow, string $what, array $before, array $after): void
    {
        Log::info('approval_flow_changed', [
            'action'       => $what,
            'actor_id'     => $actor->id,
            'actor'        => $actor->name,
            'company_id'   => $workflow->company_id,
            'workflow_id'  => $workflow->id,
            'request_type' => $workflow->request_type,
            'before'       => $before,
            'after'        => $after,
        ]);
    }

    /** @return array<int, string> */
    private function knownRequestTypes(): array
    {
        return ApprovalWorkflow::query()->distinct()->pluck('request_type')
            ->merge(self::KnownRequestTypes)
            ->filter(fn ($type): bool => is_string($type) && self::isHrRequestType($type))
            ->unique()
            ->values()
            ->all();
    }
}
