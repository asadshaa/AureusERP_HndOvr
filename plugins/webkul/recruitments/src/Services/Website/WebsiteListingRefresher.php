<?php

namespace Webkul\Recruitment\Services\Website;

use Illuminate\Support\Facades\DB;
use Webkul\Employee\Models\EmployeeJobPosition;
use Webkul\Recruitment\Enums\ChannelPostingStatus;
use Webkul\Recruitment\Models\JobPosting;
use Webkul\Recruitment\Models\JobQuestion;
use Webkul\Recruitment\Services\JobPostingChannelRegistry;
use Webkul\Recruitment\Services\JobPostingLifecycle;

/**
 * Keeps the website page of a listed job up to date: when HR edits what the
 * website shows (title, description, location, salary, apply-before date,
 * screening questions...) the job is pushed again after commit. Status
 * changes (publish, pause, close) are the lifecycle's job and are skipped.
 */
class WebsiteListingRefresher
{
    /** Job columns that change the website payload (JobPublicProfile::payload()). */
    public const PUBLIC_FIELDS = [
        'name', 'public_title', 'slug', 'description', 'requirements', 'location_type', 'address_id',
        'employment_type_id', 'salary_min', 'salary_max', 'salary_currency_id', 'salary_period',
        'salary_visible', 'valid_through', 'date_to', 'min_experience_years', 'is_active',
    ];

    public static function jobUpdated(EmployeeJobPosition $job): void
    {
        if ($job->wasChanged('posting_status') || ! $job->wasChanged(self::PUBLIC_FIELDS)) {
            return;
        }

        static::refresh((int) $job->getKey());
    }

    public static function questionChanged(JobQuestion $question): void
    {
        if ($question->job_id) {
            static::refresh((int) $question->job_id);
        }
    }

    /** Push the job again if its website ad is live (or failed, which this retries). */
    public static function refresh(int $jobId): void
    {
        if (! app(JobPostingChannelRegistry::class)->publisherFor(CompanyWebsitePublisher::CHANNEL)) {
            return;
        }

        $postingId = JobPosting::query()
            ->where('job_id', $jobId)
            ->where('channel', CompanyWebsitePublisher::CHANNEL)
            ->where('placement_key', '')
            ->whereNull('dropped_at')
            ->whereIn('status', [ChannelPostingStatus::LIVE->value, ChannelPostingStatus::FAILED->value])
            ->value('id');

        if ($postingId) {
            DB::afterCommit(fn () => app(JobPostingLifecycle::class)->syncPublishers([(int) $postingId]));
        }
    }
}
