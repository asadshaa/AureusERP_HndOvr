<?php

namespace Webkul\Recruitment\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use Webkul\Employee\Models\EmployeeJobPosition;
use Webkul\Recruitment\Enums\ChannelPostingStatus;
use Webkul\Recruitment\Enums\JobPostingStatus;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobPosting;
use Webkul\Recruitment\Models\JobPostingEvent;
use Webkul\Recruitment\Support\JobPostingChannel;
use Webkul\Recruitment\Support\JobPostingSyncResult;
use Webkul\Security\Models\User;

/**
 * Keeps a job's ads in step with the job (system side, never authorises --
 * callers already did). The on*() methods run inside the caller's transaction
 * with the job row locked; they lock the job's postings (job first, then
 * postings by id, everywhere) and derive each target from the job's status,
 * so replaying a step changes nothing. Publisher calls and notifications run
 * after commit via dispatchAfterCommit().
 */
class JobPostingLifecycle
{
    /** Fields that belong to one advertising cycle and are cleared on reopen/repost. */
    public const CYCLE_RESET = [
        'live_url'             => null,
        'apply_url'            => null,
        'posted_at'            => null,
        'posted_by'            => null,
        'removal_requested_at' => null,
        'removed_at'           => null,
        'removed_by'           => null,
        'reminder_count'       => 0,
        'last_reminded_at'     => null,
        'last_error'           => null,
        'last_error_at'        => null,
    ];

    public function __construct(
        protected JobPostingChannelRegistry $channels,
        protected JobPostingNotifier $notifier,
    ) {}

    /** Publish or resume: planned ads become "to post" (manual) or "waiting" (automatic/feed). */
    public function onJobPublished(JobPosition $lockedJob, ?User $actor, string $origin = JobPostingEvent::ORIGIN_JOB_LIFECYCLE): JobPostingSyncResult
    {
        $at = CarbonImmutable::now();
        $toPost = new EloquentCollection;
        $stillUp = new EloquentCollection;
        $publisherIds = [];

        foreach ($this->lockedPostings($lockedJob) as $posting) {
            if ($posting->status === ChannelPostingStatus::REMOVAL_PENDING) {
                // Carried over from the last close: the recruiter keeps it ("Mark as posted") or takes it down.
                $stillUp->push($posting);

                continue;
            }

            if ($posting->status === ChannelPostingStatus::PLANNED) {
                $this->activate($posting, $lockedJob, $actor, $origin);

                if ($posting->status === ChannelPostingStatus::TO_POST) {
                    $toPost->push($posting);
                }
            }

            if ($posting->status === ChannelPostingStatus::WAITING && $this->channels->canGoLive($posting->channel, $lockedJob)) {
                $publisherIds[] = $posting->id;
            }
        }

        return new JobPostingSyncResult($toPost, new EloquentCollection, new EloquentCollection, $publisherIds, $at, $stillUp);
    }

    /** Pause: automatic listings are unlisted; manual ads stay up (the job reopens soon). */
    public function onJobPaused(JobPosition $lockedJob, ?User $actor): JobPostingSyncResult
    {
        $at = CarbonImmutable::now();
        $publisherIds = [];

        foreach ($this->lockedPostings($lockedJob) as $posting) {
            if ($posting->isManual()) {
                continue;
            }

            if ($posting->status === ChannelPostingStatus::LIVE) {
                $posting->transitionTo(ChannelPostingStatus::WAITING, [], 'paused', JobPostingEvent::ORIGIN_JOB_LIFECYCLE, $actor);
            }

            // A waiting or failed listing may still be up (e.g. a sync in flight or timed out): unlist it too.
            if ($this->mayBeListed($posting)) {
                $publisherIds[] = $posting->id;
            }
        }

        return new JobPostingSyncResult(new EloquentCollection, new EloquentCollection, new EloquentCollection, $publisherIds, $at);
    }

