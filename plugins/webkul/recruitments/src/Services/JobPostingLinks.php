<?php

namespace Webkul\Recruitment\Services;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Webkul\Recruitment\Enums\ChannelPostingStatus;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobPosting;
use Webkul\Recruitment\Models\WebsiteConnection;
use Webkul\Recruitment\Services\Website\CompanyWebsitePublisher;
use Webkul\Support\Models\UTMSource;

/**
 * Tracked apply links: every job ad gets an unguessable token so the CV it
 * brings in is attributed to that ad. When the ad's company has its careers
 * website connected and the job is live there, the link is the website job
 * page with "?ref={token}" (automation plan §6 A3). Otherwise it points at the
 * Aureus careers route, which only counts as live once that route exists and
 * Aureus is reachable over public HTTPS. A link that is not live is never put
 * into ad text (localhost, private and .test hosts never count).
 */
class JobPostingLinks
{
    public const APPLY_ROUTE = 'recruitments.careers.apply';

    /** 128-bit random, lowercase hex: safe under the case-insensitive unique index. */
    public function newToken(): string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $token = bin2hex(random_bytes(16));

            if (! JobPosting::query()->where('token', $token)->exists()) {
                return $token;
            }
        }

        return bin2hex(random_bytes(16));
    }

    /**
     * Always on the configured careers base URL (never the current request's
     * host or scheme), so an ad carries the same public link whoever builds it.
     */
    public function applyUrl(JobPosting $posting): string
    {
        $websiteUrl = $this->websiteJobUrl($posting);

        if ($websiteUrl !== null) {
            return $this->withRef($websiteUrl, (string) $posting->token);
        }

        $path = Route::has(self::APPLY_ROUTE)
            ? route(self::APPLY_ROUTE, ['token' => $posting->token], absolute: false)
            : str_replace('{token}', (string) $posting->token, (string) config('recruitment.careers.apply_path'));

        return $this->baseUrl().'/'.ltrim($path, '/');
    }

    /**
     * Links can be handed to candidates: for an ad whose job is live on its
     * company website, when that page is public HTTPS; otherwise when the
     * Aureus careers route exists and the base URL is public HTTPS.
     */
    public function linksAreLive(?JobPosting $posting = null): bool
    {
        $websiteUrl = $posting ? $this->websiteJobUrl($posting) : null;

        if ($websiteUrl !== null) {
            return $this->isPublicHttps($websiteUrl);
        }

        if (! Route::has(self::APPLY_ROUTE)) {
            return false;
        }

        return $this->isPublicHttps($this->baseUrl());
    }

    /**
     * The job's page on its company website, when the company's connection is
     * switched on and the job's "Company website" ad is live with its address.
     */
    public function websiteJobUrl(JobPosting $posting): ?string
    {
        if (! $posting->company_id || ! WebsiteConnection::activeFor((int) $posting->company_id)) {
            return null;
        }

        $website = $posting->channel === CompanyWebsitePublisher::CHANNEL && (string) $posting->placement_key === ''
            ? $posting
            : JobPosting::query()
                ->where('job_id', $posting->job_id)
                ->where('company_id', $posting->company_id)
                ->where('channel', CompanyWebsitePublisher::CHANNEL)
                ->where('placement_key', '')
                ->first();

        if (! $website || $website->dropped_at !== null || $website->status !== ChannelPostingStatus::LIVE || blank($website->live_url)) {
            return null;
        }

        return (string) $website->live_url;
    }

    /** Public HTTPS: never localhost, a private or reserved IP, or a .localhost/.test/.local/.internal name. */
    public function isPublicHttps(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (($parts['scheme'] ?? null) !== 'https' || $host === '') {
            return false;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            return false;
        }

        foreach (['.localhost', '.test', '.local', '.internal'] as $suffix) {
            if (Str::endsWith($host, $suffix)) {
                return false;
            }
        }

        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }

    public function notLiveReason(?JobPosition $job = null): ?string
    {
        if ($job && $job->company_id && WebsiteConnection::activeFor((int) $job->company_id)) {
            $website = JobPosting::query()
                ->where('job_id', $job->getKey())
                ->where('channel', CompanyWebsitePublisher::CHANNEL)
                ->where('placement_key', '')
                ->first();
            $url = $website ? $this->websiteJobUrl($website) : null;

            if ($url === null) {
                return 'Apply links become active once this job is live on the company website: add the "Company website" channel and publish the job.';
            }

            return $this->isPublicHttps($url) ? null : "Apply links are not active: the website job page {$url} is not a public HTTPS address.";
        }

        if ($this->linksAreLive()) {
            return null;
        }

        return "Apply links are not active yet: connect the company's careers website (Recruitment → Configurations → Careers website), or put Aureus online over HTTPS (it is only reachable at {$this->baseUrl()}).";
    }

    public function careersHost(): ?string
    {
        $host = parse_url($this->baseUrl(), PHP_URL_HOST);

        return $host ? strtolower($host) : null;
    }

    /**
     * The ad behind a tracked link, whatever the ad's status (a late click on
     * a taken-down ad still attributes). Returns the same null for every
     * failure; deciding whether to accept the application is the caller's job.
     */
    public function resolveToken(string $token): ?JobPosting
    {
        if (preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            return null;
        }

        $posting = JobPosting::query()->where('token', $token)->with('job')->first();

        if (! $posting || ! $posting->job || $posting->job->trashed()) {
            return null;
        }

        return $posting;
    }

    /**
     * Source facts an application inherits from the ad it came through.
     *
     * @return array{job_posting_id: int, job_id: int, company_id: int, channel: string, source_id: ?int, source_details: string}
     */
    public function attributionFor(JobPosting $posting): array
    {
        $sourceId = $posting->utm_source_id;

        if (! $sourceId || ! UTMSource::query()->whereKey($sourceId)->exists()) {
            $sourceId = $this->resolveUtmSourceId($posting->definition()->utmSourceName);
            $posting->forceFill(['utm_source_id' => $sourceId])->saveQuietly();
        }

        return [
            'job_posting_id' => (int) $posting->id,
            'job_id'         => (int) $posting->job_id,
            'company_id'     => (int) $posting->company_id,
            'channel'        => (string) $posting->channel,
            'source_id'      => $sourceId,
            'source_details' => $posting->displayName(),
        ];
    }

    /** The ad's source for a form preview: never creates or saves anything. */
    public function peekSourceId(JobPosting $posting): ?int
    {
        if ($posting->utm_source_id && UTMSource::query()->whereKey($posting->utm_source_id)->exists()) {
            return (int) $posting->utm_source_id;
        }

        $id = UTMSource::query()->where('name', $posting->definition()->utmSourceName)->orderBy('id')->value('id');

        return $id ? (int) $id : null;
    }

    /** utm_sources is a global taxonomy without a unique name: reuse the oldest match, create once. */
    public function resolveUtmSourceId(string $name): int
    {
        return (int) (UTMSource::query()->where('name', $name)->orderBy('id')->value('id')
            ?? UTMSource::query()->create(['name' => $name])->id);
    }

    /** Adds ?ref={token} (or &ref=) to the website job URL, dropping any #fragment. */
    protected function withRef(string $url, string $token): string
    {
        $url = Str::before($url, '#');

        return $url.(str_contains($url, '?') ? '&' : '?').'ref='.rawurlencode($token);
    }

    protected function baseUrl(): string
    {
        return rtrim((string) (config('recruitment.careers.base_url') ?: config('app.url')), '/');
    }
}
