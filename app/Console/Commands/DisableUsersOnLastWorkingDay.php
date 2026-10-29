<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Employee\Services\Checklists\EmployeeChecklistService;

class DisableUsersOnLastWorkingDay extends Command
{
    protected $signature = 'hr:checklist-disable-users
                            {--company= : Only this company id}
                            {--date= : Local date (Y-m-d) instead of today}';

    protected $description = 'Disable the user accounts HR scheduled from the exit checklist ("Disable user on last working day"), on or after that day; idempotent and recorded on the employee';

    public function handle(EmployeeChecklistService $checklists): int
    {
        $date = $this->option('date') ?: null;

        if ($date !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            $this->error('--date must look like 2026-10-28.');

            return self::INVALID;
        }

        $result = $checklists->runScheduledUserDisables($date, $this->option('company') !== null ? (int) $this->option('company') : null);

        $this->info("Exit checklists: {$result['disabled']} user account(s) disabled, {$result['already_inactive']} already inactive.");

        return self::SUCCESS;
    }
}