    /**
     * Close (also soft delete and the safety sweep): nothing stays listed by
     * Aureus, and live manual ads are flagged for the recruiter to take down.
     */
    public function onJobClosed(JobPosition $lockedJob, ?User $actor, string $reason = 'job_closed', string $origin = JobPostingEvent::ORIGIN_JOB_LIFECYCLE): JobPostingSyncResult
    {
        $at = CarbonImmutable::now();
        $removal = new EloquentCollection;
        $notPosted = new EloquentCollection;
        $publisherIds = [];
        $details = ['reason' => $reason];

        foreach ($this->lockedPostings($lockedJob) as $posting) {
            $status = $posting->status;
            $manual = $posting->isManual();
            $hasPublisher = ! $manual && $this->channels->publisherFor($posting->channel) !== null;

            if ($status === ChannelPostingStatus::CLOSED) {
                continue;
            }

            if ($status === ChannelPostingStatus::REMOVAL_PENDING) {
                // Still up from an earlier cycle: this close restarts its take-down reminders.
                $posting->transitionTo(ChannelPostingStatus::REMOVAL_PENDING, [
                    'removal_requested_at' => $at,
                    'reminder_count'       => 0,
                    'last_reminded_at'     => null,
                ], 'removal_requested', $origin, $actor, $details);
                $removal->push($posting);

                continue;
            }

            if ($status === ChannelPostingStatus::LIVE && $manual) {
                $posting->transitionTo(ChannelPostingStatus::REMOVAL_PENDING, ['removal_requested_at' => $at], 'removal_requested', $origin, $actor, $details);
                $removal->push($posting);

                continue;
            }

            if ($hasPublisher && in_array($status, [ChannelPostingStatus::WAITING, ChannelPostingStatus::FAILED, ChannelPostingStatus::LIVE], true)) {
                // The publisher unlists it after commit, then the posting closes.
                $publisherIds[] = $posting->id;

                continue;
            }

            if ($status === ChannelPostingStatus::LIVE) {
                $posting->transitionTo(ChannelPostingStatus::CLOSED, ['removed_at' => $at, 'removed_by' => null], 'withdrawn', $origin, $actor, $details);

                continue;
            }

            $posting->transitionTo(ChannelPostingStatus::CLOSED, [], 'closed', $origin, $actor, $details);

            if ($status === ChannelPostingStatus::TO_POST) {
                $notPosted->push($posting);
            }
        }

        return new JobPostingSyncResult(new EloquentCollection, $removal, $notPosted, $publisherIds, $at);
    }

    /** Reopen (closed -> draft): closed ads are planned again for the next cycle; dropped ones stay closed. */
    public function onJobReopened(JobPosition $lockedJob, ?User $actor): JobPostingSyncResult
    {
        foreach ($this->lockedPostings($lockedJob) as $posting) {
            if ($posting->status !== ChannelPostingStatus::CLOSED) {
                continue;
            }

            if ($this->dropIfChannelRemoved($posting, $actor)) {
                continue;
            }

            $posting->transitionTo(ChannelPostingStatus::PLANNED, self::CYCLE_RESET, 'reopened', JobPostingEvent::ORIGIN_JOB_LIFECYCLE, $actor, array_filter([
                'previous_live_url'  => $posting->live_url,
                'previous_posted_at' => $posting->posted_at?->toIso8601String(),
            ]));
        }

        return JobPostingSyncResult::empty();
    }

    /** Where a channel added now starts, given the job's status. */
    public function initialStatusFor(JobPosition $job, JobPostingChannel $channel): ChannelPostingStatus
    {
        return match ($job->postingStatus()) {
            JobPostingStatus::PUBLISHED => $channel->isManual() ? ChannelPostingStatus::TO_POST : ChannelPostingStatus::WAITING,
            JobPostingStatus::CLOSED    => throw new RuntimeException('Reopen the job before adding channels.'),
            default                     => ChannelPostingStatus::PLANNED,
        };
    }

