<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Employee\Services\EmployeeCodeGenerator;

/**
 * Gives employees without an employee code one (PREFIX-0001, ...). Codes that
 * are already set are never touched. --prefix sets the company's prefix
 * first (needs --company); --dry-run only lists what would be assigned.
 */
class BackfillEmployeeCodes extends Command
{
    protected $signature = 'hr:backfill-employee-codes
                            {--company= : Only this company id}
                            {--prefix= : Set this company\'s code prefix first (with --company), e.g. SHL}
                            {--dry-run : Only list the codes that would be assigned}';

    protected $description = 'Fill empty employee codes with the company prefix sequence (never changes an existing code)';

    public function handle(EmployeeCodeGenerator $codes): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $prefix = $this->option('prefix');
        $dryRun = (bool) $this->option('dry-run');

        if ($prefix !== null) {
            $prefix = strtoupper(trim((string) $prefix));

            if ($companyId === null || ! preg_match('/^[A-Z0-9]{1,10}$/', $prefix)) {
                $this->error('--prefix needs --company and 1-10 letters or digits.');

                return self::INVALID;
            }

            if (! $dryRun) {
                $codes->sequenceFor($companyId)->update(['prefix' => $prefix]);
                $this->info("Company {$companyId}: prefix set to {$prefix}.");
            }
        }

        $assigned = $codes->backfill($companyId, $dryRun);

        if ($assigned !== []) {
            $this->table(['Employee', 'Company', 'Name', 'Code'], array_map(fn (array $row): array => [
                $row['employee_id'], $row['company_id'], $row['name'], $row['code'],
            ], $assigned));
        }

        $this->info(($dryRun ? 'Would assign ' : 'Assigned ').count($assigned).' employee code(s). Existing codes were not changed.');

        return self::SUCCESS;
    }
}
