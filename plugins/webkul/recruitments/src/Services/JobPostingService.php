<?php

namespace Webkul\Recruitment\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Webkul\Recruitment\Enums\ChannelPostingStatus;
use Webkul\Recruitment\Enums\JobPostingStatus;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobPosting;
use Webkul\Recruitment\Models\JobPostingEvent;
use Webkul\Recruitment\Support\JobPostingChannel;
use Webkul\Recruitment\Support\JobPostingSyncResult;
use Webkul\Recruitment\Support\JobPostingText;
use Webkul\Security\Models\User;
use Webkul\Support\Services\ApprovalEngine;

/**
 * Recruiter actions on a job's ads (Zoho-style multi-posting): choose the
 * channels, then for each manual ad copy the prepared text, confirm it is
 * live with its link, and confirm it is taken down when the job closes.
 * Every action authorises (JobPostingPolicy + company access), locks the job
 * then the posting, and re-checks the state on the locked rows.
 */
class JobPostingService
{
    public function __construct(
        protected JobPostingChannelRegistry $channels,
        protected JobPostingLifecycle $lifecycle,
        protected JobPostingLinks $links,
        protected JobPostingTextBuilder $text,
        protected ApprovalEngine $approvals,
    ) {}

    /**
     * @param  array<int, array{channel: string, placement?: ?string}>  $selections
     * @return EloquentCollection<int, JobPosting> the created or revived postings
     */
    public function addChannels(JobPosition $job, array $selections, User $actor): EloquentCollection
    {
        if (! Gate::forUser($actor)->allows('create', [JobPosting::class, $job])) {
            throw new RuntimeException('You are not allowed to manage job ads for this job.');
        }

        $this->assertCompanyAccess($actor, $job->company_id);

        return DB::transaction(function () use ($job, $selections, $actor): EloquentCollection {
            $lockedJob = JobPosition::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();

            if (! $lockedJob->company_id) {
                throw new RuntimeException("Set the job opening's company before adding channels.");
            }

            if ($lockedJob->postingStatus() === JobPostingStatus::CLOSED) {
                throw new RuntimeException('Reopen the job before adding channels.');
            }

            $wanted = $this->expandSelections($selections);
            $this->assertNoConflicts($lockedJob, $wanted);

            $touched = new EloquentCollection;

            foreach ($wanted as ['channel' => $channel, 'placement' => $placement]) {
                $existing = JobPosting::query()
                    ->where('job_id', $lockedJob->id)
                    ->where('channel', $channel->key)
                    ->where('placement_key', JobPosting::placementKey($placement))
                    ->lockForUpdate()
                    ->first();

                if ($existing && $existing->dropped_at === null) {
                    continue;
                }

                if ($existing) {
                    $existing->setRelation('job', $lockedJob);
                    $existing->transitionTo(
                        $this->lifecycle->initialStatusFor($lockedJob, $channel),
                        ['dropped_at' => null] + JobPostingLifecycle::CYCLE_RESET,
                        'revived',
                        JobPostingEvent::ORIGIN_USER,
                        $actor,
                        $this->previousCycleFacts($existing),
                    );
                    $touched->push($existing);

                    continue;
                }

                $posting = new JobPosting([
                    'channel'       => $channel->key,
                    'placement'     => $placement,
                    'utm_source_id' => $this->links->resolveUtmSourceId($channel->utmSourceName),
                    'creator_id'    => $actor->id,
                ]);
                $posting->job_id = $lockedJob->id;
                $posting->save();
                $posting->setRelation('job', $lockedJob);
                $posting->recordEvent('added', JobPostingEvent::ORIGIN_USER, $actor);

                $this->lifecycle->activate($posting, $lockedJob, $actor, JobPostingEvent::ORIGIN_USER);
                $touched->push($posting);
            }

            $publisherIds = $touched
                ->filter(fn (JobPosting $posting): bool => $posting->status === ChannelPostingStatus::WAITING
                    && $this->channels->canGoLive($posting->channel, $lockedJob))
                ->pluck('id')
                ->all();

            $this->lifecycle->dispatchAfterCommit($lockedJob, JobPostingSyncResult::publisherOnly($publisherIds), $actor);

            return $touched;
        });
    }

