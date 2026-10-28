<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Employee\Services\Attendance\EmployeeAttendanceNotifier;

class AlertNegativeGraceBalances extends Command
{
    protected $signature = 'hr:grace-balance-alerts
                            {--company= : Only this company id}';

    protected $description = 'Tell employees whose grace balance has gone below zero this month that it will show as a shortfall (once per employee per month)';

    public function handle(EmployeeAttendanceNotifier $notifier): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;

        $result = $notifier->alertNegativeGraceBalances($companyId);

        $this->info("Negative grace balance alerts: {$result['notified']} sent for {$result['companies']} company(ies), {$result['skipped']} already sent.");

        return self::SUCCESS;
    }
}
