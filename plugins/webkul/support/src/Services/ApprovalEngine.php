<?php

namespace Webkul\Support\Services;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\ApprovalStep;
use Webkul\Support\Models\ApprovalWorkflow;

final class ApprovalEngine
{
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
                ->where('status', 'pending')
                ->lockForUpdate()
                ->latest('id')
                ->first();
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
                'company_id'            => $companyId,
                'workflow_id'           => $workflow->id,
                'requester_id'          => $requester->id,
                'subject_type'          => $subject->getMorphClass(),
                'subject_id'            => $subject->getKey(),
                'request_type'          => $requestType,
                'amount'                => $amount,
                'context'               => $context,
                'status'                => 'pending',
                'current_step_sequence' => $firstStep->sequence,
                'submitted_at'          => now(),
            ])->fresh(['workflow.steps', 'requester']);
        });
    }

    public function canAct(ApprovalRequest $request, User $actor): bool
    {
        if ($request->status !== 'pending' || ! $this->userCanAccessCompany($actor, (int) $request->company_id)) {
            return false;
        }

        $request->loadMissing([
            'workflow.steps',
            'requester.employee.parent.user',
            'requester.employee.department.manager.user',
            'requester.employee.team.manager.user',
        ]);
        $step = $request->currentStep();
        if (! $step) {
            return false;
        }
        if ((int) $step->approver_user_id === (int) $actor->id) {
            return true;
        }
        if ($step->approver_role_id && $actor->roles()->whereKey($step->approver_role_id)->exists()) {
            return true;
        }

        return match ($step->hierarchy_route) {
            'requester_manager'  => (int) $request->requester?->employee?->parent?->user_id === (int) $actor->id,
            'department_manager' => (int) $request->requester?->employee?->department?->manager?->user_id === (int) $actor->id,
            'team_manager'       => (int) $request->requester?->employee?->team?->manager?->user_id === (int) $actor->id,
            default              => false,
        };
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
            if ($request->decisions()->where('step_id', $step->id)->where('actor_id', $actor->id)->exists()) {
                throw new RuntimeException('This user has already decided the current approval step.');
            }

            $request->decisions()->create([
                'step_id'         => $step->id,
                'actor_id'        => $actor->id,
                'decision'        => $decision,
                'reason'          => $reason,
                'previous_values' => $previousValues,
                'new_values'      => $newValues,
                'decided_at'      => now(),
            ]);

            if ($decision === 'rejected') {
                $request->update(['status' => 'rejected', 'completed_at' => now()]);
            } else {
                $approvalCount = $request->decisions()
                    ->where('step_id', $step->id)
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
                        'current_step_sequence' => $nextStep->sequence,
                    ] : [
                        'status'                => 'approved',
                        'current_step_sequence' => null,
                        'completed_at'          => now(),
                    ]);
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

    private function userCanAccessCompany(User $user, int $companyId): bool
    {
        return (int) $user->default_company_id === $companyId
            || $user->allowedCompanies()->whereKey($companyId)->exists();
    }
}
