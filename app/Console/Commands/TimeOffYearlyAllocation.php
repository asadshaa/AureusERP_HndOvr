<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\TimeOff\Services\LeaveBalanceService;
use Webkul\TimeOff\Services\YearlyLeaveAllocationService;

class TimeOffYearlyAllocation extends Command
{
    protected $signature = 'time-off:yearly-allocation
                            {--year= : Allocation year (default: the current year, Asia/Karachi)}
                            {--company= : Only this company id}
                            {--dry-run : Show what would be allocated without saving}';

    protected $description = "Create the year's leave allocations for all active employees from each company's yearly allocation policy, carrying forward unused days up to the cap (idempotent)";

    public function handle(YearlyLeaveAllocationService $service): int
    {
        $year = $this->option('year') !== null ? (int) $this->option('year') : LeaveBalanceService::today()->year;
        if ($year < 2000 || $year > 2100) {
            $this->error('--year must look like 2027.');

            return self::INVALID;
        }

        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $dryRun = (bool) $this->option('dry-run');

        $result = $service->run($year, $companyId, $dryRun);

        $rows = array_values(array_filter($result['rows'], fn (array $row): bool => $row['status'] !== 'exists'));
        if ($rows !== []) {
            $this->table(
                ['Company', 'Employee', 'Leave type', 'Grant', 'Carried', 'Total', 'Status'],
                array_map(fn (array $row): array => [
                    $row['company'],
                    $row['employee'],
                    $row['leave_type'],
                    LeaveBalanceService::format($row['grant']),
                    LeaveBalanceService::format($row['carried']),
                    LeaveBalanceService::format($row['total']),
                    $row['status'],
                ], $rows),
            );
        }

        $this->info(sprintf(
            '%sYearly leave allocation %d: %d allocation(s) %s (%s days), %d already allocated, %d with nothing to grant.',
            $dryRun ? '[dry run] ' : '',
            $year,
            $result['created'],
            $dryRun ? 'would be created' : 'created',
            LeaveBalanceService::format($result['days']),
            $result['existing'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