    /** Take a channel off the job before its ad went out (kept as history, revived if re-added). */
    public function removeChannel(JobPosting $posting, User $actor): JobPosting
    {
        return $this->mutate($posting, $actor, function (JobPosting $locked, JobPosition $job, User $actor): void {
            if ($locked->dropped_at !== null) {
                throw new RuntimeException('This channel was already removed from the job.');
            }

            if ($locked->status->isOutThere()) {
                throw new RuntimeException('Take the ad down and confirm it first.');
            }

            if ($this->lifecycle->mayBeListed($locked)) {
                throw new RuntimeException('Aureus may still be listing this ad; retry it or close the job so it is taken down first.');
            }

            if (! in_array($locked->status, [ChannelPostingStatus::PLANNED, ChannelPostingStatus::TO_POST, ChannelPostingStatus::WAITING, ChannelPostingStatus::FAILED], true)) {
                throw new RuntimeException('This channel cannot be removed in its current state.');
            }

            $locked->transitionTo(ChannelPostingStatus::CLOSED, ['dropped_at' => now()], 'dropped', JobPostingEvent::ORIGIN_USER, $actor);
        });
    }

    /** The recruiter confirms the ad is up (or updates its link). */
    public function markLive(JobPosting $posting, User $actor, ?string $liveUrl, ?CarbonInterface $postedAt = null): JobPosting
    {
        return $this->mutate($posting, $actor, function (JobPosting $locked, JobPosition $job, User $actor) use ($liveUrl, $postedAt): void {
            $channel = $this->assertManual($locked);
            $from = $locked->status;
            $jobStatus = $job->postingStatus();

            $allowed = match ($from) {
                ChannelPostingStatus::TO_POST, ChannelPostingStatus::FAILED, ChannelPostingStatus::LIVE => in_array($jobStatus, [JobPostingStatus::PUBLISHED, JobPostingStatus::PAUSED], true),
                ChannelPostingStatus::REMOVAL_PENDING                                                   => $jobStatus === JobPostingStatus::PUBLISHED,
                default                                                                                 => false,
            };

            if (! $allowed || $job->trashed()) {
                throw new RuntimeException('This ad can only be marked as posted while the job is published (or paused) and the ad is waiting to be posted.');
            }

            $liveUrl = $this->validateLiveUrl($channel, $liveUrl, required: $channel->requiresLiveUrl);

            if ($postedAt && $postedAt->isFuture()) {
                throw ValidationException::withMessages(['posted_at' => 'The posting date cannot be in the future.']);
            }

            $firstTime = $locked->posted_at === null;

            $locked->transitionTo(ChannelPostingStatus::LIVE, [
                'live_url'             => $liveUrl,
                'posted_at'            => $locked->posted_at ?? ($postedAt ?? now()),
                'posted_by'            => $locked->posted_by ?? $actor->id,
                'apply_url'            => $firstTime ? ($this->links->linksAreLive($locked) ? $this->links->applyUrl($locked) : null) : $locked->apply_url,
                'last_error'           => null,
                'last_error_at'        => null,
                'removal_requested_at' => null,
                'reminder_count'       => 0,
                'last_reminded_at'     => null,
            ], $from === ChannelPostingStatus::LIVE ? 'link_updated' : 'marked_live', JobPostingEvent::ORIGIN_USER, $actor, array_filter([
                'live_url'          => $liveUrl,
                'previous_live_url' => $from === ChannelPostingStatus::LIVE ? $locked->getOriginal('live_url') : null,
            ]));
        });
    }

