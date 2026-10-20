<?php

namespace Webkul\Support\Services;

use Filament\Notifications\Events\DatabaseNotificationsSent;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Models\ApprovalStep;

class HierarchicalNotificationService
{
    public function __construct(
        protected ApprovalEngine $approvalEngine,
    ) {}

    /**
     * Dispatches notification for the first active step of a submitted request.
     */
    public function notifyRequestSubmitted(
        ApprovalRequest $approval,
        mixed $subject = null,
        ?User $requester = null,
    ): void {
        try {
            $approval->loadMissing(['workflow.steps']);
            $currentStep = $approval->currentStep();

            if (! $currentStep) {
                return;
            }

            $approvers = $this->approvalEngine->resolveAuthorizedApprovers($approval);

            if ($approvers->isEmpty()) {
                Log::warning("HierarchicalNotification: No authorized approver resolved for submitted request #{$approval->id} at step #{$currentStep->id}.");

                return;
            }

            $subjectEmployee = $subject?->employee ?? $approval->requester?->employee;
            $hasManager = $subjectEmployee?->parent_id && $subjectEmployee?->parent?->user_id;

            $routingNote = $hasManager
                ? ''
                : ' [Routed to HR: no line manager assigned]';

            $title = 'Request awaiting your review';
            $body = "A request ({$approval->request_type}) has been submitted and is awaiting your review at step '{$currentStep->name}'.{$routingNote}";

            foreach ($approvers as $approver) {
                $idempotencyKey = "approval_submit_{$approval->id}_step_{$currentStep->id}_{$approver->id}";

                $notification = FilamentNotification::make()
                    ->warning()
                    ->title($title)
                    ->body($body);

                $this->sendIdempotentNotification($approver, $notification, $idempotencyKey, [
                    'event'               => 'request_submitted',
                    'approval_request_id' => $approval->id,
                    'step_id'             => $currentStep->id,
                    'company_id'          => $approval->company_id,
                ]);
            }
        } catch (Throwable $e) {
            report($e);
            Log::error("HierarchicalNotification: Failed to notify on submit: {$e->getMessage()}");
        }
    }

    /**
     * Dispatches notification when a request step is approved and advances to the next step.
     */
    public function notifyStepAdvanced(
        ApprovalRequest $approval,
        ApprovalStep $previousStep,
        ApprovalStep $nextStep,
        User $actor,
    ): void {
        try {
            $approval->loadMissing(['workflow.steps']);
            $approvers = $this->approvalEngine->resolveAuthorizedApprovers($approval, $nextStep);

            if ($approvers->isEmpty()) {
                Log::warning("HierarchicalNotification: No authorized approver for advanced step #{$nextStep->id} on request #{$approval->id}.");

                return;
            }

            $title = "{$nextStep->name} awaiting your approval";
            $body = "A request ({$approval->request_type}) completed preceding stage '{$previousStep->name}' (approved by {$actor->name}) and requires your decision.";

            foreach ($approvers as $approver) {
                $idempotencyKey = "approval_advance_{$approval->id}_step_{$nextStep->id}_{$approver->id}";

                $notification = FilamentNotification::make()
                    ->warning()
                    ->title($title)
                    ->body($body);

                $this->sendIdempotentNotification($approver, $notification, $idempotencyKey, [
                    'event'               => 'step_advanced',
                    'approval_request_id' => $approval->id,
                    'previous_step_id'    => $previousStep->id,
                    'next_step_id'        => $nextStep->id,
                    'actor_id'            => $actor->id,
                    'company_id'          => $approval->company_id,
                ]);
            }
        } catch (Throwable $e) {
            report($e);
            Log::error("HierarchicalNotification: Failed to notify step advancement: {$e->getMessage()}");
        }
    }

    /**
     * Dispatches notification when a request is rejected.
     */
    public function notifyRequestRejected(
        ApprovalRequest $approval,
        User $actor,
        string $reason,
    ): void {
        try {
            $approval->loadMissing(['requester', 'subject']);
            $recipients = collect([$approval->requester]);

            $subject = $approval->subject;
            if ($subject && method_exists($subject, 'employee') && $subject->employee?->user) {
                $recipients->push($subject->employee->user);
            }

            $recipients = $recipients
                ->filter(fn (?User $u): bool => $u && $u->is_active && $this->approvalEngine->userCanAccessCompany($u, (int) $approval->company_id))
                ->unique('id');

            $title = "Request rejected by {$actor->name}";
            $body = "Your request ({$approval->request_type}) was rejected by {$actor->name}.".(trim($reason) !== '' ? " Reason: {$reason}" : '');

            foreach ($recipients as $recipient) {
                $idempotencyKey = "approval_decision_{$approval->id}_rejected_{$recipient->id}";

                $notification = FilamentNotification::make()
                    ->danger()
                    ->title($title)
                    ->body($body);

                $this->sendIdempotentNotification($recipient, $notification, $idempotencyKey, [
                    'event'               => 'request_rejected',
                    'approval_request_id' => $approval->id,
                    'actor_id'            => $actor->id,
                    'reason'              => $reason,
                    'company_id'          => $approval->company_id,
                ]);
            }
        } catch (Throwable $e) {
            report($e);
            Log::error("HierarchicalNotification: Failed to notify rejection: {$e->getMessage()}");
        }
    }

