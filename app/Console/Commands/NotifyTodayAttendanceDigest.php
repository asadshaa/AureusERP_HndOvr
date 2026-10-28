<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Employee\Services\Attendance\TodayAttendanceDigest;

class NotifyTodayAttendanceDigest extends Command
{
    protected $signature = 'hr:today-attendance-digest
                            {--company= : Only this company id}
                            {--date= : Local date (Y-m-d) instead of today}';

    protected $description = "Notify each company's HR managers of today's absent and late employees and yesterday's missing check-outs (once per company per day)";

    public function handle(TodayAttendanceDigest $digest): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $date = $this->option('date') ?: null;

        if ($date !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            $this->error('--date must look like 2026-10-28.');

            return self::INVALID;
        }

        $result = $digest->send($companyId, $date);

        $this->info("Today's attendance digest: {$result['notified']} notification(s) sent for {$result['companies']} company(ies), {$result['skipped']} already sent.");

        return self::SUCCESS;
    }
}
