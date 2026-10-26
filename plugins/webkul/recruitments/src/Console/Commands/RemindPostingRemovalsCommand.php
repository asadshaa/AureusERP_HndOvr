<?php

namespace Webkul\Recruitment\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Recruitment\Enums\ChannelPostingStatus;
use Webkul\Recruitment\Enums\JobPostingStatus;
use Webkul\Recruitment\Models\JobPosition;
use Webkul\Recruitment\Models\JobPosting;
use Webkul\Recruitment\Models\JobPostingEvent;
use Webkul\Recruitment\Services\JobPostingLifecycle;
use Webkul\Recruitment\Services\JobPostingNotifier;

/**
 * Daily: closes the ads of jobs closed or deleted outside the normal flow
 * (safety sweep), then reminds recruiters about manual ads still waiting to be
 * taken down, and tells HR once when the reminders run out.
 */
class RemindPostingRemovalsCommand extends Command
{
    protected $signature = 'recruitments:remind-posting-removals {--dry-run : Only report what would be swept and reminded}';

    protected $description = 'Remind recruiters to take down job ads of closed jobs, and close ads of jobs closed outside the normal flow';

    public function handle(JobPostingLifecycle $lifecycle, JobPostingNotifier $notifier): int
    {
        if (! Schema::hasTable('recruitments_job_postings')) {
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $swept = $dryRun ? 0 : $lifecycle->sweepClosedJobs();

        $afterDays = max(0, (int) config('recruitment.postings.removal_reminder_after_days'));
        $everyDays = max(1, (int) config('recruitment.postings.removal_reminder_every_days'));
        $max = max(1, (int) config('recruitment.postings.removal_reminder_max'));

        // Whole calendar days, so a daily run is never skipped by a few seconds of
        // jitter; only ads of jobs that are closed or deleted (a republished job's
        // carried-over ad is the recruiter's call, see JobPostingLifecycle::onJobPublished()).
        $due = fn () => JobPosting::query()
            ->where('status', ChannelPostingStatus::REMOVAL_PENDING->value)
            ->whereDate('removal_requested_at', '<=', today()->subDays($afterDays))
            ->where(fn ($query) => $query->whereNull('last_reminded_at')->orWhereDate('last_reminded_at', '<=', today()->subDays($everyDays)))
            ->where('reminder_count', '<', $max)
            ->whereHas('job', fn ($query) => $query->withTrashed()->where(fn ($job) => $job
                ->where('posting_status', JobPostingStatus::CLOSED->value)
                ->orWhereNotNull('deleted_at')));

        $jobIds = $due()->distinct()->pluck('job_id');
        $reminded = 0;

        if ($dryRun) {
            $this->info("Dry run: {$jobIds->count()} job(s) have ads due for a take-down reminder.");

            return self::SUCCESS;
        }

        foreach ($jobIds as $jobId) {
            DB::transaction(function () use ($jobId, $due, $max, $notifier, &$reminded): void {
                $job = JobPosition::withTrashed()->whereKey($jobId)->lockForUpdate()->first();

                if (! $job) {
                    return;
                }

                $postings = $due()->where('job_id', $jobId)->orderBy('id')->lockForUpdate()->get();

                if ($postings->isEmpty()) {
                    return;
                }

                foreach ($postings as $posting) {
                    $posting->setRelation('job', $job);
                    $count = $posting->reminder_count + 1;

                    $posting->transitionTo(ChannelPostingStatus::REMOVAL_PENDING, [
                        'reminder_count'   => $count,
                        'last_reminded_at' => now(),
                    ], 'reminder_sent', JobPostingEvent::ORIGIN_SCHEDULER, null, ['reminder' => $count]);
                }

                $reminderNumber = (int) $postings->max('reminder_count');
                $exhausted = $postings->filter(fn (JobPosting $posting): bool => $posting->reminder_count >= $max);

                DB::afterCommit(function () use ($notifier, $job, $postings, $reminderNumber, $exhausted): void {
                    $notifier->remind($job, $postings, $reminderNumber);

                    foreach ($exhausted as $posting) {
                        $notifier->escalate($posting);
                    }
                });

                foreach ($exhausted as $posting) {
                    $posting->recordEvent('escalated', JobPostingEvent::ORIGIN_SCHEDULER, null, ['reminders' => $posting->reminder_count]);
                }

                $reminded++;
            });
        }

        $this->info("Swept {$swept} job(s); sent take-down reminders for {$reminded} job(s).");

        return self::SUCCESS;
    }
}
