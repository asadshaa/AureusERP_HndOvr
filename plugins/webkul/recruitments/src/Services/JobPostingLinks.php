<?php

namespace Webkul\Recruitment\Services;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Webkul\Recruitment\Models\JobPosting;
use Webkul\Support\Models\UTMSource;

/**
 * Tracked apply links: every job ad gets an unguessable token so the CV it
 * brings in is attributed to that ad. Until the Stage 3 careers route exists
 * and Aureus is reachable over public HTTPS, links are generated but reported
 * as not live, so they never end up in ad text.
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
        $path = Route::has(self::APPLY_ROUTE)
            ? route(self::APPLY_ROUTE, ['token' => $posting->token], absolute: false)
            : str_replace('{token}', (string) $posting->token, (string) config('recruitment.careers.apply_path'));

        return $this->baseUrl().'/'.ltrim($path, '/');
    }

    /** Links can be handed to candidates: the careers route exists and the base URL is public HTTPS. */
    public function linksAreLive(): bool
    {
        if (! Route::has(self::APPLY_ROUTE)) {
            return false;
        }

        $parts = parse_url($this->baseUrl());
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

    public function notLiveReason(): ?string
    {
        if ($this->linksAreLive()) {
            return null;
        }

        return "Apply links are not active yet: Aureus is only reachable at {$this->baseUrl()} and the careers page is not built (Stage 3).";
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

    protected function baseUrl(): string
    {
        return rtrim((string) (config('recruitment.careers.base_url') ?: config('app.url')), '/');
    }
}
