<?php

namespace Webkul\Recruitment\Services;

use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Employee\Support\HrPermissions;
use Webkul\Recruitment\Filament\Clusters\Configurations\Resources\JobPositionResource;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobPosting;
use Webkul\Recruitment\Support\JobPostingSyncResult;
use Webkul\Security\Models\User;
use Webkul\Support\Services\ApprovalEngine;
use Webkul\Support\Services\HierarchicalNotificationService;

/**
 * Tells recruiters which ads to post and which to take down. Runs after
 * commit and never affects posting state: every send is idempotent
 * (HierarchicalNotificationService) and failures are only reported.
 */
class JobPostingNotifier
{
    public function __construct(
        protected HierarchicalNotificationService $notifications,
        protected ApprovalEngine $approvals,
    ) {}

    /**
     * @param  Collection<int, JobPosting>  $postings  ads to post now
     * @param  Collection<int, JobPosting>|null  $stillUp  ads still marked for take-down from the last close
     */
    public function adsToPost(JobPosition $job, Collection $postings, ?User $actor, CarbonInterface $at, ?Collection $stillUp = null): void
    {
        $this->safely(function () use ($job, $postings, $actor, $at, $stillUp): void {
            $stillUp ??= collect();
            $recruiter = $job->recruiter;

            if (($postings->isEmpty() && $stillUp->isEmpty()) || ! $recruiter || ($actor && (int) $recruiter->id === (int) $actor->id)) {
                return;
            }

            if (! $this->isEligible($recruiter, $job)) {
                return;
            }

            $body = 'This job is now published.';

            if ($postings->isNotEmpty()) {
                $lines = $postings->map(fn (JobPosting $posting): string => '• '.$posting->displayName())->implode("\n");
                $body .= "\nPost it on:\n{$lines}\n\nUse \"Copy post\" on the Job ads tab, then \"Mark as posted\".";
            }

            if ($stillUp->isNotEmpty()) {
                $lines = $stillUp->map(fn (JobPosting $posting): string => '• '.$posting->displayName())->implode("\n");
                $body .= "\n\nStill marked for take-down from the last close (keep it with \"Mark as posted\", or take it down):\n{$lines}";
            }

            $title = $postings->isNotEmpty() ? "Post {$postings->count()} job ad(s): {$job->name}" : "Check job ads still up: {$job->name}";

            $this->send($recruiter, $job, $title, $body, "recruitment_postings_to_post_{$job->id}_".$at->format('Uu'), 'ads_to_post');
        });
    }

    public function removalsRequested(JobPosition $job, JobPostingSyncResult $result, ?User $actor): void
    {
        $this->safely(function () use ($job, $result, $actor): void {
            if ($result->removalRequested->isEmpty() && $result->notPosted->isEmpty()) {
                return;
            }

            $body = $this->removalBody($result->removalRequested, $result->notPosted);
            $title = "Take down {$result->removalRequested->count()} job ad(s): {$job->name}";

            foreach ($this->recipients($job, $result->removalRequested->merge($result->notPosted), $actor) as $user) {
                $this->send($user, $job, $title, $body, "recruitment_postings_removal_{$job->id}_".$result->at->format('Uu'), 'removal_requested');
            }
        });
    }

    /** @param Collection<int, JobPosting> $postings */
    public function remind(JobPosition $job, Collection $postings, int $reminderNumber): void
    {
        $this->safely(function () use ($job, $postings, $reminderNumber): void {
            $title = "Reminder {$reminderNumber}: take down {$postings->count()} job ad(s): {$job->name}";
            $body = $this->removalBody($postings, collect());

            foreach ($this->recipients($job, $postings, null) as $user) {
                $this->send($user, $job, $title, $body, "recruitment_postings_removal_reminder_{$job->id}_".now()->format('Y-m-d'), 'removal_reminder');
            }
        });
    }