    /** Planned -> to post / waiting, for a published job. Returns false when nothing changed. */
    public function activate(JobPosting $lockedPosting, JobPosition $lockedJob, ?User $actor, string $origin): bool
    {
        if ($lockedPosting->status !== ChannelPostingStatus::PLANNED || $lockedJob->postingStatus() !== JobPostingStatus::PUBLISHED) {
            return false;
        }

        if ($this->dropIfChannelRemoved($lockedPosting, $actor)) {
            return false;
        }

        $target = $lockedPosting->isManual() ? ChannelPostingStatus::TO_POST : ChannelPostingStatus::WAITING;

        return $lockedPosting->transitionTo($target, [], 'activated', $origin, $actor, array_filter([
            'note' => $this->channels->availabilityNote($lockedPosting->channel, $lockedJob),
        ]));
    }

    /** Publisher sync and notifications for a lifecycle step, once the transaction has committed. */
    public function dispatchAfterCommit(JobPosition $job, JobPostingSyncResult $result, ?User $actor): void
    {
        if ($result->isEmpty()) {
            return;
        }

        DB::afterCommit(function () use ($job, $result, $actor): void {
            if ($result->publisherPostingIds !== []) {
                $this->syncPublishers($result->publisherPostingIds);
            }

            if ($result->toPost->isNotEmpty() || $result->stillUp->isNotEmpty()) {
                $this->notifier->adsToPost($job, $result->toPost, $actor, $result->at, $result->stillUp);
            }

            if ($result->removalRequested->isNotEmpty() || $result->notPosted->isNotEmpty()) {
                $this->notifier->removalsRequested($job, $result, $actor);
            }
        });
    }

    /**
     * Calls each posting's publisher outside any transaction, then records the
     * state derived from the job's current status (so a stale run is harmless).
     * A publisher failure marks the ad as a problem; it never rolls back the job.
     *
     * @param  array<int, int>  $postingIds
     */
    public function syncPublishers(array $postingIds): void
    {
        foreach ($postingIds as $postingId) {
            $posting = JobPosting::query()->with('job')->find($postingId);
            $publisher = $posting ? $this->channels->publisherFor($posting->channel) : null;

            if (! $posting || ! $publisher) {
                continue;
            }

            try {
                $url = $publisher->sync($posting);

                DB::transaction(function () use ($posting, $url): void {
                    $job = JobPosition::withTrashed()->whereKey($posting->job_id)->lockForUpdate()->firstOrFail();
                    $locked = JobPosting::query()->whereKey($posting->id)->lockForUpdate()->firstOrFail();
                    $locked->setRelation('job', $job);

                    $this->applyPublisherOutcome($locked, $job, $url);
                });
            } catch (Throwable $e) {
                report($e);

                $message = Str::limit(trim($e->getMessage()) !== '' ? $e->getMessage() : class_basename($e), 1000, '');

                DB::transaction(function () use ($posting, $message): void {
                    $locked = JobPosting::query()->whereKey($posting->id)->lockForUpdate()->firstOrFail();

                    if ($locked->status === ChannelPostingStatus::FAILED || $locked->status->canTransitionTo(ChannelPostingStatus::FAILED)) {
                        $locked->transitionTo(ChannelPostingStatus::FAILED, [
                            'last_error'    => $message,
                            'last_error_at' => now(),
                        ], 'publisher_failed', JobPostingEvent::ORIGIN_PUBLISHER, null, ['error' => $message]);
                    }
                });
            }
        }
    }

