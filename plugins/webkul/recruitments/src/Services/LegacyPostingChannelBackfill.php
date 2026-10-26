<?php

namespace Webkul\Recruitment\Services;

use Illuminate\Support\Facades\DB;
use Webkul\Recruitment\Enums\ChannelPostingStatus;
use Webkul\Recruitment\Models\JobPosting;
use Webkul\Recruitment\Models\JobPostingEvent;

/**
 * One-off, idempotent conversion of the legacy posting_channels labels into
 * posting rows. Nobody knows whether those ads were actually posted, so no
 * posting facts are invented: published jobs get "to post" (manual) or
 * "waiting" rows. The legacy JSON column is never modified.
 */
class LegacyPostingChannelBackfill
{
    public function __construct(
        protected JobPostingChannelRegistry $channels,
        protected JobPostingLinks $links,
    ) {}

    /** @return array{created: int, skipped_no_company: int, skipped_unmapped: int, skipped_closed_or_trashed: int} */
    public function run(): array
    {
        $counts = ['created' => 0, 'skipped_no_company' => 0, 'skipped_unmapped' => 0, 'skipped_closed_or_trashed' => 0];
        $map = JobPostingChannelRegistry::legacyKeyMap();

        DB::table('employees_job_positions')
            ->whereNotNull('posting_channels')
            ->orderBy('id')
            ->chunkById(200, function ($jobs) use (&$counts, $map): void {
                foreach ($jobs as $job) {
                    $labels = json_decode((string) $job->posting_channels, true);

                    if (! is_array($labels) || $labels === []) {
                        continue;
                    }

                    if ($job->deleted_at !== null || $job->posting_status === 'closed') {
                        $counts['skipped_closed_or_trashed']++;

                        continue;
                    }

                    if (! $job->company_id) {
                        $counts['skipped_no_company']++;

                        continue;
                    }

                    foreach (array_unique($labels) as $label) {
                        $key = $map[$label] ?? null;

                        if (! $key || ! $this->channels->has($key)) {
                            $counts['skipped_unmapped']++;

                            continue;
                        }

                        $counts['created'] += $this->insert($job, $key, $label);
                    }
                }
            });

        return $counts;
    }

    protected function insert(object $job, string $key, string $label): int
    {
        $channel = $this->channels->resolve($key);
        $published = in_array($job->posting_status, ['published', 'paused'], true);
        $status = match (true) {
            ! $published          => ChannelPostingStatus::PLANNED,
            $channel->isManual()  => ChannelPostingStatus::TO_POST,
            default               => ChannelPostingStatus::WAITING,
        };
        $placement = $channel->requiresPlacement ? 'Legacy' : '';
        $now = now();

        $inserted = DB::table('recruitments_job_postings')->insertOrIgnore([
            'company_id'    => $job->company_id,
            'job_id'        => $job->id,
            'channel'       => $key,
            'placement'     => $placement,
            'placement_key' => JobPosting::placementKey($placement),
            'status'        => $status->value,
            'token'         => $this->links->newToken(),
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        if ($inserted === 0) {
            return 0;
        }

        $postingId = DB::table('recruitments_job_postings')
            ->where('job_id', $job->id)->where('channel', $key)->where('placement_key', JobPosting::placementKey($placement))
            ->value('id');

        DB::table('recruitments_job_posting_events')->insert([
            'company_id'     => $job->company_id,
            'job_posting_id' => $postingId,
            'event'          => 'backfilled',
            'origin'         => JobPostingEvent::ORIGIN_MIGRATION,
            'to_status'      => $status->value,
            'details'        => json_encode(['legacy_value' => $label]),
            'created_at'     => $now,
        ]);

        return 1;
    }
}
