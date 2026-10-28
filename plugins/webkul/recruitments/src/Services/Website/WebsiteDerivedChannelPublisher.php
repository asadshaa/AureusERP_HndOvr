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
 * Google for Jobs and the job-board XML feeds come from the company website
 * itself (the job page carries the JobPosting JSON-LD; the careers site serves
 * /careers/feed/{board}.xml for Indeed, Jooble, talent.com, recruit.net,
 * Expertini, TipTopJob and a generic feed), so Aureus calls nothing for them:
 * they are live exactly while the job is live on the website. Their URLs are
 * derived: Google = the website job page, a feed board = the site's feed
 * address for that board. Without a live website listing they keep waiting.
 */
class WebsiteDerivedChannelPublisher implements JobPostingPublisher
{
    public const GOOGLE = 'google_jobs';

    public const INDEED_FEED = 'indeed_feed';

    /** @param  ?string  $feedBoard  the careers-site feed key; null for Google (and "indeed" for indeed_feed) */
    public function __construct(protected string $channel, protected ?string $feedBoard = null)
    {
        if ($channel === self::INDEED_FEED) {
            $this->feedBoard ??= 'indeed';
        }

        if ($channel !== self::GOOGLE && blank($this->feedBoard)) {
            throw new InvalidArgumentException("No website-derived channel [{$channel}].");
        }
    }

    /** The company website's feed address for a board, e.g. https://site/careers/feed/jooble.xml. */
    public static function feedUrl(?WebsiteConnection $connection, string $board): ?string
    {
        if (! $connection || blank($connection->base_url)) {
            return null;
        }

        $path = $board === 'indeed'
            ? (string) config('recruitment.website.indeed_feed_path', '/careers/feed/indeed.xml')
            : str_replace('{board}', $board, (string) config('recruitment.website.feed_path', '/careers/feed/{board}.xml'));

        return rtrim((string) $connection->base_url, '/').'/'.ltrim($path, '/');
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
            : static::feedUrl(WebsiteConnection::activeFor((int) $posting->company_id), (string) $this->feedBoard);
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
     * Google and feed-board ads follow after commit.
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
        $channels = array_keys(array_filter($registry->publishers(), fn ($publisher): bool => $publisher instanceof self));

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
