<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Employee\Services\Attendance\LineManagerAttendanceDigest;

class NotifyLineManagerAttendanceDigest extends Command
{
    protected $signature = 'hr:line-manager-attendance-digest
                            {--company= : Only this company id}
                            {--date= : Local date (Y-m-d) instead of today}';

    protected $description = "Notify each line manager of their own team's late and absent people so far today (once per manager per day; nothing to report = no notice)";

    public function handle(LineManagerAttendanceDigest $digest): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $date = $this->option('date') ?: null;

        if ($date !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            $this->error('--date must look like 2026-10-28.');

            return self::INVALID;
        }

        $result = $digest->send($companyId, $date);

        $this->info("Line manager digest: {$result['notified']} notification(s) sent for {$result['companies']} company(ies), {$result['skipped']} already sent.");

        return self::SUCCESS;
    }
}
