<?php

namespace Webkul\Employee\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Employee\Services\Attendance\GeofencedAttendanceService;

class AutoCompleteShiftsCommand extends Command
{
    protected $signature = 'hr:auto-complete-shifts {--force : Force completion check}';

    protected $description = 'Automatically completes open attendance shifts that have reached their scheduled end or completed 8 hours.';

    public function handle(GeofencedAttendanceService $service): int
    {
        $this->info('Checking for completed open shifts...');

        $count = $service->autoCompleteCompletedShifts();

        $this->info("Completed {$count} open shift(s).");

        return self::SUCCESS;
    }
}