    /** The recruiter confirms the ad is taken down (WhatsApp: "position filled" posted). */
    public function markRemoved(JobPosting $posting, User $actor): JobPosting
    {
        return $this->mutate($posting, $actor, function (JobPosting $locked, JobPosition $job, User $actor): void {
            $this->assertManual($locked);

            if (! $locked->status->isOutThere()) {
                throw new RuntimeException('Only an ad that is up can be confirmed as taken down.');
            }

            $locked->transitionTo(ChannelPostingStatus::CLOSED, [
                'removed_at' => now(),
                'removed_by' => $actor->id,
            ], 'removed', JobPostingEvent::ORIGIN_USER, $actor);
        });
    }

    /** The recruiter could not post the ad (board rejected it, account issue...). */
    public function reportProblem(JobPosting $posting, User $actor, string $message): JobPosting
    {
        $message = trim($message);
        Validator::make(['message' => $message], ['message' => ['required', 'string', 'max:1000']])->validate();

        return $this->mutate($posting, $actor, function (JobPosting $locked, JobPosition $job, User $actor) use ($message): void {
            $this->assertManual($locked);

            if ($locked->status !== ChannelPostingStatus::TO_POST
                || $job->trashed()
                || ! in_array($job->postingStatus(), [JobPostingStatus::PUBLISHED, JobPostingStatus::PAUSED], true)) {
                throw new RuntimeException('A problem can only be reported for an ad that is waiting to be posted.');
            }

            $locked->transitionTo(ChannelPostingStatus::FAILED, [
                'last_error'    => $message,
                'last_error_at' => now(),
            ], 'problem_reported', JobPostingEvent::ORIGIN_USER, $actor, ['message' => $message]);
        });
    }

    /** Clear a problem: a manual ad goes back to "to post"; an automatic one is synced again. */
    public function retry(JobPosting $posting, User $actor): JobPosting
    {
        return $this->mutate($posting, $actor, function (JobPosting $locked, JobPosition $job, User $actor): void {
            if ($locked->status !== ChannelPostingStatus::FAILED) {
                throw new RuntimeException('Only an ad with a problem can be retried.');
            }

            $clear = ['last_error' => null, 'last_error_at' => null];

            if ($locked->isManual()) {
                $target = $job->postingStatus() === JobPostingStatus::CLOSED || $job->trashed()
                    ? ChannelPostingStatus::CLOSED
                    : ChannelPostingStatus::TO_POST;

                $locked->transitionTo($target, $clear, 'problem_cleared', JobPostingEvent::ORIGIN_USER, $actor);

                return;
            }

            if (! $this->channels->publisherFor($locked->channel)) {
                throw new RuntimeException($locked->definition()->unavailableReason ?? 'This channel is not available yet.');
            }

            $locked->transitionTo(ChannelPostingStatus::WAITING, $clear, 'problem_cleared', JobPostingEvent::ORIGIN_USER, $actor);
            $this->lifecycle->dispatchAfterCommit($job, JobPostingSyncResult::publisherOnly([$locked->id]), $actor);
        });
    }

    /** Put a taken-down or never-posted ad back for a published job (same tracked link). */
    public function repost(JobPosting $posting, User $actor): JobPosting
    {
        return $this->mutate($posting, $actor, function (JobPosting $locked, JobPosition $job, User $actor): void {
            if ($locked->status !== ChannelPostingStatus::CLOSED || $locked->dropped_at !== null) {
                throw new RuntimeException('Only a closed ad of this job can be posted again.');
            }

            if ($job->postingStatus() !== JobPostingStatus::PUBLISHED || $job->trashed()) {
                throw new RuntimeException('Publish the job before posting the ad again.');
            }

            $manual = $locked->isManual();
            $locked->transitionTo($manual ? ChannelPostingStatus::TO_POST : ChannelPostingStatus::WAITING, JobPostingLifecycle::CYCLE_RESET, 'reposted', JobPostingEvent::ORIGIN_USER, $actor, $this->previousCycleFacts($locked));

            if (! $manual && $this->channels->canGoLive($locked->channel, $job)) {
                $this->lifecycle->dispatchAfterCommit($job, JobPostingSyncResult::publisherOnly([$locked->id]), $actor);
            }
        });
    }

