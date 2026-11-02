<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoPurger;
use Illuminate\Console\Command;
use Webkul\Support\Models\Company;

/**
 * Removes what demo:seed created -- and only that: the rows and files
 * recorded in demo_records. Values it filled into the base demo staff
 * (DemoCompanyStaffSeeder) are put back. Real data is never touched.
 *
 * A live company (Truck It In) is purged only when named in --company, and
 * then only of the attendance demo:attendance added; a demo day someone has
 * changed since (or in a finalised month) is kept. Without --company only
 * the demo companies are purged.
 */
class DemoPurge extends Command
{
    protected $signature = 'demo:purge
        {--company=* : ShipLine and/or OpenPort (name or id), default both; or a live company given demo attendance by demo:attendance (e.g. "Truck It In"): only that attendance is removed}
        {--activity-only : Keep the demo staff; remove only attendance, leave, requests, documents and recruitment}
        {--force : Do not ask for confirmation}';

    protected $description = 'Remove the demo data created by demo:seed / demo:attendance (nothing else)';

    public function handle(DemoPurger $purger): int
    {
        $requested = (array) $this->option('company');
        $live = [];
        $demo = [];

        foreach ($requested as $value) {
            $company = DemoAttendance::liveCompany((string) $value);

            if ($company instanceof Company) {
                $live[$company->id] = $company;
            } else {
                $demo[] = $value;
            }
        }

        // No --company: the demo companies only (never a live company).
        $names = $demo !== [] || $requested === [] ? DemoSeed::resolveCompanies($demo, $this) : [];

        if ($names === null) {
            return self::FAILURE;
        }

        $companyIds = Company::query()->whereIn('name', $names)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $scope = $this->option('activity-only') ? 'demo activity' : 'all demo data';
        $question = collect([
            $names !== [] ? "{$scope} of ".implode(', ', $names) : null,
            $live !== [] ? 'the demo attendance of '.collect($live)->pluck('name')->implode(', ') : null,
        ])->filter()->implode(' and ');

        if (! $this->option('force') && ! $this->confirm("Remove {$question}?")) {
            $this->info('Nothing removed.');

            return self::SUCCESS;
        }

        $failed = false;

        if ($companyIds !== []) {
            $result = $purger->purge($companyIds, (bool) $this->option('activity-only'));
            $this->info("Removed {$result['deleted']} rows and {$result['files']} files; restored {$result['restored']} pre-existing demo rows.");

            if ($result['users'] !== []) {
                $this->line(count($result['users']).' demo login(s) removed (and dropped from storage/app/private/demo-accounts.txt).');
            }

            $failed = $this->reportFailures($result);
        }

        foreach ($live as $company) {
            $result = $purger->purge([(int) $company->id], activityOnly: true, tables: DemoPurger::ATTENDANCE_TABLES, keepChanged: true);
            $this->info("{$company->name}: removed {$result['deleted']} demo attendance rows (days and intervals).");

            if ($result['kept'] > 0) {
                $this->warn("{$result['kept']} demo day(s) were kept: changed since they were seeded, or in a finalised month.");
            }

            $failed = $this->reportFailures($result) || $failed;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @param  array{failed: int, failures: array<int, string>}  $result */
    private function reportFailures(array $result): bool
    {
        if ($result['failed'] === 0) {
            return false;
        }

        $this->warn("{$result['failed']} row(s) could not be removed:");
        foreach ($result['failures'] as $failure) {
            $this->line("  {$failure}");
        }

        return true;
    }
}
