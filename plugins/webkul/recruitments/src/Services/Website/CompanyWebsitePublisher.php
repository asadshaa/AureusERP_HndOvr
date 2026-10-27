<?php

namespace Webkul\Recruitment\Services\Website;

use RuntimeException;
use Webkul\Recruitment\Contracts\JobPostingPublisher;
use Webkul\Recruitment\Enums\ChannelPostingStatus;
use Webkul\Recruitment\Enums\JobPostingStatus;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobPosting;
use Webkul\Recruitment\Models\WebsiteConnection;
use Webkul\Recruitment\Services\JobPublicProfile;

/**
 * Lists a job on its company's careers website (automation plan §6 A3) for
 * the "careers_site" channel ("Company website").
 *
 * Token choice: a job has no token of its own; every ad (posting) has one.
 * The job's careers_site posting token is the job's identity on the website
 * (PUT/DELETE jobs/{token}, and the "job_token" of each application). It is
 * unique per job, never changes, survives reopen/repost and is revived with
 * the channel, so the job keeps one page and one address. Every other ad's
 * token travels as "?ref={token}" in its apply link and attributes the
 * application to that ad (JobPostingLinks::applyUrl()).
 *
 * Only the job's own company's connection is ever used.
 */
class CompanyWebsitePublisher implements JobPostingPublisher
{
    public const CHANNEL = 'careers_site';

    public function __construct(protected JobPublicProfile $profile) {}

    public function channelKey(): string
    {
        return self::CHANNEL;
    }

    public function isAvailable(JobPosition $job): bool
    {
        return WebsiteConnection::activeFor($job->company_id ? (int) $job->company_id : null) !== null;
    }

    public function sync(JobPosting $posting): ?string
    {
        $job = $posting->job ?? JobPosition::withTrashed()->find($posting->job_id);
        $connection = WebsiteConnection::activeFor((int) $posting->company_id);
        $list = static::shouldBeListed($posting, $job);

        if (! $connection) {
            if ($list) {
                throw new RuntimeException('The company website is not connected or is switched off (Recruitment → Configurations → Careers website).');
            }

            if ($posting->status === ChannelPostingStatus::LIVE || $posting->posted_at !== null) {
                // It was listed: claiming it is gone would leave it up on the website unnoticed.
                throw new RuntimeException('The company website connection is switched off, so the job could not be taken off the website. Switch the connection on and retry, or remove the job on the website by hand.');
            }

            return null;
        }

        $client = WordPressCareersClient::for($connection);

        if ($list) {
            $this->ensureSlug($job);

            return $client->putJob((string) $posting->token, $this->profile->payload($job));
        }

        $client->deleteJob((string) $posting->token);

        if ($job && ! $job->trashed() && $job->postingStatus() === JobPostingStatus::PUBLISHED && $posting->status !== ChannelPostingStatus::CLOSED && $posting->dropped_at === null) {
            // Published but archived (inactive): it is off the website, which the ad must not report as live.
            throw new RuntimeException('The job is archived, so it was taken off the company website. Restore the job, then retry.');
        }

        return null;
    }

    /** Listed while the job is published, active and not deleted, and the ad is still on the job. */
    public static function shouldBeListed(JobPosting $posting, ?JobPosition $job): bool
    {
        return $job !== null
            && ! $job->trashed()
            && $job->isPubliclyVisible()
            && $posting->dropped_at === null
            && $posting->status !== ChannelPostingStatus::CLOSED;
    }

    /** The website needs a slug for the job's address; give an older job one before its first push. */
    protected function ensureSlug(JobPosition $job): void
    {
        if (filled($job->slug)) {
            return;
        }

        $this->profile->prepareForSave($job);

        if (blank($job->slug)) {
            throw new RuntimeException('The job needs a name or public title before it can go on the website.');
        }

        JobPosition::withTrashed()->whereKey($job->getKey())->update(['slug' => $job->slug]);
        $job->syncOriginalAttribute('slug');
    }
}
