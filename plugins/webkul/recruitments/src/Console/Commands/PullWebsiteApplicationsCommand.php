<?php

namespace Webkul\Recruitment\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Recruitment\Models\WebsiteConnection;
use Webkul\Recruitment\Services\Website\WebsiteIntakeService;

/**
 * Every 5 minutes (routes/console.php): imports the new applications of every
 * switched-on company careers website and acknowledges them (automation plan
 * §6 A4). Safe to run again at any time: nothing is imported twice.
 */
class PullWebsiteApplicationsCommand extends Command
{
    protected $signature = 'recruitments:pull-website-applications {--company= : Only the careers website of this company id}';

    protected $description = 'Import new applications from the company careers websites and acknowledge them';

    public function handle(WebsiteIntakeService $intake): int
    {
        if (! WebsiteConnection::tableExists()) {
            return self::SUCCESS;
        }

        $companyId = filled($this->option('company')) ? (int) $this->option('company') : null;
        $results = $intake->pullAll($companyId);

        if ($results->isEmpty()) {
            $this->info('No careers website is connected and switched on.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($results as $result) {
            $result->error !== null ? $this->error($result->summary()) : $this->line($result->summary());
            $failed = $failed || $result->error !== null;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