    /**
     * Dispatches notification when a request completes final approval.
     */
    public function notifyRequestApproved(
        ApprovalRequest $approval,
        User $actor,
    ): void {
        try {
            $approval->loadMissing(['requester', 'subject']);
            $recipients = collect([$approval->requester]);

            $subject = $approval->subject;
            if ($subject && method_exists($subject, 'employee') && $subject->employee?->user) {
                $recipients->push($subject->employee->user);
            }

            $recipients = $recipients
                ->filter(fn (?User $u): bool => $u && $u->is_active && $this->approvalEngine->userCanAccessCompany($u, (int) $approval->company_id))
                ->unique('id');

            $title = 'Request approved';
            $body = "Your request ({$approval->request_type}) has been fully approved.";

            foreach ($recipients as $recipient) {
                $idempotencyKey = "approval_decision_{$approval->id}_approved_{$recipient->id}";

                $notification = FilamentNotification::make()
                    ->success()
                    ->title($title)
                    ->body($body);

                $this->sendIdempotentNotification($recipient, $notification, $idempotencyKey, [
                    'event'               => 'request_approved',
                    'approval_request_id' => $approval->id,
                    'actor_id'            => $actor->id,
                    'company_id'          => $approval->company_id,
                ]);
            }
        } catch (Throwable $e) {
            report($e);
            Log::error("HierarchicalNotification: Failed to notify approval: {$e->getMessage()}");
        }
    }

    /**
     * Dispatches notification to authorized Accounting reviewers when an approved
     * reimbursement has completed approval and is handed off to Accounting.
     */
    public function notifyAccountingReady(
        mixed $subject,
        int $companyId,
        string $reference,
        string $titleText,
        string $amountStr,
    ): void {
        try {
            $accountants = User::query()
                ->where('is_active', true)
                ->get()
                ->filter(fn (User $u): bool => (
                    $this->approvalEngine->userCanAccessCompany($u, $companyId)
                    && (
                        $u->hasRole([
                            'Admin', 'Super Admin', 'Accountant', 'accountant',
                            'controller', 'Controller', 'accounting_manager',
                            'Accounting Manager', 'Finance Manager', 'finance_manager',
                        ])
                        || $u->can('accounting_manage_vendor_bills')
                        || $u->can('accounting_view_all_records')
                    )
                ))
                ->values();

            if ($accountants->isEmpty()) {
                Log::warning("HierarchicalNotification: No authorized accountant found for company #{$companyId}.");

                return;
            }

            $title = 'Approved reimbursement ready for Accounting review';
            $body = "Reimbursement '{$titleText}' ({$reference}) for {$amountStr} has completed approval and is ready for Accounting review.";

            foreach ($accountants as $accountant) {
                $idempotencyKey = "accounting_ready_{$reference}_{$accountant->id}";

                $notification = FilamentNotification::make()
                    ->success()
                    ->title($title)
                    ->body($body);

                $this->sendIdempotentNotification($accountant, $notification, $idempotencyKey, [
                    'event'      => 'accounting_handoff_ready',
                    'company_id' => $companyId,
                    'reference'  => $reference,
                ]);
            }
        } catch (Throwable $e) {
            report($e);
            Log::error("HierarchicalNotification: Failed to notify accounting: {$e->getMessage()}");
        }
    }

    /**
     * Dispatches notification when a performance cycle is launched.
     * Notifies only the active employees included in that cycle for the company.
     */
    public function notifyPerformanceCycleLaunched(
        mixed $cycle,
        Collection $employees,
    ): void {
        try {
            $title = 'Performance review cycle is now open';
            $body = "You have been included in active performance review cycle '{$cycle->name}'. Please complete your self-review before the deadline.";

            foreach ($employees as $employee) {
                $user = $employee->user;
                if (! $user || ! $user->is_active || ! $this->approvalEngine->userCanAccessCompany($user, (int) $cycle->company_id)) {
                    continue;
                }

                $idempotencyKey = "perf_cycle_{$cycle->id}_employee_{$employee->id}";

                $notification = FilamentNotification::make()
                    ->info()
                    ->title($title)
                    ->body($body);

                $this->sendIdempotentNotification($user, $notification, $idempotencyKey, [
                    'event'       => 'performance_cycle_launched',
                    'cycle_id'    => $cycle->id,
                    'employee_id' => $employee->id,
                    'company_id'  => $cycle->company_id,
                ]);
            }
        } catch (Throwable $e) {
            report($e);
            Log::error("HierarchicalNotification: Failed to notify performance launch: {$e->getMessage()}");
        }
    }

