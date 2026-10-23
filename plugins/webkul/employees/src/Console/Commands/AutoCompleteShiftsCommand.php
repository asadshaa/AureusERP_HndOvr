<?php

namespace Webkul\Employee\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;

class AutoCompleteShiftsCommand extends Command
{
    protected $signature = 'hr:auto-complete-shifts {--force : Kept for compatibility; has no effect}';

    protected $description = 'Close open GPS attendance shifts at their scheduled end, marked for HR review. Only runs when hr_attendance_geofence.auto_close_policy is "scheduled_end".';

    public function handle(GeofencedAttendanceService $service): int
    {
        $policy = (string) config('hr_attendance_geofence.auto_close_policy', 'none');

        if ($policy !== 'scheduled_end') {
            $this->info("Auto-close is disabled (auto_close_policy={$policy}). Missing check-outs are flagged for review by hr:flag-forgotten-shifts instead.");

            return self::SUCCESS;
        }

        $count = $service->autoCompleteCompletedShifts();

        $this->info("Closed {$count} open shift(s) at their scheduled end; each is marked for HR review.");

        return self::SUCCESS;
    }
}