    /** "I did post it but never marked it": a closed job's unrecorded ad still needs taking down. */
    public function reportStillUp(JobPosting $posting, User $actor, ?string $liveUrl): JobPosting
    {
        return $this->mutate($posting, $actor, function (JobPosting $locked, JobPosition $job, User $actor) use ($liveUrl): void {
            $channel = $this->assertManual($locked);

            if ($locked->status !== ChannelPostingStatus::CLOSED || $locked->posted_at !== null || $locked->dropped_at !== null
                || ($job->postingStatus() !== JobPostingStatus::CLOSED && ! $job->trashed())) {
                throw new RuntimeException('Only an ad of a closed job that was never recorded as posted can be reported as still up.');
            }

            $locked->transitionTo(ChannelPostingStatus::REMOVAL_PENDING, [
                'live_url'             => $this->validateLiveUrl($channel, $liveUrl, required: false),
                'removal_requested_at' => now(),
            ], 'still_up_reported', JobPostingEvent::ORIGIN_USER, $actor);
        });
    }

    public function postText(JobPosting $posting, User $actor): JobPostingText
    {
        if (! Gate::forUser($actor)->allows('view', $posting)) {
            throw new RuntimeException('You are not allowed to see this job ad.');
        }

        $this->assertManual($posting);

        if ($posting->status === ChannelPostingStatus::CLOSED) {
            throw new RuntimeException('This ad is closed.');
        }

        return $this->text->build($posting);
    }

    /** Shown before closing a job: which manual ads are up and will need taking down. */
    public function closeImpactSummary(JobPosition $job): ?string
    {
        $live = JobPosting::query()
            ->where('job_id', $job->getKey())
            ->whereNull('dropped_at')
            ->where('status', ChannelPostingStatus::LIVE->value)
            ->get()
            ->filter(fn (JobPosting $posting): bool => $posting->isManual());

        if ($live->isEmpty()) {
            return null;
        }

        $names = $live->map(fn (JobPosting $posting): string => $posting->displayName())->implode(', ');

        return "{$live->count()} manual ad(s) are live ({$names}). After closing you will be asked to take them down and confirm each one.";
    }

    /**
     * Authorise, lock job then posting, run the change on the locked rows, and
     * return the caller's instance refreshed.
     *
     * @param  callable(JobPosting, JobPosition, User): void  $change
     */
    protected function mutate(JobPosting $posting, User $actor, callable $change): JobPosting
    {
        if (! Gate::forUser($actor)->allows('update', $posting)) {
            throw new RuntimeException('You are not allowed to manage job ads for this job.');
        }

        $this->assertCompanyAccess($actor, $posting->company_id);

        DB::transaction(function () use ($posting, $actor, $change): void {
            $job = JobPosition::withTrashed()->whereKey($posting->job_id)->lockForUpdate()->firstOrFail();
            $locked = JobPosting::query()->whereKey($posting->getKey())->lockForUpdate()->firstOrFail();
            $locked->setRelation('job', $job);

            $change($locked, $job, $actor);
        });

        return $posting->refresh();
    }

    /**
     * The facts of the cycle a repost/revive clears, kept in its history event.
     *
     * @return array<string, mixed>
     */
    protected function previousCycleFacts(JobPosting $posting): array
    {
        return array_filter([
            'previous_live_url'   => $posting->live_url,
            'previous_posted_at'  => $posting->posted_at?->toIso8601String(),
            'previous_posted_by'  => $posting->posted_by,
            'previous_removed_at' => $posting->removed_at?->toIso8601String(),
        ]);
    }

    protected function assertManual(JobPosting $posting): JobPostingChannel
    {
        $channel = $posting->definition();

        if (! $channel->isManual()) {
            throw new RuntimeException('Aureus lists this channel itself; it is not posted by hand.');
        }

        return $channel;
    }

