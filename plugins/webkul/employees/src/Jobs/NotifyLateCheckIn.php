<?php

namespace Webkul\Employee\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Webkul\Employee\Services\Attendance\EmployeeAttendanceNotifier;

/**
 * Tells the employee, right after a late first check-in of the day, how
 * late they were and what is left of the month's grace balance. Dispatched
 * after the check-in commits; idempotent per employee and day, so a retry
 * never notifies twice.
 */
class NotifyLateCheckIn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> seconds before the 2nd and 3rd attempt */
    public array $backoff = [30, 120];

    public function __construct(
        public int $attendanceRecordId,
    ) {
        $this->afterCommit();
    }

    public function handle(EmployeeAttendanceNotifier $notifier): void
    {
        $notifier->notifyLateCheckIn($this->attendanceRecordId);
    }
}