    /**
     * Safety net for closes and soft deletes that bypassed the service (direct
     * writes, the base employees model): moves their ads toward a safe state.
     * Never activates anything. Returns the number of jobs swept.
     */
    public function sweepClosedJobs(): int
    {
        $jobIds = JobPosition::withTrashed()
            ->where(fn ($query) => $query->where('posting_status', JobPostingStatus::CLOSED->value)->orWhereNotNull('deleted_at'))
            ->whereHas('postings', fn ($query) => $query->whereNull('dropped_at')->whereIn('status', [
                ChannelPostingStatus::PLANNED->value,
                ChannelPostingStatus::TO_POST->value,
                ChannelPostingStatus::WAITING->value,
                ChannelPostingStatus::LIVE->value,
                ChannelPostingStatus::FAILED->value,
            ]))
            ->pluck('id');

        foreach ($jobIds as $jobId) {
            DB::transaction(function () use ($jobId): void {
                $job = JobPosition::withTrashed()->whereKey($jobId)->lockForUpdate()->first();

                if (! $job || ($job->postingStatus() !== JobPostingStatus::CLOSED && ! $job->trashed())) {
                    return;
                }

                $result = $this->onJobClosed($job, null, $job->trashed() ? 'job_deleted' : 'job_closed', JobPostingEvent::ORIGIN_SCHEDULER);
                $this->dispatchAfterCommit($job, $result, null);
            });
        }

        return $jobIds->count();
    }

    /**
     * Model "deleting" guard (registered on JobPosition and EmployeeJobPosition):
     * a job whose ads may be out there cannot be deleted; a force delete is only
     * allowed when none of its ads ever went out (their rows are removed first).
     */
    public function guardJobDeletion(EmployeeJobPosition $job): bool
    {
        $postings = JobPosting::query()->where('job_id', $job->getKey())->get();

        if ($postings->contains(fn (JobPosting $posting): bool => $posting->status->isOutThere() || $this->mayBeListed($posting))) {
            return false;
        }

        if ($job->isForceDeleting()) {
            // "Went out" is read from the history and the CVs, which no later cycle resets.
            if ($postings->contains(fn (JobPosting $posting): bool => $posting->everWentOut())) {
                return false;
            }

            JobPosting::query()->whereIn('id', $postings->modelKeys())->delete();

            // Anything added meanwhile keeps the job (the foreign key refuses the delete).
            return JobPosting::query()->where('job_id', $job->getKey())->doesntExist();
        }

        return true;
    }

    /**
     * Model "restored" hook: ads that were closed only because the job was
     * deleted come back for the job's current status, like a reopen.
     */
    public function handleJobRestored(EmployeeJobPosition $job): void
    {
        DB::transaction(function () use ($job): void {
            $locked = JobPosition::query()->whereKey($job->getKey())->lockForUpdate()->first();

            if (! $locked || $locked->postingStatus() === JobPostingStatus::CLOSED) {
                return;
            }

            $actor = Auth::user() instanceof User ? Auth::user() : null;
            $toPost = new EloquentCollection;

            foreach ($this->lockedPostings($locked) as $posting) {
                $lastEvent = $posting->events()->latest('id')->first();

                if ($posting->status !== ChannelPostingStatus::CLOSED || ($lastEvent?->details['reason'] ?? null) !== 'job_deleted') {
                    continue;
                }

                if ($this->dropIfChannelRemoved($posting, $actor)) {
                    continue;
                }

                $posting->transitionTo($this->initialStatusFor($locked, $posting->definition()), self::CYCLE_RESET, 'restored', JobPostingEvent::ORIGIN_JOB_LIFECYCLE, $actor);

                if ($posting->status === ChannelPostingStatus::TO_POST) {
                    $toPost->push($posting);
                }
            }

            $publisherIds = $this->lockedPostings($locked)
                ->filter(fn (JobPosting $posting): bool => $posting->status === ChannelPostingStatus::WAITING && $this->channels->canGoLive($posting->channel, $locked))
                ->pluck('id')
                ->all();

            $this->dispatchAfterCommit($locked, new JobPostingSyncResult($toPost, new EloquentCollection, new EloquentCollection, $publisherIds, CarbonImmutable::now()), $actor);
        });
    }