    protected function assertCompanyAccess(User $actor, mixed $companyId): void
    {
        if (! $companyId || ! $this->approvals->userCanAccessCompany($actor, (int) $companyId)) {
            throw new RuntimeException('This job opening belongs to a company you cannot access.');
        }
    }

    protected function validateLiveUrl(JobPostingChannel $channel, ?string $liveUrl, bool $required): ?string
    {
        $liveUrl = filled($liveUrl) ? trim((string) $liveUrl) : null;

        Validator::make(['live_url' => $liveUrl], [
            'live_url' => [$required ? 'required' : 'nullable', 'string', 'max:2048', $channel->requiresHttps ? 'url:https' : 'url:http,https'],
        ], [
            'live_url.required' => "Paste the link of the {$channel->label} ad.",
        ])->validate();

        if ($liveUrl === null) {
            return null;
        }

        $host = strtolower((string) parse_url($liveUrl, PHP_URL_HOST));
        $host = Str::startsWith($host, 'www.') ? substr($host, 4) : $host;

        if ($host !== '' && $host === $this->links->careersHost()) {
            throw ValidationException::withMessages(['live_url' => "That's the Aureus apply link: paste the address of the ad itself."]);
        }

        if ($channel->liveUrlHosts !== []) {
            $matches = collect($channel->liveUrlHosts)->contains(fn (string $allowed): bool => $host === $allowed || Str::endsWith($host, '.'.$allowed));

            if (! $matches) {
                throw ValidationException::withMessages(['live_url' => "This doesn't look like a {$channel->label} link (expected ".implode(' or ', $channel->liveUrlHosts).').']);
            }
        }

        return $liveUrl;
    }

    /**
     * Validated channel + normalised placement pairs, with required companion
     * channels (e.g. Google for Jobs needs the careers page) added.
     *
     * @param  array<int, array{channel: string, placement?: ?string}>  $selections
     * @return array<int, array{channel: JobPostingChannel, placement: string}>
     */
    protected function expandSelections(array $selections): array
    {
        $wanted = [];

        foreach ($selections as $selection) {
            $key = (string) ($selection['channel'] ?? '');

            if (! $this->channels->has($key)) {
                throw new RuntimeException("Unknown job posting channel [{$key}].");
            }

            $channel = $this->channels->resolve($key);
            $placement = JobPosting::normalizePlacement($selection['placement'] ?? null);

            if ($channel->requiresPlacement && $placement === '') {
                throw ValidationException::withMessages(['placement' => "Enter the {$channel->placementLabel} for {$channel->label}."]);
            }

            if (! $channel->allowsPlacement() && $placement !== '') {
                throw ValidationException::withMessages(['placement' => "{$channel->label} does not take a placement."]);
            }

            $wanted[$channel->key.'|'.JobPosting::placementKey($placement)] = ['channel' => $channel, 'placement' => $placement];

            foreach ($channel->requires as $requiredKey) {
                $wanted[$requiredKey.'|'] ??= ['channel' => $this->channels->resolve($requiredKey), 'placement' => ''];
            }
        }

        if ($wanted === []) {
            throw ValidationException::withMessages(['channels' => 'Choose at least one channel.']);
        }

        return array_values($wanted);
    }

    /** @param array<int, array{channel: JobPostingChannel, placement: string}> $wanted */
    protected function assertNoConflicts(JobPosition $job, array $wanted): void
    {
        $wantedKeys = array_map(fn (array $item): string => $item['channel']->key, $wanted);
        $activeKeys = JobPosting::query()
            ->where('job_id', $job->id)
            ->whereNull('dropped_at')
            ->where('status', '!=', ChannelPostingStatus::CLOSED->value)
            ->pluck('channel')
            ->all();

        foreach ($wanted as ['channel' => $channel]) {
            foreach ($channel->conflictsWith as $conflict) {
                if (in_array($conflict, $wantedKeys, true) || in_array($conflict, $activeKeys, true)) {
                    throw new RuntimeException("{$channel->label} and {$this->channels->labelFor($conflict)} would list the job twice; choose one.");
                }
            }
        }
    }
}
