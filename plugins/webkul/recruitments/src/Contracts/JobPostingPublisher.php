<?php

namespace Webkul\Recruitment\Contracts;

use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobPosting;

/**
 * Seam for channels Aureus lists by itself: the careers page and Google for
 * Jobs (Stage 3), the Indeed/aggregator XML feeds (Stage 5.1) and provider
 * APIs (Stage 5.2). None is registered yet, so those channels wait.
 */
interface JobPostingPublisher
{
    public function channelKey(): string;

    public function isAvailable(JobPosition $job): bool;

    /**
     * Make the channel match the job's current status: list it while the job
     * is published, unlist it when the job is paused, closed or deleted. Must
     * be idempotent. Called after commit, outside any transaction. Returns the
     * public listing URL when known; throws on failure (stored as last_error).
     */
    public function sync(JobPosting $posting): ?string;
}
