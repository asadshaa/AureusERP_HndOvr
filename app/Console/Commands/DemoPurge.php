<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoPurger;
use Illuminate\Console\Command;
use Webkul\Support\Models\Company;

/**
 * Removes what demo:seed created -- and only that: the rows and files
 * recorded in demo_records. Values it filled into the base demo staff
 * (DemoCompanyStaffSeeder) are put back. Real data is never touched.
 */
class DemoPurge extends Command
{
    protected $signature = 'demo:purge
        {--company=* : ShipLine and/or OpenPort (name or id); default both}
        {--activity-only : Keep the demo staff; remove only attendance, leave, requests, documents and recruitment}
        {--force : Do not ask for confirmation}';

    protected $description = 'Remove the demo data created by demo:seed (nothing else)';

    public function handle(DemoPurger $purger): int
    {
        $names = DemoSeed::resolveCompanies((array) $this->option('company'), $this);

        if ($names === null) {
            return self::FAILURE;
        }

        $companyIds = Company::query()->whereIn('name', $names)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $scope = $this->option('activity-only') ? 'demo activity' : 'all demo data';

        if (! $this->option('force') && ! $this->confirm("Remove {$scope} of ".implode(', ', $names).'?')) {
            $this->info('Nothing removed.');

            return self::SUCCESS;
        }

        $result = $purger->purge($companyIds, (bool) $this->option('activity-only'));

        $this->info("Removed {$result['deleted']} rows and {$result['files']} files; restored {$result['restored']} pre-existing demo rows.");

        if ($result['users'] !== []) {
            $this->line(count($result['users']).' demo login(s) removed (and dropped from storage/app/private/demo-accounts.txt).');
        }

        if ($result['failed'] > 0) {
            $this->warn("{$result['failed']} row(s) could not be removed:");
            foreach ($result['failures'] as $failure) {
                $this->line("  {$failure}");
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