    public function escalate(JobPosting $posting): void
    {
        $this->safely(function () use ($posting): void {
            $job = $posting->job;
            $title = "Job ad still up after reminders: {$job->name}";
            $body = "{$posting->displayName()} was not confirmed as taken down after {$posting->reminder_count} reminder(s).".($posting->live_url ? "\nAd: {$posting->live_url}" : '');
            $requestedAt = $posting->removal_requested_at?->getTimestamp() ?? 0;

            foreach ($this->approvals->resolveHrApproversForCompany((int) $posting->company_id) as $user) {
                $this->send($user, $job, $title, $body, "recruitment_posting_removal_escalation_{$posting->id}_{$requestedAt}", 'removal_escalated');
            }
        });
    }

    /**
     * Who must act on a job's ads: its recruiter, whoever posted the ads and
     * the acting user -- if active, in the company and allowed to manage ads;
     * otherwise the company's HR approvers.
     *
     * @param  Collection<int, JobPosting>  $postings
     * @return Collection<int, User>
     */
    public function recipients(JobPosition $job, Collection $postings, ?User $actor): Collection
    {
        $postings->each(fn (JobPosting $posting) => $posting->loadMissing('postedBy'));

        $candidates = collect([$job->recruiter, $actor])
            ->merge($postings->map(fn (JobPosting $posting): ?User => $posting->postedBy))
            ->filter()
            ->unique('id')
            ->filter(fn (User $user): bool => $this->isEligible($user, $job))
            ->values();

        if ($candidates->isNotEmpty()) {
            return $candidates;
        }

        $hr = $this->approvals->resolveHrApproversForCompany((int) $job->company_id)->values();

        if ($hr->isEmpty()) {
            Log::warning("JobPostingNotifier: nobody can be told about the ads of job #{$job->id}.");
        }

        return $hr;
    }

    protected function isEligible(User $user, JobPosition $job): bool
    {
        return $user->is_active
            && $job->company_id
            && $this->approvals->userCanAccessCompany($user, (int) $job->company_id)
            && ($user->can(HrPermissions::ManageJobPostings) || $user->can('update_recruitment_job::position'));
    }

    /**
     * @param  Collection<int, JobPosting>  $toRemove
     * @param  Collection<int, JobPosting>  $notPosted
     */
    protected function removalBody(Collection $toRemove, Collection $notPosted): string
    {
        $lines = $toRemove->map(function (JobPosting $posting): string {
            $parts = array_filter([
                '• '.$posting->displayName(),
                $posting->live_url,
                $posting->postedBy ? 'posted by '.$posting->postedBy->name : null,
                $posting->definition()->removalHint,
            ]);

            return implode(' — ', $parts);
        })->implode("\n");

        $body = $lines !== '' ? "This job is closed. Take these ads down, then confirm each one on the Job ads tab:\n{$lines}" : 'This job is closed.';

        if ($notPosted->isNotEmpty()) {
            $names = $notPosted->map(fn (JobPosting $posting): string => $posting->displayName())->implode(', ');
            $body .= "\n\nNot recorded as posted: {$names}. If you did post it, use \"Ad is still up\" on the Job ads tab.";
        }

        return $body;
    }

    protected function send(User $user, JobPosition $job, string $title, string $body, string $key, string $event): void
    {
        // Filament renders titles and bodies as (sanitised) HTML: job names, placements,
        // ad links and user names are user input, so everything is escaped here.
        $notification = FilamentNotification::make()
            ->warning()
            ->title(e($title))
            ->body(nl2br(e($body)))
            ->actions([
                Action::make('open_job')
                    ->label('Open job')
                    ->url(JobPositionResource::getUrl('view', ['record' => $job], panel: 'admin')),
            ]);

        $this->notifications->sendIdempotentNotification($user, $notification, $key, [
            'event'      => $event,
            'job_id'     => $job->id,
            'company_id' => $job->company_id,
        ]);
    }

    protected function safely(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
