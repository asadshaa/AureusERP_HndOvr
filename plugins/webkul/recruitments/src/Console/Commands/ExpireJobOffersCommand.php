<?php

namespace Webkul\Recruitment\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Webkul\Recruitment\Enums\JobOfferStatus;
use Webkul\Recruitment\Models\JobOffer;
use Webkul\Recruitment\Services\JobOfferService;

/**
 * Daily (HR parity 3.6): offers whose expiry date has passed without an
 * answer (sent, or approved and never sent) become "expired" and the
 * recruiter is told. Idempotent: each offer is claimed with one
 * conditional update, so an overlapping run or a re-run changes nothing.
 */
class ExpireJobOffersCommand extends Command
{
    protected $signature = 'recruitments:expire-offers {--dry-run : Only report what would expire}';

    protected $description = 'Expire job offers past their expiry date and tell the recruiters';

    public function handle(JobOfferService $offers): int
    {
        if (! Schema::hasTable('recruitments_job_offers')) {
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $due = JobOffer::query()
                ->whereIn('status', [JobOfferStatus::APPROVED->value, JobOfferStatus::SENT->value])
                ->where('expiry_date', '<', $offers->today())
                ->count();

            $this->info("Dry run: {$due} offer(s) would expire.");

            return self::SUCCESS;
        }

        $this->info('Expired '.$offers->expireDue().' offer(s).');

        return self::SUCCESS;
    }
}
