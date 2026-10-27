<?php

namespace Webkul\Recruitment\Services\Website;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Webkul\Recruitment\Contracts\JobPostingPublisher;
use Webkul\Recruitment\Enums\ChannelPostingStatus;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobPosting;
use Webkul\Recruitment\Models\JobPostingEvent;
use Webkul\Recruitment\Models\WebsiteConnection;
use Webkul\Recruitment\Services\JobPostingChannelRegistry;
use Webkul\Recruitment\Services\JobPostingLifecycle;

/**
 * Google for Jobs and the Indeed XML feed come from the company website
 * itself (the job page carries the JobPosting JSON-LD; the Aureus Careers
 * plugin serves /careers/feed/indeed.xml), so Aureus calls nothing for them:
 * they are live exactly while the job is live on the website. Their URLs are
 * derived: Google = the website job page, Indeed = the site's feed address.
 * Without a live website listing they keep waiting, as before.
 */
class WebsiteDerivedChannelPublisher implements JobPostingPublisher
{
    public const GOOGLE = 'google_jobs';

    public const INDEED_FEED = 'indeed_feed';

    public function __construct(protected string $channel)
    {
        if (! in_array($channel, [self::GOOGLE, self::INDEED_FEED], true)) {
            throw new InvalidArgumentException("No website-derived channel [{$channel}].");
        }
    }

    public function channelKey(): string
    {
        return $this->channel;
    }

    public function isAvailable(JobPosition $job): bool
    {
        return static::websiteListing((int) $job->getKey(), $job->company_id ? (int) $job->company_id : null) !== null;
    }

    public function sync(JobPosting $posting): ?string
    {
        $job = $posting->job ?? JobPosition::withTrashed()->find($posting->job_id);

        if (! CompanyWebsitePublisher::shouldBeListed($posting, $job)) {
            // Nothing to take down here: the job drops out of Google and the feed with the website page.
            return null;
        }

        $listing = static::websiteListing((int) $posting->job_id, (int) $posting->company_id);

        if (! $listing) {
            throw new RuntimeException('Waits for the job to be live on the company website.');
        }

        return $this->channel === self::GOOGLE
            ? (string) $listing->live_url
            : rtrim((string) WebsiteConnection::activeFor((int) $posting->company_id)?->base_url, '/').'/'.ltrim((string) config('recruitment.website.indeed_feed_path', '/careers/feed/indeed.xml'), '/');
    }

    /** The job's live "Company website" ad, when its company's connection is on. */
    public static function websiteListing(int $jobId, ?int $companyId): ?JobPosting
    {
        if (! $companyId || ! WebsiteConnection::activeFor($companyId)) {
            return null;
        }

        return JobPosting::query()
            ->where('job_id', $jobId)
            ->where('company_id', $companyId)
            ->where('channel', CompanyWebsitePublisher::CHANNEL)
            ->where('placement_key', '')
            ->whereNull('dropped_at')
            ->where('status', ChannelPostingStatus::LIVE->value)
            ->whereNotNull('live_url')
            ->first();
    }

    /**
     * JobPostingEvent "created" hook (registered by the service provider): when
     * the job's website ad goes live, changes address or stops being live, its
     * Google and Indeed ads follow after commit.
     */
    public static function followWebsiteListing(JobPostingEvent $event): void
    {
        if ($event->from_status !== ChannelPostingStatus::LIVE->value && $event->to_status !== ChannelPostingStatus::LIVE->value) {
            return;
        }

        $website = JobPosting::query()->whereKey($event->job_posting_id)->first(['id', 'job_id', 'channel']);

        if (! $website || $website->channel !== CompanyWebsitePublisher::CHANNEL) {
            return;
        }

        $registry = app(JobPostingChannelRegistry::class);
        $channels = array_values(array_filter([self::GOOGLE, self::INDEED_FEED], fn (string $key): bool => $registry->publisherFor($key) instanceof self));

        if ($channels === []) {
            return;
        }

        $ids = JobPosting::query()
            ->where('job_id', $website->job_id)
            ->whereIn('channel', $channels)
            ->whereNull('dropped_at')
            ->whereIn('status', [ChannelPostingStatus::WAITING->value, ChannelPostingStatus::LIVE->value, ChannelPostingStatus::FAILED->value])
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($ids !== []) {
            DB::afterCommit(fn () => app(JobPostingLifecycle::class)->syncPublishers($ids));
        }
    }
}