    /** Model "deleted" hook: a soft-deleted job's remaining ads are closed like a job close. */
    public function handleJobDeleted(EmployeeJobPosition $job): void
    {
        if ($job->isForceDeleting()) {
            return;
        }

        DB::transaction(function () use ($job): void {
            $locked = JobPosition::withTrashed()->whereKey($job->getKey())->lockForUpdate()->first();

            if (! $locked) {
                return;
            }

            $actor = Auth::user();
            $result = $this->onJobClosed($locked, $actor instanceof User ? $actor : null, 'job_deleted');
            $this->dispatchAfterCommit($locked, $result, $actor instanceof User ? $actor : null);
        });
    }

    /** Model "saving" guard: ads are tied to one company, so a job with ads cannot change company. */
    public function assertCompanyChangeIsAllowed(EmployeeJobPosition $job): void
    {
        if ($job->exists && $job->isDirty('company_id') && JobPosting::query()->where('job_id', $job->getKey())->exists()) {
            // A validation error, so the job forms (also the employees plugin's) show it on the field.
            $message = 'This job already has job ads and cannot move to another company.';

            throw ValidationException::withMessages(['data.company_id' => $message, 'company_id' => $message]);
        }
    }

    protected function applyPublisherOutcome(JobPosting $posting, JobPosition $job, ?string $url): void
    {
        $now = now();

        if ($job->trashed() || $job->postingStatus() === JobPostingStatus::CLOSED) {
            $posting->transitionTo(ChannelPostingStatus::CLOSED, $posting->status->isOutThere() ? ['removed_at' => $posting->removed_at ?? $now] : [], 'publisher_synced', JobPostingEvent::ORIGIN_PUBLISHER, null);

            return;
        }

        if ($job->postingStatus() === JobPostingStatus::PUBLISHED) {
            if ($posting->status->canTransitionTo(ChannelPostingStatus::LIVE) || $posting->status === ChannelPostingStatus::LIVE) {
                $posting->transitionTo(ChannelPostingStatus::LIVE, [
                    'live_url'      => $url ?? $posting->live_url,
                    'posted_at'     => $posting->posted_at ?? $now,
                    'last_error'    => null,
                    'last_error_at' => null,
                ], 'publisher_synced', JobPostingEvent::ORIGIN_PUBLISHER, null);
            }

            return;
        }

        // Paused (or not yet published again): the publisher has unlisted it.
        if (in_array($posting->status, [ChannelPostingStatus::LIVE, ChannelPostingStatus::FAILED], true)) {
            $posting->transitionTo(ChannelPostingStatus::WAITING, ['last_error' => null, 'last_error_at' => null], 'publisher_synced', JobPostingEvent::ORIGIN_PUBLISHER, null);
        }
    }

    /** An automatic ad that its publisher may still be listing (waiting/failed syncs included). */
    public function mayBeListed(JobPosting $posting): bool
    {
        return ! $posting->isManual()
            && $this->channels->publisherFor($posting->channel) !== null
            && in_array($posting->status, [ChannelPostingStatus::WAITING, ChannelPostingStatus::FAILED, ChannelPostingStatus::LIVE], true);
    }

    /** A channel removed from the code cannot be advertised again: drop its ad instead of re-planning it. */
    protected function dropIfChannelRemoved(JobPosting $posting, ?User $actor): bool
    {
        if ($this->channels->has((string) $posting->channel)) {
            return false;
        }

        $posting->transitionTo(ChannelPostingStatus::CLOSED, ['dropped_at' => now()], 'dropped', JobPostingEvent::ORIGIN_JOB_LIFECYCLE, $actor, ['reason' => 'channel_removed']);

        return true;
    }

    /** @return EloquentCollection<int, JobPosting> */
    protected function lockedPostings(JobPosition $lockedJob): EloquentCollection
    {
        $postings = JobPosting::query()
            ->where('job_id', $lockedJob->getKey())
            ->whereNull('dropped_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $postings->each(fn (JobPosting $posting) => $posting->setRelation('job', $lockedJob));

        return $postings;
    }
}
