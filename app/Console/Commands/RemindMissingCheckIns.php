<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Employee\Services\Attendance\EmployeeAttendanceNotifier;

class RemindMissingCheckIns extends Command
{
    protected $signature = 'hr:remind-missing-check-in
                            {--company= : Only this company id}';

    protected $description = 'Remind employees who are due at work (10:15; 14:15 after a first half off) and have not checked in today (once per employee per day)';

    public function handle(EmployeeAttendanceNotifier $notifier): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;

        $result = $notifier->remindMissingCheckIns($companyId);

        $this->info("Check-in reminders: {$result['notified']} sent for {$result['companies']} company(ies), {$result['skipped']} already sent.");

        return self::SUCCESS;
    }
}