    /**
     * Dispatches notification when an employee submits their self-review.
     * Notifies the assigned reviewer (or company HR fallback if no reviewer is assigned).
     */
    public function notifyPerformanceSelfReviewSubmitted(mixed $review): void
    {
        try {
            $review->loadMissing(['employee', 'reviewer.user', 'cycle']);
            $reviewerUser = $review->reviewer?->user;

            $companyId = (int) $review->company_id;

            if ($reviewerUser && $reviewerUser->is_active && $this->approvalEngine->userCanAccessCompany($reviewerUser, $companyId) && (int) $reviewerUser->id !== (int) $review->employee?->user_id) {
                $recipients = collect([$reviewerUser]);
                $routingNote = '';
            } else {
                $recipients = $this->approvalEngine->resolveHrApproversForCompany($companyId, (int) $review->employee?->user_id);
                $routingNote = ' [Routed to HR: no manager reviewer assigned]';
            }

            if ($recipients->isEmpty()) {
                Log::warning("HierarchicalNotification: No reviewer or HR fallback found for self-review #{$review->id}.");

                return;
            }

            $employeeName = $review->employee?->name ?? 'An employee';
            $cycleName = $review->cycle?->name ?? 'Performance Cycle';

            $title = "Self-review submitted: {$employeeName}";
            $body = "{$employeeName} has submitted their self-review for cycle '{$cycleName}'. Please complete the manager evaluation.{$routingNote}";

            foreach ($recipients as $recipient) {
                $idempotencyKey = "perf_self_review_{$review->id}_{$recipient->id}";

                $notification = FilamentNotification::make()
                    ->warning()
                    ->title($title)
                    ->body($body);

                $this->sendIdempotentNotification($recipient, $notification, $idempotencyKey, [
                    'event'       => 'performance_self_review_submitted',
                    'review_id'   => $review->id,
                    'employee_id' => $review->employee_id,
                    'company_id'  => $companyId,
                ]);
            }
        } catch (Throwable $e) {
            report($e);
            Log::error("HierarchicalNotification: Failed to notify self review: {$e->getMessage()}");
        }
    }

    /**
     * Dispatches notification when a manager completes a performance review.
     * Evaluated employee is notified without leaking confidential comments or ratings.
     */
    public function notifyPerformanceManagerReviewCompleted(mixed $review): void
    {
        try {
            $review->loadMissing(['employee.user', 'cycle']);
            $employeeUser = $review->employee?->user;

            if (! $employeeUser || ! $employeeUser->is_active || ! $this->approvalEngine->userCanAccessCompany($employeeUser, (int) $review->company_id)) {
                return;
            }

            $cycleName = $review->cycle?->name ?? 'Performance Cycle';
            $title = 'Performance review completed';
            $body = "Your manager evaluation for cycle '{$cycleName}' has been completed.";

            $idempotencyKey = "perf_mgr_review_{$review->id}_{$employeeUser->id}";

            $notification = FilamentNotification::make()
                ->success()
                ->title($title)
                ->body($body);

            $this->sendIdempotentNotification($employeeUser, $notification, $idempotencyKey, [
                'event'       => 'performance_manager_review_completed',
                'review_id'   => $review->id,
                'employee_id' => $review->employee_id,
                'company_id'  => $review->company_id,
            ]);
        } catch (Throwable $e) {
            report($e);
            Log::error("HierarchicalNotification: Failed to notify manager review completion: {$e->getMessage()}");
        }
    }

    /**
     * Centralized delivery helper enforcing idempotency and recording audit logs.
     *
     * @param  array<string, mixed>  $auditMetadata
     */
    public function sendIdempotentNotification(
        User $recipient,
        FilamentNotification $notification,
        string $idempotencyKey,
        array $auditMetadata = [],
    ): bool {
        if (! $recipient->is_active) {
            return false;
        }

        // Idempotency check: query notifications table for an existing record with this idempotency key
        $alreadyExists = DB::table('notifications')
            ->where('notifiable_type', $recipient->getMorphClass())
            ->where('notifiable_id', $recipient->getKey())
            ->where('data', 'like', '%"'.$idempotencyKey.'"%')
            ->exists();

        if ($alreadyExists) {
            Log::info("HierarchicalNotification: Skipped duplicate notification [{$idempotencyKey}] for user #{$recipient->id}.");

            return false;
        }

        $viewData = array_merge($notification->getViewData() ?? [], [
            'idempotency_key' => $idempotencyKey,
            'audit'           => $auditMetadata,
            'dispatched_at'   => now()->toIso8601String(),
        ]);

        $notification->viewData($viewData);

        $recipient->notifyNow($notification->toDatabase());
        DatabaseNotificationsSent::dispatch($recipient);

        Log::info("HierarchicalNotification: Sent notification [{$idempotencyKey}] to user #{$recipient->id}.", $auditMetadata);

        return true;
    }
}
