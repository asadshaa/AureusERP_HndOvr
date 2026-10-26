<?php

namespace Webkul\Recruitment\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Webkul\Recruitment\Enums\JobPostingStatus;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Security\Models\User;
use Webkul\Support\Models\ApprovalRequest;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\Support\Services\HierarchicalNotificationService;

/**
 * The only supported way to move a job opening through its posting lifecycle:
 * draft -> approval -> published -> paused/closed. Approval reuses the shared
 * ApprovalEngine (request type JobPosition::APPROVAL_REQUEST_TYPE); the
 * approved/rejected outcome is written back by JobPosition::synchronizeApprovalState().
 */
class JobOpeningPublicationService
{
    public function __construct(protected ApprovalEngine $approvals) {}

    public function submitForApproval(JobPosition $job, User $actor): ApprovalRequest
    {
        $this->authorize($job, $actor);
        $this->assertStatus($job, JobPostingStatus::DRAFT, 'Only a draft job can be submitted for approval.');

        $companyId = (int) ($job->company_id ?? $actor->default_company_id);

        $approval = DB::transaction(function () use ($job, $actor, $companyId): ApprovalRequest {
            $approval = $this->approvals->submit($job, $actor, JobPosition::APPROVAL_REQUEST_TYPE, null, [
                'company_id' => $companyId,
                'job_id'     => $job->id,
                'job_name'   => $job->name,
                'summary'    => "Publish job opening: {$job->name}",
            ]);

            $job->update(['posting_status' => JobPostingStatus::PENDING_APPROVAL->value]);

            return $approval;
        });

        DB::afterCommit(fn () => app(HierarchicalNotificationService::class)->notifyRequestSubmitted($approval, $job, $actor));

        return $approval;
    }

    public function publish(JobPosition $job, User $actor): JobPosition
    {
        $this->authorize($job, $actor);

        $current = $job->postingStatus();
        if (! in_array($current, [JobPostingStatus::APPROVED, JobPostingStatus::PAUSED], true)) {
            throw new RuntimeException('A job can only be published after its approval (or resumed from paused).');
        }

        $job->update([
            'posting_status' => JobPostingStatus::PUBLISHED->value,
            'published_at'   => $job->published_at ?? now(),
        ]);

        return $job->refresh();
    }

    public function pause(JobPosition $job, User $actor): JobPosition
    {
        $this->authorize($job, $actor);
        $this->assertStatus($job, JobPostingStatus::PUBLISHED, 'Only a published job can be paused.');

        $job->update(['posting_status' => JobPostingStatus::PAUSED->value]);

        return $job->refresh();
    }

    public function close(JobPosition $job, User $actor): JobPosition
    {
        $this->authorize($job, $actor);

        if (! $job->postingStatus()->canTransitionTo(JobPostingStatus::CLOSED)) {
            throw new RuntimeException('This job cannot be closed from its current status.');
        }

        $job->update(['posting_status' => JobPostingStatus::CLOSED->value]);

        return $job->refresh();
    }

    /** A closed job goes back to draft and must be approved again before it is republished. */
    public function reopen(JobPosition $job, User $actor): JobPosition
    {
        $this->authorize($job, $actor);
        $this->assertStatus($job, JobPostingStatus::CLOSED, 'Only a closed job can be reopened.');

        $job->update([
            'posting_status' => JobPostingStatus::DRAFT->value,
            'published_at'   => null,
        ]);

        return $job->refresh();
    }

    protected function authorize(JobPosition $job, User $actor): void
    {
        if (! Gate::forUser($actor)->allows('update', $job)) {
            throw new RuntimeException('You are not allowed to change this job opening.');
        }

        if ($job->company_id && ! $this->approvals->userCanAccessCompany($actor, (int) $job->company_id)) {
            throw new RuntimeException('This job opening belongs to a company you cannot access.');
        }
    }

    protected function assertStatus(JobPosition $job, JobPostingStatus $expected, string $message): void
    {
        if ($job->postingStatus() !== $expected) {
            throw new RuntimeException($message);
        }
    }
}
